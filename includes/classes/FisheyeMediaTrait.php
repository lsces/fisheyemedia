<?php
/**
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeGallery;

/**
 * Shared engine used by both media inheritance branches - FisheyeMediaGallery (FisheyeProgram, a
 * show with no video file of its own) and FisheyeMediaImage (FisheyeFilm/FisheyeSeason/
 * FisheyeAlbum, each a real attached file) - hence a trait rather than living on either single
 * intermediate class. Relies only on methods/properties FisheyeBase already provides on both
 * branches (getExtraImagePath(), resizeImageFile(), storeXref(), mDb, mContentId, getTitle()).
 *
 * @package fisheyemedia
 */
// Longest side kept for artwork stored by storeArtworkXref() - large enough to read a booklet.
const FISHEYEMEDIA_ARTWORK_MAX = 1600;

trait FisheyeMediaTrait {
	// The pictures folder (find / make / list), shared with any other item that has one - fisheye's own small trait.
	use \Bitweaver\Fisheye\FisheyePicturesFolderTrait;

	/**
	 * A description that is about to be replaced by one read from Plex keeps the BBC's "PLAY: <iPlayer url>" and "INFO: <programme url>" lines the old one
	 * carried (the BBC downloads put them there; Plex's own summary has none), added at the end unless the new text already holds them.
	 */
	protected function keepBbcLines( string $pNewDescription ): string {
		if( !preg_match_all( '/^(?:PLAY|INFO):[ \t]*\S+[ \t]*$/mi', (string)( $this->mInfo['data'] ?? '' ), $found ) ) {
			return $pNewDescription;
		}
		$lines = array_filter( array_map( 'trim', $found[0] ), fn( $l ) => stripos( $pNewDescription, $l ) === false );
		return $lines ? rtrim( $pNewDescription )."\n\n".implode( "\n\n", $lines ) : $pNewDescription;
	}

	/** An item's pictures folder uses the Images Grid layout, whose title links back to the item. */
	public function picturesFolderLayout(): ?string {
		return FISHEYE_PAGINATION_IMAGES_GRID;
	}

	/**
	 * Every live xref row on this content object, across all groups except liberty's synthetic
	 * 'history' group (rows with an end_date - archived by a reload's reconcile, or by hand).
	 * liberty's own LibertyXrefContent::allXrefs() deliberately includes history, since the
	 * generic xref grid shows it on its own History tab; the media view/edit code here wants
	 * current data only, so it reads through this instead. Empty if xref info isn't loaded.
	 *
	 * @return array
	 */
	public function liveXrefs(): array {
		$ret = [];
		foreach( $this->mXrefInfo->mGroups ?? [] as $groupName => $group ) {
			if( $groupName === 'history' ) {
				continue;
			}
			foreach( $group->mXrefs as $xref ) {
				$ret[] = $xref;
			}
		}
		return $ret;
	}

	/**
	 * Grab a frame from a given video file and store it as a new 'image' xref on THIS content
	 * object - the shared engine behind FisheyeSeason::grabVideoFrameImage() (source: its own
	 * seed episode) and FisheyeProgram::grabVideoFrameImage() (source: its first season's own
	 * seed episode, since a show has no video file of its own). Reuses
	 * mime_film_grab_video_frame() - the same ffmpegthumbnailer/ffmpeg chain a plain film's own
	 * attachment thumbnail already falls back to.
	 *
	 * @param string $pVideoFile  absolute path to a real video file to grab a frame from
	 * @return string|null  the new xref row's xkey_ext, or null if this content type has no
	 *                       image storage location, the file doesn't exist, or the grab/resize/
	 *                       store itself failed
	 */
	protected function grabVideoFrameIntoImageXref( string $pVideoFile ): ?string {
		if( !is_file( $pVideoFile ) ) {
			return null;
		}
		// Routed through getExtraImagePath() (empty relative path -> the directory itself) rather
		// than hardcoding getImageStorageRoot().'images/' here, so this automatically picks up
		// whichever storage location the calling subclass actually overrides it with (storage/
		// attachments/<branch>/ for every current fisheye content type - see that method's own
		// docblock).
		$imagesDir = $this->getExtraImagePath( '' );
		if( empty( $imagesDir ) ) {
			return null;
		}
		\Bitweaver\KernelTools::mkdir_p( $imagesDir );
		$tmpFile = tempnam( sys_get_temp_dir(), 'fisheye_frame_' );
		$relativePath = null;
		if( \Bitweaver\Liberty\mime_film_grab_video_frame( $pVideoFile, $tmpFile ) ) {
			$baseName = $this->getTitle();
			$n = 1;
			do {
				$fileName = "$baseName-frame-$n.jpg";
				$n++;
			} while( is_file( $imagesDir.$fileName ) );
			if( self::resizeImageFile( $tmpFile, $imagesDir.$fileName, 400 ) ) {
				$relativePath = $fileName;
				$nextXorder = 1 + (int)$this->mDb->getOne(
					"SELECT COALESCE(MAX(`xorder`),0) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id`=? AND `item`='image'",
					[ $this->mContentId ]
				);
				$xrefParamHash = [ 'content_id' => $this->mContentId, 'item' => 'image', 'xkey_ext' => $relativePath, 'xorder' => $nextXorder ];
				$this->storeXref( $xrefParamHash );
			}
		}
		@unlink( $tmpFile );
		return $relativePath;
	}

	/**
	 * Shared engine behind FisheyeFilm::registerFeaturettesFromDisk() and
	 * FisheyeSeason::registerFeaturettesFromDisk() (previously two near-identical copies of this
	 * same scan-and-register logic) - scans a "Featurettes/" subfolder inside the given
	 * containing directory and registers each real video file as a 'featurette' xref on this
	 * content object. Rebuild-not-diff, same as
	 * every other reload* method here. Each subclass still owns resolving its own containing
	 * directory (a film's own folder via `dirname($pRelativePath)`; a season's via
	 * `resolveSeasonDirectoryFromDisk()`) - genuinely different logic per type, not worth forcing
	 * into a shared shape - only the scan-and-register half was actually duplicated.
	 *
	 * @param string $pContainingDirAbsolute  absolute path to the film/season's own folder,
	 *                                         trailing slash
	 * @param string $pContainingDirRelative  same folder, relative to the storage root (no
	 *                                         trailing slash) - used to build each featurette's
	 *                                         xkey_ext
	 * @return array{items:array}
	 */
	protected function registerFeaturettesFromFolder( string $pContainingDirAbsolute, string $pContainingDirRelative ): array {
		$summary = [ 'items' => [] ];
		$featurettesDir = $pContainingDirAbsolute.'Featurettes/';
		if( !is_dir( $featurettesDir ) ) {
			return $summary;
		}

		self::deleteXrefByItem( $this->mContentId, [ 'featurette' ] );

		// Same per-item thumbnail grab as FisheyeSeason::registerEpisodesFromFilesystem()'s own
		// no-Plex fallback - a featurette has no Plex metadata to source a thumb from at all
		// (Plex doesn't catalogue bonus content), so a local frame grab is the only option.
		$imagesDir = $this->getExtraImagePath( '' );
		\Bitweaver\KernelTools::mkdir_p( $imagesDir );

		$files = scandir( $featurettesDir );
		natsort( $files );
		$xorder = 0;
		foreach( $files as $file ) {
			if( !is_file( $featurettesDir.$file ) ) {
				continue;
			}
			if( !in_array( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ), [ 'mkv', 'mp4', 'm4v', 'avi' ], true ) ) {
				continue;
			}
			$xorder++;
			$title = pathinfo( $file, PATHINFO_FILENAME );
			$featuretteData = [ 'title' => $title ];
			// Plex never catalogues bonus content, so there's no metadata source for duration,
			// resolution or audio layout here at all - straight from the file's own container via
			// ffprobe instead.
			$durationMs = \Bitweaver\Liberty\mime_film_get_duration_ms( $featurettesDir.$file );
			if( $durationMs !== null ) {
				$featuretteData['duration'] = $durationMs;
			}
			$qualityInfo = \Bitweaver\Liberty\mime_film_get_quality_info( $featurettesDir.$file );
			if( $qualityInfo['resolution'] !== null ) {
				$featuretteData['resolution'] = $qualityInfo['resolution'];
			}
			if( $qualityInfo['audio'] !== null ) {
				$featuretteData['audio'] = $qualityInfo['audio'];
			}
			// Named after the featurette's own source file (unique within this folder), not its
			// xorder position - reload is rebuild-not-diff (every xref row deleted and re-created
			// above), so a position-based name would both mis-attach an old thumb to the wrong
			// file the moment a new featurette shifts the ordering, and force an expensive
			// re-grab of every thumbnail on every reload even when nothing about that file
			// changed. Keying by the file's own name means an already-grabbed thumbnail is
			// simply reused - only genuinely new featurettes pay the ffmpeg cost.
			$fileName = $title.'.jpg';
			if( is_file( $imagesDir.$fileName ) ) {
				$featuretteData['thumb'] = $fileName;
			} else {
				$tmpFile = tempnam( sys_get_temp_dir(), 'fisheye_featurette_thumb_' );
				if( \Bitweaver\Liberty\mime_film_grab_video_frame( $featurettesDir.$file, $tmpFile ) ) {
					if( self::resizeImageFile( $tmpFile, $imagesDir.$fileName, 400 ) ) {
						$featuretteData['thumb'] = $fileName;
					}
				}
				@unlink( $tmpFile );
			}
			$xrefHash = [
				'content_id' => $this->mContentId,
				'item'       => 'featurette',
				'xkey_ext'   => $pContainingDirRelative.'/Featurettes/'.$file,
				'edit'       => json_encode( $featuretteData ),
				'xorder'     => $xorder,
			];
			$this->storeXref( $xrefHash );
			$summary['items'][] = $title;
		}

		return $summary;
	}

	// Extra images (the 'image' xref item, Images tab) - media types only, so here rather than on
	// fisheye's FisheyeBase.

	/**
	 * Whether addImageXrefFile() below actually does anything for this content type - a real
	 * method call, callable from a template (`{if $gContent->supportsAddImage()}`, templates/
	 * xref/view_images_group.tpl), unlike a bare `method_exists(...)` call, which Smarty here
	 * rejects as an unknown modifier (found live - "unknown modifier 'method_exists'").
	 *
	 * @return bool
	 */
	public function supportsAddImage(): bool {
		return method_exists( $this, 'getImageStorageRoot' );
	}

	/**
	 * Generic hook liberty/add_xref.php calls (via method_exists()) when the only addable item
	 * in a group is 'image' - redirects straight to the real upload flow instead of rendering
	 * add_xref.tpl's generic form, which has no file upload at all and would otherwise create a
	 * dead xref row with an empty xkey_ext and no file behind it.
	 *
	 * @return string|null
	 */
	public function getAddImageUrl(): ?string {
		return $this->supportsAddImage() ? FISHEYEMEDIA_PKG_URL.'add_image_xref.php?content_id='.$this->mContentId : null;
	}

	/** Whether the item's tile has a thumbnail - an attachment row; a type whose tile is a file on disk checks for that as well. */
	public function hasThumbnail(): bool {
		return (bool)$this->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_attachments` WHERE `content_id` = ?", [ $this->mContentId ] );
	}

	/**
	 * Called after pictures are uploaded into this item's pictures folder: the first one becomes the item's thumbnail when it has
	 * none yet, as for an image added on the Images tab. An item that already has a thumbnail is left alone - Set as Thumbnail
	 * on the Images tab replaces one.
	 *
	 * @param int $pImageContentId  content_id of the first picture uploaded
	 */
	public function folderImageAdded( int $pImageContentId ): void {
		if( !$this->hasThumbnail() ) {
			$this->promoteFolderImageToThumbnail( $pImageContentId );
		}
	}

	/**
	 * Make one of the pictures folder's images this item's thumbnail: its file is copied into the item's own image
	 * storage for the moment, handed to the type's promoteImageToThumbnail() (the call the Images tab's Set as
	 * Thumbnail already makes for an 'image' row), and the copy removed - the thumbnail is a rendered copy, so
	 * nothing keeps pointing at the folder image.
	 *
	 * @param int $pImageContentId  must be an image in this item's own folder
	 */
	public function promoteFolderImageToThumbnail( int $pImageContentId ): bool {
		if( !method_exists( $this, 'promoteImageToThumbnail' ) || !method_exists( $this, 'getExtraImagePath' ) ) {
			return false;
		}
		$inFolder = false;
		foreach( $this->getPicturesFolderImages( 500 ) as $image ) {
			$inFolder = $inFolder || $image['content_id'] === $pImageContentId;
		}
		$image = $inFolder ? \Bitweaver\Fisheye\FisheyeImage::lookup( [ 'content_id' => $pImageContentId ] ) : null;
		$source = $image && $image->isValid() ? $image->getSourceFile() : '';
		if( !$source || !is_file( $source ) ) {
			return false;
		}
		$name = 'thumbsource-'.$pImageContentId.'.jpg';
		$copy = $this->getExtraImagePath( $name );
		if( !is_dir( dirname( $copy ) ) && !@mkdir( dirname( $copy ), 0775, true ) ) {
			return false;
		}
		if( !@copy( $source, $copy ) ) {
			return false;
		}
		$ok = $this->promoteImageToThumbnail( $name );
		@unlink( $copy );
		return $ok;
	}

	/**
	 * Move one of the item's own 'image' rows into its pictures folder (made if it is not there): the file is filed in the
	 * folder as an ordinary fisheye image, named for its part (Front, Back, ...) when the row says so, then the row and its
	 * file are removed from the item. The item's thumbnail is a rendered copy and is not touched.
	 *
	 * @return array  'title' of the picture filed, or 'error'
	 */
	public function moveImageToPictures( int $pXrefId ): array {
		$row = $this->mDb->getRow( "SELECT `xref_id`, `xkey_ext`, `data` FROM `".BIT_DB_PREFIX."liberty_xref`
			WHERE `xref_id` = ? AND `content_id` = ? AND `item` = 'image' AND `end_date` IS NULL", [ $pXrefId, $this->mContentId ] );
		$file = $row ? $this->getExtraImagePath( '' ).$row['xkey_ext'] : '';
		if( !$row || $row['xkey_ext'] === '' || basename( $row['xkey_ext'] ) !== $row['xkey_ext'] || !is_file( $file ) ) {
			return [ 'error' => 'That image has no file to move.' ];
		}
		$folder = $this->ensurePicturesFolder();
		if( !empty( $folder['error'] ) ) {
			return $folder;
		}
		$data = json_decode( (string)$row['data'], true );
		$title = is_array( $data ) && !empty( $data['types'][0] ) ? (string)$data['types'][0] : pathinfo( $row['xkey_ext'], PATHINFO_FILENAME );

		require_once FISHEYE_PKG_INCLUDE_PATH.'upload_inc.php';
		$tmp = tempnam( sys_get_temp_dir(), 'moveimg_' );
		if( !@copy( $file, $tmp ) ) {
			return [ 'error' => 'The image could not be read.' ];
		}
		$fileHash = [ 'name' => $row['xkey_ext'], 'tmp_name' => $tmp, 'size' => filesize( $tmp ), 'error' => 0 ];
		$errors = fisheye_store_upload( $fileHash, [ 'title' => $title, 'gallery_additions' => [ $folder['gallery_id'] ] ], false );
		@unlink( $tmp );
		if( $errors ) {
			return [ 'error' => implode( '; ', array_map( 'strval', (array)$errors ) ) ];
		}
		$stepHash = [ 'xref_id' => (int)$row['xref_id'], 'expunge' => 3 ];
		if( $this->stepXref( $stepHash ) ) {
			@unlink( $file );
		}
		return [ 'title' => $title ];
	}

	/**
	 * How many things the Extras button has to offer, by kind: 'featurettes' (a type that has them adds its own) and 'extras',
	 * the rest - here the item's own 'image' rows plus the pictures folder's images. Counted in SQL - nothing is loaded.
	 *
	 * @return array{featurettes:int,extras:int}
	 */
	public function getExtrasCounts(): array {
		$counts = [ 'featurettes' => 0, 'extras' => 0 ];
		if( !$this->mContentId ) {
			return $counts;
		}
		$counts['extras'] = (int)$this->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'image' AND `end_date` IS NULL", [ $this->mContentId ] );
		if( $folder = $this->getPicturesFolder() ) {
			$counts['extras'] += (int)$this->mDb->getOne(
				"SELECT COUNT(*) FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
				 WHERE m.`gallery_content_id` = ? AND lc.`content_type_guid` <> 'fisheyegallery'", [ $folder['content_id'] ] );
		}
		return $counts;
	}

	/** Everything the Extras button has to offer, all kinds together. */
	public function getExtrasCount(): int {
		return array_sum( $this->getExtrasCounts() );
	}

	/**
	 * What the Extras popup lists, one group per kind of thing. Each group: 'id' (a tab name), 'kind' (its title), 'items'
	 * (each with 'thumb', 'url' and 'title') and 'links' (the icons for that tab: 'title', 'url', 'icon', only the ones this user
	 * may use). Pictures are the base group - the item's own 'image' rows and the pictures folder's images; a type adds its own
	 * by overriding this and calling parent::, and a further kind (documents, audio) is one more group.
	 *
	 * @return array{groups:array[]}
	 */
	public function getExtrasGroups(): array {
		global $gBitUser;
		$pictures = [];
		$rows = $this->mDb->getAll( "SELECT `xref_id`, `xkey_ext`, `data` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'image' AND `end_date` IS NULL ORDER BY `xorder`", [ $this->mContentId ] ) ?: [];
		foreach( $rows as $row ) {
			$data = json_decode( (string)$row['data'], true );
			$view = FISHEYEMEDIA_PKG_URL.'view_extra_image.php?xref_id='.(int)$row['xref_id'];
			$pictures[] = [
				'thumb' => $view,
				'url'   => $view.'&name=image.jpg',
				'title' => is_array( $data ) && !empty( $data['types'][0] ) ? (string)$data['types'][0] : pathinfo( (string)$row['xkey_ext'], PATHINFO_FILENAME ),
			];
		}
		foreach( $this->getPicturesFolderImages( 200 ) as $image ) {
			$pictures[] = [ 'thumb' => $image['thumb'], 'url' => $image['url'], 'title' => $image['title'] ];
		}
		$links = [];
		if( $this->hasUpdatePermission() && $gBitUser->hasPermission( 'p_fisheye_upload' ) ) {
			$links[] = [ 'title' => 'Add Pictures', 'url' => FISHEYEMEDIA_PKG_URL.'pictures.php?content_id='.$this->mContentId, 'icon' => 'list-add' ];
		}
		if( $folder = $this->getPicturesFolder() ) {
			$links[] = [ 'title' => 'Open pictures folder', 'url' => BIT_ROOT_URL.'index.php?content_id='.$folder['content_id'], 'icon' => 'folder-open' ];
		}
		return [ 'groups' => ( $pictures || $links ) ? [ [ 'id' => 'pictures', 'kind' => 'Extras', 'items' => $pictures, 'links' => $links ] ] : [] ];
	}

	/**
	 * Resolve an 'image'/'episode' xref row's own relative path (xkey_ext, or an episode's
	 * 'thumb' data key) to a real filesystem path - view_extra_image.php's own generic serving
	 * hook. Default here: getImageStorageRoot()-relative - only still relevant to a future
	 * content type that doesn't override this. Film, Album, Season and Program all now override
	 * it with their own storage/attachments/<branch>/ resolution instead - see each class's own
	 * getImageStorageBranchPath() docblock for why: the external library tree's ownership/
	 * permissions aren't guaranteed to be web-writable, where storage/attachments/ always is.
	 *
	 * @param string $pRelativePath
	 * @return string  empty string if this content type has no image storage root
	 */
	public function getExtraImagePath( string $pRelativePath ): string {
		if( !method_exists( $this, 'getImageStorageRoot' ) ) {
			return '';
		}
		$root = $this->getImageStorageRoot();
		return $root ? $root.$pRelativePath : '';
	}

	/**
	 * Whether grabVideoFrameImage() exists and does anything for this content type - default
	 * false here, overridden true on FisheyeSeason (the only type with an episode video to grab
	 * a frame from). Same "real method call, not a bare function" reasoning as
	 * supportsAddImage() above.
	 *
	 * @return bool
	 */
	public function canGrabVideoFrame(): bool {
		return false;
	}

	/**
	 * Move an uploaded file into this content's own images/ folder as a brand new, uniquely-named
	 * image - the "create" counterpart to replaceXrefFile()'s "overwrite an existing row's file
	 * in place" (edit_image_item.tpl/edit_xref.php). Built for add_image_xref.php, the dedicated
	 * upload page the Images tab's own group-tab override (templates/xref/view_images_group.tpl)
	 * links to instead of the generic add_xref.php/add_xref.tpl, which has no file upload at all -
	 * that flow required creating an empty xref row first, then editing it separately to attach
	 * a file, an awkward two-step process this page collapses into one.
	 *
	 * Lives here on FisheyeBase rather than duplicated on FisheyeFilm/Season/Program separately -
	 * same reasoning as resizeImageFile() above, and routed through getExtraImagePath() the same
	 * way grabVideoFrameIntoImageXref() is, so it automatically lands in whichever storage/
	 * attachments/<branch>/ location the calling subclass actually overrides it with.
	 *
	 * @param string $pTmpPath       the uploaded file's own tmp_name
	 * @param string $pOriginalName  the uploaded file's own original name, for its extension
	 * @return string|null  the new file's path, relative to getExtraImagePath() (an 'image'
	 *                       xref row's own xkey_ext shape) - null if this content type has no
	 *                       image storage location, or the move itself failed
	 */
	public function addImageXrefFile( string $pTmpPath, string $pOriginalName ): ?string {
		$imagesDir = $this->getExtraImagePath( '' );
		if( empty( $imagesDir ) ) {
			return null;
		}
		\Bitweaver\KernelTools::mkdir_p( $imagesDir );
		$baseName = preg_replace( '/[^A-Za-z0-9]+/', '_', $this->getTitle() ) ?: 'image';
		$ext = strtolower( pathinfo( $pOriginalName, PATHINFO_EXTENSION ) ) ?: 'jpg';
		$n = 1;
		do {
			$fileName = "$baseName-manual-$n.$ext";
			$n++;
		} while( is_file( $imagesDir.$fileName ) );
		if( !move_uploaded_file( $pTmpPath, $imagesDir.$fileName ) ) {
			return null;
		}
		return $fileName;
	}

	/**
	 * The source path each of this item's live 'image' rows was made from (data.source), keyed
	 * by source - so a reload doesn't add the same scan or download twice.
	 *
	 * @return array<string, int>  source => xref_id
	 */
	protected function imageSources(): array {
		$ret = [];
		$rows = $this->mDb->getAll(
			"SELECT `xref_id`, `data` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'image' AND `end_date` IS NULL",
			[ $this->mContentId ]
		);
		foreach( $rows as $row ) {
			$data = !empty( $row['data'] ) ? json_decode( $row['data'], true ) : null;
			if( !empty( $data['source'] ) ) {
				$ret[$data['source']] = (int)$row['xref_id'];
			}
		}
		return $ret;
	}

	/**
	 * Copy one artwork file into this item's own image folder (storeArtworkImage()) and add it as a
	 * new 'image' row after the existing ones, remembering where it came from.
	 *
	 * @param string $pSourceFile  absolute path of the image to store
	 * @param string $pNameHint    basis for the stored file name (a relative path is flattened)
	 * @param string $pSource      what data.source records ('Artwork/Book 01.jpg', 'caa:12345')
	 * @param array  $pExtraData   anything else worth keeping in data (Cover Art Archive types)
	 * @param int    $pMax         longest side kept, FISHEYEMEDIA_ARTWORK_MAX when 0 (a smaller picture is never enlarged)
	 * @return string|null  the stored file name, or null if the image couldn't be read
	 */
	protected function storeArtworkXref( string $pSourceFile, string $pNameHint, string $pSource, array $pExtraData = [], int $pMax = 0 ): ?string {
		$ext = strtolower( pathinfo( $pSourceFile, PATHINFO_EXTENSION ) );
		$base = trim( preg_replace( '/[^A-Za-z0-9._-]+/', '-', str_replace( '/', ' - ', pathinfo( $pNameHint, PATHINFO_DIRNAME ) !== '.' ? pathinfo( $pNameHint, PATHINFO_DIRNAME ).'/'.pathinfo( $pNameHint, PATHINFO_FILENAME ) : pathinfo( $pNameHint, PATHINFO_FILENAME ) ) ), '-' );
		$fileName = $base.'.'.( in_array( $ext, [ 'png', 'gif' ], true ) ? $ext : 'jpg' );
		$branch = $this->getExtraImagePath( '' );
		\Bitweaver\KernelTools::mkdir_p( $branch );
		if( !self::storeArtworkImage( $pSourceFile, $branch.$fileName, $pMax > 0 ? $pMax : FISHEYEMEDIA_ARTWORK_MAX ) ) {
			return null;
		}
		$nextOrder = (int)$this->mDb->getOne( "SELECT MAX(`xorder`) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'image' AND `end_date` IS NULL", [ $this->mContentId ] ) + 1;
		$xrefHash = [ 'content_id' => $this->mContentId, 'item' => 'image', 'xkey_ext' => $fileName, 'xorder' => $nextOrder,
			'edit' => json_encode( [ 'source' => $pSource ] + $pExtraData ) ];
		$this->storeXref( $xrefHash );
		return $fileName;
	}

	/**
	 * Write an image at no more than $pMax px on its longest side - first frame only (a multi-page
	 * TIFF scan), PNG/GIF kept as they are, anything else (TIFF, BMP, WebP, JPEG) saved as JPEG,
	 * metadata stripped. Imagick directly rather than liberty's resize function, which keeps the
	 * source format and copies small images untouched - a TIFF would come out as an unviewable
	 * .jpg-named TIFF.
	 *
	 * @return bool
	 */
	protected static function storeArtworkImage( string $pSourceFile, string $pDestFile, int $pMax ): bool {
		if( !extension_loaded( 'imagick' ) ) {
			return in_array( strtolower( pathinfo( $pSourceFile, PATHINFO_EXTENSION ) ), [ 'jpg', 'jpeg', 'png', 'gif' ], true )
				&& self::resizeImageFile( $pSourceFile, $pDestFile, $pMax );
		}
		try {
			$im = new \Imagick();
			$im->readImage( $pSourceFile.'[0]' );
			if( $im->getImageWidth() > $pMax || $im->getImageHeight() > $pMax ) {
				$im->thumbnailImage( $pMax, $pMax, true );
			}
			$destExt = strtolower( pathinfo( $pDestFile, PATHINFO_EXTENSION ) );
			if( $destExt === 'jpg' ) {
				$im->setImageBackgroundColor( 'white' );
				$im = $im->mergeImageLayers( \Imagick::LAYERMETHOD_FLATTEN );
				$im->setImageFormat( 'jpeg' );
				$im->setImageCompressionQuality( 88 );
			} else {
				$im->setImageFormat( $destExt );
			}
			$im->stripImage();
			$ok = $im->writeImage( $pDestFile );
			$im->clear();
			if( $ok ) {
				@chmod( $pDestFile, 0644 );
			}
			return (bool)$ok;
		} catch( \Exception $e ) {
			return false;
		}
	}

}

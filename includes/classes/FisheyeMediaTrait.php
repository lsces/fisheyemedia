<?php
/**
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

/**
 * Shared engine used by both media inheritance branches - FisheyeMediaGallery (FisheyeProgram, a
 * show with no video file of its own) and FisheyeMediaImage (FisheyeFilm/FisheyeSeason/
 * FisheyeAlbum, each a real attached file) - hence a trait rather than living on either single
 * intermediate class. Relies only on methods/properties FisheyeBase already provides on both
 * branches (getExtraImagePath(), resizeImageFile(), storeXref(), mDb, mContentId, getTitle()).
 *
 * @package fisheyemedia
 */
trait FisheyeMediaTrait {

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

}

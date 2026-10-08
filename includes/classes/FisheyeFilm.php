<?php
/**
 * Film — extends FisheyeImage with content_type_guid='fisheyefilm'.
 *
 * Exists purely to ring-fence film-specific xref_group/item registrations (genre/director/
 * writer/star/content_rating/duration, IMDB link) away from plain fisheyeimage photo rows and
 * from FisheyeSeason/FisheyeAlbum's own item sets — no behavioural difference from FisheyeImage
 * otherwise, same pattern as Contact/ContactPerson/ContactBusiness.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;

// mime_film_get_storage_root() below is only auto-loaded via the LibertyMime attachment-plugin
// dispatch, which doesn't fire on every entry path - require it directly rather than depending on
// some other content on the same page happening to trigger that loader first. Same fix as
// FisheyeProgram.php/FisheyeSeason.php.
require_once dirname( __DIR__, 3 ).'/liberty/plugins/mime.film.php';

define( 'FISHEYEFILM_CONTENT_TYPE_GUID', 'fisheyefilm' );

class FisheyeFilm extends FisheyeMediaImage {

	public function __construct( $pImageId = null, $pContentId = null ) {
		parent::__construct( $pImageId, $pContentId );
		$this->mContentTypeGuid = FISHEYEFILM_CONTENT_TYPE_GUID;
		$this->registerContentType( FISHEYEFILM_CONTENT_TYPE_GUID, [
			'content_type_guid' => FISHEYEFILM_CONTENT_TYPE_GUID,
			'content_name'      => 'Film',
			'handler_class'     => 'FisheyeFilm',
			'handler_package'   => 'fisheyemedia',
			'handler_file'      => 'FisheyeFilm.php',
			'maintainer_url'    => 'https://www.bitweaver.org',
		] );
		// mPackageGuid='fisheyemedia' is set automatically by registerContentType()
		// because handler_package('fisheyemedia') != content_type_guid('fisheyefilm').
	}

	/**
	 * Override LibertyContent::getEditUrl()'s generic '<package>/edit.php' default - fisheye's
	 * own edit.php is the GALLERY edit page (FisheyeGallery::getAllLayouts() etc.), not a film's.
	 * Without this, liberty/edit_xref.php's post-save/post-delete redirect (which calls
	 * getEditUrl() on whatever content type the xref belongs to) sent a film back to the wrong
	 * page entirely - a fatal error hit live 2026-09-02 ("Call to undefined method
	 * FisheyeFilm::getAllLayouts()") the moment an image xref row was actually deleted.
	 *
	 * @param int|null $pContentId
	 * @param array|null $pMixed  extra query params to append (content_id itself is skipped)
	 * @return string
	 */
	public function getEditUrl( $pContentId = null, $pMixed = null ) {
		$contentId = \Bitweaver\BitBase::verifyId( $pContentId ) ? $pContentId : $this->mContentId;
		$ret = FISHEYEMEDIA_PKG_URL.'edit_film.php?content_id='.$contentId;
		foreach( (array)$pMixed as $key => $value ) {
			if( $key !== 'content_id' ) {
				$ret .= '&'.$key.'='.$value;
			}
		}
		return $ret;
	}

	/**
	 * Override FisheyeImage::getDisplayUrl()'s generic default - was previously relying on the
	 * base class's own attachment_plugin_guid==='mimefilm' guess to route here, which was never
	 * actually correct (a plain FisheyeImage with an ordinary video file attached - existing
	 * sites have these - would wrongly match it too, since that check has nothing to do with
	 * content type). Explicit override instead, same pattern FisheyeSeason/FisheyeProgram/
	 * FisheyeAlbum already each needed for themselves.
	 *
	 * @return string
	 */
	public function getDisplayUrl( $pContentId = null, $pMixed = null ) {
		$contentId = \Bitweaver\BitBase::verifyId( $pContentId ) ? $pContentId : $this->mContentId;
		return FISHEYEMEDIA_PKG_URL.'view_film.php?content_id='.$contentId;
	}

	/**
	 * The storage root this film's own 'image' xref rows live relative to - the plain
	 * fisheye_disk_storage_root (no A-M/N-Z split, unlike TV). Exists so edit_xref.php can call
	 * this generically (via method_exists()) without needing to know 'image' means anything
	 * fisheye-specific, or which root function applies to which content type - see
	 * FisheyeSeason::getImageStorageRoot()'s docblock for the fuller reasoning (found live
	 * 2026-09-02 as a real bug: edit_xref.php previously hardcoded this exact function for every
	 * content type, silently wrong for Season/Program on any deployment where the TV root
	 * genuinely differs from the film root).
	 *
	 * @return string empty string if the config is unset
	 */
	public function getImageStorageRoot(): string {
		return \Bitweaver\Liberty\mime_film_get_storage_root();
	}

	/**
	 * Generic file-lifecycle hook liberty/edit_xref.php calls (via method_exists()) when a file
	 * is uploaded to replace an xref row's own referenced file - deliberately generic (item name
	 * + its xkey_ext + the uploaded tmp path) so the shared controller never needs to know this
	 * is fisheye-specific or what 'image' means; this class decides internally which items it
	 * actually applies to (only 'image' - "replace what's in this slot", not "point this row at
	 * a different file", so xkey_ext itself never changes).
	 *
	 * @param string $pItem
	 * @param string $pXkeyExt
	 * @param string $pTmpPath  the uploaded file's own tmp_name
	 * @return bool
	 */
	public function replaceXrefFile( string $pItem, string $pXkeyExt, string $pTmpPath ): bool {
		if( $pItem !== 'image' || empty( $pXkeyExt ) ) {
			return false;
		}
		return move_uploaded_file( $pTmpPath, $this->getImageStorageBranchPath().$pXkeyExt );
	}

	/**
	 * Generic file-lifecycle hook liberty/edit_xref.php calls (via method_exists()) on a real
	 * hard-delete (expunge=3) of an xref row - see replaceXrefFile()'s docblock for why this is
	 * generic rather than fisheye-specific in the controller. Only 'image' rows have a disposable
	 * local file worth cleaning up (a local copy of a Plex/TMDB download); every other item type
	 * (e.g. 'episode', whose xkey_ext is the real, precious video file) must never be touched here.
	 *
	 * @param string $pItem
	 * @param string $pXkeyExt
	 * @return bool
	 */
	public function deleteXrefFile( string $pItem, string $pXkeyExt ): bool {
		if( $pItem !== 'image' || empty( $pXkeyExt ) ) {
			return false;
		}
		$path = $this->getImageStorageBranchPath().$pXkeyExt;
		if( !is_file( $path ) ) {
			return false;
		}
		return @unlink( $path );
	}

	/**
	 * Override of FisheyeBase's own getImageStorageRoot()-relative default - a film's own
	 * downloaded Plex alternates live in storage/attachments/<branch>/, not the external film
	 * library tree (see getImageStorageBranchPath()'s own docblock).
	 */
	public function getExtraImagePath( string $pRelativePath ): string {
		return $this->getImageStorageBranchPath().$pRelativePath;
	}

	/**
	 * This film's own storage/attachments/<branch>/ path - home for its downloaded Plex image
	 * alternates and any manual uploads. Not a new convention - this is the same home every other
	 * attachment type already uses for its own extras, this class just wasn't following it yet.
	 * Always nginx-writable by construction, unlike the external film-library tree
	 * (getImageStorageRoot()) - a disk-managed media tree isn't guaranteed to be web-writable the
	 * way storage/ is, so extras always live under storage/ rather than being written back
	 * alongside the source files.
	 *
	 * @return string
	 */
	private function getImageStorageBranchPath(): string {
		return STORAGE_PKG_PATH.\Bitweaver\Liberty\liberty_mime_get_storage_branch( [ 'attachment_id' => $this->mContentId ] );
	}

	/**
	 * Promote one of this film's already-downloaded 'image' xref alternates into its actual
	 * displayed thumbnail. No separate "which one is the thumbnail" bookkeeping needed -
	 * mime_film_get_thumbnail_url() just reads whatever's in storage/attachments/<branch>/thumbs/
	 * regardless of how it got there - so this just regenerates thumbs/ directly from the chosen
	 * alternate, already sitting in the same branch as the thumbs themselves.
	 *
	 * @param string $pRelativePath  an 'image' xref row's own xkey_ext value (a bare filename)
	 * @return bool
	 */
	public function promoteImageToThumbnail( string $pRelativePath ): bool {
		$branchPath = $this->getImageStorageBranchPath();
		$sourcePath = $branchPath.$pRelativePath;
		if( !is_file( $sourcePath ) ) {
			return false;
		}
		foreach( glob( $branchPath.'thumbs/*' ) ?: [] as $oldThumb ) {
			@unlink( $oldThumb );
		}
		$fileHash = [ 'type' => 'image/jpeg', 'source_file' => $sourcePath, 'dest_branch' => \Bitweaver\Liberty\liberty_mime_get_storage_branch( [ 'attachment_id' => $this->mContentId ] ) ];
		$ok = \Bitweaver\Liberty\liberty_generate_thumbnails( $fileHash );
		$this->load();
		return $ok;
	}

	/**
	 * Locate this film in the local Plex library, matched by its real absolute file path
	 * (Plex's own media_parts.file, not fisheye's root-relative convention — realpath() bridges
	 * the fisheye_disk_storage_root symlink back to whatever real path Plex actually stored,
	 * since the two commonly differ). Shared by reloadPlexMetadata() and reloadPlexImages() so
	 * the file-matching logic (and its 'no fisheye_plex_db_path configured'/'no match found'
	 * silent-skip behaviour) exists in exactly one place.
	 *
	 * @return array{db:\PDO,id:int}|null  null if unconfigured or no match found
	 */
	/**
	 * Register an already-on-disk film (no-copy attachment via mime.film.php), link it into a
	 * gallery, and backfill Plex metadata - the one real per-film registration sequence, shared
	 * by admin_import_film.php (single film) and load_film.php (bulk selection) so it exists in
	 * exactly one place. Caller is responsible for validating $pRelativePath is a real file under
	 * the configured storage root first - this only re-checks for an existing registration
	 * (idempotent against being called twice for the same file).
	 *
	 * $pGalleryTitle defaults to 'Films' (the flat top-level pool) - a collection is just another
	 * gallery whose own title matches the on-disk subfolder name a film was found in (see
	 * load_film.php's own folder-scoping doc), so passing that title here is all a caller needs
	 * to do to link into it instead. No matching gallery (a folder browsed before its collection
	 * gallery exists yet) degrades the same way 'Films' missing always has - $linked stays false,
	 * not an error.
	 *
	 * $pFetchImages additionally calls reloadPlexImages() per film - off by default. Deliberately
	 * opt-in, not folded into the metadata backfill above: downloading N posters/backdrops is the
	 * heavier of the two Plex operations, and a bulk caller (load_film.php) importing many films
	 * at once needs to be able to choose that cost explicitly rather than always paying it.
	 *
	 * @return array 'already'=>content_id if already registered, or 'created'/'linked'/'plex'
	 *               (/'images' if $pFetchImages) on success, or 'error'=>string on failure - same
	 *               shape either caller can render.
	 */
	public static function registerFromDisk( string $pRelativePath, ?string $pTitle = null, bool $pFetchImages = false, string $pGalleryTitle = 'Films' ): array {
		global $gBitDb;

		$title = trim( (string)$pTitle ) ?: pathinfo( $pRelativePath, PATHINFO_FILENAME );

		$existingContentId = $gBitDb->getOne(
			"SELECT la.content_id FROM liberty_attachments la INNER JOIN liberty_files lf ON lf.file_id = la.foreign_id WHERE la.attachment_plugin_guid = 'mimefilm' AND lf.file_name = ?",
			[ $pRelativePath ]
		);
		if( $existingContentId ) {
			return [ 'already' => $existingContentId ];
		}

		$film = new FisheyeFilm();
		$pParamHash = [
			'title' => $title,
			'mimeplugin' => [
				'mimefilm' => [ 'file_name' => $pRelativePath ],
			],
		];
		if( !$film->store( $pParamHash ) ) {
			return [ 'error' => implode( '; ', $film->mErrors ) ];
		}

		$galleryContentId = $gBitDb->getOne(
			"SELECT lc.content_id FROM liberty_content lc INNER JOIN fisheye_gallery fg ON fg.content_id = lc.content_id WHERE lc.content_type_guid = 'fisheyegallery' AND lc.title = ?",
			[ $pGalleryTitle ]
		);
		$linked = false;
		if( $galleryContentId ) {
			$gallery = new FisheyeGallery( null, $galleryContentId );
			$gallery->load();
			$linked = $gallery->addItem( $film->mContentId );
		}
		$plexMeta = $film->reloadPlexMetadata();
		$featurettes = $film->registerFeaturettesFromDisk( $pRelativePath );

		$ret = [ 'created' => $film->mContentId, 'linked' => $linked, 'plex' => $plexMeta, 'featurettes' => $featurettes ];
		if( $pFetchImages ) {
			$ret['images'] = $film->reloadPlexImages();
		}
		return $ret;
	}

	/**
	 * A film living in its own folder alongside a Featurettes/ subfolder (DVD-era bonus content -
	 * the same shape as an episode living under a season, just one level shallower) gets each
	 * Featurettes file registered as a
	 * 'featurette' xref on this film's own content_id - not a separate FisheyeFilm, not a gallery
	 * of its own. Same rebuild-not-diff convention as every other xref-based reload* here.
	 *
	 * A bare single file directly under Films/ (no folder of its own) has nothing to check
	 * against - $pRelativePath's own dirname() is 'Films' itself in that case, whose sibling
	 * 'Featurettes' would only ever be the top-level Films/Featurettes/ that doesn't exist on
	 * this install, so this is a safe no-op for the common case.
	 *
	 * @param string $pRelativePath  this film's own attachment path, relative to
	 *                               getImageStorageRoot() (mime_film_get_storage_root())
	 * @return array  Summary of what was found/stored, for the calling page's result display.
	 */
	public function registerFeaturettesFromDisk( string $pRelativePath ): array {
		$root = $this->getImageStorageRoot();
		if( empty( $root ) ) {
			return [ 'items' => [] ];
		}
		// See FisheyeMediaTrait::registerFeaturettesFromFolder()'s own docblock - the scan-and-register
		// half is shared with FisheyeSeason's own version. This method's only remaining job is
		// resolving *this* film's own containing directory (a bare single file directly under
		// Films/ has dirname()=='Films' itself, whose sibling 'Featurettes' would only ever be
		// the top-level Films/Featurettes/ that doesn't exist on this install - a safe no-op for
		// that common case).
		return $this->registerFeaturettesFromFolder( $root.dirname( $pRelativePath ).'/', dirname( $pRelativePath ) );
	}

	/**
	 * This film's own attachment path, relative to getImageStorageRoot() - the input
	 * registerFeaturettesFromDisk() needs, re-derived for an already-stored film rather than
	 * passed down from the original import call. getField('file_name') is NOT this - it only
	 * ever holds a bare basename (getSourceFile()'s own fallback-to-basename branch is what
	 * generally applies in practice), not the full relative path liberty_files.file_name
	 * actually stores; found live when a real edit_film.php "Reload Featurettes" click on a film
	 * nested under a Collection sub-folder silently resolved to the storage root itself instead
	 * of the film's own folder. mStorage's own already-resolved absolute source_file (same one
	 * matchPlexMetadataItem() below relies on) is the one value proven to always point at the
	 * real file, so this strips the storage root back off that instead of trusting getField().
	 *
	 * @return string|null  relative path, or null if the film's own file can't be resolved
	 */
	public function getRelativeFilePath(): ?string {
		$this->load();
		$sourceFile = $this->mStorage[$this->mContentId]['source_file'] ?? null;
		$realPath = $sourceFile ? realpath( $sourceFile ) : null;
		$root = realpath( $this->getImageStorageRoot() );
		if( empty( $realPath ) || empty( $root ) || !str_starts_with( $realPath, $root ) ) {
			return null;
		}
		return ltrim( substr( $realPath, strlen( $root ) ), '/' );
	}

	/** The path stored for this film's file (liberty_files.file_name, relative to the storage root), whether or not a file is there. */
	public function getStoredFilePath(): ?string {
		global $gBitDb;
		$path = $gBitDb->getOne(
			"SELECT lf.`file_name` FROM `".BIT_DB_PREFIX."liberty_files` lf INNER JOIN `".BIT_DB_PREFIX."liberty_attachments` la ON la.`foreign_id` = lf.`file_id`
			 WHERE la.`content_id` = ? AND la.`attachment_plugin_guid` = 'mimefilm'", [ (int)$this->mContentId ]
		);
		return $path !== false && $path !== null ? (string)$path : null;
	}

	/**
	 * Point this film at its file's new path after the file was renamed or moved on disk. The new path (relative to the storage root, as
	 * Films/Name (Year).mp4) must be a real file and not already another film's; the title and everything else on the film are untouched.
	 *
	 * @return array{ok:bool, error?:string, old?:?string, new?:string}
	 */
	public function relocateFile( string $pNewPath ): array {
		global $gBitDb;
		$new = trim( str_replace( '\\', '/', $pNewPath ), "/ \t" );
		if( $new === '' || in_array( '..', explode( '/', $new ), true ) ) {
			return [ 'ok' => false, 'error' => KernelTools::tra( 'Give the file as a path under the storage root, like Films/Name (Year).mp4.' ) ];
		}
		$root = \Bitweaver\Liberty\mime_film_get_storage_root();
		if( $root === '' || !is_file( $root.$new ) ) {
			return [ 'ok' => false, 'error' => KernelTools::tra( 'There is no file at' ).' '.$root.$new ];
		}
		$old = $this->getStoredFilePath();
		if( $old === $new ) {
			return [ 'ok' => true, 'old' => $old, 'new' => $new ];
		}
		$other = $gBitDb->getOne(
			"SELECT la.`content_id` FROM `".BIT_DB_PREFIX."liberty_files` lf INNER JOIN `".BIT_DB_PREFIX."liberty_attachments` la ON la.`foreign_id` = lf.`file_id`
			 WHERE la.`attachment_plugin_guid` = 'mimefilm' AND lf.`file_name` = ? AND la.`content_id` <> ?", [ $new, (int)$this->mContentId ]
		);
		if( $other ) {
			return [ 'ok' => false, 'error' => sprintf( KernelTools::tra( 'Another film (content %d) already uses that file.' ), (int)$other ) ];
		}
		$gBitDb->query(
			"UPDATE `".BIT_DB_PREFIX."liberty_files` SET `file_name` = ? WHERE `file_id` IN ( SELECT la.`foreign_id` FROM `".BIT_DB_PREFIX."liberty_attachments` la
			 WHERE la.`content_id` = ? AND la.`attachment_plugin_guid` = 'mimefilm' )", [ $new, (int)$this->mContentId ]
		);
		return [ 'ok' => true, 'old' => $old, 'new' => $new ];
	}

	/**
	 * This film's own facts, xref data and tab content, bucketed once for view_film.php/
	 * view_film.tpl - same "one flat pass over liveXrefs(), keyed by item name only" shape as
	 * FisheyeSeason::getSeasonViewData(), rather than view_film.php hand-rolling its own near-
	 * identical bucketing loop. Deliberately not keyed off which x_group an item happens to be
	 * organised under (that's a tab-layout concern - see media.php's xref_schemes) - hardcoding
	 * group names here already broke silently once, when 'star' moved out of 'metadata' into its
	 * own 'cast' tab and this page kept reading only 'metadata'.
	 *
	 * @return array{genres:array,directors:array,writers:array,stars:array,narrators:array,creditRoles:array<string,string>,creditUrls:array<string,string>,contentRating:?string,
	 *               durationMs:?int,resolution:?string,audio:?string,externalLinks:array,
	 *               filmImages:array,featurettes:array,firstTab:?string}
	 */
	public function getFilmViewData(): array {
		$this->loadXrefInfo();
		$genres = $directors = $writers = $stars = $narrators = $roles = [];
		// name => contact page, for credits linked to a contact (xref) - the index.php?content_id=
		// dispatcher routes to whatever display page that contact's own package defines.
		$creditUrls = [];
		$contentRating = $durationMs = $resolution = $audio = null;
		$externalLinks = [];
		// this film's own alternate poster/backdrop images (reloadPlexImages()) - xref-based, not
		// a second liberty_attachments row per image. Rendered via view_extra_image.php (xref_id
		// only, never a raw path - see that script's own docblock for why) rather than a direct
		// URL, since these files live outside storage/attachments/ with no nginx location serving
		// that tree yet.
		$filmImages = [];
		// this film's own bonus content, for a DVD-rip-with-extras style folder - Featurettes/ is
		// no different to Season/, same xref-on-the-parent's-own-content_id shape as a season's
		// episodes, played via the same play_episode.php (xref_id only, widened to accept either
		// item).
		$featurettes = [];
		if( $this->mXrefInfo ) {
			foreach( $this->liveXrefs() as $xref ) {
				switch( $xref['item'] ) {
					case 'genre':          $genres[]     = $xref['xkey_ext']; break;
					case 'director':       $directors[]  = $xref['xkey_ext']; $creditUrls = self::creditUrl( $creditUrls, $xref ); break;
					case 'writer':         $writers[]    = $xref['xkey_ext']; $creditUrls = self::creditUrl( $creditUrls, $xref ); break;
					case 'star':
						$stars[] = $xref['xkey_ext'];
						$creditUrls = self::creditUrl( $creditUrls, $xref );
						if( !empty( $xref['data'] ) && ( $roleData = json_decode( $xref['data'], true ) ) && !empty( $roleData['role'] ) ) {
							$roles[$xref['xkey_ext']] = $roleData['role'];
						}
						break;
					case 'narrator':       $narrators[]  = $xref['xkey_ext']; $creditUrls = self::creditUrl( $creditUrls, $xref ); break;
					case 'content_rating': $contentRating = $xref['xkey_ext']; break;
					case 'duration':       $durationMs    = (int)$xref['xkey_ext']; break;
					case 'resolution':     $resolution    = $xref['xkey_ext']; break;
					case 'audio':          $audio         = $xref['xkey_ext']; break;
					case 'image':          $filmImages[]  = [ 'xref_id' => $xref['xref_id'] ]; break;
					case 'featurette':
						$data = !empty( $xref['data'] ) ? json_decode( $xref['data'], true ) : [];
						$featurettes[] = [
							'xref_id'    => $xref['xref_id'],
							'title'      => $data['title'] ?? $xref['xkey_ext'],
							'summary'    => $data['summary'] ?? '',
							'thumb'      => $data['thumb'] ?? null,
							'durationMs' => $data['duration'] ?? null,
							'resolution' => $data['resolution'] ?? null,
							'audio'      => $data['audio'] ?? null,
						];
						break;
				}
				// external links (imdb/tvdb/tmdb/...) - identified by having a cross_ref_href
				// (the href template's marker, loaded onto every xref row already, see
				// LibertyXrefType.php), not by group name - built into a real url here since
				// nothing generic renders these outside the admin xref-list table.
				if( !empty( $xref['cross_ref_href'] ) && !empty( $xref['xkey'] )) {
					$externalLinks[] = [
						'title' => $xref['xref_title'] ?? strtoupper( $xref['item'] ),
						'url'   => $xref['cross_ref_href'].$xref['xkey'],
					];
				}
			}
		}
		// Which of the Featurettes/Images tabs starts active - same "whichever actually has
		// content" reasoning as FisheyeSeason::getSeasonViewData()'s own $firstTab, just without
		// an Episodes option since a film has no episode grid of its own.
		$firstTab = match( true ) {
			(bool)$featurettes => 'featurettes',
			(bool)$filmImages  => 'images',
			default            => null,
		};
		return [
			'genres'        => $genres,
			'directors'     => $directors,
			'writers'       => $writers,
			'stars'         => $stars,
			'narrators'     => $narrators,
			'creditRoles'   => $roles,
			'creditUrls'    => $creditUrls,
			'contentRating' => $contentRating,
			'durationMs'    => $durationMs,
			'resolution'    => $resolution,
			'audio'         => $audio,
			'externalLinks' => $externalLinks,
			'filmImages'    => $filmImages,
			'featurettes'   => $featurettes,
			'firstTab'      => $firstTab,
		];
	}

	/** The film xref items that credit a person. */
	public const CREDIT_ITEMS = FisheyeCredits::ITEMS;

	/**
	 * Every person credited on a film, one entry per distinct name - FisheyeCredits::survey() for films,
	 * with each person's films under 'films' (what the film people pass reads).
	 *
	 * @return array{films:int, credits:int, people:array<string,array>}
	 */
	public static function surveyCredits(): array {
		global $gBitSystem;
		// Plex lists a film's whole cast; only the first stars billed (default 10) are worth a contact each - see FisheyeCredits::survey().
		$depth = max( 1, (int)$gBitSystem->getConfig( 'fisheyemedia_film_cast_depth', 10 ) );
		$survey = FisheyeCredits::survey( [ 'fisheyefilm' ], null, $depth );
		foreach( $survey['people'] as &$person ) {
			$person['films'] = $person['items'];
		}
		unset( $person );
		return [ 'films' => $survey['items'], 'credits' => $survey['credits'], 'people' => $survey['people'] ];
	}

	/**
	 * Link film credit rows to a contact - see FisheyeCredits::linkRows().
	 *
	 * @param int[] $pXrefIds
	 * @return int  rows linked
	 */
	public static function linkCreditRows( array $pXrefIds, int $pContactId, ?string $pXkey ): int {
		return FisheyeCredits::linkRows( $pXrefIds, $pContactId, $pXkey );
	}

	/**
	 * The TMDb movie id held in each film's own `tmdb` xref (xkey) - lets a people-matching tool ask
	 * TMDb who was credited on a film. Films without one are simply absent.
	 *
	 * @param int[] $pContentIds
	 * @return array<int,int>  film content_id => TMDb movie id
	 */
	public static function tmdbIdsByFilm( array $pContentIds ): array {
		global $gBitDb;
		$pContentIds = array_values( array_unique( array_filter( array_map( 'intval', $pContentIds ) ) ) );
		if( !$pContentIds ) {
			return [];
		}
		$rows = $gBitDb->getAll(
			"SELECT x.`content_id`, x.`xkey` FROM `".BIT_DB_PREFIX."liberty_xref` x
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = x.`content_id`
			 WHERE lc.`content_type_guid` = 'fisheyefilm' AND x.`item` = 'tmdb' AND x.`end_date` IS NULL
			 AND x.`content_id` IN ( ".implode( ',', array_fill( 0, count( $pContentIds ), '?' ) )." )",
			$pContentIds
		) ?: [];
		$ret = [];
		foreach( $rows as $row ) {
			if( ctype_digit( (string)$row['xkey'] ) ) {
				$ret[(int)$row['content_id']] = (int)$row['xkey'];
			}
		}
		return $ret;
	}

	/** Adds a credit row's name => contact page url to $pUrls when the row is linked to a contact. */
	private static function creditUrl( array $pUrls, \ArrayAccess|array $pXref ): array {
		if( !empty( $pXref['xref'] ) && !empty( $pXref['xkey_ext'] ) ) {
			$pUrls[$pXref['xkey_ext']] = BIT_ROOT_URL.'index.php?content_id='.(int)$pXref['xref'];
		}
		return $pUrls;
	}

	private function matchPlexMetadataItem(): ?array {
		// refresh mStorage - needed when called right after store() on a just-created film,
		// whose in-memory object hasn't necessarily loaded its attachment row yet.
		$this->load();
		$sourceFile = $this->mStorage[$this->mContentId]['source_file'] ?? null;
		$realPath = $sourceFile ? realpath( $sourceFile ) : null;
		if( empty( $realPath ) ) {
			return null;
		}
		return self::matchPlexMetadataItemForPath( $realPath );
	}

	/**
	 * The DB-query half of matchPlexMetadataItem(), split out so load_film.php can pre-check a
	 * candidate file for a Plex match *before* registering it - matchPlexMetadataItem() itself
	 * needs an already-stored film (its own mStorage/source_file), which doesn't exist yet at that
	 * point in the batch-import flow.
	 *
	 * @param string $pAbsoluteRealPath  a real, already-resolved absolute path (realpath() output)
	 * @return array{db:\PDO,id:int}|null  null if unconfigured or no match found
	 */
	public static function matchPlexMetadataItemForPath( string $pAbsoluteRealPath ): ?array {
		global $gBitSystem;

		$dbPath = $gBitSystem->getConfig( 'fisheye_plex_db_path', '' );
		if( empty( $dbPath ) || !is_file( $dbPath ) || empty( $pAbsoluteRealPath ) ) {
			return null;
		}

		try {
			$plexDb = new \PDO( 'sqlite:'.$dbPath );
		} catch( \Exception $e ) {
			return null;
		}

		$stmt = $plexDb->prepare(
			"SELECT mi.id FROM media_parts mp
			 JOIN media_items mi2 ON mi2.id = mp.media_item_id
			 JOIN metadata_items mi ON mi.id = mi2.metadata_item_id
			 WHERE mp.file = ? AND mi.metadata_type = 1"
		);
		$stmt->execute( [ $pAbsoluteRealPath ] );
		$metadataItemId = $stmt->fetchColumn();
		if( !$metadataItemId ) {
			return null;
		}

		return [ 'db' => $plexDb, 'id' => (int)$metadataItemId ];
	}

	/**
	 * Best-effort metadata backfill/refresh from the local Plex library. Moved here from
	 * admin_import_film.php's one-off helper function 2026-09-02 so edit_film.php's
	 * 'Reload Metadata' action (for a film imported before this backfill existed, or re-synced
	 * after a Plex library update) can call the exact same logic as first-import instead of
	 * duplicating it.
	 *
	 * Plex's own library db is world-readable (confirmed 2026-09-02, no permission workaround
	 * needed) so genre/director/writer/star/content_rating/duration are always available with no
	 * config beyond fisheye_plex_db_path; imdb/tmdb need fisheye_plex_token too (Plex's own
	 * Preferences.xml, where that lives, is NOT world-readable — has to be copied into
	 * kernel_config by hand once). All text values go into xkey_ext, not xkey — the xref table's
	 * `data`-style free text column, not the short indexed `xkey` one (view_film.php
	 * reads xkey_ext, and getting this backwards silently stores nothing readable), duration
	 * is stored as Plex's own raw milliseconds. Silently does
	 * nothing if fisheye_plex_db_path isn't configured or the file has no Plex match — metadata
	 * entry always remains possible by hand either way via the generic xref table.
	 *
	 * Deliberately separate from reloadPlexImages() - text metadata and
	 * image fetching are different weight/frequency operations (the former is near-instant,
	 * the latter downloads several image files), so they get their own action/button each
	 * rather than one doing both.
	 *
	 * A second run rebuilds from scratch rather than diffing - every item this method writes
	 * (genre/director/writer/star/content_rating/duration/imdb/tmdb) is deleted for this content_id
	 * via LibertyContent::deleteXrefByItem() *before* re-inserting, since storeXref() always
	 * inserts a fresh row when called without an xref_id (correct for the multiple=1 items, which
	 * have no natural single-row key to update in place) - without the upfront delete, a second
	 * "Reload Metadata" just appended duplicate rows on top of the first run's. Same
	 * rebuild-not-diff pattern deleteXrefByItem()'s own docblock already documents for health's
	 * RebuildHRDerived.php and food's FoodAssembly::clearItems().
	 *
	 * @return array Summary of what was found/stored, for the calling page's result display.
	 */
	public function reloadPlexMetadata(): array {
		global $gBitSystem;
		$summary = [ 'matched' => false, 'items' => [] ];

		// Resolution/audio layout come straight from the file's own container via ffprobe, not
		// Plex - unlike everything else this method fetches, so this runs regardless of whether a
		// Plex match exists at all below (a no-Plex-match film would otherwise never get these).
		$this->load();
		$sourceFile = $this->mStorage[$this->mContentId]['source_file'] ?? null;
		if( !empty( $sourceFile ) && is_file( $sourceFile ) ) {
			$qualityInfo = \Bitweaver\Liberty\mime_film_get_quality_info( $sourceFile );
			foreach( [ 'resolution', 'audio' ] as $item ) {
				$wanted = $qualityInfo[$item] !== null ? [ [ 'key' => '', 'xkey_ext' => $qualityInfo[$item], 'xorder' => 0 ] ] : [];
				$this->reconcileXrefItem( $item, $wanted, null );
				if( $qualityInfo[$item] !== null ) {
					$summary['items'][] = "$item: {$qualityInfo[$item]}";
				}
			}
		}

		$plexMatch = $this->matchPlexMetadataItem();
		if( !$plexMatch ) {
			return $summary;
		}
		$plexDb = $plexMatch['db'];
		$metadataItemId = $plexMatch['id'];

		$stmt = $plexDb->prepare( "SELECT content_rating, duration, summary FROM metadata_items WHERE id = ?" );
		$stmt->execute( [ $metadataItemId ] );
		$plexRow = $stmt->fetch( \PDO::FETCH_ASSOC );
		if( !$plexRow ) {
			return $summary;
		}
		$summary['matched'] = true;

		// the film's own description - same gap/fix as FisheyeProgram::reloadPlexMetadata() (see
		// its own docblock for the two silent-failure bugs found there: by-reference store() param,
		// and 'edit' not 'data' as the input key for LibertyContent::verify() to pick up). Unlike
		// FisheyeGallery::store(), FisheyeImage::store()'s own verifyImageData() has no equivalent
		// hard requirement for 'title' to be present, so it's safely omitted here (confirmed
		// against edit_film.php's own title-only $gContent->store() calls using this same path).
		if( !empty( $plexRow['summary'] ) ) {
			// The title goes too: the search index text is built from the hash being saved, so a save without it would index the description alone.
			$descriptionStoreHash = [ 'content_id' => $this->mContentId, 'title' => $this->getTitle(), 'edit' => $plexRow['summary'] ];
			$this->store( $descriptionStoreHash );
			$summary['items'][] = 'description updated';
		}

		// Reconciled, never wiped: a reload keeps every row's history (entry/last_update/end_date),
		// leaves hand-edited rows alone, and keeps a credit's link to its contact (xref/xkey) - see
		// FisheyeMediaTrait::reconcileXrefItem(). Same convention as FisheyeAlbum's music reloads.
		$tally = function( string $pItem, array $pCounts ) use ( &$summary ) {
			$summary['items'][] = $pItem.': '.implode( ', ', array_map( fn( $k, $n ) => "$n $k", array_keys( $pCounts ), $pCounts ) );
		};

		// tag_type: 1=genre, 4=director, 5=writer, 6=actor(star) - confirmed against real live
		// data 2026-09-02, not documented anywhere by Plex itself. The full cast is kept (no cap) -
		// credits are keyed by name, so a person Plex lists twice is stored once.
		$tagStmt = $plexDb->prepare(
			"SELECT t.tag FROM taggings tg JOIN tags t ON t.id = tg.tag_id WHERE tg.metadata_item_id = ? AND t.tag_type = 1 ORDER BY tg.\"index\""
		);
		$tagStmt->execute( [ $metadataItemId ] );
		$wanted = [];
		foreach( array_unique( $tagStmt->fetchAll( \PDO::FETCH_COLUMN ) ) as $value ) {
			$wanted[] = [ 'key' => $value, 'xkey_ext' => $value, 'xorder' => count( $wanted ) + 1 ];
		}
		$tally( 'genre', $this->reconcileXrefItem( 'genre', $wanted, 'xkey_ext', false, false ) );
		$credits = FisheyeCredits::plexCredits( $plexDb, $metadataItemId );
		foreach( FisheyeCredits::ITEMS as $item ) {
			$wanted = [];
			foreach( $credits[$item] as $value ) {
				$row = [ 'key' => $value, 'xkey_ext' => $value, 'xorder' => count( $wanted ) + 1 ];
				if( $item === 'star' && isset( $credits['roles'][$value] ) ) {
					$row['data'] = [ 'role' => $credits['roles'][$value] ];
				}
				$wanted[] = $row;
			}
			$tally( $item, $this->reconcileXrefItem( $item, $wanted, 'xkey_ext', false, true ) );
		}
		$tally( FisheyeCredits::CHARACTER_ITEM, $this->reconcileXrefItem( FisheyeCredits::CHARACTER_ITEM, FisheyeCredits::characterRows( $credits ), [ FisheyeCredits::class, 'characterKey' ], false, true ) );

		if( !empty( $plexRow['content_rating'] ) ) {
			// Plex stores e.g. 'gb/12A' - the region prefix isn't useful for display.
			$rating = preg_replace( '#^[a-z]{2}/#i', '', $plexRow['content_rating'] );
			$tally( 'content_rating', $this->reconcileXrefItem( 'content_rating', [ [ 'key' => '', 'xkey_ext' => $rating, 'xorder' => 0 ] ], null ) );
		}
		if( !empty( $plexRow['duration'] ) ) {
			$tally( 'duration', $this->reconcileXrefItem( 'duration', [ [ 'key' => '', 'xkey_ext' => (string)(int)$plexRow['duration'], 'xorder' => 0 ] ], null ) );
		}

		$plexToken = $gBitSystem->getConfig( 'fisheye_plex_token', '' );
		if( !empty( $plexToken ) ) {
			$apiUrl = "http://localhost:32400/library/metadata/$metadataItemId?X-Plex-Token=".urlencode( $plexToken );
			$xml = @file_get_contents( $apiUrl );
			if( $xml !== false && preg_match_all( '#<Guid id="(imdb|tmdb)://([^"]+)"#', $xml, $matches, PREG_SET_ORDER ) ) {
				foreach( $matches as $match ) {
					$tally( $match[1], $this->reconcileXrefItem( $match[1], [ [ 'key' => '', 'xkey' => $match[2], 'xorder' => 0 ] ], null ) );
				}
			}
		}

		return $summary;
	}

	/**
	 * Just the credits from Plex - director, writer and the full star list - reconciled the way reloadPlexMetadata() does (history kept, hand
	 * edits and links to contacts left alone), without its ffprobe, description, Plex API or other items. For refreshing a whole library's
	 * cast cheaply (the film people pass's bulk reload).
	 *
	 * @return array{matched:bool, counts:array<string,int>}  the number of credits stored per item
	 */
	public function reloadPlexCredits(): array {
		$summary = [ 'matched' => false, 'counts' => [] ];
		$plexMatch = $this->matchPlexMetadataItem();
		if( !$plexMatch ) {
			return $summary;
		}
		$summary['matched'] = true;
		$credits = FisheyeCredits::plexCredits( $plexMatch['db'], $plexMatch['id'] );
		foreach( FisheyeCredits::ITEMS as $item ) {
			$wanted = [];
			foreach( $credits[$item] as $value ) {
				$row = [ 'key' => $value, 'xkey_ext' => $value, 'xorder' => count( $wanted ) + 1 ];
				if( $item === 'star' && isset( $credits['roles'][$value] ) ) {
					$row['data'] = [ 'role' => $credits['roles'][$value] ];
				}
				$wanted[] = $row;
			}
			$this->reconcileXrefItem( $item, $wanted, 'xkey_ext', false, true );
			$summary['counts'][$item] = count( $wanted );
		}
		$this->reconcileXrefItem( FisheyeCredits::CHARACTER_ITEM, FisheyeCredits::characterRows( $credits ), [ FisheyeCredits::class, 'characterKey' ], false, true );
		return $summary;
	}

	/**
	 * Fetch alternate poster/backdrop images from Plex's local API (posters/arts endpoints -
	 * xref-based rather than a second liberty_attachments row per image, since a film can have
	 * several alternates and LibertyMime only supports one attachment per content_id) and store
	 * real local copies, decoupling from
	 * Plex's continued availability. Deliberately its own action, separate from
	 * reloadPlexMetadata() - downloading N image files is a heavier,
	 * slower operation than the near-instant text-metadata backfill, so it gets its own
	 * button/action rather than running unconditionally every time metadata is reloaded.
	 *
	 * Needs fisheye_plex_token (the posters/arts endpoints aren't in the world-readable db, only
	 * via Plex's authenticated local API). Idempotent **per type** (poster/art), not globally -
	 * a type is only re-fetched if every existing row of that type has been deleted first; a
	 * global "any image exists at all" check would mean tidying down to just the kept images of
	 * one type by deleting a whole other type still blocked ever re-fetching that now-empty type
	 * without wiping everything else too. A new fetch continues the existing xorder sequence rather than
	 * restarting at 1, so a top-up run doesn't collide with rows the other type still has.
	 *
	 * Storage: this film's own storage/attachments/<branch>/ - alongside thumbs/, the same home
	 * every other attachment already uses for its own conversions/extras. Not the external film
	 * library tree - that folder's ownership/permissions aren't guaranteed to be web-writable
	 * (a disk reorg can easily land a folder at 755, not nginx-writable), where storage/attachments/ is always
	 * nginx-owned by construction. Files named `<film file's own basename>-poster-N.jpg` /
	 * `-art-N.jpg` (a leftover disambiguation habit from the old shared-folder days - harmless
	 * now each branch is already per-content_id, kept for readability browsing the folder
	 * directly). xkey_ext holds just the bare filename now, resolved against this branch (see
	 * getImageStorageBranchPath()) rather than fisheye_disk_storage_root; xorder numbers posters
	 * first (1 = primary/poster - also the one mime_film_get_thumbnail_url() picks as the
	 * default thumbnail source), then backdrops continuing on.
	 *
	 * Capped at 5 of each type - a well-known
	 * film's poster/art set from Plex can run into dozens and most are near-duplicates.
	 *
	 * Fetches TMDB's own pre-resized w342 (poster)/w780 (art) sizes, not the 'original' full
	 * resolution (1-4MB apiece, wasted weight for what's only ever shown as a thumbnail on
	 * view_film.tpl). w185/w300 (TMDB's next size down) turned out too small once seen rendered -
	 * bumped up a tier. TMDB only offers a fixed size set (no arbitrary width) - w342 is its
	 * closest poster size to a ~400px target, w780 the closest backdrop size above it (nothing
	 * exists between w300 and w780 for backdrops).
	 *
	 * @return array Summary of what was found/stored, for the calling page's result display.
	 */
	public function reloadPlexImages(): array {
		global $gBitSystem;
		$summary = [ 'matched' => false, 'items' => [] ];

		$plexMatch = $this->matchPlexMetadataItem();
		if( !$plexMatch ) {
			return $summary;
		}
		$summary['matched'] = true;
		$metadataItemId = $plexMatch['id'];

		// Per-type, not global - checked below per poster/art rather than skipping the whole
		// method just because *some* image exists. A global check meant that deliberately
		// deleting one entire type as part of tidying (e.g. every poster, keeping the backdrops)
		// still blocked ever re-fetching that now-empty type without wiping everything else too -
		// found live 2026-09-02 against Casino Royale (content_id=4066). Also tracks the current
		// max xorder so a top-up run continues the sequence rather than restarting at 1 and
		// colliding with what's already there.
		$this->loadXrefInfo();
		$existingImagePaths = [];
		$xorder = 0;
		foreach( $this->liveXrefs() as $xref ) {
			if( $xref['item'] === 'image' ) {
				$existingImagePaths[] = $xref['xkey_ext'];
				$xorder = max( $xorder, (int)$xref['xorder'] );
			}
		}

		$plexToken = $gBitSystem->getConfig( 'fisheye_plex_token', '' );
		if( empty( $plexToken ) ) {
			$summary['items'][] = 'fisheye_plex_token is not configured - the posters/arts endpoints need it.';
			return $summary;
		}

		// Lives in this film's own storage/attachments/<branch>/ - alongside thumbs/, same as
		// every other conversion/derived file any liberty attachment already keeps there - not
		// the external film-library tree.
		// Always nginx-writable by construction, unlike the external tree (found live: collection
		// folders made via mkdir/os.makedirs() during this library's reorganisation landed at 755,
		// not writable by php-fpm at all).
		$destBranch = \Bitweaver\Liberty\liberty_mime_get_storage_branch( [ 'attachment_id' => $this->mContentId ] );
		$imagesDir = STORAGE_PKG_PATH.$destBranch;
		KernelTools::mkdir_p( $imagesDir );

		$sourceFile = $this->mStorage[$this->mContentId]['source_file'] ?? '';
		$baseName = pathinfo( $sourceFile, PATHINFO_FILENAME ) ?: $this->getTitle();

		// Auto-pick a real cover (Plex's own currently-selected poster) as the primary thumbnail
		// in place of the video frame-grab fallback (mime_video_create_thumbnail(), via
		// renderThumbnails()'s video-type branch) - a real cover image is always preferable to a
		// last-resort screen grab when one's available. Once only -
		// gated on $existingImagePaths being empty (this method's first-ever run for this film),
		// same "don't silently override a later manual choice" reasoning as Season/Program/
		// Album's own auto-pick gate (empty($this->mStorage) there - doesn't apply to Film, whose
		// mStorage always already has one entry, the video's own mimefilm attachment).
		//
		// Deliberately NOT the shared FisheyeBase::attachThumbnail() Season/Program/Album use -
		// that reuses array_key_first($this->mStorage) as the attachment to overwrite, which for
		// a film would be the video's own mimefilm attachment (mStorage is keyed by content_id,
		// same as the video's), corrupting the file reference entirely. promoteImageToThumbnail()
		// is the safe mechanism already established here instead - it only ever touches files
		// directly under storage/attachments/<branch>/, never the attachment/DB layer, so it
		// can't collide with the video attachment no matter what's already in mStorage.
		if( empty( $existingImagePaths ) ) {
			$postersXml = @file_get_contents( "http://localhost:32400/library/metadata/$metadataItemId/posters?X-Plex-Token=".urlencode( $plexToken ) );
			if( $postersXml !== false && preg_match_all( '#<Photo\b[^>]*/>#', $postersXml, $tagMatches ) ) {
				foreach( $tagMatches[0] as $tag ) {
					if( str_contains( $tag, 'selected="1"' ) && preg_match( '#\bthumb="([^"]+)"#', $tag, $m ) ) {
						$thumb = html_entity_decode( $m[1] );
						$thumbUrl = str_starts_with( $thumb, '/' )
							? "http://localhost:32400$thumb".( str_contains( $thumb, '?' ) ? '&' : '?' )."X-Plex-Token=".urlencode( $plexToken )
							: $thumb;
						$imageData = @file_get_contents( $thumbUrl );
						if( $imageData !== false ) {
							$selectedFileName = "$baseName-poster-selected.jpg";
							file_put_contents( $imagesDir.$selectedFileName, $imageData );
							if( $this->promoteImageToThumbnail( $selectedFileName ) ) {
								$summary['items'][] = 'thumbnail: attached from Plex\'s own selected poster (overrides the video frame-grab fallback)';
							}
						}
						break;
					}
				}
			}
		}

		// TMDB serves the same image at several pre-resized widths from a predictable URL (its
		// own image CDN, not a Plex-specific thing) - swapping the 'key' attribute's '/original/'
		// segment for one of these avoids downloading/storing full-resolution (1-4MB). w342/w780
		// are real TMDB poster/backdrop size names, not arbitrary numbers - see this method's own
		// docblock for why these particular ones. Still resized further via resizeImageFile()
		// below - TMDB's own presets are width-only, no bounding-box option, so a backdrop at
		// w780 landscape is still 780px wide until the local resize step also caps it.
		$thumbSizes = [ 'poster' => 'w342', 'art' => 'w780' ];

		foreach( [ 'poster' => 'posters', 'art' => 'arts' ] as $type => $endpoint ) {
			$alreadyHasType = false;
			foreach( $existingImagePaths as $path ) {
				if( str_contains( $path, "-$type-" ) ) {
					$alreadyHasType = true;
					break;
				}
			}
			if( $alreadyHasType ) {
				$summary['items'][] = "$type: already has stored images - not re-fetched (delete all of this type first to force a re-fetch of it).";
				continue;
			}

			$apiUrl = "http://localhost:32400/library/metadata/$metadataItemId/$endpoint?X-Plex-Token=".urlencode( $plexToken );
			$xml = @file_get_contents( $apiUrl );
			if( $xml === false || !preg_match_all( '#<Photo[^>]*\bkey="([^"]+)"#', $xml, $matches ) ) {
				continue;
			}
			$fetched = 0;
			foreach( $matches[1] as $imageUrl ) {
				if( $fetched >= 5 ) {
					break;
				}
				$imageUrl = html_entity_decode( $imageUrl );
				// Plex's own newer agents (e.g. tv.plex.agents.series) serve bundled art via a
				// local proxy path rather than a direct https:// URL - no remote size variant to
				// swap in for these, fetch as-is through the local API instead
				$imageUrl = str_starts_with( $imageUrl, '/' )
					? "http://localhost:32400$imageUrl".( str_contains( $imageUrl, '?' ) ? '&' : '?' )."X-Plex-Token=".urlencode( $plexToken )
					: str_replace( '/original/', '/'.$thumbSizes[$type].'/', $imageUrl );
				$imageData = @file_get_contents( $imageUrl );
				if( $imageData === false ) {
					continue;
				}
				$fetched++;
				$fileName = "$baseName-$type-$fetched.jpg";
				// xkey_ext is now just the bare filename, resolved against this film's own
				// storage/attachments/<branch>/ rather than a fisheye_disk_storage_root-relative
				// path - no directory component needed, the branch is already per-content_id.
				$tmpFile = tempnam( sys_get_temp_dir(), 'fisheye_alt_' );
				file_put_contents( $tmpFile, $imageData );
				$resized = self::resizeImageFile( $tmpFile, $imagesDir.$fileName, 400 );
				@unlink( $tmpFile );
				if( !$resized ) {
					continue;
				}
				$xorder++;
				$xrefParamHash = [ 'content_id' => $this->mContentId, 'item' => 'image', 'xkey_ext' => $fileName, 'xorder' => $xorder ];
				$this->storeXref( $xrefParamHash );
				$summary['items'][] = "$type: $fileName";
			}
		}

		return $summary;
	}
}

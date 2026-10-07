<?php
/**
 * TV show ("program") — extends FisheyeGallery (not FisheyeImage) with
 * content_type_guid='fisheyeprogram'.
 *
 * A phantom subclass exactly like FisheyeFilm/FisheyeSeason/FisheyeAlbum, just built on
 * FisheyeGallery instead of FisheyeImage - a show is genuinely a gallery (it holds its seasons
 * as real members via addItem()/loadImages(), completely unchanged from plain FisheyeGallery
 * behaviour) but also needs its own metadata (genre/cast/external links) and, critically, its
 * own genuinely selected thumbnail - a plain FisheyeGallery has no metadata of its own and picks
 * its thumbnail by bubbling down into whichever member it happens to land on, which breaks
 * entirely once that member (a FisheyeSeason) has no mime attachment to derive one from. The
 * correct fix was a real program liberty object storing all the
 * program data and a selected thumbnail, not another bubble-down guess.
 *
 * The existing 'Inspector Morse' gallery (content_id=4069) was retyped from 'fisheyegallery' to
 * this guid as part of introducing it - same content_id, same fisheye_gallery_image_map rows
 * (season membership untouched), just a different handler class and its own xref items from
 * here on.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;

// mime_film_get_tvshow_storage_root() below is only auto-loaded via the LibertyMime
// attachment-plugin dispatch, which never fires for a show (no video attachment of its own) -
// require it directly rather than depending on some other content on the same page happening to
// trigger that loader first.
require_once dirname( __DIR__, 3 ).'/liberty/plugins/mime.film.php';

define( 'FISHEYEPROGRAM_CONTENT_TYPE_GUID', 'fisheyeprogram' );

class FisheyeProgram extends FisheyeMediaGallery {

	public function __construct( $pGalleryId = null, $pContentId = null ) {
		parent::__construct( $pGalleryId, $pContentId );
		$this->mContentTypeGuid = FISHEYEPROGRAM_CONTENT_TYPE_GUID;
		$this->registerContentType( FISHEYEPROGRAM_CONTENT_TYPE_GUID, [
			'content_type_guid' => FISHEYEPROGRAM_CONTENT_TYPE_GUID,
			'content_name'      => 'TV Show',
			'handler_class'     => 'FisheyeProgram',
			'handler_package'   => 'fisheyemedia',
			'handler_file'      => 'FisheyeProgram.php',
			'maintainer_url'    => 'https://www.bitweaver.org',
		] );
		// mPackageGuid='fisheyemedia' is set automatically by registerContentType()
		// because handler_package('fisheyemedia') != content_type_guid('fisheyeprogram').
	}

	/**
	 * A show gets its own dedicated view page (view_program.php - header facts + a grid of its
	 * own season members) rather than the generic gallery view.php a plain FisheyeGallery uses -
	 * fisheyeprogram directs to view_program.php as a program-specific "gallery" of seasons.
	 * Every gallery-grid template links via getDisplayUrl() generically (see
	 * fisheye_fixed_grid_inc.tpl), so overriding this alone is enough to redirect from the TV
	 * Shows gallery grid with no template changes.
	 *
	 * Originally named list_program.php - renamed to view_program.php 2026-09-02 to match the
	 * view_X.php convention every other per-item content type uses (view_film.php, view_image.php);
	 * the old name made a Shows gallery's member links look like they pointed at a listing page,
	 * inconsistent with the Films gallery's own view_film.php links.
	 *
	 * @return string
	 */
	public function getDisplayUrl( $pContentId = null, $pMixed = null ) {
		$contentId = \Bitweaver\BitBase::verifyId( $pContentId ) ? $pContentId : $this->mContentId;
		return FISHEYEMEDIA_PKG_URL.'view_program.php?content_id='.$contentId;
	}

	/**
	 * Override LibertyContent::getEditUrl()'s generic '<package>/edit.php' default - same
	 * fatal-until-fixed reasoning as FisheyeSeason::getEditUrl() - edit_program.php (title edit +
	 * xref table + Reload Metadata/Reload Images, a clone of edit_film.php) is this show's own
	 * real edit page, not the plain FisheyeGallery one.
	 *
	 * @return string
	 */
	public function getEditUrl( $pContentId = null, $pMixed = null ) {
		$contentId = \Bitweaver\BitBase::verifyId( $pContentId ) ? $pContentId : $this->mContentId;
		$ret = FISHEYEMEDIA_PKG_URL.'edit_program.php?content_id='.$contentId;
		foreach( (array)$pMixed as $key => $value ) {
			if( $key !== 'content_id' ) {
				$ret .= '&'.$key.'='.$value;
			}
		}
		return $ret;
	}

	/**
	 * Deleting a show needs to take its seasons with it - FisheyeGallery::expunge()'s own
	 * recursion only ever cascades into sub-*galleries*, never into plain gallery items, and a
	 * season (FisheyeSeason extends FisheyeImage, not FisheyeGallery) is exactly that. Scoped
	 * to FisheyeProgram rather than fixing this at the FisheyeGallery level - a show's seasons
	 * are never meaningfully shared with another gallery the way a photo can be, so there's no
	 * call to touch the shared base class's behaviour for every other gallery type in the
	 * package (Films, Pictures, Library...) just to cover this one case.
	 *
	 * Each season's own expunge() (inherited from FisheyeImage) already cleans up its episode
	 * xrefs and images via LibertyMime::expunge(), so no separate episode/image handling is
	 * needed here.
	 *
	 * Same "scope to FisheyeProgram, not the shared base" reasoning covers one more thing:
	 * FisheyeGallery::expunge() (what parent::expunge() below reaches) calls
	 * LibertyContent::expunge() directly rather than LibertyMime::expunge() - fine for every
	 * other gallery, which never has a real attachment of its own, but this show might (its own
	 * selected thumbnail, see getThumbnailUrl() below) - left alone, that attachment row would
	 * still be sitting in liberty_attachments when the liberty_content DELETE runs, and the
	 * LIBERTY_ATTACHMENTS_CON_REF foreign key would block it (found live 2026-09-04 deleting a
	 * test show). Expunge it here first rather than touching FisheyeGallery's own behaviour.
	 *
	 * @return bool
	 */
	public function expunge(): bool {
		if( $this->isValid() && $this->loadImages() ) {
			foreach( $this->mItems as $season ) {
				if( is_a( $season, '\Bitweaver\Fisheye\FisheyeSeason' ) ) {
					// abort the whole show delete rather than silently skip a season that fails
					if( !$season->expunge() ) {
						// $season is a separate object instance - pull its own mErrors across,
						// since they don't reach $this->mErrors on their own
						$this->mErrors['expunge_season'] = "Season ".$season->mContentId." could not be expunged: "
							.( $season->mErrors['expunge_attachment'] ?? implode( '; ', $season->mErrors ) ?: 'unknown reason' );
						return false;
					}
				}
			}
		}
		$query = "SELECT `attachment_id` FROM `".BIT_DB_PREFIX."liberty_attachments` WHERE `content_id`=?";
		foreach( $this->mDb->getCol( $query, [ $this->mContentId ] ) as $attachmentId ) {
			if( !$this->expungeAttachment( $attachmentId ) ) {
				// expungeAttachment() already set mErrors['expunge_attachment'] to the specific reason
				return false;
			}
		}
		return parent::expunge();
	}

	/**
	 * A show's own selected thumbnail. FisheyeGallery descends from LibertyMime
	 * just like a real photo does, so this show has its own unused attachment slot -
	 * reloadPlexImages() stores a real image attachment there via attachThumbnail(), same as a
	 * normal upload would. Read here via LibertyMime's own storage-based lookup (explicit class
	 * scoping, since FisheyeGallery's own getThumbnailUrl() override always bubbles to a member
	 * instead) rather than the earlier xref-based approach, which (a) never generated an actual
	 * small thumbnail, just linked to the same file shown in the Images tab, and (b) broke for a
	 * genuinely anonymous visitor - every xref group in media.php, images included, is
	 * role_id=3 ('Registered'), so loadXrefInfo() silently returned nothing for a guest even
	 * though the file itself was already public.
	 *
	 * @return string
	 */
	public function getThumbnailUri( $pSize = 'small', $pInfoHash = null ) {
		return $this->getThumbnailUrl( $pSize ) ?: '';
	}

	public function getThumbnailUrl( string $pSize = 'small', ?array $pInfoHash = null, ?int $pSecondaryId = null, ?int $pDefault = null ): string|null {
		if( $this->isValid() ) {
			// Explicit class scoping, not $this->load() - FisheyeGallery::load() shortcuts
			// straight to LibertyContent::load() (same shortcut its own store() override takes),
			// so mStorage is never populated via the normal load() path at all. Found live
			// 2026-09-02: attachThumbnail() had stored a real attachment correctly, but this
			// method still read back empty because $this->load() never touched mStorage.
			\Bitweaver\Liberty\LibertyMime::load();
			$url = \Bitweaver\Liberty\LibertyMime::getThumbnailUrl( $pSize, $pInfoHash, $pSecondaryId, $pDefault );
			if( !empty( $url ) ) {
				return $url;
			}
		}
		return null;
	}

	/**
	 * Short-circuits FisheyeGallery::getThumbnailImage()'s own recursion, which treats ANY
	 * nested FisheyeGallery-family member as "just another gallery to bubble down through" via
	 * `is_a($ret, FisheyeGallery)` - since FisheyeProgram itself extends FisheyeGallery, a parent
	 * gallery's own getThumbnailImage() (e.g. 'TV Shows', resolving a random member) would
	 * otherwise recurse straight past this show's own real attachment and back into whichever
	 * season it happens to contain, undoing the entire point of this class existing - found live
	 * 2026-09-02, immediately after Reload Images populated this show's own images and the
	 * top-level galleries listing *still* showed the season's poster instead.
	 *
	 * Falls back to the normal inherited behaviour if this show has no attachment of its own yet
	 * (e.g. before Reload Images has ever been run) rather than returning nothing.
	 *
	 * @return static|mixed
	 */
	public function getThumbnailImage( $pContentId=null, $pThumbnailContentId=null, $pThumbnailContentType=null ) {
		if( $this->isValid() ) {
			// Explicit class scoping - see getThumbnailUrl()'s identical comment above.
			\Bitweaver\Liberty\LibertyMime::load();
			if( !empty( $this->mStorage ) ) {
				return $this;
			}
		}
		return parent::getThumbnailImage( $pContentId, $pThumbnailContentId, $pThumbnailContentType );
	}

	/**
	 * Promote one of this show's already-downloaded 'image' xref alternates (a local file under
	 * the TV storage root's images/ folder) into the real, single thumbnail attachment - the
	 * manual "change it" action, since the auto-picked (Plex's own currently-
	 * selected poster) default is sometimes not the best of the available alternates.
	 *
	 * @param string $pRelativePath  an 'image' xref row's own xkey_ext value
	 * @return bool
	 */
	public function promoteImageToThumbnail( string $pRelativePath ): bool {
		$path = $this->getExtraImagePath( $pRelativePath );
		if( empty( $path ) || !is_file( $path ) ) {
			return false;
		}
		return $this->attachThumbnail( $path );
	}

	/**
	 * The storage root this show's own 'image' xref rows live relative to - the TV-specific
	 * per-show root (A-M/N-Z split), resolved directly from this show's own title (it already IS
	 * the real show name, unlike a season). NOT the same root as a plain film's
	 * fisheye_disk_storage_root - see FisheyeSeason::getImageStorageRoot()'s identical docblock
	 * for why edit_xref.php calls this generically rather than assuming one shared root.
	 *
	 * @return string empty string if the config is unset
	 */
	public function getImageStorageRoot(): string {
		return \Bitweaver\Liberty\mime_film_get_tvshow_storage_root( $this->getTitle() );
	}

	/**
	 * Override of FisheyeBase's own getImageStorageRoot()-relative default - a show's own
	 * downloaded Plex alternates live in storage/attachments/<branch>/, not the external TV
	 * library tree, same fix FisheyeFilm/FisheyeAlbum already got - this class just wasn't
	 * following it yet.
	 */
	public function getExtraImagePath( string $pRelativePath ): string {
		return $this->getImageStorageBranchPath().$pRelativePath;
	}

	/**
	 * This show's one-and-only season, for the single-season case (a flat/single-episode show,
	 * see FisheyeSeason::registerFromDisk()'s own "isFlatSeason" handling) - null for a show with
	 * zero or more than one season, since there'd be no single unambiguous season to return.
	 *
	 * @return FisheyeSeason|null
	 */
	public function getSingleSeason(): ?FisheyeSeason {
		// loadImages() takes its param by reference - a literal array fatals.
		$listHash = [ 'max_records' => -1 ];
		$this->loadImages( $listHash );
		return count( (array)$this->mItems ) === 1 ? current( $this->mItems ) : null;
	}

	/**
	 * This show's own storage/attachments/<branch>/ path - home for its downloaded Plex image
	 * alternates and any manual uploads, same convention FisheyeFilm::getImageStorageBranchPath()
	 * already established. Always nginx-writable by construction, unlike the external TV library
	 * tree (getImageStorageRoot(), still used by grabVideoFrameImage() to locate a season's own
	 * episode video file, unrelated to where images live).
	 *
	 * @return string
	 */
	private function getImageStorageBranchPath(): string {
		return STORAGE_PKG_PATH.\Bitweaver\Liberty\liberty_mime_get_storage_branch( [ 'attachment_id' => $this->mContentId ] );
	}

	/**
	 * Generic file-lifecycle hook liberty/edit_xref.php calls (via method_exists()) when a file
	 * is uploaded to replace an xref row's own referenced file - see FisheyeFilm::
	 * replaceXrefFile()'s identical docblock for the fuller reasoning (same method, same shape,
	 * just this show's own storage root).
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
		$path = $this->getExtraImagePath( $pXkeyExt );
		if( empty( $path ) ) {
			return false;
		}
		return move_uploaded_file( $pTmpPath, $path );
	}

	/**
	 * Generic file-lifecycle hook liberty/edit_xref.php calls (via method_exists()) on a real
	 * hard-delete (expunge=3) of an xref row - see FisheyeFilm::deleteXrefFile()'s identical
	 * docblock for the fuller reasoning.
	 *
	 * @param string $pItem
	 * @param string $pXkeyExt
	 * @return bool
	 */
	public function deleteXrefFile( string $pItem, string $pXkeyExt ): bool {
		if( $pItem !== 'image' || empty( $pXkeyExt ) ) {
			return false;
		}
		$path = $this->getExtraImagePath( $pXkeyExt );
		if( empty( $path ) || !is_file( $path ) ) {
			return false;
		}
		return @unlink( $path );
	}

	/**
	 * Locate this show in the local Plex library by title, matched against Plex's own show-level
	 * entries (metadata_type=2) directly - unlike a film or season, a show's own title (this
	 * object's getTitle()) already IS the real show name, so no file-path matching or walking up
	 * a parent_id chain is needed at all. Reliable enough for a personal single-library
	 * collection; a real title collision across two different shows isn't a case this needs to
	 * handle.
	 *
	 * @return array{db:\PDO,id:int}|null  null if unconfigured or no match found
	 */
	/**
	 * Register a show (found or created by title - no file attachment, a show is a pure gallery
	 * record), link it into the "TV Shows" gallery, and backfill Plex metadata - the show-level
	 * equivalent of FisheyeFilm::registerFromDisk(), shared by load_program.php. Unlike a film,
	 * there's no disk path to validate here - the show "exists" the moment its folder is picked
	 * from load_program.php's listing; matching real season/episode files happens one level down,
	 * in FisheyeSeason::registerFromDisk().
	 *
	 * gallery_id is always returned alongside content_id (both branches) - load_program.php scopes
	 * by gallery_id throughout, same convention load_film.php already settled on, so a caller
	 * never needs a second lookup just to get it.
	 *
	 * @return array 'already'=>content_id + 'gallery_id' if already registered, or
	 *               'created'=>content_id + 'gallery_id'/'linked'/'plex' on success, or
	 *               'error'=>string on failure.
	 */
	public static function registerFromDisk( string $pShowTitle ): array {
		global $gBitDb;

		$existingContentId = $gBitDb->getOne(
			"SELECT content_id FROM liberty_content WHERE content_type_guid = 'fisheyeprogram' AND title = ?",
			[ $pShowTitle ]
		);
		if( $existingContentId ) {
			$existing = new FisheyeProgram( null, $existingContentId );
			$existing->load();
			return [ 'already' => $existingContentId, 'gallery_id' => $existing->mGalleryId ];
		}

		$program = new FisheyeProgram();
		// store() takes its param by reference - can't pass an array literal directly.
		$storeHash = [ 'title' => $pShowTitle ];
		if( !$program->store( $storeHash ) ) {
			return [ 'error' => implode( '; ', $program->mErrors ) ];
		}
		// store() on a freshly-created object doesn't refresh its own in-memory fields - getTitle()
		// would return '' from here on without this, same bug class as HealthDay's own
		// findOrCreate() once had. Real, confirmed impact: reloadPlexMetadata()'s description-store
		// below reads $this->getTitle() and needs a real value or FisheyeGallery::verifyGalleryData()
		// silently fails it (title required) - reproduced live 2026-09-03, root cause of Andromeda's
		// missing description despite everything else (genre/cast/episodes/images) loading fine.
		$program->load();

		$galleryContentId = $gBitDb->getOne(
			"SELECT lc.content_id FROM liberty_content lc INNER JOIN fisheye_gallery fg ON fg.content_id = lc.content_id WHERE lc.content_type_guid = 'fisheyegallery' AND lc.title = ?",
			[ 'TV Shows' ]
		);
		$linked = false;
		if( $galleryContentId ) {
			$gallery = new FisheyeGallery( null, $galleryContentId );
			$gallery->load();
			$linked = $gallery->addItem( $program->mContentId );
		}
		// Halt here rather than blindly fetching metadata/images against a title match that may
		// well be wrong or missing - exact title matching is fragile in both directions, confirmed
		// live on Dinnerladies: Plex's own title is the single word "Dinnerladies", the on-disk
		// folder is "Dinner Ladies", and stripping ":" out of titles for comparison made it worse,
		// not better. The show record itself (above) still always gets created -
		// cheap, and gives searchPlexShows()/setPlexMatchOverride() (the "Search Plex" action on
		// edit_program.php) something to attach a manually-confirmed match to - but no metadata/
		// image fetch runs until a match is actually confirmed, automatic or manual.
		if( !$program->hasPlexMatch() ) {
			return [ 'created' => $program->mContentId, 'gallery_id' => $program->mGalleryId, 'linked' => $linked, 'no_match' => true ];
		}
		$plexMeta = $program->reloadPlexMetadata();
		// Unlike FisheyeFilm::registerFromDisk()'s opt-in $pFetchImages (a bulk 20-film import
		// paying for N image downloads at once is a real cost worth choosing explicitly), shows
		// are registered one at a time here - no reason to make images a separate manual step.
		$plexImages = $program->reloadPlexImages();

		return [ 'created' => $program->mContentId, 'gallery_id' => $program->mGalleryId, 'linked' => $linked, 'plex' => $plexMeta, 'images' => $plexImages ];
	}

	/**
	 * Cheap public wrapper around matchPlexShowMetadataItem() for callers (registerFromDisk()
	 * above) that only need to know whether a match exists, not the live PDO handle that method
	 * also returns.
	 *
	 * @return bool
	 */
	public function hasPlexMatch(): bool {
		return $this->matchPlexShowMetadataItem() !== null;
	}

	/**
	 * @return bool  always true - see FisheyeBase::canGrabVideoFrame()'s own docblock
	 */
	public function canGrabVideoFrame(): bool {
		return true;
	}

	/**
	 * A show has no video file of its own to grab a frame from - unlike FisheyeSeason's own
	 * version, walks this show's own seasons (loadImages(), the same gallery-item mechanism
	 * every other gallery uses) looking for the first one with a real seed episode file, and
	 * grabs from that instead. Built for Flying Scotsman's show-level image gap specifically,
	 * distinct from the season-level gap this mechanism was first built for.
	 *
	 * @return string|null  the new xref row's xkey_ext, or null if no season has a usable
	 *                       episode file, or the grab/resize/store itself failed
	 */
	public function grabVideoFrameImage(): ?string {
		$root = $this->getImageStorageRoot();
		if( empty( $root ) || !$this->loadImages() ) {
			return null;
		}
		foreach( $this->mItems as $season ) {
			if( !is_a( $season, '\Bitweaver\Fisheye\FisheyeSeason' ) ) {
				continue;
			}
			$season->loadXrefInfo();
			$episodeXref = $season->mXrefInfo ? $season->mXrefInfo->findRowByItem( 'episode' ) : null;
			if( $episodeXref && !empty( $episodeXref['xkey_ext'] ) && is_file( $root.$episodeXref['xkey_ext'] ) ) {
				return $this->grabVideoFrameIntoImageXref( $root.$episodeXref['xkey_ext'] );
			}
		}
		return null;
	}

	/**
	 * Free-text search against Plex's own local library for a TV show, so a failed automatic
	 * title match (see registerFromDisk()'s halt, and matchPlexShowMetadataItem()'s own docblock)
	 * can be fixed by hand rather than requiring the on-disk folder or Plex's own title to be
	 * edited to match exactly. Plain SQL LIKE against the same local Plex SQLite db every other
	 * Plex lookup in this class already reads directly - no need for Plex's HTTP search API when
	 * the db is already sitting right there and every other match in this file already queries
	 * it this way.
	 *
	 * @param string $pQuery  free-text fragment of the show's title
	 * @return array  list of ['id'=>, 'title'=>, 'year'=>], up to 20, title order
	 */
	public static function searchPlexShows( string $pQuery ): array {
		global $gBitSystem;
		$ret = [];
		$pQuery = trim( $pQuery );
		if( $pQuery === '' ) {
			return $ret;
		}
		$dbPath = $gBitSystem->getConfig( 'fisheye_plex_db_path', '' );
		if( empty( $dbPath ) || !is_file( $dbPath ) ) {
			return $ret;
		}
		try {
			$plexDb = new \PDO( 'sqlite:'.$dbPath );
		} catch( \Exception $e ) {
			return $ret;
		}
		$stmt = $plexDb->prepare( "SELECT id, title, year FROM metadata_items WHERE metadata_type = 2 AND title LIKE ? ORDER BY title LIMIT 20" );
		$stmt->execute( [ '%'.$pQuery.'%' ] );
		foreach( $stmt->fetchAll( \PDO::FETCH_ASSOC ) as $row ) {
			$ret[] = [ 'id' => (int)$row['id'], 'title' => $row['title'], 'year' => $row['year'] ];
		}
		return $ret;
	}

	/**
	 * Persist a manually-confirmed Plex match (one row picked from searchPlexShows() results),
	 * so matchPlexShowMetadataItem() uses it directly from now on instead of re-deriving from
	 * the (potentially mismatched) title string every time. Rebuild-not-diff, same convention as
	 * every other single-cardinality reload* xref here - delete any prior override, then insert.
	 *
	 * @param int $pMetadataItemId  a Plex metadata_items.id, from searchPlexShows()
	 * @return bool
	 */
	public function setPlexMatchOverride( int $pMetadataItemId ): bool {
		self::deleteXrefByItem( $this->mContentId, [ 'plex_match' ] );
		$xrefHash = [ 'content_id' => $this->mContentId, 'item' => 'plex_match', 'xkey' => (string)$pMetadataItemId ];
		return $this->storeXref( $xrefHash );
	}

	private function matchPlexShowMetadataItem(): ?array {
		global $gBitSystem;

		$dbPath = $gBitSystem->getConfig( 'fisheye_plex_db_path', '' );
		if( empty( $dbPath ) || !is_file( $dbPath ) ) {
			return null;
		}

		try {
			$plexDb = new \PDO( 'sqlite:'.$dbPath );
		} catch( \Exception $e ) {
			return null;
		}

		// A manually-confirmed match (setPlexMatchOverride() above) always wins over the
		// automatic title lookup below. Plain direct SQL rather than the generic
		// lookupXrefByItem()/loadXrefInfo() helpers - both require a registered
		// liberty_xref_item config row, and this is a purely internal bookkeeping value, never
		// shown through the generic xref grid, so it was never worth registering as one.
		$overrideId = $this->mDb->getOne(
			"SELECT `xkey` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'plex_match'",
			[ $this->mContentId ]
		);
		if( $overrideId ) {
			return [ 'db' => $plexDb, 'id' => (int)$overrideId ];
		}

		// case-insensitive - Plex's own title casing doesn't always match the folder-derived title
		// stored here (e.g. Plex has "dinnerladies", this show is titled "Dinnerladies"), and an
		// exact match would otherwise report "no match" for a show clearly present in Plex.
		$stmt = $plexDb->prepare( "SELECT id FROM metadata_items WHERE metadata_type = 2 AND title = ? COLLATE NOCASE" );
		$stmt->execute( [ $this->getTitle() ] );
		$showMetadataItemId = $stmt->fetchColumn();
		if( !$showMetadataItemId ) {
			return null;
		}

		return [ 'db' => $plexDb, 'id' => (int)$showMetadataItemId ];
	}

	/**
	 * Best-effort metadata backfill/refresh for this show, same shape as
	 * FisheyeFilm::reloadPlexMetadata() (same tag_type map, same delete-then-reinsert rebuild, same
	 * imdb/tmdb guid fetch) - the piece that was missing entirely until now, which is why
	 * view_program.php's facts
	 * panel was always empty and its one 'Reload Images' button looked orphaned with nothing to
	 * pair it with. A show-level Plex record only carries genre + actor(tag_type 1/6) taggings in
	 * practice - director/writer are per-episode, not per-show, so those two stay empty here and
	 * the template's own {if $directors|@count} guards already handle that with no special-casing
	 * needed.
	 *
	 * @return array Summary of what was found/stored, for the calling page's result display.
	 */
	public function reloadPlexMetadata(): array {
		global $gBitSystem;
		$summary = [ 'matched' => false, 'items' => [] ];

		$plexMatch = $this->matchPlexShowMetadataItem();
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

		// the show's own description - a real gap found live 2026-09-02 on Inspector Morse's Plex
		// record - stored directly on liberty_content.data (view_program.php's own
		// {if $gContent->mInfo.data} block was
		// already there, just never had anything to show since nothing populated it).
		if( !empty( $plexRow['summary'] ) ) {
			// FisheyeGallery::store() (inherited) declares its param by-reference, so a literal
			// inline array here is a fatal error - must assign to a variable first. The input key
			// is 'edit', not 'data' - LibertyContent::verify() only maps content_store['data']
			// from $pParamHash['edit'] (via the format plugin's own verify_function, e.g.
			// bithtml_verify_data()) - same "wrong field name" gotcha already hit once this
			// session for xref rows, now found again one layer up in plain content storage.
			// 'title' must also be included even though it's unchanged - FisheyeGallery::store()'s
			// own verifyGalleryData() unconditionally requires it in the hash (unlike rows_per_page/
			// cols_per_page/thumbnail_size, which fall back to $this->mInfo when omitted) and
			// otherwise fails validation silently - reloadPlexMetadata() never checked store()'s
			// return value, so this failed with no visible error until checked directly against
			// the database.
			$descriptionStoreHash = [ 'content_id' => $this->mContentId, 'title' => $this->getTitle(), 'edit' => $plexRow['summary'] ];
			$this->store( $descriptionStoreHash );
			$summary['items'][] = 'description updated';
		}

		self::deleteXrefByItem(
			$this->mContentId,
			[ 'genre', 'director', 'writer', 'star', 'content_rating', 'duration', 'imdb', 'tmdb' ]
		);

		// tag_type: 1=genre, 4=director, 5=writer, 6=actor(star) - same mapping confirmed against
		// real live data as FisheyeFilm::reloadPlexMetadata(); a show-level record just tends to
		// have nothing under 4/5.
		$tagTypes = [ 'genre' => 1, 'director' => 4, 'writer' => 5, 'star' => 6 ];
		foreach( $tagTypes as $item => $tagType ) {
			$tagStmt = $plexDb->prepare(
				"SELECT t.tag FROM taggings tg JOIN tags t ON t.id = tg.tag_id WHERE tg.metadata_item_id = ? AND t.tag_type = ? ORDER BY tg.\"index\""
			);
			$tagStmt->execute( [ $metadataItemId, $tagType ] );
			$xorder = 1;
			foreach( $tagStmt->fetchAll( \PDO::FETCH_COLUMN ) as $value ) {
				// 'star' capped at 5 - a show's aggregate cast list spans every season/episode and
				// can run into hundreds (confirmed live: 200 rows for Inspector Morse).
				if( $item === 'star' && $xorder > 5 ) { break; }
				$xrefParamHash = [ 'content_id' => $this->mContentId, 'item' => $item, 'xkey_ext' => $value, 'xorder' => $xorder ];
				$this->storeXref( $xrefParamHash );
				$summary['items'][] = "$item: $value";
				$xorder++;
			}
		}

		if( !empty( $plexRow['content_rating'] ) ) {
			// Plex stores e.g. 'gb/15' - the region prefix isn't useful for display.
			$rating = preg_replace( '#^[a-z]{2}/#i', '', $plexRow['content_rating'] );
			$ratingParamHash = [ 'content_id' => $this->mContentId, 'item' => 'content_rating', 'xkey_ext' => $rating ];
			$this->storeXref( $ratingParamHash );
			$summary['items'][] = "content_rating: $rating";
		}
		if( !empty( $plexRow['duration'] ) ) {
			$durationParamHash = [ 'content_id' => $this->mContentId, 'item' => 'duration', 'xkey_ext' => (string)(int)$plexRow['duration'] ];
			$this->storeXref( $durationParamHash );
			$summary['items'][] = "duration: {$plexRow['duration']}ms";
		}

		$plexToken = $gBitSystem->getConfig( 'fisheye_plex_token', '' );
		if( !empty( $plexToken ) ) {
			$apiUrl = "http://localhost:32400/library/metadata/$metadataItemId?X-Plex-Token=".urlencode( $plexToken );
			$xml = @file_get_contents( $apiUrl );
			if( $xml !== false && preg_match_all( '#<Guid id="(imdb|tmdb)://([^"]+)"#', $xml, $matches, PREG_SET_ORDER ) ) {
				foreach( $matches as $match ) {
					$linkParamHash = [ 'content_id' => $this->mContentId, 'item' => $match[1], 'xkey' => $match[2] ];
					$this->storeXref( $linkParamHash );
					$summary['items'][] = "{$match[1]}: {$match[2]}";
				}
			}
		}

		return $summary;
	}

	/**
	 * Fetch alternate poster/backdrop images from Plex for this show, same shape as
	 * FisheyeFilm::reloadPlexImages()/FisheyeSeason::reloadPlexImages() (per-type idempotency,
	 * w342/w780 TMDB sizes, 5-per-type cap, xref-based storage in storage/attachments/<branch>/ -
	 * see FisheyeFilm's own docblock for the fuller reasoning, not repeated here). Filename
	 * basename is this show's own title (no episode file needed to derive it, unlike a season).
	 *
	 * @return array Summary of what was found/stored, for the calling page's result display.
	 */
	public function reloadPlexImages(): array {
		global $gBitSystem;
		$summary = [ 'matched' => false, 'items' => [] ];

		$plexMatch = $this->matchPlexShowMetadataItem();
		if( !$plexMatch ) {
			return $summary;
		}
		$summary['matched'] = true;
		$metadataItemId = $plexMatch['id'];

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

		// Auto-pick the real thumbnail attachment (once only - see FisheyeSeason::
		// reloadPlexImages()'s identical block for the fuller reasoning) from Plex's own
		// currently-selected poster rather than just grabbing whichever alternate comes first.
		if( empty( $this->mStorage ) ) {
			$postersXml = @file_get_contents( "http://localhost:32400/library/metadata/$metadataItemId/posters?X-Plex-Token=".urlencode( $plexToken ) );
			if( $postersXml !== false && preg_match_all( '#<Photo\b[^>]*/>#', $postersXml, $tagMatches ) ) {
				foreach( $tagMatches[0] as $tag ) {
					if( str_contains( $tag, 'selected="1"' ) && preg_match( '#\bthumb="([^"]+)"#', $tag, $m ) ) {
						$thumb = html_entity_decode( $m[1] );
						$thumbUrl = str_starts_with( $thumb, '/' )
							? "http://localhost:32400$thumb".( str_contains( $thumb, '?' ) ? '&' : '?' )."X-Plex-Token=".urlencode( $plexToken )
							: $thumb;
						if( $this->attachThumbnail( $thumbUrl ) ) {
							$summary['items'][] = 'thumbnail: attached from Plex\'s own selected poster';
						}
						break;
					}
				}
			}
		}

		$imagesDir = $this->getImageStorageBranchPath();
		KernelTools::mkdir_p( $imagesDir );

		$baseName = $this->getTitle();
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

	/**
	 * Follow a show's folder when it has been renamed on disk (Plex wants "Doctor Who (1963)" once there are three Doctor Whos): its episode
	 * and featurette rows hold their file as `TV Shows/<folder>/...` and its title - and its seasons' - is the folder name, so after a rename
	 * every one of them points at nothing. A preview first ($pApply false): how many rows move, how many of the new files really exist
	 * (only those are moved), the titles that would change. Applying rewrites each row's path in place keeping its place in history (the
	 * last-update stamp is left alone, so a later Plex reload still refreshes it) and renames the program and the seasons that carry its title.
	 * Nothing is fetched from Plex - run Reload Metadata/Episodes afterwards to refresh what Plex now says.
	 *
	 * @return array{ok:bool, error?:string, old_folder:string, new_folder:string, rows:int, movable:int, missing:list<string>, moved:int,
	 *               titles:list<array{content_id:int, from:string, to:string}>, titles_changed:int, conflict:?string}
	 */
	public function relocateFolder( string $pOldFolder, string $pNewFolder, bool $pApply = false ): array {
		global $gBitDb;
		$pOldFolder = trim( $pOldFolder, "/ \t" );
		$pNewFolder = trim( $pNewFolder, "/ \t" );
		$result = [ 'ok' => false, 'old_folder' => $pOldFolder, 'new_folder' => $pNewFolder, 'rows' => 0, 'movable' => 0, 'missing' => [], 'moved' => 0,
			'titles' => [], 'titles_changed' => 0, 'conflict' => null ];
		if( $pOldFolder === '' || $pNewFolder === '' || $pOldFolder === $pNewFolder || str_contains( $pNewFolder, '/' ) || str_contains( $pOldFolder, '/' ) ) {
			$result['error'] = KernelTools::tra( 'Give the show\'s old and new folder names (different, no slashes).' );
			return $result;
		}
		$root = \Bitweaver\Liberty\mime_film_get_tvshow_storage_root( $pNewFolder );
		if( $root === '' || !is_dir( $root.'TV Shows/'.$pNewFolder ) ) {
			$result['error'] = KernelTools::tra( 'The new folder does not exist on disk:' ).' '.$root.'TV Shows/'.$pNewFolder;
			return $result;
		}
		$seasonIds = FisheyeCredits::seasonIdsForProgram( (int)$this->mContentId );
		$ids = array_merge( [ (int)$this->mContentId ], $seasonIds );
		$rows = $gBitDb->getAll(
			"SELECT x.`xref_id`, x.`content_id`, x.`xkey_ext`, x.`last_update_date`, lc.`content_type_guid` FROM `".BIT_DB_PREFIX."liberty_xref` x
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = x.`content_id`
			 WHERE x.`content_id` IN ( ".implode( ',', array_fill( 0, count( $ids ), '?' ) )." ) AND x.`item` IN ( 'episode', 'featurette' )
			 AND x.`end_date` IS NULL AND x.`xkey_ext` LIKE ?",
			array_merge( $ids, [ 'TV Shows/'.$pOldFolder.'/%' ] )
		) ?: [];
		$oldPrefix = 'TV Shows/'.$pOldFolder.'/';
		$move = [];
		foreach( $rows as $row ) {
			$result['rows']++;
			$new = 'TV Shows/'.$pNewFolder.'/'.substr( $row['xkey_ext'], strlen( $oldPrefix ) );
			if( is_file( $root.$new ) ) {
				$result['movable']++;
				$move[] = [ $row, $new ];
			} elseif( count( $result['missing'] ) < 10 ) {
				$result['missing'][] = $new;
			}
		}
		// The program's title is the folder name, and a season's is "<show> - <season folder>".
		$oldTitle = (string)$this->getTitle();
		$result['titles'][] = [ 'content_id' => (int)$this->mContentId, 'from' => $oldTitle, 'to' => $pNewFolder ];
		foreach( $seasonIds as $seasonId ) {
			$seasonTitle = (string)$gBitDb->getOne( "SELECT `title` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ?", [ $seasonId ] );
			if( $oldTitle !== '' && str_starts_with( $seasonTitle, $oldTitle ) ) {
				$result['titles'][] = [ 'content_id' => $seasonId, 'from' => $seasonTitle, 'to' => $pNewFolder.substr( $seasonTitle, strlen( $oldTitle ) ) ];
			}
		}
		$taken = $gBitDb->getOne( "SELECT `content_id` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_type_guid` = 'fisheyeprogram' AND `title` = ? AND `content_id` <> ?", [ $pNewFolder, (int)$this->mContentId ] );
		if( $taken ) {
			$result['conflict'] = sprintf( KernelTools::tra( 'Another show is already titled "%s" (content %d).' ), $pNewFolder, (int)$taken );
		}
		$result['ok'] = !$result['conflict'] && $result['movable'] > 0;
		if( !$pApply || !$result['ok'] ) {
			return $result;
		}
		foreach( $move as [ $row, $new ] ) {
			$xref = new \Bitweaver\Liberty\LibertyXref();
			$xref->mContentTypeGuid = $row['content_type_guid'];
			$xref->load( (int)$row['xref_id'] );
			$hash = [ 'xref_id' => (int)$row['xref_id'], 'xkey_ext' => $new, 'last_update_date' => (int)$row['last_update_date'] ];
			if( $xref->store( $hash ) ) {
				$result['moved']++;
			}
		}
		foreach( $result['titles'] as $title ) {
			$content = FisheyeGallery::lookup( [ 'content_id' => $title['content_id'] ] );
			if( $content && $content->isValid() ) {
				$content->load();
				$hash = [ 'content_id' => $title['content_id'], 'title' => $title['to'], 'edit' => (string)( $content->mInfo['data'] ?? '' ) ];
				if( $content->store( $hash ) ) {
					$result['titles_changed']++;
				}
			}
		}
		return $result;
	}
}

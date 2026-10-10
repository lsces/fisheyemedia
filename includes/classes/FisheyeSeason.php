<?php
/**
 * TV season — extends FisheyeImage with content_type_guid='fisheyeseason'.
 *
 * The real, folder-leaf content_id in the show->season->episode tree - an episode itself is one
 * level further down still, a plain 'episode' liberty_xref row under its season's own content_id,
 * not a content object of its own. (Stale note this replaces: a show was originally meant to be a
 * computed, FoodDay-pattern browsing level with no content_id of its own - superseded once
 * FisheyeProgram was built, which gives every show a genuine content_id/gallery, see that class's
 * own docblock.) Ring-fences season-level metadata (genre/director/
 * writer/star/content_rating/duration, IMDB+TheTVDB links) plus the EPISODE xref item away from
 * plain fisheyeimage photo rows and from FisheyeFilm/FisheyeAlbum's own item sets - no other
 * behavioural difference from FisheyeImage, same pattern as Contact/ContactPerson/ContactBusiness.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;

// mime_film_get_tvshow_storage_root() below is only auto-loaded via the LibertyMime
// attachment-plugin dispatch, which never fires for a season (no video attachment of its own) -
// require it directly rather than depending on some other content on the same page happening to
// trigger that loader first. Same fix as FisheyeProgram.php.
require_once dirname( __DIR__, 3 ).'/liberty/plugins/mime.film.php';

define( 'FISHEYESEASON_CONTENT_TYPE_GUID', 'fisheyeseason' );

class FisheyeSeason extends FisheyeMediaImage {

	public function __construct( $pImageId = null, $pContentId = null ) {
		parent::__construct( $pImageId, $pContentId );
		$this->mContentTypeGuid = FISHEYESEASON_CONTENT_TYPE_GUID;
		$this->registerContentType( FISHEYESEASON_CONTENT_TYPE_GUID, [
			'content_type_guid' => FISHEYESEASON_CONTENT_TYPE_GUID,
			'content_name'      => 'TV Season',
			'handler_class'     => 'FisheyeSeason',
			'handler_package'   => 'fisheyemedia',
			'handler_file'      => 'FisheyeSeason.php',
			'maintainer_url'    => 'https://www.bitweaver.org',
		] );
		// mPackageGuid='fisheyemedia' is set automatically by registerContentType()
		// because handler_package('fisheyemedia') != content_type_guid('fisheyeseason').
	}

	/**
	 * Override LibertyContent::getEditUrl()'s generic '<package>/edit.php' default - same fatal-
	 * error bug FisheyeFilm hit live ("Call to undefined method ...::getAllLayouts()"),
	 * fisheye's own edit.php being the GALLERY edit page, not a season's. See FisheyeFilm's own
	 * identical override for the fuller explanation.
	 *
	 * @param int|null $pContentId
	 * @param array|null $pMixed
	 * @return string
	 */
	public function getEditUrl( $pContentId = null, $pMixed = null ) {
		$contentId = \Bitweaver\BitBase::verifyId( $pContentId ) ? $pContentId : $this->mContentId;
		$ret = FISHEYEMEDIA_PKG_URL.'edit_season.php?content_id='.$contentId;
		foreach( (array)$pMixed as $key => $value ) {
			if( $key !== 'content_id' ) {
				$ret .= '&'.$key.'='.$value;
			}
		}
		return $ret;
	}

	/**
	 * Override FisheyeImage::getDisplayUrl()'s image_id-keyed default - a season is never a plain
	 * photo, so its display page is view_season.php (facts + episode list, no video player of its
	 * own), the matching pair to edit_season.php alongside
	 * view_program.php/edit_program.php. Previously fell through to FisheyeImage's generic
	 * getDisplayUrl(), which pointed a season's gallery-grid link at view_image.php - wrong page
	 * for a season, just never noticed since nothing linked to a season directly until
	 * view_program.php's season grid was built.
	 *
	 * @return string
	 */
	public function getDisplayUrl( $pContentId = null, $pMixed = null ) {
		$contentId = \Bitweaver\BitBase::verifyId( $pContentId ) ? $pContentId : $this->mContentId;
		return FISHEYEMEDIA_PKG_URL.'view_season.php?content_id='.$contentId;
	}

	/**
	 * A season has no mime attachment of its own at all (no single file - it's a pure metadata
	 * container over its episodes' own xref rows), so
	 * FisheyeImage's own mime-derived thumbnail is never populated by the normal load path.
	 *
	 * Fixed properly by recognising that a season DOES have its own unused attachment slot (FisheyeImage descends from
	 * LibertyMime just like any real photo/film does - it just never had anything stored there),
	 * so reloadPlexImages() now stores a real image attachment via attachThumbnail() the same way
	 * a normal upload would. This reads that attachment directly via LibertyMime's own storage-
	 * based lookup (explicit class scoping, since FisheyeImage's own getThumbnailUrl() override
	 * reads a differently-shaped mInfo field that only its own load() populates) rather than the
	 * earlier xref-based approach, which (a) never generated an actual small thumbnail, just
	 * linked to the same file shown in the Images tab, and (b) broke for a genuinely anonymous
	 * visitor - every xref group in media.php, images included, is role_id=3 ('Registered'), so
	 * loadXrefInfo() silently returned nothing for a guest even though the file itself was
	 * already public. Neither problem exists here - a real attachment gets real generated
	 * thumbnails via the standard, already-correct liberty machinery.
	 *
	 * @return string
	 */
	public function getThumbnailUri( string $pSize = 'small' ): string {
		return $this->getThumbnailUrl( $pSize ) ?: '';
	}

	public function getThumbnailUrl( string $pSize = 'small', ?array $pInfoHash = null, ?int $pSecondaryId = null, ?int $pDefault = null ): string|null {
		if( $this->isValid() ) {
			$this->load();
			$url = \Bitweaver\Liberty\LibertyMime::getThumbnailUrl( $pSize, $pInfoHash, $pSecondaryId, $pDefault );
			if( !empty( $url ) ) {
				return $url;
			}
		}
		return null;
	}

	/**
	 * Promote one of this season's already-downloaded 'image' xref alternates (a local file
	 * under the TV storage root's images/ folder) into the real, single thumbnail attachment -
	 * a manual "change it" action, since the auto-picked (Plex's own
	 * currently-selected poster) default is sometimes not the best of the available alternates.
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
	 * The storage root this season's own 'image' xref rows (and 'episode' rows) live relative to
	 * - the TV-specific per-show root (A-M/N-Z split), resolved via the show title found by
	 * walking this season's own parent gallery (see matchPlexSeasonMetadataItem()'s identical
	 * lookup - nothing on the season object itself carries the show's name directly). NOT the
	 * same root as a plain film's fisheye_disk_storage_root - edit_xref.php calls this generically
	 * (via method_exists(), same as FisheyeFilm's own version of this method) rather than
	 * assuming every fisheye content type's images share one root, a real bug that a shared-root
	 * install can mask (both roots resolving to the same place there) even though they genuinely
	 * differ on a split install.
	 *
	 * @return string empty string if the show title can't be resolved or the config is unset
	 */
	public function getImageStorageRoot(): string {
		$parents = $this->getParentGalleries();
		$showTitle = $parents ? ( current( $parents )['title'] ?? '' ) : '';
		return $showTitle ? \Bitweaver\Liberty\mime_film_get_tvshow_storage_root( $showTitle ) : '';
	}

	/**
	 * Override of FisheyeBase's own getImageStorageRoot()-relative default - a season's own
	 * downloaded Plex alternates and per-episode thumbs live in storage/attachments/<branch>/,
	 * not the external TV library tree, same fix FisheyeFilm/FisheyeAlbum already got - this
	 * class just wasn't following it yet.
	 */
	public function getExtraImagePath( string $pRelativePath ): string {
		return $this->getImageStorageBranchPath().$pRelativePath;
	}

	/**
	 * This season's own storage/attachments/<branch>/ path - home for its downloaded Plex image
	 * alternates, per-episode thumbs, and any manual uploads, same convention
	 * FisheyeFilm::getImageStorageBranchPath() already established. Always nginx-writable by
	 * construction, unlike the external TV library tree (getImageStorageRoot(), still used for
	 * locating this season's own episode video files, unrelated to where images live).
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
	 * just this season's own storage root).
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
	 * The images/episodes/featurettes arrays view_season.php and view_program.php's own
	 * single-season dispatch both need from this season's own xrefs - previously two separate,
	 * near-identical copies of the same switch-over-liveXrefs() logic (missing 'featurette'
	 * entirely in both, since that item type didn't exist yet), factored out here rather than
	 * adding a third copy. `$externalLinks` (view_season.php's own IMDB/TVDB/TMDB link list) stays
	 * that page's own separate concern - view_program.php's single-season page never built it, so
	 * it isn't part of what was actually shared.
	 *
	 * @return array{images:array,episodes:array,featurettes:array,firstTab:?string}
	 */
	public function getSeasonViewData(): array {
		$this->loadXrefInfo();
		$images = [];
		$episodes = [];
		$featurettes = [];
		if( $this->mXrefInfo ) {
			foreach( $this->liveXrefs() as $xref ) {
				$data = !empty( $xref['data'] ) ? json_decode( $xref['data'], true ) : [];
				switch( $xref['item'] ) {
					case 'image':
						$images[] = [ 'xref_id' => $xref['xref_id'] ];
						break;
					case 'episode':
						$episodes[] = [
							'xref_id'        => $xref['xref_id'],
							'xorder'         => (int)$xref['xorder'],
							'title'          => $data['title'] ?? pathinfo( $xref['xkey_ext'], PATHINFO_FILENAME ),
							'summary'        => $data['summary'] ?? '',
							'air_date'       => $data['air_date'] ?? '',
							'directors'      => $data['director'] ?? [],
							'writers'        => $data['writer'] ?? [],
							'stars'          => $data['star'] ?? [],
							'narrators'      => $data['narrator'] ?? [],
							'roles'          => array_filter( (array)( $data['roles'] ?? [] ), fn( $r ) => FisheyeCredits::selfFunction( (string)$r ) === null ),
							'functions'      => array_filter( array_map( fn( $r ) => ( $f = FisheyeCredits::selfFunction( (string)$r ) ) ? FisheyeCredits::functionLabel( $f ) : '', (array)( $data['roles'] ?? [] ) ) ),
							'content_rating' => $data['content_rating'] ?? '',
							'durationMs'     => $data['duration'] ?? null,
							'thumb'          => $data['thumb'] ?? null,
							'resolution'     => $data['resolution'] ?? null,
							'audio'          => $data['audio'] ?? null,
						];
						break;
					case 'featurette':
						$featurettes[] = [
							'xref_id'    => $xref['xref_id'],
							'title'      => $data['title'] ?? pathinfo( $xref['xkey_ext'], PATHINFO_FILENAME ),
							'summary'    => $data['summary'] ?? '',
							'thumb'      => $data['thumb'] ?? null,
							'durationMs' => $data['duration'] ?? null,
							'resolution' => $data['resolution'] ?? null,
							'audio'      => $data['audio'] ?? null,
						];
						break;
				}
			}
		}
		// Which of the Episodes/Featurettes/Images tabs starts active - episodes first since
		// that's the overwhelmingly common case, falling through to whichever else actually has
		// content for the rare season that's extras-only or images-only.
		$firstTab = match( true ) {
			(bool)$episodes     => 'episodes',
			(bool)$featurettes  => 'featurettes',
			(bool)$images       => 'images',
			default             => null,
		};
		// Every credited name in the season's episodes, resolved once to the contact it is linked to (if any).
		$creditNames = [];
		foreach( $episodes as $episode ) {
			$creditNames = array_merge( $creditNames, $episode['directors'], $episode['writers'], $episode['stars'], $episode['narrators'] );
		}
		return [ 'images' => $images, 'episodes' => $episodes, 'featurettes' => $featurettes, 'firstTab' => $firstTab,
			'creditUrls' => FisheyeCredits::urlsForNames( $creditNames ) ];
	}

	/**
	 * This season's own episode titles, for LibertyContent::verify()/setIndexData()'s shared
	 * getExtraIndexWords() hook - an episode has no liberty_content row of its own (it's an xref
	 * on this season's own content_id), so without this it could never be searched for at all.
	 * Lets a search for a specific episode ("newton", "oasis") surface the season it actually
	 * belongs to.
	 *
	 * @param array $pParamHash  unused here - this season's own already-stored xrefs are the
	 *                           source, not anything from the calling save
	 * @return string
	 */
	public function getExtraIndexWords( array $pParamHash ): string {
		$this->loadXrefInfo();
		$words = [];
		if( $this->mXrefInfo ) {
			foreach( $this->liveXrefs() as $xref ) {
				if( $xref['item'] !== 'episode' ) {
					continue;
				}
				$data = !empty( $xref['data'] ) ? json_decode( $xref['data'], true ) : [];
				$words[] = $data['title'] ?? pathinfo( $xref['xkey_ext'], PATHINFO_FILENAME );
			}
		}
		return implode( ' ', $words );
	}

	/**
	 * Locate this season in the local Plex library. A season has no file of its own to match by
	 * (unlike a film) - matched instead via one of its own episodes' file path (an 'episode'
	 * xref row's xkey_ext), walking Plex's own metadata_items.parent_id from that episode
	 * (metadata_type=4) up to its season (metadata_type=3) in a single join - an episode's own
	 * metadata_item.parent_id always resolves to its season's metadata_item row this way.
	 *
	 * Resolves the TV-specific per-show storage root (mime_film_get_tvshow_storage_root(),
	 * A-M/N-Z split) via the show's own title - found by walking this season's parent gallery
	 * (the show-level FisheyeGallery it's linked into)
	 * rather than any field on the season itself, which doesn't carry the
	 * show's name directly.
	 *
	 * @return array{db:\PDO,id:int,root:string}|null  null if unconfigured or no match found
	 */
	/**
	 * Register a season under an already-registered show, seed it with one real episode file (the
	 * one thing matchPlexSeasonMetadataItem() needs to find the right Plex season - it matches by
	 * an existing 'episode' xref's own file path, chicken-and-egg otherwise), then immediately call
	 * reloadPlexEpisodes() to replace that single seed row with the show's real full episode list
	 * from Plex. Mirrors the proven manual sequence a superseded one-off smoke test established,
	 * with the season linked into a real FisheyeProgram gallery instead of that script's plain-
	 * FisheyeGallery fallback (FisheyeProgram didn't exist yet when it was written).
	 *
	 * Season title convention: "<show title> - <season folder name>" (e.g. "Example Show -
	 * Season 1", "Example Show - Specials") - the folder name is used verbatim rather than
	 * trying to normalize "Specials" into a numbered series, since it already reads correctly and
	 * Plex's own season match doesn't depend on this title at all (only on the seeded file path).
	 *
	 * @param string $pShowTitle        Real show title, used to resolve the TV storage root and
	 *                                   build the season title.
	 * @param string $pSeasonFolderName Real subfolder name under the show's own folder, e.g.
	 *                                   "Season 1" or "Specials".
	 * @param int    $pShowContentId    The already-registered FisheyeProgram's content_id to link
	 *                                   this season into.
	 * @return array 'already'=>content_id if a real episode was already seeded (re-running just
	 *               re-syncs from Plex), or 'created'/'episodes' on success, or 'error'=>string on
	 *               failure (including "no episode files found in this folder").
	 */
	public static function registerFromDisk( string $pShowTitle, string $pSeasonFolderName, int $pShowContentId ): array {
		global $gBitDb;

		// '.' is load_program.php's sentinel for "no season subfolder at all - episode files
		// sit directly in the show folder" (a flat single-season show, e.g. a one-off
		// documentary with no Season 01/ subfolder). Treat it as season dir == show dir, and
		// title it plainly as Season 1 rather than "<show> - ." .
		$isFlatSeason = ( $pSeasonFolderName === '.' );
		$seasonTitle = $isFlatSeason ? $pShowTitle.' - Season 1' : $pShowTitle.' - '.$pSeasonFolderName;
		$alreadySeeded = false;

		$existingContentId = $gBitDb->getOne(
			"SELECT content_id FROM liberty_content WHERE content_type_guid = 'fisheyeseason' AND title = ?",
			[ $seasonTitle ]
		);
		if( $existingContentId ) {
			$season = new FisheyeSeason( null, $existingContentId );
			$season->load();
			$alreadySeeded = (bool)$gBitDb->getOne(
				"SELECT xref_id FROM liberty_xref WHERE content_id = ? AND item = 'episode'",
				[ $existingContentId ]
			);
		} else {
			$season = new FisheyeSeason();
			// store() takes its param by reference - can't pass an array literal directly.
			$storeHash = [ 'title' => $seasonTitle ];
			if( !$season->store( $storeHash ) ) {
				return [ 'error' => implode( '; ', $season->mErrors ) ];
			}
			// store() on a freshly-created object doesn't refresh its own in-memory fields -
			// getTitle() would return '' from here on without this. Real, confirmed impact (same
			// bug just fixed in FisheyeProgram::registerFromDisk()): reloadPlexImages() below
			// builds each stored image's filename from $this->getTitle() - would silently produce
			// " - episode-N.jpg"-style broken filenames for a freshly-created season otherwise.
			$season->load();
		}

		$showGallery = new FisheyeGallery( null, $pShowContentId );
		$showGallery->load();
		if( !$showGallery->isInGallery( $pShowContentId, $season->mContentId ) ) {
			// addItem() defaults item_position to null, leaving every season to sort by its
			// arbitrary creation order (content_id) rather than season number - derive a real
			// position from the folder name instead ("Season 11" -> 110), counting by tens per
			// image_order.tpl's own convention so gaps stay available for manual reordering
			// later. A non-numbered folder (e.g. "Specials") sorts first, ahead of Season 1.
			$position = preg_match( '/(\d+)/', $pSeasonFolderName, $m ) ? (int)$m[1] * 10 : 0;
			$showGallery->addItem( $season->mContentId, $position );
		}

		if( !$alreadySeeded ) {
			$root = \Bitweaver\Liberty\mime_film_get_tvshow_storage_root( $pShowTitle );
			$seasonDir = $isFlatSeason
				? $root.'TV Shows/'.$pShowTitle.'/'
				: $root.'TV Shows/'.$pShowTitle.'/'.$pSeasonFolderName.'/';
			$seedFile = null;
			if( !empty( $root ) && is_dir( $seasonDir ) ) {
				foreach( scandir( $seasonDir ) as $file ) {
					if( !is_file( $seasonDir.$file ) ) {
						continue;
					}
					if( in_array( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ), [ 'mkv', 'mp4', 'm4v', 'avi' ], true ) ) {
						$seedFile = $file;
						break;
					}
				}
			}
			if( empty( $seedFile ) ) {
				return [ 'error' => 'No episode files found in '.$seasonDir ];
			}
			// storeXref() also takes its param by reference - same reason as store() above.
			$xkeyExt = $isFlatSeason
				? 'TV Shows/'.$pShowTitle.'/'.$seedFile
				: 'TV Shows/'.$pShowTitle.'/'.$pSeasonFolderName.'/'.$seedFile;
			$xrefHash = [
				'content_id' => $season->mContentId,
				'item'       => 'episode',
				'xkey_ext'   => $xkeyExt,
				'edit'       => json_encode( [ 'title' => pathinfo( $seedFile, PATHINFO_FILENAME ) ] ),
				'xorder'     => 1,
			];
			$season->storeXref( $xrefHash );
		}

		$episodes = $season->reloadPlexEpisodes();
		// Same reasoning as FisheyeProgram::registerFromDisk() - not opt-in the way film's bulk
		// import needs it to be, and reloadPlexImages() is already idempotent per-type internally
		// so calling it every time (including a re-run against an already-registered season) is
		// safe, not wasted re-downloading.
		$images = $season->reloadPlexImages();
		// Same "always safe to re-run" reasoning - a "Featurettes/" folder appearing after the
		// season was already registered (e.g. moved into place later) gets picked up on the next
		// ordinary reload, not just at first creation.
		$featurettes = $season->registerFeaturettesFromDisk();

		return [ 'created' => $season->mContentId, 'episodes' => $episodes, 'images' => $images, 'featurettes' => $featurettes ];
	}

	private function matchPlexSeasonMetadataItem(): ?array {
		global $gBitSystem;

		$dbPath = $gBitSystem->getConfig( 'fisheye_plex_db_path', '' );
		if( empty( $dbPath ) || !is_file( $dbPath ) ) {
			return null;
		}

		$this->loadXrefInfo();
		$episodeXrefs = $this->mXrefInfo
			? array_filter( $this->liveXrefs(), fn( $xref ) => $xref['item'] === 'episode' )
			: [];
		if( empty( $episodeXrefs ) ) {
			return null;
		}

		$parents = $this->getParentGalleries();
		if( empty( $parents ) ) {
			return null;
		}
		$showTitle = current( $parents )['title'] ?? '';
		if( empty( $showTitle ) ) {
			return null;
		}

		$root = \Bitweaver\Liberty\mime_film_get_tvshow_storage_root( $showTitle );
		if( empty( $root ) ) {
			return null;
		}

		try {
			$plexDb = new \PDO( 'sqlite:'.$dbPath );
		} catch( \Exception $e ) {
			return null;
		}

		$stmt = $plexDb->prepare(
			"SELECT mi.parent_id FROM media_parts mp
			 JOIN media_items mi2 ON mi2.id = mp.media_item_id
			 JOIN metadata_items mi ON mi.id = mi2.metadata_item_id
			 WHERE mp.file = ? AND mi.metadata_type = 4"
		);

		// try every seeded episode row, not just the first - a single stale/renamed anchor
		// file (e.g. after fixing a mistagged episode) shouldn't permanently break matching
		// for the whole season when another row still points at a real file
		// Anchors in this show's own folder go first: the root is shared by every show, and a stray row left from
		// another show (Plex once merged two Doctor Who folders) would otherwise match that show's season, and
		// this season's real episodes would then be archived as no longer listed.
		$anchors = array_values( array_filter( $episodeXrefs, fn( $xref ) => !empty( $xref['xkey_ext'] ) ) );
		// A stored path is "<shelf>/<show folder>/<season folder>/<file>"; the shelf is whatever precedes the show folder.
		$shelf = $anchors ? dirname( $anchors[0]['xkey_ext'], 3 ) : '.';
		$shelf = $shelf === '.' ? '' : $shelf.'/';
		$ownFolder = $shelf.$showTitle.'/';
		$isOwn = fn( $xref ) => str_starts_with( $xref['xkey_ext'], $ownFolder );
		if( is_dir( $root.$ownFolder ) && !array_filter( $anchors, $isOwn ) ) {
			// Only another show's rows are left: anchor on a file in this season's own folder on disk instead.
			$seasonFolder = preg_match( '/ - (Season \\d+|Specials)$/', $this->getTitle(), $m ) ? $m[1] : '';
			foreach( $seasonFolder ? (array)glob( $root.$ownFolder.$seasonFolder.'/*.*' ) : [] as $file ) {
				array_unshift( $anchors, [ 'xkey_ext' => substr( $file, strlen( $root ) ) ] );
				break;
			}
		}
		usort( $anchors, fn( $a, $b ) => $isOwn( $b ) <=> $isOwn( $a ) );
		foreach( $anchors as $episodeXref ) {
			$realPath = realpath( $root.$episodeXref['xkey_ext'] );
			if( empty( $realPath ) ) {
				continue;
			}
			$stmt->execute( [ $realPath ] );
			$seasonMetadataItemId = $stmt->fetchColumn();
			if( $seasonMetadataItemId ) {
				return [ 'db' => $plexDb, 'id' => (int)$seasonMetadataItemId, 'root' => $root,
					'folder' => implode( '/', array_slice( explode( '/', $episodeXref['xkey_ext'] ), 0, $shelf === '' ? 1 : 2 ) ).'/' ];
			}
		}

		return null;
	}

	/**
	 * Resolves this season's real on-disk folder, preferring one of its own 'episode' xref's
	 * xkey_ext (unambiguous when available) but falling back to reconstructing it from the
	 * season's own title when there's no episode left to derive it from at all - e.g. every
	 * episode was deleted, leaving nothing else to derive the folder from: without this fallback,
	 * getEpisodeFileCountOnDisk() would silently return null forever afterwards, making a
	 * deleted-then-reload cycle permanently impossible.
	 *
	 * The reconstruction still can't just trust the title outright, because a flat (no-subfolder)
	 * season's synthetic title ("Show - Season 1") is textually indistinguishable from a real
	 * show that genuinely has a subfolder literally named "Season 1" - resolved by trying the
	 * subfolder interpretation first (real directory check) and only falling back to "files sit
	 * directly in the show's own folder" when no such subfolder actually exists, mirroring
	 * registerFromDisk()'s own original flat-vs-not decision.
	 *
	 * Shared by registerEpisodesFromFilesystem() and getEpisodeFileCountOnDisk() below - both
	 * need the same folder, one to register from it, one just to count.
	 *
	 * @return array{dir:string,relative:string}|null  dir is absolute with trailing slash,
	 *                                                   relative is dir relative to the storage
	 *                                                   root (the form xkey_ext values use) -
	 *                                                   null if the show/root/folder can't be
	 *                                                   resolved at all by either method.
	 */
	private function resolveSeasonDirectoryFromDisk(): ?array {
		$parents = $this->getParentGalleries();
		$showTitle = empty( $parents ) ? '' : ( current( $parents )['title'] ?? '' );
		if( empty( $showTitle ) ) {
			return null;
		}
		$root = \Bitweaver\Liberty\mime_film_get_tvshow_storage_root( $showTitle );
		if( empty( $root ) ) {
			return null;
		}

		$this->loadXrefInfo();
		$episodeXrefs = $this->mXrefInfo
			? array_filter( $this->liveXrefs(), fn( $xref ) => $xref['item'] === 'episode' )
			: [];
		$seedXref = current( $episodeXrefs );
		if( !empty( $seedXref['xkey_ext'] ) ) {
			$relativeSeasonDir = dirname( $seedXref['xkey_ext'] );
			$seasonDir = $root.$relativeSeasonDir.'/';
			if( is_dir( $seasonDir ) ) {
				return [ 'dir' => $seasonDir, 'relative' => $relativeSeasonDir ];
			}
		}

		// No usable seed episode (none left, or its file has since moved/gone) - reconstruct from
		// the season's own title convention instead ("<show title> - <season folder name>").
		$prefix = $showTitle.' - ';
		$title = $this->getTitle();
		if( !str_starts_with( $title, $prefix ) ) {
			return null;
		}
		$seasonFolderNameGuess = substr( $title, strlen( $prefix ) );

		$subfolderRelative = 'TV Shows/'.$showTitle.'/'.$seasonFolderNameGuess;
		if( is_dir( $root.$subfolderRelative.'/' ) ) {
			return [ 'dir' => $root.$subfolderRelative.'/', 'relative' => $subfolderRelative ];
		}
		if( $seasonFolderNameGuess === 'Season 1' ) {
			$flatRelative = 'TV Shows/'.$showTitle;
			if( is_dir( $root.$flatRelative.'/' ) ) {
				return [ 'dir' => $root.$flatRelative.'/', 'relative' => $flatRelative ];
			}
		}

		return null;
	}

	/**
	 * A season's own bonus-content folder, DVD-era-style - "Featurettes/" directly inside the
	 * season's own folder, same convention and same 'featurette' xref item as
	 * FisheyeFilm::registerFeaturettesFromDisk(). A show shipping this content as a differently-
	 * shaped "Extras/<season folder>/" sibling structure instead needs its extras physically
	 * moved inside each season's own folder first to fit this convention. The scan-and-register
	 * logic itself lives in FisheyeMediaTrait::registerFeaturettesFromFolder() (shared with Film) - this
	 * method's only job is resolving *this* season's own containing directory.
	 *
	 * No-op (empty summary, not an error) when there's no real season folder to resolve at all, or
	 * no "Featurettes/" subfolder exists - most seasons genuinely have no bonus content.
	 *
	 * @return array{items:array}  Summary shape matching every other reload* method here.
	 */
	public function registerFeaturettesFromDisk(): array {
		$dirInfo = $this->resolveSeasonDirectoryFromDisk();
		if( !$dirInfo ) {
			return [ 'items' => [] ];
		}
		return $this->registerFeaturettesFromFolder( $dirInfo['dir'], $dirInfo['relative'] );
	}

	/**
	 * Real episode video files (by extension) directly inside a season folder - shared scan used
	 * by both registerEpisodesFromFilesystem() and getEpisodeFileCountOnDisk() below.
	 *
	 * @param string $pSeasonDir  absolute path, trailing slash
	 * @return array  filenames only (no path), natsort-ordered
	 */
	private function scanEpisodeFiles( string $pSeasonDir ): array {
		$files = [];
		foreach( scandir( $pSeasonDir ) as $file ) {
			if( is_file( $pSeasonDir.$file ) && in_array( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ), [ 'mkv', 'mp4', 'm4v', 'avi' ], true ) ) {
				$files[] = $file;
			}
		}
		natsort( $files );
		return $files;
	}

	/**
	 * How many real episode files actually sit in this season's own folder right now - compared
	 * against countRegisteredEpisodes() below by load_program.php to offer a "Reload Episodes"
	 * action directly from the show page whenever a season (typically the only one - a
	 * single-season show is the common case) has picked up more files than are currently
	 * registered, without needing to visit edit_season.php at all.
	 *
	 * @return int|null  null if the season's own folder can't be resolved at all (see
	 *                    resolveSeasonDirectoryFromDisk())
	 */
	public function getEpisodeFileCountOnDisk(): ?int {
		$dirInfo = $this->resolveSeasonDirectoryFromDisk();
		if( !$dirInfo ) {
			return null;
		}
		return count( $this->scanEpisodeFiles( $dirInfo['dir'] ) );
	}

	/**
	 * How many 'episode' xref rows are currently registered for this season - see
	 * getEpisodeFileCountOnDisk()'s own docblock for why this is compared against it.
	 *
	 * @return int
	 */
	public function countRegisteredEpisodes(): int {
		$this->loadXrefInfo();
		if( !$this->mXrefInfo ) {
			return 0;
		}
		return count( array_filter( $this->liveXrefs(), fn( $xref ) => $xref['item'] === 'episode' ) );
	}

	/**
	 * registerFromDisk()'s single seed episode is only ever meant to be temporary - matched
	 * against Plex, then replaced by reloadPlexEpisodes()'s real full list. When a show has no
	 * Plex/TVDB entry at all, that replacement never happens, so this exists to register every
	 * real episode file already in the season's own folder directly instead, parsing the
	 * "SnnEnn"/title out of the filename (the "Show - SnnEnn - Title.ext" convention used
	 * throughout the TV library - see project_tv_library memory).
	 *
	 * Derives the season's own real folder from an existing 'episode' xref's xkey_ext rather than
	 * reconstructing it from the season title, since a flat (no-subfolder) season's title
	 * ("Show - Season 1") doesn't correspond to a real "Season 1" folder on disk.
	 *
	 * Reconciled like the Plex-match branch (never wiped - history and hand edits kept).
	 *
	 * @return array Same shape as the Plex-match branch: matched=>bool, items=>episode titles.
	 */
	private function registerEpisodesFromFilesystem( bool $pDataOnly = false ): array {
		$summary = [ 'matched' => false, 'items' => [] ];

		$dirInfo = $this->resolveSeasonDirectoryFromDisk();
		if( !$dirInfo ) {
			return $summary;
		}
		$seasonDir = $dirInfo['dir'];
		$relativeSeasonDir = $dirInfo['relative'];

		$files = $this->scanEpisodeFiles( $seasonDir );
		if( empty( $files ) ) {
			return $summary;
		}

		// Reconciled, never wiped - see reloadPlexEpisodes(). Rows are collected, then reconciled once.
		$wantedEpisodes = [];

		// Same per-episode thumbnail this season's Plex-match branch above stores (its own
		// 'thumb' key, resolved later by view_extra_image.php) - just sourced from a local
		// ffmpeg/ffmpegthumbnailer frame grab (mime_film_grab_video_frame(), the same engine
		// FisheyeMediaTrait::grabVideoFrameIntoImageXref() already uses) instead of Plex's HTTP API,
		// since there's no Plex metadata_item id to fetch one from here.
		$imagesDir = $this->getImageStorageBranchPath();
		KernelTools::mkdir_p( $imagesDir );

		$existingEpisodes = $pDataOnly ? $this->liveEpisodeData() : [];
		$xorder = 1;
		foreach( $files as $file ) {
			// Data-only: nothing here comes from Plex, so an episode already registered is left exactly as it is.
			if( $pDataOnly && isset( $existingEpisodes[$relativeSeasonDir.'/'.$file] ) ) {
				$wantedEpisodes[] = [
					'key'      => $relativeSeasonDir.'/'.$file,
					'xkey_ext' => $relativeSeasonDir.'/'.$file,
					'xorder'   => $xorder++,
					'data'     => $existingEpisodes[$relativeSeasonDir.'/'.$file],
				];
				continue;
			}
			$stem = pathinfo( $file, PATHINFO_FILENAME );
			// "Show - SnnEnn - Episode Title" -> keep the title; "Show - SnnEnn" alone (no per-
			// episode title, common for documentaries) -> keep the SnnEnn tag itself rather than
			// the whole filename stem.
			$episodeTitle = $stem;
			if( preg_match( '/ - (S\d+E[\dE&-]+)(?: - (.+))?$/i', $stem, $m ) ) {
				$episodeTitle = $m[2] ?? $m[1];
			}
			$episodeData = [ 'title' => $episodeTitle ];
			$this->tvdbFill( $episodeData, preg_match( '/S\d+E(\d+)/i', $stem, $epMatch ) ? (int)$epMatch[1] : $xorder );
			// Plex never gets a chance to supply this for a no-match show - straight from the
			// file's own container via ffprobe instead (same helper as the featurette fallback,
			// FisheyeMediaTrait::registerFeaturettesFromFolder()).
			$durationMs = \Bitweaver\Liberty\mime_film_get_duration_ms( $seasonDir.$file );
			if( $durationMs !== null ) {
				$episodeData['duration'] = $durationMs;
			}
			$qualityInfo = \Bitweaver\Liberty\mime_film_get_quality_info( $seasonDir.$file );
			if( $qualityInfo['resolution'] !== null ) {
				$episodeData['resolution'] = $qualityInfo['resolution'];
			}
			if( $qualityInfo['audio'] !== null ) {
				$episodeData['audio'] = $qualityInfo['audio'];
			}
			// Named after the episode's own source file (unique within this folder), not its
			// xorder position - same reasoning as the featurette fallback's identical fix: a
			// position-based name mis-attaches an old thumb the moment episode numbering shifts,
			// and forces an expensive re-grab on every reload even when nothing changed.
			$fileName = $stem.'.jpg';
			if( is_file( $imagesDir.$fileName ) ) {
				$episodeData['thumb'] = $fileName;
			} else {
				$tmpFile = tempnam( sys_get_temp_dir(), 'fisheye_ep_thumb_' );
				if( \Bitweaver\Liberty\mime_film_grab_video_frame( $seasonDir.$file, $tmpFile ) ) {
					if( self::resizeImageFile( $tmpFile, $imagesDir.$fileName, 400 ) ) {
						$episodeData['thumb'] = $fileName;
					}
				}
				@unlink( $tmpFile );
			}
			$wantedEpisodes[] = [
				'key'      => $relativeSeasonDir.'/'.$file,
				'xkey_ext' => $relativeSeasonDir.'/'.$file,
				'xorder'   => $xorder++,
				'data'     => $episodeData,
			];
			$summary['items'][] = $episodeTitle;
		}
		$summary['matched'] = true;
		$summary['items'][] = self::describeCounts( 'episodes', $this->reconcileXrefItem( 'episode', $wantedEpisodes, 'xkey_ext' ) );
		foreach( $this->deriveCreditDirectory() as $role => $roleCounts ) {
			$summary['items'][] = self::describeCounts( "credits ($role)", $roleCounts );
		}

		return $summary;
	}

	/**
	 * Fetch this season's full episode list from Plex - the "Load Episodes" action,
	 * superseding an earlier one-off smoke test that had
	 * registered only the one episode it was hand-fed. Reconciled, never wiped: rows are
	 * matched by the episode's own file path (its natural key), so an unchanged episode is left alone
	 * with its history, a changed packet archives the old row and inserts the new, a hand-edited row is
	 * kept, and an episode Plex no longer lists is archived.
	 *
	 * There is no season-level facts panel to populate - Plex doesn't put anything up on a season
	 * page itself, it's the TV that toggles to display a selected episode's metadata as you
	 * select each; confirmed directly against the local Plex db: the season's
	 * own metadata_item has empty content_rating/duration and no genre/director/writer/star
	 * taggings at all. Real per-episode facts - director(tag_type 4)/writer(5)/star(6, the full
	 * list - no cap)/content_rating/duration - DO exist one level
	 * down, on each episode's own metadata_item (metadata_type=4). Genre never exists below show level in Plex's own model, so it's not attempted here -
	 * that's what view_program.php's own facts panel already covers, one level up.
	 *
	 * Each episode's full packet (title/summary/air_date/director/writer/star/content_rating/
	 * duration) is JSON-encoded into the xref row's `data` column (via the 'edit' param key -
	 * LibertyContent::verify() only maps content_store['data'] from $pParamHash['edit'], not a
	 * literal 'data' key, a non-obvious gotcha worth remembering when storing anything this way),
	 * so a single
	 * already-loaded xref row carries everything view_season.php needs to show when that episode
	 * is selected - no per-episode page/request needed, matching Plex's own smart-TV pattern of
	 * a live highlight-swaps-the-detail-panel interaction rather than navigating away.
	 *
	 * @param bool $pDataOnly  a data reload of an already-loaded season (what the credits tool does): the Plex text and
	 *                         tags are refreshed, but an episode already registered keeps its thumbnail, resolution and
	 *                         audio untouched and nothing is fetched from Plex over HTTP - so a thumbnail replaced by hand
	 *                         survives, and a reload is a database read, not a download.
	 * @return array Summary of what was found/stored, for the calling page's result display.
	 */
	public function reloadPlexEpisodes( bool $pDataOnly = false ): array {
		global $gBitSystem;
		$summary = [ 'matched' => false, 'items' => [] ];

		$plexMatch = $this->matchPlexSeasonMetadataItem();
		if( !$plexMatch ) {
			// No Plex/TVDB match at all (e.g. a manually-curated documentary series) - fall back
			// to registering every real episode file already sitting in the season's own folder,
			// rather than leaving the season stuck at the single seed episode registerFromDisk()
			// planted. Without this, any non-catalogued show silently shows only its first episode.
			return $this->registerEpisodesFromFilesystem( $pDataOnly );
		}
		$plexDb = $plexMatch['db'];
		$seasonMetadataItemId = $plexMatch['id'];
		$root = $plexMatch['root'];

		$realRoot = realpath( $root );
		if( empty( $realRoot ) ) {
			return $summary;
		}
		$realRoot = rtrim( $realRoot, '/' ).'/';

		$plexToken = $gBitSystem->getConfig( 'fisheye_plex_token', '' );
		$imagesDir = $this->getImageStorageBranchPath();
		if( !empty( $plexToken ) ) {
			KernelTools::mkdir_p( $imagesDir );
		}

		$stmt = $plexDb->prepare(
			"SELECT mi.id, mi.\"index\", mi.title, mi.summary, mi.originally_available_at,
			        mi.content_rating, mi.duration, mp.file
			 FROM metadata_items mi
			 JOIN media_items mi2 ON mi2.metadata_item_id = mi.id
			 JOIN media_parts mp ON mp.media_item_id = mi2.id
			 WHERE mi.parent_id = ? AND mi.metadata_type = 4
			 ORDER BY mi.\"index\""
		);
		$stmt->execute( [ $seasonMetadataItemId ] );
		$episodeRows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
		if( empty( $episodeRows ) ) {
			return $summary;
		}
		$summary['matched'] = true;

		// Reconciled, never wiped: an episode row keeps its history (entry/last_update/end_date), a hand
		// edit is left alone, a changed packet archives the old row, an episode Plex no longer lists is
		// archived - see FisheyeMediaTrait/LibertyXref::reconcileItem(). Rows are collected, then
		// reconciled once (keyed by the episode's own file path).
		$wantedEpisodes = [];
		// Data-only reload: what is already stored for an episode that Plex does not supply (its thumbnail, the
		// ffprobe resolution/audio) is kept, and no thumbnail is fetched or overwritten - a thumbnail replaced by hand
		// stays. Only a new episode takes the full path below.
		$existingEpisodes = $pDataOnly ? $this->liveEpisodeData() : [];

		foreach( $episodeRows as $row ) {
			if( !str_starts_with( $row['file'], $realRoot ) || !is_file( $row['file'] ) ) {
				continue;
			}
			$relativePath = substr( $row['file'], strlen( $realRoot ) );
			if( !str_starts_with( $relativePath, $plexMatch['folder'] ) ) {
				continue;
			}

			$episodeData = [
				'title'    => $row['title'],
				'summary'  => $row['summary'],
				'air_date' => !empty( $row['originally_available_at'] ) ? gmdate( 'Y-m-d', (int)$row['originally_available_at'] ) : null,
			];
			// director/writer/star, and narrator (an actor tagged with a narrator role - they stay in the cast too).
			foreach( FisheyeCredits::plexCredits( $plexDb, (int)$row['id'] ) as $tagItem => $values ) {
				if( $values ) {
					$episodeData[$tagItem] = $values;
				}
			}
			if( !empty( $row['content_rating'] ) ) {
				$episodeData['content_rating'] = preg_replace( '#^[a-z]{2}/#i', '', $row['content_rating'] );
			}
			// Plex gave this episode no people: TheTVDB's, from the show's cache file (no API call here), so a reload never wipes them.
			$this->tvdbFill( $episodeData, (int)$row['index'] );
			if( $pDataOnly && isset( $existingEpisodes[$relativePath] ) ) {
				$kept = $existingEpisodes[$relativePath];
				if( !empty( $row['duration'] ) ) {
					$episodeData['duration'] = (int)$row['duration'];
				} elseif( isset( $kept['duration'] ) ) {
					$episodeData['duration'] = $kept['duration'];
				}
				foreach( [ 'resolution', 'audio', 'thumb' ] as $keptKey ) {
					if( isset( $kept[$keptKey] ) ) {
						$episodeData[$keptKey] = $kept[$keptKey];
					}
				}
				$wantedEpisodes[] = [ 'key' => $relativePath, 'xkey_ext' => $relativePath, 'xorder' => (int)$row['index'], 'data' => $episodeData ];
				$summary['items'][] = "S{$row['index']}: {$row['title']}";
				continue;
			}
			if( !empty( $row['duration'] ) ) {
				$episodeData['duration'] = (int)$row['duration'];
			} else {
				// Plex itself sometimes has no duration for a real, matched episode - straight
				// from the file's own container via ffprobe instead, same fallback the no-Plex
				// path uses (registerEpisodesFromFilesystem()).
				$durationMs = \Bitweaver\Liberty\mime_film_get_duration_ms( $row['file'] );
				if( $durationMs !== null ) {
					$episodeData['duration'] = $durationMs;
				}
			}
			// Plex has no resolution/audio-layout fields of its own worth trusting here either -
			// straight from the file's own container via ffprobe, same as the no-Plex path.
			$qualityInfo = \Bitweaver\Liberty\mime_film_get_quality_info( $row['file'] );
			if( $qualityInfo['resolution'] !== null ) {
				$episodeData['resolution'] = $qualityInfo['resolution'];
			}
			if( $qualityInfo['audio'] !== null ) {
				$episodeData['audio'] = $qualityInfo['audio'];
			}

			// this episode's own Plex-generated screenshot ("thumb") - a real per-episode still,
			// distinct from the season-level poster/backdrop alternates reloadPlexImages() fetches.
			// Confirmed live: each episode's own metadata carries a `thumb="..."`
			// attribute (a lowercase-only match so it can't collide with `parentThumb`/
			// `grandparentThumb` on the same element). Stored in this season's own
			// storage/attachments/<branch>/, same as its other alternates, named by episode index
			// rather than xorder/basename since a season has no single file of its own to derive a
			// name from.
			if( !empty( $plexToken ) ) {
				$episodeXml = @file_get_contents( "http://localhost:32400/library/metadata/{$row['id']}?X-Plex-Token=".urlencode( $plexToken ) );
				if( $episodeXml !== false && preg_match( '#\bthumb="([^"]+)"#', $episodeXml, $m ) ) {
					$thumbPath = html_entity_decode( $m[1] );
					$thumbUrl = "http://localhost:32400$thumbPath?X-Plex-Token=".urlencode( $plexToken );
					$imageData = @file_get_contents( $thumbUrl );
					if( !empty( $imageData ) ) {
						$tmpFile = tempnam( sys_get_temp_dir(), 'fisheye_ep_thumb_' );
						file_put_contents( $tmpFile, $imageData );
						$fileName = $this->getTitle().' - episode-'.(int)$row['index'].'.jpg';
						if( self::resizeImageFile( $tmpFile, $imagesDir.$fileName, 400 ) ) {
							$episodeData['thumb'] = $fileName;
						}
						@unlink( $tmpFile );
					}
				}
			}
			if( empty( $episodeData['thumb'] ) ) {
				// Plex had no token configured, no thumb attribute for this specific episode, or
				// the HTTP fetch itself failed - same local frame-grab fallback the no-Plex path
				// uses (registerEpisodesFromFilesystem()), rather than leaving this one episode
				// with no thumbnail at all just because Plex's own half didn't come through.
				// Named after the episode's own file (not the index-based name the Plex branch
				// above uses) so "does a fallback thumb already exist" is a simple, stable check
				// independent of Plex's own naming.
				\Bitweaver\KernelTools::mkdir_p( $imagesDir );
				$fallbackFileName = pathinfo( $row['file'], PATHINFO_FILENAME ).'.jpg';
				if( is_file( $imagesDir.$fallbackFileName ) ) {
					$episodeData['thumb'] = $fallbackFileName;
				} else {
					$tmpFile = tempnam( sys_get_temp_dir(), 'fisheye_ep_thumb_' );
					if( \Bitweaver\Liberty\mime_film_grab_video_frame( $row['file'], $tmpFile ) ) {
						if( self::resizeImageFile( $tmpFile, $imagesDir.$fallbackFileName, 400 ) ) {
							$episodeData['thumb'] = $fallbackFileName;
						}
					}
					@unlink( $tmpFile );
				}
			}

			$wantedEpisodes[] = [
				'key'      => $relativePath,
				'xkey_ext' => $relativePath,
				'xorder'   => (int)$row['index'],
				'data'     => $episodeData,
			];
			$summary['items'][] = "S{$row['index']}: {$row['title']}";
		}
		$summary['items'][] = self::describeCounts( 'episodes', $this->reconcileXrefItem( 'episode', $wantedEpisodes, 'xkey_ext' ) );
		foreach( $this->deriveCreditDirectory() as $role => $roleCounts ) {
			$summary['items'][] = self::describeCounts( "credits ($role)", $roleCounts );
		}

		return $summary;
	}

	/** The content id of the program (show) this season sits in, or 0. */
	private function tvdbProgramId(): int {
		$parents = $this->getParentGalleries();
		if( !$parents ) {
			return 0;
		}
		$first = current( $parents );
		return (int)( $first['content_id'] ?? array_key_first( $parents ) );
	}

	/** This season's number from its title ("Show - Season 3" is 3, "Show - Specials" is 0); null for anything else. */
	private function tvdbSeasonNumber(): ?int {
		$title = (string)$this->getTitle();
		if( preg_match( '/\bSeason\s+(\d+)\s*$/i', $title, $m ) ) {
			return (int)$m[1];
		}
		return preg_match( '/\bSpecials?\s*$/i', $title ) ? 0 : null;
	}

	/** Fill an episode's data packet with TheTVDB's people when Plex gave it none (reads the show's cache file; never the API). */
	private function tvdbFill( array &$pData, int $pEpisode ): void {
		if( !empty( $pData['director'] ) || !empty( $pData['writer'] ) || !empty( $pData['star'] ) ) {
			return;
		}
		if( !( $programId = $this->tvdbProgramId() ) ) {
			return;
		}
		if( $credits = FisheyeTvdb::creditsFor( $programId, $this->tvdbSeasonNumber(), $pEpisode ) ) {
			$pData = array_merge( $pData, $credits, [ 'credits_source' => 'thetvdb' ] );
		}
	}

	/** "SxE" keys of this season's live episodes that have no director, writer or star - what TheTVDB is asked for. */
	public function episodesWithoutPeople(): array {
		$season = $this->tvdbSeasonNumber();
		$keys = [];
		foreach( $this->liveEpisodeRows() as $row ) {
			$data = json_decode( (string)$row['data'], true ) ?: [];
			if( $season !== null && empty( $data['director'] ) && empty( $data['writer'] ) && empty( $data['star'] ) ) {
				$keys[] = $season.'x'.$this->episodeNumberOf( $row );
			}
		}
		return $keys;
	}

	/** @return list<array{xkey_ext:string,xorder:int,data:string}> */
	private function liveEpisodeRows(): array {
		global $gBitDb;
		return $gBitDb->getAll(
			"SELECT `xkey_ext`, `xorder`, `data` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'episode' AND `end_date` IS NULL ORDER BY `xorder`",
			[ $this->mContentId ]
		) ?: [];
	}

	private function episodeNumberOf( array $pRow ): int {
		return preg_match( '/S\d+E(\d+)/i', (string)$pRow['xkey_ext'], $m ) ? (int)$m[1] : (int)$pRow['xorder'];
	}

	/**
	 * Fill this season's episodes that have no people from the show's TheTVDB cache and rebuild the season's credits - for a show already loaded,
	 * without a Plex reload. Episodes keep every other field; only those with no director, writer or star are touched.
	 *
	 * @return array{episodes:int, filled:int, credits:array}
	 */
	public function fillCreditsFromCache(): array {
		$result = [ 'episodes' => 0, 'filled' => 0, 'credits' => [] ];
		$wanted = [];
		foreach( $this->liveEpisodeRows() as $row ) {
			$data = json_decode( (string)$row['data'], true ) ?: [];
			$before = $data;
			$this->tvdbFill( $data, $this->episodeNumberOf( $row ) );
			$wanted[] = [ 'key' => $row['xkey_ext'], 'xkey_ext' => $row['xkey_ext'], 'xorder' => (int)$row['xorder'], 'data' => $data ];
			$result['episodes']++;
			$result['filled'] += $data !== $before ? 1 : 0;
		}
		if( $result['filled'] ) {
			$this->reconcileXrefItem( 'episode', $wanted, 'xkey_ext' );
			$result['credits'] = $this->deriveCreditDirectory();
		}
		return $result;
	}

	/**
	 * Rebuild this season's credits directory from its episodes: one row per person per role (director/
	 * writer/star; cast only when they have a real presence in the season - see below) - xkey_ext = the name, data = {"episodes":[the episode numbers they appear in]} - so a
	 * person can be linked to a contact once per season, and a contact's own page can find every season
	 * it is on. Episodes are xref rows, not content items, so the season is the lowest level that can carry
	 * a person's xref. Derived from the live episode rows (the source of truth stays the episode JSON, as
	 * Plex wrote it), reconciled never wiped (history kept, a hand edit left alone), and a person already
	 * linked to a contact on any film/program/season has that link carried onto the new row, so a name is
	 * resolved once everywhere. Episode numbers come from the SnnEnn in the episode's file path (every
	 * number of a double episode), falling back to its position.
	 *
	 * @return array<string,array<string,int>>  role => reconcile counts
	 */
	public function deriveCreditDirectory(): array {
		global $gBitDb;
		$episodes = $gBitDb->getAll(
			"SELECT `xkey_ext`, `xorder`, `data` FROM `".BIT_DB_PREFIX."liberty_xref`
			 WHERE `content_id` = ? AND `item` = 'episode' AND `end_date` IS NULL ORDER BY `xorder`",
			[ $this->mContentId ]
		) ?: [];
		$byRole = [];
		$charEpisodes = [];
		$seen = 0;
		foreach( $episodes as $episode ) {
			$data = !empty( $episode['data'] ) ? json_decode( $episode['data'], true ) : [];
			$numbers = [ (int)$episode['xorder'] ];
			if( preg_match( '/S\d+E([\dE&-]+)/i', (string)$episode['xkey_ext'], $m ) && preg_match_all( '/\d+/', $m[1], $nums ) ) {
				$numbers = array_map( 'intval', $nums[0] );
			}
			foreach( FisheyeCredits::ITEMS as $role ) {
				foreach( array_values( (array)( $data[$role] ?? [] ) ) as $position => $name ) {
					$name = trim( preg_replace( '/\s+/u', ' ', (string)$name ) );
					if( $name === '' ) {
						continue;
					}
					$entry = &$byRole[$role][$name];
					$entry ??= [ 'first' => $seen++, 'episodes' => [], 'billed' => $position, 'roles' => [] ];
					if( $role === 'star' && !empty( $data['roles'][$name] ) ) {
						// tidied here too: an episode read from before the tidy still holds the raw "Sgt. Hanlon"
						$roleText = FisheyeCredits::tidyRoleName( (string)$data['roles'][$name] );
						$entry['roles'][$roleText] = $roleText;
						$charEpisodes[$name][$roleText] = array_merge( $charEpisodes[$name][$roleText] ?? [], $numbers );
					}
					$entry['episodes'] = array_merge( $entry['episodes'], $numbers );
					// Plex lists the cast in billing order, so the position in an episode's list is the billing.
					$entry['billed'] = min( $entry['billed'], $position );
					unset( $entry );
				}
			}
		}
		$allNames = [];
		foreach( $byRole as $rolePeople ) {
			$allNames = array_merge( $allNames, array_keys( $rolePeople ) );
		}
		$known = $allNames ? FisheyeCredits::linkedContactsByName( $allNames ) : [];
		// A season's cast directory keeps the people with a real presence in the season - in at least
		// `min` of its episodes, or among the top `billed` names of an episode's cast list - and anyone
		// already linked to a contact. A one-episode guest stays in the episode's own cast list (its JSON)
		// but is not promoted to a season row. Directors and writers are always kept: each usually works
		// a single episode. Both numbers are site settings (Media Library Settings).
		global $gBitSystem;
		$minEpisodes = max( 1, (int)( $gBitSystem->getConfig( 'fisheyemedia_credit_min_episodes', 2 ) ?: 2 ) );
		$billedTop   = max( 0, (int)( $gBitSystem->getConfig( 'fisheyemedia_credit_billed_top', 6 ) ?: 6 ) );
		$counts = [];
		foreach( FisheyeCredits::ITEMS as $role ) {
			$people = $byRole[$role] ?? [];
			if( $role === 'star' ) {
				$people = array_filter( $people, fn( $person, $name ) =>
					count( array_unique( $person['episodes'] ) ) >= $minEpisodes
					|| $person['billed'] < $billedTop
					|| isset( $known[mb_strtolower( $name )] ),
					ARRAY_FILTER_USE_BOTH );
			}
			if( $role === 'star' ) {
				$keptStars = array_keys( $people );
			}
			// Most episodes first, then order of first appearance - the billing a cast list wants.
			uasort( $people, fn( $a, $b ) => [ count( $b['episodes'] ), $a['first'] ] <=> [ count( $a['episodes'] ), $b['first'] ] );
			$wanted = [];
			foreach( $people as $name => $person ) {
				$episodeList = array_values( array_unique( $person['episodes'] ) );
				sort( $episodeList );
				$row = [ 'key' => $name, 'xkey_ext' => $name, 'xorder' => count( $wanted ) + 1, 'data' => [ 'episodes' => $episodeList ] ];
				if( $role === 'star' && $person['roles'] ) {
					// The characters played in this season, in order of first appearance.
					$row['data']['roles'] = array_slice( array_values( $person['roles'] ), 0, 8 );
				}
				if( $link = ( $known[mb_strtolower( $name )] ?? null ) ) {
					$row['xref'] = $link['xref'];
					$row['xkey'] = $link['xkey'];
				}
				$wanted[] = $row;
			}
			$counts[$role] = $this->reconcileXrefItem( $role, $wanted, 'xkey_ext', false, true );
		}
		// The characters: one row per (actor, role) the season's kept cast played, with the episodes - the actor named in the data, the pair
		// as the key, so an actor playing two parts in a season has two rows. Linked to the character's contact by the characters pass.
		$characters = [];
		foreach( $keptStars ?? [] as $name ) {
			foreach( $charEpisodes[$name] ?? [] as $roleText => $numbers ) {
				$episodeList = array_values( array_unique( $numbers ) );
				sort( $episodeList );
				$characters[] = [ 'key' => $name.'|'.$roleText, 'xkey_ext' => $roleText, 'xorder' => count( $characters ) + 1,
					'data' => [ 'actor' => $name, 'k' => $name.'|'.$roleText, 'episodes' => $episodeList ] ];
			}
		}
		$counts[FisheyeCredits::CHARACTER_ITEM] = $this->reconcileXrefItem( FisheyeCredits::CHARACTER_ITEM, $characters, [ FisheyeCredits::class, 'characterKey' ], false, true );
		return $counts;
	}

	/**
	 * This season's live episode rows' data packets, by episode file path - what a data-only reload keeps for an
	 * episode that is already registered.
	 *
	 * @return array<string,array>
	 */
	private function liveEpisodeData(): array {
		global $gBitDb;
		$ret = [];
		foreach( $gBitDb->getAll(
			"SELECT `xkey_ext`, `data` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'episode' AND `end_date` IS NULL",
			[ $this->mContentId ]
		) ?: [] as $row ) {
			$ret[(string)$row['xkey_ext']] = !empty( $row['data'] ) ? ( json_decode( $row['data'], true ) ?: [] ) : [];
		}
		return $ret;
	}

	/** One summary line for a reconcile result, e.g. "episodes: 12 unchanged, 0 inserted, 0 archived". */
	private static function describeCounts( string $pLabel, array $pCounts ): string {
		return $pLabel.': '.implode( ', ', array_map( fn( $k, $n ) => "$n $k", array_keys( $pCounts ), $pCounts ) );
	}

	/**
	 * @return bool  always true - see FisheyeBase::canGrabVideoFrame()'s own docblock
	 */
	public function canGrabVideoFrame(): bool {
		return true;
	}

	/**
	 * Grab a frame from this season's own seed episode video and store it as a new 'image' xref -
	 * the engine behind fallbackFrameGrabImage()'s automatic no-Plex-images fallback below, and
	 * also exposed directly as the "Grab Thumbnail from Video" action on the Images tab
	 * (templates/xref/view_images_group.tpl's own group-tab override, edit_season.php's
	 * fGrabFrame handler) - a manual fallback for a season with no usable Plex images at all.
	 * The actual grab is FisheyeMediaTrait::grabVideoFrameIntoImageXref() - this just resolves which video file to
	 * grab from (its own seed episode).
	 *
	 * Always grabs a fresh one - does not check whether an image already exists, unlike the
	 * automatic fallback below (whose own caller does that check itself before calling this). A
	 * deliberate manual click is an "add one more" action, same as uploading via Add Image, not
	 * a "only if nothing else worked" one.
	 *
	 * @return string|null  the new xref row's xkey_ext, or null if there's no episode file to
	 *                       grab from (or on disk), or the grab/resize/store itself failed
	 */
	public function grabVideoFrameImage(): ?string {
		$root = $this->getImageStorageRoot();
		$this->loadXrefInfo();
		$episodeXref = $this->mXrefInfo ? $this->mXrefInfo->findRowByItem( 'episode' ) : null;
		if( empty( $root ) || !$episodeXref || empty( $episodeXref['xkey_ext'] ) ) {
			return null;
		}
		return $this->grabVideoFrameIntoImageXref( $root.$episodeXref['xkey_ext'] );
	}

	/**
	 * Shared fallback used at every exit point of reloadPlexImages() below - if nothing usable
	 * came back from Plex (no season match at all, no fisheye_plex_token configured, or a real
	 * match that simply has zero photos - a real Plex match with zero photos does happen), grab
	 * a frame from this season's own seed episode file instead of leaving the
	 * gallery grid with no thumbnail at all - see grabVideoFrameImage() above for the actual
	 * grab.
	 *
	 * A no-op once a real 'image' xref row already exists (checked directly against this
	 * season's own xref state, not $summary - several exit points push informational text
	 * into $summary['items'] that isn't an actual image, e.g. the "token not configured"
	 * message, so that array alone isn't a reliable signal), so it's safe to call
	 * unconditionally at every exit point rather than needing each call site to work out for
	 * itself whether the fallback is still needed.
	 *
	 * @param array $summary  reloadPlexImages()'s own summary array, appended to in place
	 */
	private function fallbackFrameGrabImage( array &$summary ): void {
		$this->loadXrefInfo();
		if( $this->mXrefInfo ) {
			foreach( $this->liveXrefs() as $xref ) {
				if( $xref['item'] === 'image' ) {
					return;
				}
			}
		}
		$relativePath = $this->grabVideoFrameImage();
		if( $relativePath ) {
			$summary['items'][] = "frame grab: $relativePath";
			$this->attachThumbnail( $this->getExtraImagePath( $relativePath ) );
		}
	}

	/**
	 * Fetch alternate poster/backdrop images from Plex for this season, same shape as
	 * FisheyeFilm::reloadPlexImages() (per-type idempotency, w342/w780 TMDB sizes, 5-per-type
	 * cap, xref-based storage in storage/attachments/<branch>/ - see that method's own docblock
	 * for the fuller reasoning, not repeated here). Differences specific to a season: matched via
	 * matchPlexSeasonMetadataItem() (no file of its own to match by directly), and the filename
	 * basename is this season's own title rather than a source file's, since a season has no
	 * single source file of its own (e.g. 'Example Show - Series 1').
	 *
	 * @return array Summary of what was found/stored, for the calling page's result display.
	 */
	public function reloadPlexImages(): array {
		global $gBitSystem;
		$summary = [ 'matched' => false, 'items' => [] ];

		$plexMatch = $this->matchPlexSeasonMetadataItem();
		if( !$plexMatch ) {
			$this->fallbackFrameGrabImage( $summary );
			return $summary;
		}
		$summary['matched'] = true;
		$metadataItemId = $plexMatch['id'];

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
			$this->fallbackFrameGrabImage( $summary );
			return $summary;
		}

		// Auto-pick the real thumbnail attachment (once only - a later manual promotion via
		// promoteImageToThumbnail() shouldn't be silently overwritten by a later reload) from
		// Plex's own currently-selected poster ('selected="1"' in the /posters listing) rather
		// than just grabbing whichever alternate happens to come first - that "selected" one is
		// Plex's (and, upstream, TheTVDB's) own curated choice, not just one candidate among
		// many. Its 'key' is often an internal 'metadata://...' reference with no directly
		// fetchable URL of its own, but the same <Photo> element's 'thumb' attribute always is -
		// either a Plex.tv proxy URL (external providers) or a local '/library/metadata/.../file?
		// url=...' path (Plex's own cached copy), both fetchable through Plex's local API once
		// resolved. Found live: without this, only the undifferentiated alternates list was
		// ever fetched, never Plex's own actual pick.
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
				// Plex's own newer TV agent (tv.plex.agents.series) serves its bundled art via a
				// local proxy path ("/library/metadata/.../file?url=metadata://...") rather than a
				// direct https:// URL - no remote size variant to swap in for these, fetch as-is
				// through the local API instead
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

		$this->fallbackFrameGrabImage( $summary );
		return $summary;
	}
}

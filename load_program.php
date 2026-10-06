<?php
/**
 * TV equivalent of load_film.php, two levels deep to match the real show->season->episode
 * structure (see FisheyeSeason::registerFromDisk()'s own docblock) rather than films' flatter
 * folder->file model:
 *
 * - No ?show= yet: lists real show folders (scanned across both A-M/N-Z storage roots - see
 *   mime_film_get_tvshow_storage_root()) not yet registered as a FisheyeProgram. Picking one
 *   registers the show (find-or-create, cheap - no attachment, just a gallery record + Plex
 *   metadata backfill) and moves to season level.
 * - ?show=Name: the show is guaranteed registered by this point (registerFromDisk() is
 *   idempotent). Lists real season folders under that show not yet registered as a FisheyeSeason,
 *   as a plain list rather than a big table - a show typically has a handful of seasons, nothing
 *   like the scale load_film.php's flat file list deals with. Each selected season gets created,
 *   seeded with one real episode file, and immediately synced against Plex for its full episode
 *   list (FisheyeSeason::registerFromDisk() does all three) - there's no separate "fetch episodes
 *   later" step the way film's Plex-image fetch is optional, since a season with no episodes at
 *   all isn't useful.
 *
 * Scoped two ways, same split as load_film.php ended up with after the same mistake there:
 * - ?gallery_id=N (program_gallery_icons_inc.tpl's "Load Seasons" icon on an already-registered
 *   show's own page) - N gets *loaded*, not re-registered, so revisiting an existing show doesn't
 *   waste a Plex lookup every time. N == $topGalleryId ("TV Shows" itself, the pool gallery every
 *   show lives inside, resolved by title via FisheyeGallery::getTopGalleryId()) falls through to
 *   the top-level discovery view instead of
 *   trying to register "TV Shows" as if it were a show - found the hard way 2026-09-03 when "TV
 *   Shows" got set to program_grid too (same as "Films" did) and its own icon had no such
 *   sentinel, registering a bogus show literally titled "TV Shows".
 * - ?show=Name (the top-level discovery list, below) - only a folder name is known at that point,
 *   no content_id yet, so this path actually calls registerFromDisk() (idempotent either way).
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

require_once dirname( __DIR__ ).'/liberty/plugins/mime.film.php';

const LOAD_PROGRAM_LIMIT = 20;
const LOAD_PROGRAM_EXTENSIONS = [ 'mkv', 'mp4', 'm4v', 'avi' ];
// Sentinel season-folder value meaning "no season subfolder at all - episode files sit directly
// in the show folder" - a single-episode show with no Season 01/ subfolder is a real, if
// uncommon, case. '.' is unambiguous (scandir() already skips it as a real entry) and
// FisheyeSeason::registerFromDisk() treats it as "season dir == show dir", titling it
// "<show> - Season 1".
const LOAD_PROGRAM_FLAT_SEASON = '.';
// Resolved by title, not hardcoded - see FisheyeGallery::getTopGalleryId()'s own docblock for
// why (was a literal, install-order-dependent "2" here until 2026-09-03).
$topGalleryId = FisheyeGallery::getTopGalleryId( 'TV Shows' );

$galleryIdParam = (int)( $_REQUEST['gallery_id'] ?? 0 );
// See load_film.php's own comment on this same decode - detoxify() HTML-escapes every $_REQUEST
// value, which breaks a raw filesystem lookup like this one for any show name containing &, <, or >.
$showParam = htmlspecialchars_decode( trim( (string)( $_REQUEST['show'] ?? '' ) ), ENT_NOQUOTES );

$scopeShow = null;      // ['content_id'=>, 'title'=>] once a show is known/registered
$showResult = null;     // registerFromDisk() result, only shown the first time a show is created
$seasonResult = null;
$candidates = [];       // either show-folder names (top level) or season-folder names (scoped)
$reloadCandidates = []; // already-registered seasons with more files on disk than registered
$episodeReloadResult = null; // fReloadSeasonEpisodes result, only shown right after that action

if( $galleryIdParam && $galleryIdParam !== $topGalleryId ) {
	$program = new FisheyeProgram( $galleryIdParam );
	$program->load();
	if( $program->isValid() ) {
		$scopeShow = [ 'content_id' => $program->mContentId, 'gallery_id' => $program->mGalleryId, 'title' => $program->getTitle() ];
	}
} elseif( $showParam !== '' ) {
	$programRow = FisheyeProgram::registerFromDisk( $showParam );
	if( !empty( $programRow['error'] ) ) {
		$showResult = [ 'error' => $programRow['error'] ];
	} else {
		$showContentId = $programRow['already'] ?? $programRow['created'];
		$scopeShow = [ 'content_id' => $showContentId, 'gallery_id' => $programRow['gallery_id'], 'title' => $showParam ];
		if( !empty( $programRow['created'] ) ) {
			$showResult = $programRow;
		}
	}
}

if( $scopeShow === null ) {

	// Top level: real show folders across both storage roots, not yet a FisheyeProgram. Same
	// physical folder can only resolve from one root or the other in practice (each root's own
	// TV Shows/ only ever holds the letters it owns), but dedupe by real path anyway in case a
	// site's am/nz config happens to coincide (some installs point both at the same single root).
	$seenRoots = [];
	foreach( [ 'fisheye_tvshow_storage_root_am', 'fisheye_tvshow_storage_root_nz' ] as $configKey ) {
		$root = $gBitSystem->getConfig( $configKey, '' );
		if( empty( $root ) ) {
			continue;
		}
		$showsDir = rtrim( $root, '/' ).'/TV Shows/';
		$realShowsDir = realpath( $showsDir );
		if( empty( $realShowsDir ) || isset( $seenRoots[$realShowsDir] ) ) {
			continue;
		}
		$seenRoots[$realShowsDir] = true;

		$entries = scandir( $showsDir );
		natsort( $entries );
		foreach( $entries as $entry ) {
			if( count( $candidates ) >= LOAD_PROGRAM_LIMIT ) {
				break 2;
			}
			if( str_starts_with( $entry, '.' ) || !is_dir( $showsDir.$entry ) ) {
				continue;
			}
			$existingContentId = $gBitDb->getOne(
				"SELECT content_id FROM liberty_content WHERE content_type_guid = 'fisheyeprogram' AND title = ?",
				[ $entry ]
			);
			if( $existingContentId ) {
				continue;
			}
			$candidates[] = $entry;
		}
	}

} else {

	$showTitle = $scopeShow['title'];
	$showContentId = $scopeShow['content_id'];

	if( !empty( $_REQUEST['fImportSeasons'] ) ) {
		$seasonResult = [ 'created' => [], 'errors' => [] ];
		foreach( (array)( $_REQUEST['selected'] ?? [] ) as $seasonFolder ) {
			// Same detoxify() decode as $showParam above - these came from checkbox values.
			$seasonFolder = htmlspecialchars_decode( trim( (string)$seasonFolder ), ENT_NOQUOTES );
			if( $seasonFolder === '' ) {
				continue;
			}
			$row = FisheyeSeason::registerFromDisk( $showTitle, $seasonFolder, $showContentId );
			if( !empty( $row['error'] ) ) {
				$seasonResult['errors'][] = [ 'folder' => $seasonFolder, 'error' => $row['error'] ];
			} else {
				$seasonResult['created'][] = [ 'folder' => $seasonFolder, 'content_id' => $row['created'], 'episodes' => $row['episodes'], 'images' => $row['images'] ];
			}
		}
	} elseif( !empty( $_REQUEST['fReloadSeasonEpisodes'] ) ) {
		// The show-page shortcut for a season that's picked up more episode files than are
		// currently registered (see the $reloadCandidates detection below) - lets the common
		// single-season show get its episode list refreshed without ever visiting
		// edit_season.php, which is where this same action (FisheyeSeason::reloadPlexEpisodes())
		// otherwise lives.
		$reloadSeasonId = (int)( $_REQUEST['season_content_id'] ?? 0 );
		if( $reloadSeasonId ) {
			$reloadSeason = new FisheyeSeason( null, $reloadSeasonId );
			$reloadSeason->load();
			if( $reloadSeason->isValid() ) {
				$episodeReloadResult = [ 'title' => $reloadSeason->getTitle(), 'episodes' => $reloadSeason->reloadPlexEpisodes( true ) ];
			}
		}
	}

	$root = \Bitweaver\Liberty\mime_film_get_tvshow_storage_root( $showTitle );
	$showDir = $root.'TV Shows/'.$showTitle.'/';
	if( !empty( $root ) && is_dir( $showDir ) ) {
		$entries = scandir( $showDir );
		natsort( $entries );
		foreach( $entries as $entry ) {
			if( count( $candidates ) >= LOAD_PROGRAM_LIMIT ) {
				break;
			}
			if( str_starts_with( $entry, '.' ) || !is_dir( $showDir.$entry ) ) {
				continue;
			}
			$seasonTitle = $showTitle.' - '.$entry;
			// A content record existing isn't enough on its own to call this season "already
			// loaded" - it also needs to actually be linked into this show's own gallery. A
			// season can exist unlinked (e.g. registerFromDisk() ran once but the gallery link
			// step never completed) and would otherwise be permanently hidden from this picker
			// while still missing from view_program.php's own season grid.
			$existingContentId = $gBitDb->getOne(
				"SELECT lc.content_id FROM liberty_content lc
				 INNER JOIN fisheye_gallery_image_map m ON m.item_content_id = lc.content_id AND m.gallery_content_id = ?
				 WHERE lc.content_type_guid = 'fisheyeseason' AND lc.title = ?",
				[ $showContentId, $seasonTitle ]
			);
			if( $existingContentId ) {
				continue;
			}
			$candidates[] = $entry;
		}

		// No season subfolders at all - check whether episode files sit directly in the show
		// folder instead (a flat single-season show) before giving up.
		if( empty( $candidates ) ) {
			$flatSeasonTitle = $showTitle.' - Season 1';
			$existingFlatContentId = $gBitDb->getOne(
				"SELECT content_id FROM liberty_content WHERE content_type_guid = 'fisheyeseason' AND title = ?",
				[ $flatSeasonTitle ]
			);
			if( !$existingFlatContentId ) {
				foreach( $entries as $entry ) {
					if( is_file( $showDir.$entry ) && in_array( strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) ), LOAD_PROGRAM_EXTENSIONS, true ) ) {
						$candidates[] = LOAD_PROGRAM_FLAT_SEASON;
						break;
					}
				}
			}
		}
	}

	// Seasons already registered under this show whose own folder now has more real episode
	// files than are currently registered - most often a season that was first registered with
	// no Plex/TVDB match (FisheyeSeason::registerEpisodesFromFilesystem()'s fallback only ever
	// grabs what existed at that moment), but just as easily a genuinely new episode dropped into
	// an existing, already-matched season's folder later. Offered here rather than only on
	// edit_season.php's own 'Load Episodes' button, since most shows here are single-season and
	// this page is already the one place view_program.php's icon bar links to for show-level
	// season work.
	$registeredSeasonIds = $gBitDb->getCol(
		"SELECT lc.content_id FROM liberty_content lc
		 INNER JOIN fisheye_gallery_image_map m ON m.item_content_id = lc.content_id AND m.gallery_content_id = ?
		 WHERE lc.content_type_guid = 'fisheyeseason'",
		[ $showContentId ]
	);
	foreach( $registeredSeasonIds as $seasonContentId ) {
		$existingSeason = new FisheyeSeason( null, (int)$seasonContentId );
		$existingSeason->load();
		if( !$existingSeason->isValid() ) {
			continue;
		}
		$onDisk = $existingSeason->getEpisodeFileCountOnDisk();
		$registeredCount = $existingSeason->countRegisteredEpisodes();
		if( $onDisk !== null && $onDisk > $registeredCount ) {
			$reloadCandidates[] = [
				'content_id' => $existingSeason->mContentId,
				'title'      => $existingSeason->getTitle(),
				'on_disk'    => $onDisk,
				'registered' => $registeredCount,
			];
		}
	}
}

// The page heading's own "TV Shows" text doubles as a link back to the real gallery, so the
// list of what was just loaded stays reachable from the gallery view too -
// getDisplayUrlFromHash() just needs the id, no need to load the whole
// object for a URL.
$topGalleryUrlHash = [ 'gallery_id' => $topGalleryId ];
$gBitSmarty->assign( 'topGalleryUrl', FisheyeGallery::getDisplayUrlFromHash( $topGalleryUrlHash ) );

$gBitSmarty->assign( 'candidateLimit', LOAD_PROGRAM_LIMIT );
$gBitSmarty->assign( 'candidates', $candidates );
$gBitSmarty->assign( 'reloadCandidates', $reloadCandidates );
$gBitSmarty->assign( 'episodeReloadResult', $episodeReloadResult );
$gBitSmarty->assign( 'scopeShow', $scopeShow );
$gBitSmarty->assign( 'showResult', $showResult );
$gBitSmarty->assign( 'seasonResult', $seasonResult );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_program.tpl', KernelTools::tra( 'Load TV Shows' ), [ 'display_mode' => 'edit' ] );

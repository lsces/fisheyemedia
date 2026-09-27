<?php
/**
 * Lists films found under the storage root's Films/ folder (or one of its real subfolders, e.g.
 * Films/<Collection Name>/, standing in for a collection) that aren't registered yet, so an admin can
 * pick a batch to import rather than the whole folder at once - deliberately capped
 * (LOAD_FILM_LIMIT below), not a "scan and import everything" tool. Registration itself
 * (FisheyeFilm::registerFromDisk() - store/gallery-link/Plex-backfill) is cheap per film; the one
 * genuinely expensive step, thumbnail generation, isn't triggered here at all - it happens lazily
 * per film via mime_film_get_thumbnail_url() whenever that film's own page is first viewed, not
 * as part of this bulk import - a batch import followed by a page that displays many
 * freshly-imported films together could still trigger a burst of synchronous ffmpeg calls at
 * that later point; not addressed by this page.
 *
 * Folder scoping: a collection isn't modelled as anything more formal than
 * "a real subfolder under Films/ whose name matches a gallery's own title" - see the
 * $topGalleryId/gallery_id/folder handling below for the three ways this page ends up
 * scoped to one.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb, $gLibertySystem;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

require_once dirname( __DIR__ ).'/liberty/plugins/mime.film.php';
if( !$gLibertySystem->isPluginActive( 'mimefilm' ) ) {
	$gLibertySystem->setActivePlugin( 'mimefilm' );
}

const LOAD_FILM_LIMIT = 20;
const LOAD_FILM_EXTENSIONS = [ 'mkv', 'mp4', 'm4v', 'avi' ];
// Resolved by title, not hardcoded - see FisheyeGallery::getTopGalleryId()'s own docblock for
// why (was a literal, install-order-dependent "1" here until 2026-09-03).
$topGalleryId = FisheyeGallery::getTopGalleryId( 'Films' );

$root = \Bitweaver\Liberty\mime_film_get_storage_root();
$filmsDir = $root.'Films/';

// Which folder to scan and which gallery to link into are deliberately independent - a gallery's
// own title is NOT assumed to match a folder name (that was tried, too rigid the moment a
// collection's folder doesn't happen to match its gallery title exactly). Instead:
// - ?gallery_id=N (N != top-level) - the "Load Films" icon on a collection gallery's own page
//   (film_grid's film_gallery_icons_inc.tpl) links here with its own gallery_id. That gallery is
//   the link target from here on, however the request got here - carried through as a hidden
//   field / query param regardless of which folder subsequently gets chosen.
// - ?folder=Name - which folder to actually scan, chosen explicitly from the picker below rather
//   than assumed. If no $scopeGallery is known, linking falls back to the old folder-name-matches-
//   gallery-title convention (the only option left when there's no explicit target).
// - neither yet, or gallery_id given but no folder yet - show the folder picker instead of
//   scanning anything; reloading with a folder in the link is what actually selects one.
$galleryIdParam = (int)( $_REQUEST['gallery_id'] ?? 0 );
// detoxify() HTML-escapes every $_REQUEST value on the way in (correct for values headed to
// HTML output) - this one is used for a raw filesystem lookup instead, so it needs decoding back
// first, otherwise any folder name containing &, <, or > silently fails to match on disk.
$folderParam = htmlspecialchars_decode( trim( (string)( $_REQUEST['folder'] ?? '' ) ), ENT_NOQUOTES );
$folderName = $folderParam !== '' ? $folderParam : null;

$scopeGallery = null;
if( $galleryIdParam && $galleryIdParam !== $topGalleryId ) {
	$scopeGallery = new FisheyeGallery( $galleryIdParam );
	$scopeGallery->load();
	if( !$scopeGallery->isValid() ) {
		$scopeGallery = null;
	}
}
$galleryTitle = $scopeGallery ? $scopeGallery->getTitle() : ( $folderName ?? 'Films' );

// A specific collection gallery was the entry point but no folder's been picked yet - show the
// picker only, don't guess/scan Films/ root as if it were this gallery's own content.
$needsFolderChoice = ( $scopeGallery !== null ) && ( $folderName === null );

$scanDir = $needsFolderChoice ? null : $filmsDir.( $folderName !== null ? $folderName.'/' : '' );
$scanRelativePrefix = 'Films/'.( $folderName !== null ? $folderName.'/' : '' );

// Real subfolders under Films/ - offered as a picker whenever a folder hasn't been chosen yet
// (true top level, or a collection gallery still needing one) - collections aren't nested, so
// this never applies once inside a chosen folder.
$subfolders = [];
if( $folderName === null && is_dir( $filmsDir ) ) {
	foreach( scandir( $filmsDir ) as $entry ) {
		if( str_starts_with( $entry, '.' ) || !is_dir( $filmsDir.$entry ) ) {
			continue;
		}
		$subfolders[] = $entry;
	}
	natsort( $subfolders );
}

$result = null;
if( !empty( $_REQUEST['fImport'] ) && empty( $gBitSystem->getConfig( 'fisheye_plex_token', '' ) ) ) {
	// registerFromDisk() -> reloadPlexMetadata() silently skips just the imdb/tmdb GUID lookup
	// when this is unset (genre/cast/rating/duration still come from Plex's local db, no token
	// needed there) - fine for a single film re-synced later via 'Reload Metadata', but a whole
	// batch importing "successfully" while silently missing imdb/tmdb links for every film isn't
	// something to only notice after the fact - a Plex reinstall can silently clear this token.
	// Stop the batch outright rather than import anything with it unset.
	$result = [ 'error' => KernelTools::tra( 'fisheye_plex_token is not configured - set it on the General Settings tab first (imdb/tmdb links would silently be skipped for every film in this batch otherwise).' ) ];
} elseif( !empty( $_REQUEST['fImport'] ) ) {
	$fetchImages = !empty( $_REQUEST['fetch_images'] );
	$batchStart = microtime( true );
	$result = [ 'imported' => [], 'already' => [], 'skipped' => [], 'errors' => [], 'fetch_images' => $fetchImages ];
	foreach( (array)( $_REQUEST['selected'] ?? [] ) as $relativePath ) {
		$relativePath = trim( (string)$relativePath );
		if( empty( $relativePath ) || !is_file( $root.$relativePath ) ) {
			continue;
		}
		// Skip rather than register a film with no Plex match at all - there'd be no way to tell
		// which ones haven't actually loaded metadata otherwise. E.g. Plex's own title changing
		// (a punctuation/spacing tweak) while the on-disk filename doesn't match anymore means the
		// automatic title-independent realpath match below never fires.
		// Registering it anyway used to just leave a metadata-less film sitting in the library
		// indistinguishable from a properly-loaded one; left un-imported instead, it stays in the
		// candidate list every re-scan until the mismatch is actually fixed (rename the file, or
		// fix Plex's own match) and a re-run picks it up. Checked here, before store(), rather than
		// inside registerFromDisk() itself - matching needs a real absolute path, which a
		// not-yet-registered file already has without needing a content_id first.
		if( !FisheyeFilm::matchPlexMetadataItemForPath( realpath( $root.$relativePath ) ?: '' ) ) {
			$result['skipped'][] = [ 'path' => $relativePath ];
			continue;
		}
		$filmStart = microtime( true );
		$row = FisheyeFilm::registerFromDisk( $relativePath, null, $fetchImages, $galleryTitle );
		$elapsed = round( microtime( true ) - $filmStart, 2 );
		if( !empty( $row['already'] ) ) {
			$result['already'][] = [ 'path' => $relativePath, 'content_id' => $row['already'] ];
		} elseif( !empty( $row['created'] ) ) {
			$result['imported'][] = [ 'path' => $relativePath, 'content_id' => $row['created'], 'plex' => $row['plex'], 'images' => $row['images'] ?? null, 'seconds' => $elapsed ];
		} else {
			$result['errors'][] = [ 'path' => $relativePath, 'error' => $row['error'] ?? KernelTools::tra( 'Unknown error' ) ];
		}
	}
	$result['total_seconds'] = round( microtime( true ) - $batchStart, 2 );
}

// Re-scan every time (including right after an import) so the list always reflects what's
// actually still outstanding - cheap: a capped directory listing plus one indexed lookup per
// candidate file, not a concern at this scale.
//
// Two shapes live side by side under a collection folder: most films are flat files directly under $scanDir, but a
// DVD-rip-with-extras film sits one level deeper in its own subfolder alongside its Featurettes/
// (FisheyeFilm::registerFeaturettesFromDisk()'s own "film" shape). So this scans both - $scanDir's
// own files, plus one level into any of its subfolders (Featurettes/ itself excluded, since its
// contents are the bonus files, not separate films - they get attached automatically once the
// main film file here is imported, not offered as their own candidates).
$scanTargets = [];
if( !empty( $root ) && $scanDir !== null && is_dir( $scanDir ) ) {
	$topEntries = scandir( $scanDir );
	natsort( $topEntries );
	foreach( $topEntries as $entry ) {
		$fullPath = $scanDir.$entry;
		if( is_file( $fullPath ) ) {
			$scanTargets[] = [ 'full' => $fullPath, 'relative' => $scanRelativePrefix.$entry, 'file' => $entry ];
		} elseif( $folderName !== null && is_dir( $fullPath ) && !str_starts_with( $entry, '.' ) && $entry !== 'Featurettes' ) {
			// Only descend a level once already scoped inside one collection folder - at the true
			// top level every subfolder here is itself a whole other collection (Aardman, Alien,
			// ...), browsed separately via $subfolders below, not flattened into this list (found
			// live 2026-09-04: an earlier version of this descent ran unconditionally and pulled
			// e.g. Films/Aardman/Shaun the Sheep Farmageddon (2019).mkv into the top-level scan).
			$subEntries = scandir( $fullPath );
			natsort( $subEntries );
			foreach( $subEntries as $subEntry ) {
				$subFullPath = $fullPath.'/'.$subEntry;
				if( is_file( $subFullPath ) ) {
					$scanTargets[] = [ 'full' => $subFullPath, 'relative' => $scanRelativePrefix.$entry.'/'.$subEntry, 'file' => $subEntry ];
				}
			}
		}
	}
}

$candidates = [];
foreach( $scanTargets as $target ) {
	if( count( $candidates ) >= LOAD_FILM_LIMIT ) {
		break;
	}
	$ext = strtolower( pathinfo( $target['file'], PATHINFO_EXTENSION ) );
	if( !in_array( $ext, LOAD_FILM_EXTENSIONS, true ) ) {
		continue;
	}
	$existingContentId = $gBitDb->getOne(
		"SELECT la.content_id FROM liberty_attachments la INNER JOIN liberty_files lf ON lf.file_id = la.foreign_id WHERE la.attachment_plugin_guid = 'mimefilm' AND lf.file_name = ?",
		[ $target['relative'] ]
	);
	if( $existingContentId ) {
		continue;
	}
	$candidates[] = [
		'relative_path' => $target['relative'],
		'title'         => pathinfo( $target['file'], PATHINFO_FILENAME ),
	];
}

// The page heading's own "Films" text doubles as a link back to the real gallery - same tidy as
// load_program.php's "TV Shows" heading.
$topGalleryUrlHash = [ 'gallery_id' => $topGalleryId ];
$gBitSmarty->assign( 'topGalleryUrl', FisheyeGallery::getDisplayUrlFromHash( $topGalleryUrlHash ) );

$gBitSmarty->assign( 'storageRoot', $root );
$gBitSmarty->assign( 'filmsDir', $scanDir );
$gBitSmarty->assign( 'folderName', $folderName );
$gBitSmarty->assign( 'scopeGallery', $scopeGallery );
$gBitSmarty->assign( 'needsFolderChoice', $needsFolderChoice );
$gBitSmarty->assign( 'subfolders', $subfolders );
$gBitSmarty->assign( 'candidates', $candidates );
$gBitSmarty->assign( 'candidateLimit', LOAD_FILM_LIMIT );
$gBitSmarty->assign( 'result', $result );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_film.tpl', KernelTools::tra( 'Load Films' ), [ 'display_mode' => 'edit' ] );

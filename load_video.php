<?php
/**
 * Lists un-registered video files under an artist/composer gallery's own "Videos/" subfolder
 * (e.g. Music/André Rieu/Videos/) - a concert DVD or music video sitting alongside that artist's
 * albums, registered as a real FisheyeFilm (same content type normal Films use) but linked into a
 * small "Videos" gallery nested under the artist's own, rather than the top-level Films gallery -
 * the plain "Videos" gallery flagged as a known limitation in MANUAL.md ("a one-off single-video
 * show... faking an S01E01-style episode number just to fit the model - a plain Videos gallery...
 * would fit these better").
 *
 * Deliberately does NOT gate on a Plex match the way load_film.php does (matchPlexMetadataItemForPath()
 * skipping anything unmatched) - Plex almost never scans Music/<Artist>/Videos/, so that gate would
 * block everything here. registerFromDisk() already degrades gracefully with no Plex match (same
 * as a Featurette), so every selected video registers regardless; Plex metadata still gets fetched
 * opportunistically when a match does exist (Lester: "a lot of the material will eventually be
 * linked to tv and film programs... need plex info to marry up with existing copies"). Detecting
 * and linking to an existing Film/TV copy of the same content instead of creating a fresh
 * registration is a real follow-on, not built yet - this always registers a new FisheyeFilm.
 *
 * Gallery linking is done by this page itself, not via registerFromDisk()'s own $pGalleryTitle
 * param - that does a bare title lookup with no parent scoping, unsafe once more than one artist
 * has its own generically-titled "Videos" gallery (see FisheyeGallery::findOrCreateNestedGallery()'s
 * own docblock for the same nesting shape FisheyeAlbum's box sets already use).
 *
 * @package fisheyemedia
 * @subpackage functions
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

const LOAD_VIDEO_LIMIT = 20;
const LOAD_VIDEO_EXTENSIONS = [ 'mkv', 'mp4', 'm4v', 'avi' ];

$galleryIdParam = (int)( $_REQUEST['gallery_id'] ?? 0 );
if( !$galleryIdParam ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No gallery specified.' ) );
}
$gallery = new FisheyeGallery( $galleryIdParam );
$gallery->load();
// isValid() alone doesn't prove load() found a real row - see load_album.php's own identical
// check for why.
if( !$gallery->isValid() || empty( $gallery->getTitle() ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No gallery exists with the given ID.' ) );
}
$galleryTitle = $gallery->getTitle();

// Same folder resolution as load_album.php - Music/<title>/ directly, or one level under this
// gallery's own real parent for a nested gallery (a box set's own "Videos" folder, say).
$root = \Bitweaver\Liberty\mime_film_get_storage_root();
$musicDir = $root.'Music/';
$artistRelative = null;
if( !empty( $root ) ) {
	if( is_dir( $musicDir.$galleryTitle.'/' ) ) {
		$artistRelative = 'Music/'.$galleryTitle.'/';
	} else {
		$parentGalleries = $gallery->getParentGalleries();
		$parentTitle = $parentGalleries ? current( $parentGalleries )['title'] : null;
		if( $parentTitle && is_dir( $musicDir.$parentTitle.'/'.$galleryTitle.'/' ) ) {
			$artistRelative = 'Music/'.$parentTitle.'/'.$galleryTitle.'/';
		}
	}
}
$videosDir = $artistRelative ? $root.$artistRelative.'Videos/' : null;
$videosRelativePrefix = $artistRelative ? $artistRelative.'Videos/' : null;

$result = null;
if( !empty( $_REQUEST['fImport'] ) ) {
	$fetchImages = !empty( $_REQUEST['fetch_images'] );
	// FISHEYE_PAGINATION_FILM_GRID here (not just the storePreference() below) - passing it into
	// findOrCreateNestedGallery()'s own initial store() call is what gets rows_per_page/cols_per_page
	// force-set correctly at creation time, same reasoning as FisheyeAlbum::createSubGallery()'s own
	// identical two-step pagination handling (see that method's own comment). Without this, a
	// freshly-created "Videos" gallery fell back to the site's own default pagination style
	// (Galleriffic) instead of the film grid its own content (FisheyeFilm rows) actually needs.
	$videosGalleryResult = FisheyeGallery::findOrCreateNestedGallery( 'Videos', $galleryTitle, FISHEYE_PAGINATION_FILM_GRID );
	if( !empty( $videosGalleryResult['error'] ) ) {
		$result = [ 'error' => $videosGalleryResult['error'] ];
	} else {
		// content_id (not gallery_id, which is fisheye_gallery's own separate PK - see
		// findOrCreateNestedGallery()'s own docblock) is what the (null, $pContentId) constructor
		// slot expects.
		$videosGallery = new FisheyeGallery( null, $videosGalleryResult['content_id'] );
		$videosGallery->load();
		if( empty( $videosGalleryResult['already'] ) ) {
			$videosGallery->storePreference( 'gallery_pagination', FISHEYE_PAGINATION_FILM_GRID );
		}

		$result = [ 'imported' => [], 'already' => [], 'errors' => [], 'fetch_images' => $fetchImages ];
		foreach( (array)( $_REQUEST['selected'] ?? [] ) as $relativePath ) {
			// Same detoxify() decode as load_film.php - HTML-escapes every $_REQUEST value, which
			// breaks a raw filesystem lookup like this one for any folder/file name containing &, <, or >.
			$relativePath = htmlspecialchars_decode( trim( (string)$relativePath ), ENT_NOQUOTES );
			if( empty( $relativePath ) || !is_file( $root.$relativePath ) ) {
				continue;
			}
			// '' rather than a gallery title - see this file's own docblock for why the linking
			// happens here instead of inside registerFromDisk() itself.
			$row = FisheyeFilm::registerFromDisk( $relativePath, null, $fetchImages, '' );
			if( !empty( $row['already'] ) ) {
				$videosGallery->addItem( $row['already'] );
				$result['already'][] = [ 'path' => $relativePath, 'content_id' => $row['already'] ];
			} elseif( !empty( $row['created'] ) ) {
				$videosGallery->addItem( $row['created'] );
				$result['imported'][] = [ 'path' => $relativePath, 'content_id' => $row['created'], 'plex' => $row['plex'], 'images' => $row['images'] ?? null ];
			} else {
				$result['errors'][] = [ 'path' => $relativePath, 'error' => $row['error'] ?? KernelTools::tra( 'Unknown error' ) ];
			}
		}
	}
}

// Re-scan every time (including right after an import) so the list always reflects what's still
// outstanding. Same two-shapes-side-by-side scan as load_film.php: most videos sit flat directly
// under Videos/, but a DVD rip with its own extras can sit one level deeper in its own subfolder.
$scanTargets = [];
if( $videosDir && is_dir( $videosDir ) ) {
	$topEntries = scandir( $videosDir );
	natsort( $topEntries );
	foreach( $topEntries as $entry ) {
		$fullPath = $videosDir.$entry;
		if( is_file( $fullPath ) ) {
			$scanTargets[] = [ 'relative' => $videosRelativePrefix.$entry, 'file' => $entry ];
		} elseif( is_dir( $fullPath ) && !str_starts_with( $entry, '.' ) ) {
			$subEntries = scandir( $fullPath );
			natsort( $subEntries );
			foreach( $subEntries as $subEntry ) {
				if( is_file( $fullPath.'/'.$subEntry ) ) {
					$scanTargets[] = [ 'relative' => $videosRelativePrefix.$entry.'/'.$subEntry, 'file' => $subEntry ];
				}
			}
		}
	}
}

$candidates = [];
foreach( $scanTargets as $target ) {
	if( count( $candidates ) >= LOAD_VIDEO_LIMIT ) {
		break;
	}
	$ext = strtolower( pathinfo( $target['file'], PATHINFO_EXTENSION ) );
	if( !in_array( $ext, LOAD_VIDEO_EXTENSIONS, true ) ) {
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

$gBitSmarty->assign( 'galleryTitle', $galleryTitle );
$gBitSmarty->assign( 'galleryUrl', $gallery->getDisplayUrl() );
$gBitSmarty->assign( 'galleryIdParam', $galleryIdParam );
$gBitSmarty->assign( 'videosDir', $videosDir );
$gBitSmarty->assign( 'candidateLimit', LOAD_VIDEO_LIMIT );
$gBitSmarty->assign( 'candidates', $candidates );
$gBitSmarty->assign( 'result', $result );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_video.tpl', KernelTools::tra( 'Load Videos: ' ).$galleryTitle, [ 'display_mode' => 'edit' ] );

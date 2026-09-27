<?php
/**
 * Lists real on-disk collection folders directly under Films/ that don't have a matching gallery
 * yet, so an admin can create the gallery (parented under "Films", film_grid pagination style
 * matching every other collection) before ever importing the films inside it -
 * load_film.php's own folder scoping already degrades gracefully without one ($linked stays
 * false, per FisheyeFilm::registerFromDisk()'s own docblock), but that leaves imported films
 * effectively invisible in the gallery hierarchy until a gallery exists to link them into.
 * Deliberately a separate one-off step ahead of import - creating the
 * gallery is cheap/instant, importing films is the expensive step load_film.php already owns.
 *
 * "Real collection" vs a single packaged film in its own folder - the standard is that a folder
 * is only used when there's a Featurettes set to go with it. A folder
 * holding exactly one film unit (one flat video file, or one nested single-film subfolder, and
 * nothing else besides a possible Featurettes/) is that packaged-film case, not a collection, and
 * is deliberately excluded here: it needs no gallery of its own, load_film.php's own subfolder
 * descent (2026-09-04) already registers it as a flat top-level film. Only folders holding more
 * than one film unit are offered.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;

require_once '../kernel/includes/setup_inc.php';
require_once dirname( __DIR__ ).'/liberty/plugins/mime.film.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

const LOAD_COLLECTION_EXTENSIONS = [ 'mkv', 'mp4', 'm4v', 'avi' ];

$topGalleryId = FisheyeGallery::getTopGalleryId( 'Films' );
$root = \Bitweaver\Liberty\mime_film_get_storage_root();
$filmsDir = $root.'Films/';

function load_collection_gallery_id_for_title( string $pTitle ) {
	global $gBitDb;
	return $gBitDb->getOne(
		"SELECT fg.gallery_id FROM `".BIT_DB_PREFIX."fisheye_gallery` fg
		 INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.content_id = fg.content_id
		 WHERE lc.title = ?",
		[ $pTitle ]
	);
}

// A folder's own "film unit" count - a flat video file directly inside it counts as one, a
// nested subfolder (Featurettes/ excluded) counts as one regardless of how many files it holds -
// more than one of either shape makes this a real collection.
function load_collection_unit_count( string $pDir ): int {
	$units = 0;
	foreach( scandir( $pDir ) ?: [] as $entry ) {
		if( str_starts_with( $entry, '.' ) ) {
			continue;
		}
		$fullPath = $pDir.$entry;
		if( is_file( $fullPath ) ) {
			if( in_array( strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) ), LOAD_COLLECTION_EXTENSIONS, true ) ) {
				$units++;
			}
		} elseif( is_dir( $fullPath ) && $entry !== 'Featurettes' ) {
			$units++;
		}
	}
	return $units;
}

$result = null;
if( !empty( $_REQUEST['fCreate'] ) ) {
	$result = [ 'created' => [], 'errors' => [] ];
	foreach( (array)( $_REQUEST['selected'] ?? [] ) as $folderName ) {
		// Same detoxify() decode as load_film.php - HTML-escapes every $_REQUEST value, which
		// breaks a raw filesystem lookup like this one for any folder name containing &, <, or >.
		$folderName = htmlspecialchars_decode( trim( (string)$folderName ), ENT_NOQUOTES );
		if( empty( $folderName ) || !is_dir( $filmsDir.$folderName ) ) {
			continue;
		}
		if( load_collection_gallery_id_for_title( $folderName ) ) {
			continue;
		}
		$gallery = new FisheyeGallery();
		// gallery_pagination needs to be in the store hash itself, not just the storePreference()
		// call below - FisheyeGallery::verifyGalleryData() branches on it to fix rows/cols_per_page
		// at 4*8 for film_grid, and that only runs during store().
		$storeHash = [ 'title' => $folderName, 'gallery_pagination' => FISHEYE_PAGINATION_FILM_GRID ];
		if( $gallery->store( $storeHash ) ) {
			$gallery->storePreference( 'gallery_pagination', FISHEYE_PAGINATION_FILM_GRID );
			if( $topGalleryId ) {
				$gallery->addToGalleries( [ $topGalleryId ] );
			}
			$result['created'][] = [ 'folder' => $folderName, 'gallery_id' => $gallery->mGalleryId ];
		} else {
			$result['errors'][] = [ 'folder' => $folderName, 'error' => implode( '; ', $gallery->mErrors ) ];
		}
	}
}

// Re-scan every time (including right after a create) so the list always reflects what's still
// outstanding - real subfolders under Films/ holding more than one film unit, that don't already
// have a matching gallery.
$candidates = [];
if( !empty( $root ) && is_dir( $filmsDir ) ) {
	$entries = scandir( $filmsDir );
	natsort( $entries );
	foreach( $entries as $entry ) {
		if( str_starts_with( $entry, '.' ) || !is_dir( $filmsDir.$entry ) ) {
			continue;
		}
		if( load_collection_unit_count( $filmsDir.$entry.'/' ) <= 1 ) {
			continue;
		}
		if( load_collection_gallery_id_for_title( $entry ) ) {
			continue;
		}
		$candidates[] = [ 'folder' => $entry ];
	}
}

$topGalleryUrlHash = [ 'gallery_id' => $topGalleryId ];
$gBitSmarty->assign( 'topGalleryUrl', FisheyeGallery::getDisplayUrlFromHash( $topGalleryUrlHash ) );
$gBitSmarty->assign( 'candidates', $candidates );
$gBitSmarty->assign( 'result', $result );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_collection.tpl', KernelTools::tra( 'Load Collections' ), [ 'display_mode' => 'edit' ] );

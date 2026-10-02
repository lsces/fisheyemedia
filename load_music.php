<?php
/**
 * Lists real on-disk artist/composer folders directly under fisheye_disk_storage_root's own
 * Music/ that don't have a matching gallery yet, so an admin can create the gallery before ever
 * importing the albums inside it - same one-off "create the gallery first, cheap/instant" step
 * load_collection.php already established for Films, load_album.php then handles the (more
 * expensive) album import into that gallery. This page's own icon lives on the top-level "Music"
 * gallery only (music_gallery_icons_inc.tpl's "Add Music Collection"), same gating as Film's own
 * "Load Collections" icon.
 *
 * No intermediate base-folder tier (there was one - a Classical/Modern-style split - until Lester
 * flattened the tidied portion of the library to sit directly under Music/; every real
 * artist/composer/collection folder now lives at exactly this one level, matching load_album.php's
 * own folder resolution).
 *
 * Every artist/composer folder gets offered here, even one holding just a single album today -
 * an artist with one album now commonly gains a second later, and a bare album registered without
 * a gallery would need retrofitting into one at that point. Uniformly gallery-first avoids that:
 * load_album.php's own import step works the same whether it's populating one album or several.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;

require_once '../kernel/includes/setup_inc.php';
require_once dirname( __DIR__ ).'/liberty/plugins/mime.film.php';
// FISHEYEALBUM_TRACK_EXTENSIONS below is a plain namespaced const, not a class - referencing it
// alone doesn't trigger the autoloader the way instantiating FisheyeAlbum would.
require_once __DIR__.'/includes/classes/FisheyeAlbum.php';

global $gBitSystem, $gBitSmarty, $gBitDb, $gBitUser, $gLibertySystem;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

const LOAD_MUSIC_LIMIT = 20;

function load_music_gallery_id_for_title( string $pTitle ) {
	global $gBitDb;
	return $gBitDb->getOne(
		"SELECT fg.gallery_id FROM `".BIT_DB_PREFIX."fisheye_gallery` fg
		 INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.content_id = fg.content_id
		 WHERE lc.title = ?",
		[ $pTitle ]
	);
}

// An artist/composer folder's own "album unit" count - a subfolder counts as one album unit if it
// either directly contains a recognised track file, or looks like a disc subfolder (CD1/CD2/etc,
// same convention FisheyeAlbum::registerFromDisk() already scans for). Any real folder with at
// least one album unit is worth its own gallery - see this file's own docblock for why a lone
// album today still gets one, not just folders already holding several.
//
// A discography-category folder (Studio/Live/Compilation/... - FisheyeAlbum::isCategoryFolder())
// holds its own real album folders one level deeper still, same shape a CD1/CD2 disc subfolder
// already got special-cased for - missing this here meant any artist already reorganised into
// categories on disk, but not yet given a top-level gallery, silently showed zero units and
// dropped out of the candidate list entirely (found live: several modern artists vanished from
// the alphabetical list the moment their folders were split into Studio/Live/Compilation).
function load_music_has_track_folder( string $pFullPath, string $pEntryName ): bool {
	if( !is_dir( $pFullPath ) ) {
		return false;
	}
	foreach( scandir( $pFullPath ) ?: [] as $subEntry ) {
		$subPath = $pFullPath.$subEntry;
		$ext = strtolower( pathinfo( $subEntry, PATHINFO_EXTENSION ) );
		if( in_array( $ext, FISHEYEALBUM_TRACK_EXTENSIONS, true ) ) {
			return true;
		}
		// A disc subfolder (CD1/CD2/etc) holds its own track files one level deeper still.
		if( is_dir( $subPath ) && preg_match( '/^CD\s*\d+/i', $subEntry ) && array_filter(
			scandir( $subPath ) ?: [],
			fn( $discEntry ) => in_array( strtolower( pathinfo( $discEntry, PATHINFO_EXTENSION ) ), FISHEYEALBUM_TRACK_EXTENSIONS, true )
		) ) {
			return true;
		}
	}
	return false;
}

function load_music_unit_count( string $pDir ): int {
	$units = 0;
	foreach( scandir( $pDir ) ?: [] as $entry ) {
		if( str_starts_with( $entry, '.' ) ) {
			continue;
		}
		$fullPath = $pDir.$entry.'/';
		if( !is_dir( $fullPath ) ) {
			continue;
		}
		if( FisheyeAlbum::isGroupFolder( $fullPath ) ) {
			foreach( scandir( $fullPath ) ?: [] as $albumEntry ) {
				if( load_music_has_track_folder( $fullPath.$albumEntry.'/', $albumEntry ) ) {
					$units++;
				}
			}
			continue;
		}
		if( load_music_has_track_folder( $fullPath, $entry ) ) {
			$units++;
		}
	}
	return $units;
}

$topGalleryId = FisheyeGallery::getTopGalleryId( 'Music' );
$root = \Bitweaver\Liberty\mime_film_get_storage_root();
$musicDir = $root.'Music/';

$result = null;
// One folder at a time, start to finish: Process creates (or reuses) that folder's gallery and
// goes straight to its next step - the first per-artist tool another package registered through
// the 'music_artist_tools' service (contactwiki's people pass, which belongs before the albums),
// or load_album.php when there is none. That step hands on to load_album.php itself once done.
if( !empty( $_REQUEST['process_folder'] ) ) {
	$result = [ 'errors' => [] ];
	// See load_film.php's own comment on this same decode - detoxify() HTML-escapes every
	// $_REQUEST value, which breaks a raw filesystem lookup like this one for any collection
	// name containing &, <, or >.
	$folderName = htmlspecialchars_decode( trim( (string)$_REQUEST['process_folder'] ), ENT_NOQUOTES );
	if( $folderName === '' || !is_dir( $musicDir.$folderName ) ) {
		$result['errors'][] = [ 'folder' => $folderName, 'error' => KernelTools::tra( 'Folder not found under Music/' ) ];
	} else {
		$galleryId = load_music_gallery_id_for_title( $folderName );
		if( !$galleryId ) {
			$gallery = new FisheyeMediaGallery();
			$storeHash = [ 'title' => $folderName, 'gallery_pagination' => FISHEYE_PAGINATION_MUSIC_GRID ];
			if( $gallery->store( $storeHash ) ) {
				$gallery->storePreference( 'gallery_pagination', FISHEYE_PAGINATION_MUSIC_GRID );
				if( $topGalleryId ) {
					$gallery->addToGalleries( [ $topGalleryId ] );
				}
				$galleryId = $gallery->mGalleryId;
			} else {
				$result['errors'][] = [ 'folder' => $folderName, 'error' => implode( '; ', $gallery->mErrors ) ];
			}
		}
		if( $galleryId ) {
			$nextUrl = FISHEYEMEDIA_PKG_URL.'load_album.php?gallery_id=';
			foreach( $gLibertySystem->getServiceValues( 'music_artist_tools' ) ?? [] as $artistTools ) {
				foreach( $artistTools as $tool ) {
					if( empty( $tool['perm'] ) || $gBitUser->hasPermission( $tool['perm'] ) ) {
						$nextUrl = $tool['url'];
						break 2;
					}
				}
			}
			KernelTools::bit_redirect( $nextUrl.$galleryId );
		}
	}
}

$candidates = [];
if( !empty( $root ) && is_dir( $musicDir ) ) {
	$entries = scandir( $musicDir );
	natsort( $entries );
	foreach( $entries as $entry ) {
		if( count( $candidates ) >= LOAD_MUSIC_LIMIT ) {
			break;
		}
		if( str_starts_with( $entry, '.' ) || !is_dir( $musicDir.$entry ) ) {
			continue;
		}
		if( load_music_unit_count( $musicDir.$entry.'/' ) < 1 ) {
			continue;
		}
		if( load_music_gallery_id_for_title( $entry ) ) {
			continue;
		}
		$candidates[] = [ 'folder' => $entry ];
	}
}

$topGalleryUrlHash = [ 'gallery_id' => $topGalleryId ];
$gBitSmarty->assign( 'topGalleryUrl', FisheyeGallery::getDisplayUrlFromHash( $topGalleryUrlHash ) );
$gBitSmarty->assign( 'candidateLimit', LOAD_MUSIC_LIMIT );
$gBitSmarty->assign( 'candidates', $candidates );
$gBitSmarty->assign( 'result', $result );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_music.tpl', KernelTools::tra( 'Load Music Collections' ), [ 'display_mode' => 'edit' ] );

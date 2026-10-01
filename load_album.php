<?php
/**
 * Register album folders into an already-existing music collection gallery - one level, unlike
 * load_program.php's show->season nesting, since the gallery itself (an artist/composer folder,
 * e.g. "Bob Marley" or "Classic Composers") is already known from the gallery_id the calling
 * icon passes (music_gallery_icons_inc.tpl's "Load Album" button, shown on any collection gallery
 * below the top-level "Music" one - see FisheyeAlbum.php's own docblock for the wider
 * load_collection/load_discography plan this is the first piece of).
 *
 * No top-level discovery step the way load_program.php has one for shows - creating a NEW
 * collection gallery is "Add Music Collection" (load_music.php) on the top-level gallery's own
 * icon set instead; this page only ever lists album folders under an *existing* collection
 * gallery's own folder.
 *
 * Folder resolution: a collection gallery's title is normally expected to match a real folder
 * directly under fisheye_disk_storage_root's own Music/ (no separate config key for this - same
 * fisheye_disk_storage_root as Films) - same one-level layout load_music.php's own candidate scan
 * uses. A box set's own nested gallery (FisheyeAlbum::createSubGallery(), see below) sits one
 * level deeper than that though - Music/<artist>/<box set>/, not Music/<box set>/ - so a direct
 * lookup falling through tries the gallery's own real parent gallery next, same one level
 * FisheyeAlbum's own getParentGalleries() call already covers (a box set is never nested more than
 * one level deep).
 *
 * Two different "this folder isn't an album itself" shapes exist, but they're handled quite
 * differently (discography categories used to get the same nested-gallery treatment as box sets,
 * before deciding that was an unnecessary complication for something that's really just a display
 * grouping):
 * - a box-set-shaped candidate (still has a real CDxx/Volume-N subfolder - see
 *   FisheyeAlbum::isBoxSetFolder()) - one work split across genuinely distinct recordings/discs -
 *   still gets its own nested gallery via createSubGallery(), since it's a real structural grouping
 *   (letting a handful of discs be picked at a time), not just a type label.
 * - a discography-category folder (Studio/Live/Compilation/Remaster/Single/Soundtrack/Tribute/
 *   Other - see FisheyeAlbum::isCategoryFolder()) is transparently flattened instead: its own real
 *   album folders show up directly in *this* page's own candidate list as "Category/Album" entries
 *   (no separate gallery, no extra click-through), and register straight into this artist's own
 *   gallery with the category stored as a plain 'category' xref on the album (registerFromDisk()'s
 *   own $pCategory param) for display-side grouping instead.
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

// mime_film_get_storage_root() below is only auto-loaded via the LibertyMime attachment-plugin
// dispatch - same fix FisheyeProgram.php/FisheyeSeason.php/FisheyeFilm.php/FisheyeAlbum.php/
// load_collection.php all already needed.
require_once dirname( __DIR__ ).'/liberty/plugins/mime.film.php';

const LOAD_ALBUM_LIMIT = 10;

$galleryIdParam = (int)( $_REQUEST['gallery_id'] ?? 0 );
if( !$galleryIdParam ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No gallery specified.' ) );
}
$gallery = new FisheyeGallery( $galleryIdParam );
$gallery->load();
// isValid() alone doesn't prove load() actually found a matching row - it only checks that the
// constructor was given a format-valid positive integer, and the constructor's own mGalleryId
// assignment is never cleared when load()'s query comes back empty. A bogus/no-longer-existent
// gallery_id was passing this check and falling through to an empty getTitle(), which degenerated
// into treating the bare Music/ folder itself as the candidate list (found live, a content_id
// mistakenly used as a gallery_id - see FisheyeGallery::findOrCreateNestedGallery()'s own docblock
// for how that number got into a URL in the first place).
if( !$gallery->isValid() || empty( $gallery->getTitle() ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No gallery exists with the given ID.' ) );
}
$galleryTitle = $gallery->getTitle();

$root = \Bitweaver\Liberty\mime_film_get_storage_root();
$artistDir = null;
$artistRelative = null;
// Music/<title>/, or under its artist (directly or inside a Studio/Live/... category folder) for a
// box set's own nested gallery - see FisheyeMediaGallery::resolveMusicFolder().
if( $artistRelative = FisheyeMediaGallery::resolveMusicFolder( $gallery ) ) {
	$artistDir = $root.$artistRelative;
}

$importResult = null;
if( !empty( $_REQUEST['fImportAlbums'] ) ) {
	$fetchDiscogs = !empty( $_REQUEST['fetch_discogs'] );
	$importResult = [ 'created' => [], 'subgalleries' => [], 'errors' => [] ];
	foreach( (array)( $_REQUEST['selected'] ?? [] ) as $albumFolder ) {
		// Same detoxify() decode as load_film.php - HTML-escapes every $_REQUEST value, which
		// breaks a raw filesystem lookup like this one for any folder name containing &, <, or >.
		$albumFolder = htmlspecialchars_decode( trim( (string)$albumFolder ), ENT_NOQUOTES );
		if( $albumFolder === '' ) {
			continue;
		}
		// A flattened candidate looks like "Studio/Some Album" - the category is only the part
		// before the first '/', and only when it's a real category name (an album whose own real
		// title happens to contain a slash - unlikely, but not impossible - would otherwise be
		// mistaken for one).
		$category = null;
		$relativeAlbumPath = $albumFolder;
		if( str_contains( $albumFolder, '/' ) ) {
			[ $possibleCategory, $rest ] = explode( '/', $albumFolder, 2 );
			if( FisheyeAlbum::isCategoryFolder( $possibleCategory ) ) {
				$category = $possibleCategory;
			}
		}
		// A CDxx subfolder still sitting inside means the folder was deliberately kept as a box set
		// of distinct recordings rather than flattened into one multi-disc album (see
		// FisheyeAlbum::isBoxSetFolder()'s own docblock) - unlike a discography category, this is a
		// real structural grouping worth its own nested gallery (letting a handful of discs be
		// picked at a time), so it still gets that treatment even when found inside a category
		// folder (e.g. "Studio/Some Box Set").
		if( FisheyeAlbum::isBoxSetFolder( $artistDir.$relativeAlbumPath.'/' ) ) {
			$row = FisheyeAlbum::createSubGallery( $artistRelative.$relativeAlbumPath, (int)$gallery->mContentId );
			if( !empty( $row['error'] ) ) {
				$importResult['errors'][] = [ 'folder' => $albumFolder, 'error' => $row['error'] ];
			} else {
				// Same getDisplayUrlFromHash() route the top-level "Music" gallery link elsewhere
				// on this page already uses, not a hardcoded view.php?gallery_id= guess.
				$subGalleryUrlHash = [ 'gallery_id' => $row['gallery_id'] ];
				$importResult['subgalleries'][] = [
					'folder'   => $albumFolder,
					'url'      => FisheyeGallery::getDisplayUrlFromHash( $subGalleryUrlHash ),
					'loadUrl'  => FISHEYEMEDIA_PKG_URL.'load_album.php?gallery_id='.$row['gallery_id'],
					'already'  => !empty( $row['already'] ),
				];
			}
			continue;
		}
		// A bare CDxx folder being imported directly means this gallery is itself a box set's own
		// nested gallery (see above) - the title itself stays the plain folder name (registerFromDisk()'s
		// own default, required so getImageStorageRoot() can resolve this disc's real folder back
		// from it), but its real content (getDiscTitle()) goes into the description instead, same
		// field view_album.tpl already renders for every other album.
		$discTitle = preg_match( FISHEYEALBUM_DISC_FOLDER_PATTERN, $albumFolder ) ? FisheyeAlbum::getDiscTitle( $artistDir.$relativeAlbumPath.'/' ) : null;
		// $gallery->mContentId, not $galleryTitle - see registerFromDisk()'s own docblock for why a
		// bare-title lookup there was linking albums into the wrong same-named gallery.
		$row = FisheyeAlbum::registerFromDisk( $artistRelative.$relativeAlbumPath, null, $gallery->mContentId, $discTitle, $fetchDiscogs, $category );
		if( !empty( $row['error'] ) ) {
			$importResult['errors'][] = [ 'folder' => $albumFolder, 'error' => $row['error'] ];
		} else {
			$importResult['created'][] = [ 'folder' => $albumFolder, 'content_id' => $row['created'] ?? $row['already'], 'tracks' => $row['tracks'] ?? null, 'cover' => $row['cover'] ?? null, 'discogs' => $row['discogs'] ?? null ];
		}
	}
}

$candidates = [];
if( $artistDir ) {
	$entries = scandir( $artistDir );
	natsort( $entries );
	foreach( $entries as $entry ) {
		if( count( $candidates ) >= LOAD_ALBUM_LIMIT ) {
			break;
		}
		if( str_starts_with( $entry, '.' ) || !is_dir( $artistDir.$entry ) ) {
			continue;
		}
		// A discography category is transparently flattened - its own real album folders show up
		// directly here as "Category/Album" entries, never the bare category name itself (see this
		// file's own docblock for why - display grouping via a plain xref, not a nested gallery).
		if( FisheyeAlbum::isCategoryFolder( $entry ) ) {
			$categoryDir = $artistDir.$entry.'/';
			$categoryEntries = scandir( $categoryDir ) ?: [];
			natsort( $categoryEntries );
			foreach( $categoryEntries as $categoryEntry ) {
				if( count( $candidates ) >= LOAD_ALBUM_LIMIT ) {
					break 2;
				}
				if( str_starts_with( $categoryEntry, '.' ) || !is_dir( $categoryDir.$categoryEntry ) ) {
					continue;
				}
				if( !FisheyeAlbum::folderHasTracks( $categoryDir.$categoryEntry.'/' ) ) {
					continue; // an Artwork/scans-style extras folder, not a real album
				}
				$existingContentId = $gBitDb->getOne(
					"SELECT content_id FROM liberty_content WHERE content_type_guid = 'fisheyealbum' AND title = ?",
					[ $categoryEntry ]
				);
				if( $existingContentId ) {
					continue;
				}
				$candidates[] = $entry.'/'.$categoryEntry;
			}
			continue;
		}
		if( !FisheyeAlbum::folderHasTracks( $artistDir.$entry.'/' ) ) {
			continue; // an Artwork/Videos/scans-style extras folder, not a real album
		}
		$existingContentId = $gBitDb->getOne(
			"SELECT content_id FROM liberty_content WHERE content_type_guid = 'fisheyealbum' AND title = ?",
			[ $entry ]
		);
		if( $existingContentId ) {
			continue;
		}
		$candidates[] = $entry;
	}
}

$gBitSmarty->assign( 'galleryTitle', $galleryTitle );
$gBitSmarty->assign( 'galleryUrl', $gallery->getDisplayUrl() );
$gBitSmarty->assign( 'galleryIdParam', $galleryIdParam );
$gBitSmarty->assign( 'artistDir', $artistDir );
$gBitSmarty->assign( 'candidateLimit', LOAD_ALBUM_LIMIT );
$gBitSmarty->assign( 'candidates', $candidates );
$gBitSmarty->assign( 'importResult', $importResult );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_album.tpl', KernelTools::tra( 'Load Albums: ' ).$galleryTitle, [ 'display_mode' => 'edit' ] );

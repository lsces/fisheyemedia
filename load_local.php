<?php
/**
 * Loads the Local Videos folder (home videos, one-off downloads, material with no Plex or TMDB entry)
 * into a top-level "Local Videos" gallery of its own, beside Films and TV Shows. Each video is a
 * FisheyeFilm titled from its file name, registered with no Plex match needed (as load_video.php does);
 * loose files sit directly in the Local Videos gallery, and each real subfolder that is a collection of
 * one-off videos becomes its own film-grid gallery under it, titled by the folder.
 *
 * A subfolder whose files are tagged SnnEnn is a series, not a collection of films - it is listed so it
 * is not missed, but it is loaded as a show, not here.
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

const LOAD_LOCAL_LIMIT = 20;
const LOAD_LOCAL_EXTENSIONS = [ 'mkv', 'mp4', 'm4v', 'avi' ];
// Both the folder under the storage root and the title of the top-level gallery.
const LOAD_LOCAL_FOLDER = 'Local Videos';

$root = \Bitweaver\Liberty\mime_film_get_storage_root();
$localDir = $root.LOAD_LOCAL_FOLDER.'/';

// detoxify() HTML-escapes every $_REQUEST value; this one is used for a raw filesystem lookup, so it is decoded back.
$folderParam = htmlspecialchars_decode( trim( (string)( $_REQUEST['folder'] ?? '' ) ), ENT_NOQUOTES );
if( $folderParam !== '' && ( str_contains( $folderParam, '/' ) || $folderParam === '..' || !is_dir( $localDir.$folderParam ) ) ) {
	$folderParam = '';
}

$result = null;
$topGalleryId = FisheyeGallery::getTopGalleryId( LOAD_LOCAL_FOLDER );

if( !empty( $_REQUEST['fCreateTop'] ) && !$topGalleryId ) {
	$gallery = new FisheyeGallery();
	$storeHash = [ 'title' => LOAD_LOCAL_FOLDER, 'gallery_pagination' => FISHEYE_PAGINATION_FILM_GRID ];
	if( $gallery->store( $storeHash ) ) {
		$gallery->storePreference( 'gallery_pagination', FISHEYE_PAGINATION_FILM_GRID );
		$topGalleryId = (int)$gallery->mGalleryId;
		$result = [ 'created_top' => true ];
	} else {
		$result = [ 'error' => implode( '; ', $gallery->mErrors ) ];
	}
}

$topGallery = null;
if( $topGalleryId ) {
	$topGallery = new FisheyeGallery( $topGalleryId );
	$topGallery->load();
	if( !$topGallery->isValid() ) {
		$topGallery = null;
	}
}

$relativePrefix = LOAD_LOCAL_FOLDER.'/'.( $folderParam !== '' ? $folderParam.'/' : '' );
$scanDir = $localDir.( $folderParam !== '' ? $folderParam.'/' : '' );

if( !empty( $_REQUEST['fImport'] ) && $topGallery ) {
	$target = $topGallery;
	if( $folderParam !== '' ) {
		// One film-grid gallery per collection folder, under Local Videos.
		$made = FisheyeMediaGallery::findOrCreateNestedGallery( $folderParam, (int)$topGallery->mContentId, FISHEYE_PAGINATION_FILM_GRID );
		if( !empty( $made['error'] ) ) {
			$result = [ 'error' => $made['error'] ];
			$target = null;
		} else {
			$target = new FisheyeGallery( null, $made['content_id'] );
			$target->load();
			if( empty( $made['already'] ) ) {
				$target->storePreference( 'gallery_pagination', FISHEYE_PAGINATION_FILM_GRID );
			}
		}
	}
	if( $target ) {
		$result = [ 'imported' => [], 'already' => [], 'errors' => [] ];
		foreach( (array)( $_REQUEST['selected'] ?? [] ) as $relativePath ) {
			$relativePath = htmlspecialchars_decode( trim( (string)$relativePath ), ENT_NOQUOTES );
			if( $relativePath === '' || !str_starts_with( $relativePath, $relativePrefix ) || !is_file( $root.$relativePath ) ) {
				continue;
			}
			// '' for the gallery title - linked here, into the right gallery, not by a bare title lookup.
			$row = FisheyeFilm::registerFromDisk( $relativePath, null, false, '' );
			if( !empty( $row['already'] ) ) {
				$target->addItem( $row['already'] );
				$result['already'][] = [ 'path' => $relativePath, 'content_id' => $row['already'] ];
			} elseif( !empty( $row['created'] ) ) {
				$target->addItem( $row['created'] );
				$result['imported'][] = [ 'path' => $relativePath, 'content_id' => $row['created'] ];
			} else {
				$result['errors'][] = [ 'path' => $relativePath, 'error' => $row['error'] ?? KernelTools::tra( 'Unknown error' ) ];
			}
		}
	}
}

// The folders: collections of one-off videos, and series (SnnEnn-tagged) that load as shows.
$folders = [];
if( is_dir( $localDir ) ) {
	$entries = scandir( $localDir );
	natsort( $entries );
	foreach( $entries as $entry ) {
		if( str_starts_with( $entry, '.' ) || !is_dir( $localDir.$entry ) ) {
			continue;
		}
		$files = [];
		foreach( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $localDir.$entry, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if( $file->isFile() && in_array( strtolower( $file->getExtension() ), LOAD_LOCAL_EXTENSIONS, true ) ) {
				$files[] = $file->getFilename();
			}
		}
		if( !$files ) {
			continue;
		}
		$tagged = count( array_filter( $files, fn( $f ) => preg_match( '/S\d+E\d+/i', $f ) ) );
		$folders[] = [ 'name' => $entry, 'files' => count( $files ), 'series' => $tagged * 2 >= count( $files ) ];
	}
}

$candidates = [];
if( is_dir( $scanDir ) ) {
	$entries = scandir( $scanDir );
	natsort( $entries );
	foreach( $entries as $entry ) {
		if( count( $candidates ) >= LOAD_LOCAL_LIMIT ) {
			break;
		}
		if( !is_file( $scanDir.$entry ) || !in_array( strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) ), LOAD_LOCAL_EXTENSIONS, true ) ) {
			continue;
		}
		$relative = $relativePrefix.$entry;
		$existing = $gBitDb->getOne(
			"SELECT la.content_id FROM liberty_attachments la INNER JOIN liberty_files lf ON lf.file_id = la.foreign_id WHERE la.attachment_plugin_guid = 'mimefilm' AND lf.file_name = ?",
			[ $relative ]
		);
		if( !$existing ) {
			$candidates[] = [ 'relative_path' => $relative, 'title' => pathinfo( $entry, PATHINFO_FILENAME ) ];
		}
	}
}

$gBitSmarty->assign( 'localDir', $localDir );
$gBitSmarty->assign( 'localDirExists', is_dir( $localDir ) );
$gBitSmarty->assign( 'topGallery', $topGallery );
$gBitSmarty->assign( 'folderName', $folderParam );
$gBitSmarty->assign( 'folders', $folders );
$gBitSmarty->assign( 'candidates', $candidates );
$gBitSmarty->assign( 'candidateLimit', LOAD_LOCAL_LIMIT );
$gBitSmarty->assign( 'result', $result );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_local.tpl', KernelTools::tra( 'Load Local Videos' ), [ 'display_mode' => 'edit' ] );

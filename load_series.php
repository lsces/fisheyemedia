<?php
/**
 * Builds an author's series galleries from the author's folder of series folders - the books
 * counterpart of load_album.php's folder-driven loading, with a review step because the match is by
 * title, not by anything certain.
 *
 * For an author gallery it lists the Library/ folders (the likeliest preselected, spellings allowing),
 * proposes which of the gallery's own books make up which series folder (FisheyeSeries::
 * proposeFromFolder() - read-only), and creates only the series that are ticked: a series gallery under
 * the author, the books moved into it in publication order. A book moved into a series leaves the
 * author's own list, so the author page shows it once, in its series' strip.
 *
 * A second section rearranges what is already there: every book under this author - in the author's own
 * list or in one of its series - with a choice of where to move it, so a series built from a bad match
 * (a "complete library" folder) can be put right a book at a time. A series left empty is left in
 * place, to be deleted from its own gallery page.
 *
 * Nothing is written until the create button is pressed, and what is created is recomputed from the
 * disk and the database at that moment, not taken from the form - the form only says which folders to
 * create and what to call them.
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

// A series with at least this many proposed books is preticked; fewer is left for you to decide.
const LOAD_SERIES_PRETICK_MIN = 3;

$gContent = FisheyeGallery::lookup( [ 'gallery_id' => $_REQUEST['gallery_id'] ?? 0 ] );
if( !$gContent || !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No gallery exists with the given ID' ) );
}
$authorContentId = (int)$gContent->mContentId;

$root = \Bitweaver\Liberty\mime_film_get_storage_root();
$folders = FisheyeSeries::libraryFolders();

// detoxify() HTML-escapes every $_REQUEST value - undone here, as load_music.php does, since folder
// and series names can hold &, < or >.
$decode = fn( $pValue ) => htmlspecialchars_decode( trim( (string)$pValue ), ENT_NOQUOTES );
$folder = $decode( $_REQUEST['folder'] ?? '' );
if( $folder === '' || !in_array( $folder, $folders, true ) ) {
	$folder = FisheyeSeries::guessAuthorFolder( $gContent->getTitle(), $folders ) ?? '';
}

/**
 * The books directly in the author's gallery (a book already in a series is no longer proposed).
 */
function load_series_direct_books( int $pAuthorContentId ): array {
	global $gBitDb;
	$rows = $gBitDb->getAll(
		"SELECT lc.`content_id`, lc.`title`, lc.`event_time` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` fgim
		 INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = fgim.`item_content_id`
		 WHERE fgim.`gallery_content_id` = ? AND lc.`content_type_guid` = 'fisheyebook'",
		[ $pAuthorContentId ]
	);
	return array_map( fn( $r ) => [ 'content_id' => (int)$r['content_id'], 'title' => (string)$r['title'], 'event_time' => (int)$r['event_time'] ], $rows );
}

/**
 * The series galleries directly under this author, by title.
 *
 * @return list<array{content_id:int,title:string}>
 */
function load_series_children( int $pAuthorContentId ): array {
	global $gBitDb;
	$rows = $gBitDb->getAll(
		"SELECT lc.`content_id`, lc.`title` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` fgim
		 INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = fgim.`item_content_id`
		 WHERE fgim.`gallery_content_id` = ? AND lc.`content_type_guid` = 'fisheyeseries'",
		[ $pAuthorContentId ]
	);
	usort( $rows, fn( $a, $b ) => strnatcasecmp( $a['title'], $b['title'] ) );
	return array_map( fn( $r ) => [ 'content_id' => (int)$r['content_id'], 'title' => (string)$r['title'] ], $rows );
}

/**
 * A gallery's books in their order there (position, then title).
 */
function load_series_books_in( int $pContainerContentId ): array {
	global $gBitDb;
	$rows = $gBitDb->getAll(
		"SELECT lc.`content_id`, lc.`title`, lc.`event_time`, fgim.`item_position` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` fgim
		 INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = fgim.`item_content_id`
		 WHERE fgim.`gallery_content_id` = ? AND lc.`content_type_guid` = 'fisheyebook'",
		[ $pContainerContentId ]
	);
	usort( $rows, fn( $a, $b ) => [ (float)$a['item_position'] ?: PHP_INT_MAX, mb_strtolower( $a['title'] ) ] <=> [ (float)$b['item_position'] ?: PHP_INT_MAX, mb_strtolower( $b['title'] ) ] );
	return array_map( fn( $r ) => [ 'content_id' => (int)$r['content_id'], 'title' => (string)$r['title'], 'event_time' => (int)$r['event_time'], 'year' => $r['event_time'] ? gmdate( 'Y', (int)$r['event_time'] ) : '' ], $rows );
}

$moveResult = null;
if( !empty( $_REQUEST['move_books'] ) ) {
	$moveResult = [ 'moved' => 0, 'errors' => [] ];
	$childIds = array_column( load_series_children( $authorContentId ), 'content_id' );
	$containers = array_merge( [ $authorContentId ], $childIds );
	$touched = [];
	// move[<group the book is listed under>][<book>] = where to send it - keyed by group because a book that sits in the
	// author's own list AND a series is listed under both, and one field name for it let the unchanged second select
	// override the choice made on the first.
	$moves = [];
	foreach( (array)( $_REQUEST['move'] ?? [] ) as $fromKey => $byBook ) {
		foreach( (array)$byBook as $bookId => $target ) {
			if( $decode( $target ) !== '' ) {
				$moves[] = [ $decode( $fromKey ), (int)$bookId, $decode( $target ) ];
			}
		}
	}
	foreach( $moves as [ $fromKey, $bookId, $target ] ) {
		if( $target === 'new' ) {
			// A series started from here: the name typed beside the choices, made once and reused for every book sent to it.
			$newName = $decode( $_REQUEST['new_series_name'] ?? '' );
			if( $newName === '' ) {
				$moveResult['errors'][] = $bookId.' - '.KernelTools::tra( 'give the new series a name' );
				continue;
			}
			if( !isset( $newSeries ) ) {
				$into = FisheyeSeries::findOrCreateUnder( $newName, $authorContentId );
				if( !$into['series'] ) {
					$moveResult['errors'][] = $newName.' - '.$into['error'];
					continue;
				}
				$newSeries = (int)$into['series']->mContentId;
				$containers[] = $newSeries;
			}
			$target = (string)$newSeries;
		}
		$targetId = $target === 'author' ? $authorContentId : (int)$target;
		$currentId = $fromKey === 'author' ? $authorContentId : (int)$fromKey;
		if( !in_array( $targetId, $containers, true ) || !in_array( $currentId, $containers, true ) ) {
			$moveResult['errors'][] = $bookId.' - '.KernelTools::tra( 'not a series of this author' );
			continue;
		}
		if( $currentId === $targetId ) {
			continue;
		}
		// The book must really be in the place it is being moved out of (it may also be filed elsewhere, which is left alone).
		if( !$gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` WHERE `gallery_content_id` = ? AND `item_content_id` = ?", [ $currentId, $bookId ] ) ) {
			continue;
		}
		$to = $targetId === $authorContentId ? $gContent : new FisheyeSeries( null, $targetId );
		$from = $currentId === $authorContentId ? $gContent : new FisheyeSeries( null, $currentId );
		// Loaded whatever isValid() says: isInGallery() needs the gallery id load() fills in, and answers false without it.
		foreach( [ $to, $from ] as $gallery ) {
			if( !is_numeric( $gallery->mGalleryId ) ) {
				$gallery->load();
			}
		}
		// A book already in the target (listed twice, in the author's list and its series) is only taken out of where it
		// is leaving - nothing is added, so the target's order is left as it is.
		$wasThere = $to->isInGallery( $targetId, $bookId );
		$to->addItem( $bookId );
		if( $to->isInGallery( $targetId, $bookId ) ) {
			$from->removeItem( $bookId );
			$moveResult['moved']++;
			if( !$wasThere ) {
				$touched[$targetId] = true;
			}
		} else {
			$moveResult['errors'][] = $bookId.' - '.KernelTools::tra( 'could not be added to that series' );
		}
	}
	// Publication order again in every series that gained a book (one only losing a book keeps its order).
	foreach( array_keys( $touched ) as $containerId ) {
		if( $containerId !== $authorContentId ) {
			$series = new FisheyeSeries( null, $containerId );
			$series->load();
			$series->renumberByPublication();
		}
	}
}

$result = null;
if( $folder !== '' && !empty( $_REQUEST['create_series'] ) ) {
	$result = [ 'created' => [], 'errors' => [] ];
	$proposal = FisheyeSeries::proposeFromFolder( $root.'Library/'.$folder.'/', load_series_direct_books( $authorContentId ) );
	$byFolder = [];
	foreach( $proposal['series'] as $entry ) {
		$byFolder[$entry['folder']] = $entry;
	}
	foreach( (array)( $_REQUEST['series_create'] ?? [] ) as $i => $ticked ) {
		$seriesFolder = $decode( $_REQUEST['series_folder'][$i] ?? '' );
		$entry = $byFolder[$seriesFolder] ?? null;
		if( !$entry ) {
			$result['errors'][] = $seriesFolder.' - '.KernelTools::tra( 'no longer proposed' );
			continue;
		}
		$name = $decode( $_REQUEST['series_name'][$i] ?? '' ) ?: $entry['name'];
		// "Put in": an existing series of this author, or (blank) a series of the name given.
		$targetId = (int)( $_REQUEST['series_target'][$i] ?? 0 );
		if( $targetId && in_array( $targetId, array_column( load_series_children( $authorContentId ), 'content_id' ), true ) ) {
			$series = new FisheyeSeries( null, $targetId );
			$series->load();
			$name = $series->getTitle();
			$made = [ 'already' => true ];
		} else {
			$into = FisheyeSeries::findOrCreateUnder( $name, $authorContentId );
			if( !$into['series'] ) {
				$result['errors'][] = $name.' - '.$into['error'];
				continue;
			}
			$series = $into['series'];
			$made = [ 'already' => !$into['created'] ];
		}
		$moved = 0;
		foreach( $entry['books'] as $book ) {
			$series->addItem( $book['content_id'] );
			// Leaves the author's own list only once it really is in the series.
			if( $series->isInGallery( $series->mContentId, $book['content_id'] ) ) {
				$gContent->removeItem( $book['content_id'] );
				$moved++;
			}
		}
		$series->renumberByPublication();
		$result['created'][] = [ 'name' => $name, 'moved' => $moved, 'merged' => !empty( $made['already'] ) ];
	}
}

$proposal = $folder !== '' ? FisheyeSeries::proposeFromFolder( $root.'Library/'.$folder.'/', load_series_direct_books( $authorContentId ) ) : null;

// The year each book is shown with - gmdate() so an old book's negative timestamp is fine.
$addYear = fn( array $book ) => $book + [ 'year' => $book['event_time'] ? gmdate( 'Y', $book['event_time'] ) : '' ];
if( $proposal ) {
	foreach( $proposal['series'] as $i => $entry ) {
		$proposal['series'][$i]['books'] = array_map( $addYear, $entry['books'] );
	}
}

// Every book under this author and where it is now, for the rearrange form.
$seriesTargets = load_series_children( $authorContentId );
foreach( $seriesTargets as $i => $target ) {
	$seriesTargets[$i]['count'] = (int)$gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` WHERE `gallery_content_id` = ?", [ $target['content_id'] ] );
}
// Each proposed series against what exists: the series of the same name is preselected as where to put it, else one the
// name starts with ("Acorna's Children" for "Acorna") - flagged as only similar. A new series is the fallback.
if( $proposal ) {
	foreach( $proposal['series'] as $i => $entry ) {
		$key = FisheyeSeries::seriesKey( $entry['name'] );
		$proposal['series'][$i]['target'] = 0;
		$proposal['series'][$i]['similar'] = false;
		foreach( $seriesTargets as $target ) {
			if( FisheyeSeries::seriesKey( $target['title'] ) === $key ) {
				$proposal['series'][$i]['target'] = $target['content_id'];
				$proposal['series'][$i]['similar'] = false;
				break;
			}
			if( !$proposal['series'][$i]['target'] && $key !== '' && FisheyeSeries::seriesKey( $target['title'] ) !== '' && str_starts_with( $key, FisheyeSeries::seriesKey( $target['title'] ) ) ) {
				$proposal['series'][$i]['target'] = $target['content_id'];
				$proposal['series'][$i]['similar'] = true;
			}
		}
	}
}
$rearrange = [ [ 'key' => 'author', 'title' => KernelTools::tra( 'Not in a series' ), 'books' => load_series_books_in( $authorContentId ) ] ];
foreach( $seriesTargets as $child ) {
	$rearrange[] = [ 'key' => (string)$child['content_id'], 'title' => $child['title'], 'books' => load_series_books_in( $child['content_id'] ) ];
}

$authorUrlHash = [ 'gallery_id' => $gContent->mGalleryId ];
$gBitSmarty->assign( 'authorUrl', FisheyeGallery::getDisplayUrlFromHash( $authorUrlHash ) );
$gBitSmarty->assign( 'authorTitle', $gContent->getTitle() );
$gBitSmarty->assign( 'galleryId', $gContent->mGalleryId );
$gBitSmarty->assign( 'folders', $folders );
$gBitSmarty->assign( 'folder', $folder );
$gBitSmarty->assign( 'proposal', $proposal );
$gBitSmarty->assign( 'preTickMin', LOAD_SERIES_PRETICK_MIN );
$gBitSmarty->assign( 'result', $result );
$gBitSmarty->assign( 'moveResult', $moveResult );
$gBitSmarty->assign( 'rearrange', $rearrange );
$gBitSmarty->assign( 'seriesTargets', $seriesTargets );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_series.tpl', KernelTools::tra( 'Series from Folders' ), [ 'display_mode' => 'edit' ] );

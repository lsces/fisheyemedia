<?php
/**
 * Loads books from an uploaded catalogue spreadsheet (see FisheyeBookCatalogue for the sheet shape)
 * into the Library gallery, one group at a time - the books counterpart of load_music.php.
 *
 * Filing: an author with two or more books in the catalogue gets a gallery of their own, directly under
 * the top-level Library gallery - named for their contact once the authors pass has made one (so a pen
 * name's books and the real name's land together, each credit still reading as published), else as
 * written in the sheet. A book whose author has only
 * the one is filed into a gallery named for its Category instead (Workshop, Steam, Fiction ...) - a
 * gallery apiece for hundreds of one-book authors would bury the real ones. The author is still a
 * credit on the book itself, so a one-book author turning into a two-book author later only means
 * promoting them to a gallery of their own; nothing about the book changes.
 *
 * A group is loaded a few books per submit (LOAD_BOOKS_BATCH) and the page comes straight back, so a
 * long author never runs one request long; running it again carries on, and a book already loaded
 * is recognised (FisheyeBook::findContentId()) and skipped, so repeating it is harmless. From an
 * author's gallery the books still to load are a tick-list (the first batch pre-ticked, untick to
 * leave one out); loaded books drop off it.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

const LOAD_BOOKS_BATCH = 10;
const LOAD_BOOKS_GROUPS_SHOWN = 30;

$topGalleryId = FisheyeGallery::getTopGalleryId( 'Library' );
$topContentId = $topGalleryId ? (int)$gBitDb->getOne( "SELECT `content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery` WHERE `gallery_id` = ?", [ $topGalleryId ] ) : 0;

$import = FisheyeBookCatalogue::importUploaded();
$result = null;

// Series grouping: on by default, with the umbrella choices (FisheyeSeries::umbrellas()) the page last posted, or their
// defaults. Posted back by the "Series" form and carried through every Process press.
$seriesForm = isset( $_REQUEST['series_form'] );
$useSeries = !$seriesForm || !empty( $_REQUEST['use_series'] );
$splitChosen = $seriesForm ? array_map( 'strval', array_keys( (array)( $_REQUEST['split'] ?? [] ) ) ) : null;

$catalogue = FisheyeBookCatalogue::cached();
$groups = FisheyeBookCatalogue::plan( $catalogue['rows'] );

// From an author's gallery (gallery_id): only that author's books are offered, and the group is the one
// named for the gallery.
$authorGallery = !empty( $_REQUEST['gallery_id'] ) ? FisheyeGallery::lookup( [ 'gallery_id' => (int)$_REQUEST['gallery_id'] ] ) : null;
if( $authorGallery && !$authorGallery->isValid() ) {
	$authorGallery = null;
}
$onlyKey = $authorGallery ? 'a|'.mb_strtolower( FisheyeBookCredits::canonicalName( $authorGallery->getTitle() ) ) : null;

// The tick-list's row numbers when it was posted (an empty list is a real answer, so the form marks itself).
$pickedRows = !empty( $_REQUEST['picked_form'] ) ? array_map( 'intval', (array)( $_REQUEST['book'] ?? [] ) ) : null;

if( !empty( $_REQUEST['process_group'] ) ) {
	// detoxify() HTML-escapes every $_REQUEST value - undone here, as load_music.php does, since a
	// name can hold &, < or >.
	$groupKey = htmlspecialchars_decode( trim( (string)$_REQUEST['process_group'] ), ENT_NOQUOTES );
	if( !$topContentId ) {
		$result = [ 'created' => 0, 'already' => 0, 'errors' => [ KernelTools::tra( 'There is no top-level Library gallery to file books under.' ) ], 'group' => '', 'details' => [] ];
	} elseif( !isset( $groups[$groupKey] ) ) {
		$result = [ 'created' => 0, 'already' => 0, 'errors' => [ KernelTools::tra( 'That group is not in the loaded catalogue.' ) ], 'group' => '', 'details' => [] ];
	} else {
		$result = FisheyeBookLoader::loadGroup( $groups[$groupKey], $pickedRows, $useSeries, $splitChosen, LOAD_BOOKS_BATCH );
	}
}

// Opening an author's gallery files the books already loaded that credit them (a co-author's, mostly).
$filedHere = 0;
if( $authorGallery ) {
	$filedHere = FisheyeBookCredits::fileCreditedBooks( $catalogue['rows'], (int)$authorGallery->mContentId, $authorGallery->getTitle() );
}

// What is still to do per group - recounted after any processing above.
$pending = [];
foreach( $groups as $key => $group ) {
	if( $onlyKey !== null && $key !== $onlyKey ) {
		continue;
	}
	$loaded = 0;
	foreach( $group['rows'] as $row ) {
		if( FisheyeBook::findContentId( $row ) ) {
			$loaded++;
		}
	}
	if( $loaded < count( $group['rows'] ) ) {
		$pending[] = [ 'key' => $key, 'kind' => $group['kind'], 'name' => $group['name'], 'total' => count( $group['rows'] ), 'loaded' => $loaded ];
	}
}

// From an author's gallery: the books still to load (this author as primary author, in catalogue order, the
// next batch pre-ticked; loaded ones are left off), and those they only co-wrote - filed here when the primary author's books are loaded, so they
// are listed for information, not processed from this page.
$bookList = [];
$booksTotal = $booksLoaded = 0;
$coCredited = [];
$seriesPlanView = null;
if( $authorGallery ) {
	$seriesPlanView = $useSeries && isset( $groups[$onlyKey] ) ? FisheyeSeries::planForRows( $groups[$onlyKey]['rows'], FisheyeBookCredits::authorQid( $groups[$onlyKey]['name'] ), $splitChosen ) : null;
	$seriesPosition = [];
	foreach( $seriesPlanView['series'] ?? [] as $planned ) {
		foreach( $planned['rows'] as $position => $i ) {
			$seriesPosition[$i] = $planned['name'].' #'.( $position + 1 );
		}
	}
	$nextBatch = LOAD_BOOKS_BATCH;
	foreach( $groups[$onlyKey]['rows'] ?? [] as $rowIndex => $row ) {
		$booksTotal++;
		if( FisheyeBook::findContentId( $row ) ) {
			$booksLoaded++;
			continue;
		}
		$bookList[] = [ 'index' => $rowIndex, 'title' => $row['title'], 'year' => substr( (string)( $row['published'] ?? '' ), 0, 4 ), 'isbn' => $row['isbn'] ?? '',
			'coauthors' => array_slice( (array)( $row['authors'] ?? [] ), 1 ), 'series' => $seriesPosition[$rowIndex] ?? '', 'ticked' => $nextBatch-- > 0 ];
	}
	$thisAuthor = mb_strtolower( FisheyeBookCredits::canonicalName( $authorGallery->getTitle() ) );
	foreach( $catalogue['rows'] as $row ) {
		$names = array_map( fn( $n ) => mb_strtolower( FisheyeBookCredits::canonicalName( (string)$n ) ), (array)( $row['authors'] ?? [] ) );
		if( ( $names[0] ?? '' ) !== $thisAuthor && in_array( $thisAuthor, $names, true ) ) {
			$coCredited[] = [ 'title' => $row['title'], 'primary' => FisheyeBookCatalogue::primaryAuthor( $row ), 'loaded' => (bool)FisheyeBook::findContentId( $row ) ];
		}
	}
}

$topGalleryUrlHash = [ 'gallery_id' => $topGalleryId ];
$gBitSmarty->assign( 'topGalleryUrl', $topGalleryId ? FisheyeGallery::getDisplayUrlFromHash( $topGalleryUrlHash ) : null );
$gBitSmarty->assign( 'catalogueRows', count( $catalogue['rows'] ) );
$gBitSmarty->assign( 'catalogueImported', $catalogue['imported'] );
$gBitSmarty->assign( 'catalogueFile', $catalogue['file'] );
$gBitSmarty->assign( 'groupCount', count( $groups ) );
$gBitSmarty->assign( 'pending', array_slice( $pending, 0, LOAD_BOOKS_GROUPS_SHOWN ) );
$gBitSmarty->assign( 'pendingTotal', count( $pending ) );
$gBitSmarty->assign( 'groupsShown', LOAD_BOOKS_GROUPS_SHOWN );
$gBitSmarty->assign( 'batchSize', LOAD_BOOKS_BATCH );
$gBitSmarty->assign( 'minAuthorBooks', FisheyeBookCatalogue::MIN_AUTHOR_BOOKS );
$gBitSmarty->assign( 'hasTopGallery', (bool)$topContentId );
$gBitSmarty->assign( 'import', $import );
$gBitSmarty->assign( 'authorGalleryId', $authorGallery ? $authorGallery->mGalleryId : 0 );
$gBitSmarty->assign( 'authorGalleryTitle', $authorGallery ? $authorGallery->getTitle() : '' );
$gBitSmarty->assign( 'authorGalleryUrl', $authorGallery ? $authorGallery->getDisplayUrl() : '' );
$gBitSmarty->assign( 'filedHere', $filedHere );
$gBitSmarty->assign( 'bookList', $bookList );
$gBitSmarty->assign( 'booksTotal', $booksTotal );
$gBitSmarty->assign( 'booksLoaded', $booksLoaded );
$gBitSmarty->assign( 'onlyKey', $onlyKey );
$gBitSmarty->assign( 'useSeries', $useSeries );
$gBitSmarty->assign( 'seriesPlan', $seriesPlanView );
$gBitSmarty->assign( 'seriesWikidataFailed', $authorGallery && $useSeries && isset( $groups[$onlyKey] ) && $seriesPlanView === null );
$gBitSmarty->assign( 'coCredited', $coCredited );
$gBitSmarty->assign( 'authorInCatalogue', $onlyKey !== null && isset( $groups[$onlyKey] ) );
$gBitSmarty->assign( 'result', $result );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_books.tpl', KernelTools::tra( 'Load Books' ), [ 'display_mode' => 'edit' ] );

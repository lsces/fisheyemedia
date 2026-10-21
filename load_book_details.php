<?php
/**
 * Fills in the Library's books from Open Library, ten at a time: each book with an ISBN is looked up
 * (FisheyeBook::reloadFromOpenLibrary()) and gets what it is missing - edition and work ids, pages,
 * format, date, language, publisher, and a cover that also becomes its thumbnail. Blanks only: nothing
 * the catalogue or an editor entered is replaced, and a book that already has an image keeps it, so a
 * book can be run again without harm. The same action as 'Reload from Open Library' on a book's own
 * edit page, over the books that still lack an Open Library edition id or an image.
 *
 * Nothing is looked up until the button is pressed. Books Open Library has no record of stay in the
 * list; the start position moves past them so the next press goes on to the next ten.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeImage;
use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPackage( 'contactwiki' );
$gBitSystem->verifyPermission( 'p_fisheye_admin' );

// Books per press - Open Library takes a few requests each, one at a time.
const LOAD_BOOK_DETAILS_BATCH = 10;

/**
 * The books with an ISBN that are missing an Open Library edition id, an image or the thumbnail attachment that shows it, in content_id order.
 *
 * @return list<int>
 */
function load_book_details_pending(): array {
	global $gBitDb;
	$live = "x.`end_date` IS NULL";
	$rows = $gBitDb->getCol(
		"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."liberty_content` lc
		 WHERE lc.`content_type_guid` = 'fisheyebook'
		 AND EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` x WHERE x.`content_id` = lc.`content_id` AND x.`item` = 'isbn' AND $live )
		 AND ( NOT EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` x WHERE x.`content_id` = lc.`content_id` AND x.`item` = 'ol_edition' AND $live )
		    OR NOT EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` x WHERE x.`content_id` = lc.`content_id` AND x.`item` = 'image' AND $live )
		    OR NOT EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_attachments` a WHERE a.`content_id` = lc.`content_id` ) )
		 ORDER BY lc.`content_id`"
	) ?: [];
	return array_map( 'intval', $rows );
}

$pending = load_book_details_pending();
$start = max( 0, (int)( $_REQUEST['start'] ?? 0 ) );

$results = null;
if( !empty( $_REQUEST['fetch'] ) ) {
	$results = [];
	$skipped = 0;
	foreach( array_slice( $pending, $start, LOAD_BOOK_DETAILS_BATCH ) as $contentId ) {
		$book = FisheyeImage::lookup( [ 'content_id' => $contentId ] );
		if( !$book || !$book->isValid() || !method_exists( $book, 'reloadFromOpenLibrary' ) ) {
			continue;
		}
		$found = $book->reloadFromOpenLibrary();
		$results[] = [ 'title' => $book->getTitle(), 'url' => $book->getDisplayUrl(), 'matched' => $found['matched'], 'items' => $found['items'], 'error' => $found['error'] ?? '' ];
	}
	// A book still missing something afterwards stays in the list - the next batch starts after it.
	$stillPending = load_book_details_pending();
	$start += count( array_intersect( array_slice( $pending, $start, LOAD_BOOK_DETAILS_BATCH ), $stillPending ) );
	$pending = $stillPending;
}

// Google Books covers that turned out to be the grey "image not available" stand-in: each press removes the next batch of
// them (image row, file, thumbnail) and asks again, which now passes stand-ins over for a smaller size or another source.
$blankBooks = FisheyeBook::booksWithBlankGoogleCover();
$replaced = null;
if( !empty( $_REQUEST['replace_blank'] ) ) {
	$replaced = [];
	foreach( array_slice( $blankBooks, 0, LOAD_BOOK_DETAILS_BATCH ) as $contentId ) {
		$book = FisheyeImage::lookup( [ 'content_id' => $contentId ] );
		if( !$book || !$book->isValid() || !$book->dropBlankGoogleCover() ) {
			continue;
		}
		$found = $book->reloadFromOpenLibrary();
		$replaced[] = [ 'title' => $book->getTitle(), 'url' => $book->getDisplayUrl(), 'items' => $found['items'] ?? [], 'error' => $found['error'] ?? '' ];
	}
	$blankBooks = FisheyeBook::booksWithBlankGoogleCover();
	$pending = load_book_details_pending();
}

$gBitSmarty->assign( 'blankCount', count( $blankBooks ) );
$gBitSmarty->assign( 'replaced', $replaced );
$gBitSmarty->assign( 'pendingCount', count( $pending ) );
$gBitSmarty->assign( 'start', $start );
$gBitSmarty->assign( 'batchSize', LOAD_BOOK_DETAILS_BATCH );
$gBitSmarty->assign( 'results', $results );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_book_details.tpl', KernelTools::tra( 'Book Details from Open Library' ), [ 'display_mode' => 'edit' ] );

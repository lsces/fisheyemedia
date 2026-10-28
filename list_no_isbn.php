<?php
/**
 * The books with no ISBN, for finding one by hand: each with its author and year, search links (AbeBooks, Open Library, Google Books) built
 * from its title and author, and a box to paste the ISBN into. Saving goes through FisheyeBook::storeXref() as the cover picker's Add ISBN
 * does (the check digit is verified, ISBN-13 and ISBN-10 kept), and the book then leaves the list and is picked up by the Book Details jobs.
 * A book with no ISBN can also be given its cover from the Find a Cover page. Books that already have a picture (the same test as the Book
 * Details no-cover list) are left off unless 'all=1' asks for them: a book older than the ISBN that has its cover is finished.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeImage;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

$decode = fn( $pValue ) => htmlspecialchars_decode( trim( (string)$pValue ), ENT_NOQUOTES );
$message = '';
$errors = [];

if( !empty( $_REQUEST['add_isbn'] ) ) {
	$book = FisheyeImage::lookup( [ 'content_id' => (int)( $_REQUEST['content_id'] ?? 0 ) ] );
	if( !$book || !$book->isValid() || !method_exists( $book, 'storeXref' ) ) {
		$errors[] = KernelTools::tra( 'That book could not be found.' );
	} elseif( $gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'isbn' AND `end_date` IS NULL", [ $book->mContentId ] ) ) {
		$errors[] = $book->getTitle().' - '.KernelTools::tra( 'already has an ISBN.' );
	} elseif( $book->storeXref( [ 'content_id' => $book->mContentId, 'item' => 'isbn', 'isbn' => $decode( $_REQUEST['isbn'] ?? '' ) ] ) ) {
		$message = $book->getTitle().' - '.KernelTools::tra( 'the ISBN is added.' );
	} else {
		$errors[] = $book->getTitle().' - '.implode( '; ', array_map( 'strval', array_values( $book->mErrors ) ) );
	}
}

$showAll = !empty( $_REQUEST['all'] );
// Narrow the list to work through one author (any part of the name) and/or the books added most recently, so a fresh load is not lost among the old ones.
$authorFilter = trim( (string)( $_REQUEST['author'] ?? '' ) );
$newest = !empty( $_REQUEST['newest'] );
$books = [];
foreach( $gBitDb->getAll(
	"SELECT lc.`content_id`, lc.`title`, lc.`event_time` FROM `".BIT_DB_PREFIX."liberty_content` lc
	 WHERE lc.`content_type_guid` = 'fisheyebook'
	 AND NOT EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` x WHERE x.`content_id` = lc.`content_id` AND x.`item` = 'isbn' AND x.`end_date` IS NULL )".
	 ( $showAll ? '' : " AND NOT EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_attachments` a WHERE a.`content_id` = lc.`content_id` )" )."
	 ".( $authorFilter !== '' ? " AND EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` ax WHERE ax.`content_id` = lc.`content_id` AND ax.`item` = 'author' AND ax.`end_date` IS NULL AND ax.`xkey_ext` LIKE ? )" : '' )."
	 ORDER BY ".( $newest ? 'lc.`content_id` DESC' : 'lc.`title`' ), $authorFilter !== '' ? [ '%'.$authorFilter.'%' ] : [] ) ?: [] as $row ) {
	$books[(int)$row['content_id']] = [ 'content_id' => (int)$row['content_id'], 'title' => (string)$row['title'], 'year' => $row['event_time'] ? gmdate( 'Y', (int)$row['event_time'] ) : '', 'authors' => [] ];
}
if( $books ) {
	$ids = implode( ',', array_keys( $books ) );
	foreach( $gBitDb->getAll( "SELECT `content_id`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` IN ( $ids ) AND `item` = 'author' AND `end_date` IS NULL ORDER BY `xorder`" ) ?: [] as $x ) {
		if( (string)$x['xkey_ext'] !== '' ) {
			$books[(int)$x['content_id']]['authors'][] = (string)$x['xkey_ext'];
		}
	}
	foreach( $books as &$book ) {
		// Search terms, for the bookseller and catalogue links: the title and the first author.
		$book['terms'] = rawurlencode( trim( $book['title'].' '.( $book['authors'][0] ?? '' ) ) );
		$book['titleTerm'] = rawurlencode( $book['title'] );
		$book['authorTerm'] = rawurlencode( $book['authors'][0] ?? '' );
	}
	unset( $book );
}

$gBitSmarty->assign( 'books', array_values( $books ) );
$gBitSmarty->assign( 'showAll', $showAll );
$gBitSmarty->assign( 'authorFilter', $authorFilter );
$gBitSmarty->assign( 'newest', $newest );
$gBitSmarty->assign( 'message', $message );
$gBitSmarty->assign( 'errors', $errors );

$gBitSystem->display( 'bitpackage:fisheyemedia/list_no_isbn.tpl', KernelTools::tra( 'Books with no ISBN' ), [ 'display_mode' => 'edit' ] );

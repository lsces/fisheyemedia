<?php
/**
 * Book Files from the Library: for each book, find its folder in the Library tree (author/series/book, from the galleries the book is filed in), show
 * what is in it, and on request write the book's ebook / audiobook / extra rows so the files appear on its Extras strip (FisheyeBookFiles). A rebuild
 * of just those three items; a book whose folder is not found keeps what it has. With a search, the folders under the matching authors that no listed
 * book resolved to are listed too - the books still to catalogue.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeImage;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

const LOAD_BOOK_FILES_BATCH = 20;
const LOAD_BOOK_FILES_LIST = 60;

$find = trim( (string)( $_REQUEST['find'] ?? '' ) );
$onlyTodo = !empty( $_REQUEST['todo'] );
$message = '';
$errors = [];
$root = FisheyeBookFiles::libraryRoot();

if( !empty( $_REQUEST['scan'] ) ) {
	$done = 0;
	$files = 0;
	foreach( array_slice( array_map( 'intval', (array)( $_REQUEST['book'] ?? [] ) ), 0, LOAD_BOOK_FILES_BATCH ) as $id ) {
		$book = FisheyeImage::lookup( [ 'content_id' => $id ] );
		if( !$book || !$book->isValid() || !( $book instanceof FisheyeBook ) ) {
			$errors[] = $id.' - '.KernelTools::tra( 'that is not a book.' );
			continue;
		}
		$one = FisheyeBookFiles::register( $book );
		if( $one['folder'] === null ) {
			$errors[] = $book->getTitle().' - '.KernelTools::tra( 'no folder found, nothing changed.' );
		} else {
			$done++;
			$files += $one['ebook'] + $one['audiobook'] + $one['extra'];
		}
		foreach( $one['errors'] as $error ) {
			$errors[] = $book->getTitle().' - '.$error;
		}
	}
	$message = $done.' '.KernelTools::tra( 'books scanned,' ).' '.$files.' '.KernelTools::tra( 'files registered.' );
}

$books = [];
$matchedFolders = [];
if( $root !== '' && is_dir( $root ) ) {
	$where = '';
	$bind = [];
	if( $find !== '' ) {
		// The title, an author credit, or a series filed under a gallery with that name (a book in Anne McCaffrey's series credited only to a co-writer).
		$where = " AND ( UPPER( lc.`title` ) LIKE ? OR EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` ax WHERE ax.`content_id` = lc.`content_id` AND ax.`item` = 'author' AND ax.`end_date` IS NULL AND UPPER( ax.`xkey_ext` ) LIKE ? )
			OR EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m1 JOIN `".BIT_DB_PREFIX."fisheye_gallery_image_map` m2 ON m2.`item_content_id` = m1.`gallery_content_id`
				JOIN `".BIT_DB_PREFIX."liberty_content` ag ON ag.`content_id` = m2.`gallery_content_id` WHERE m1.`item_content_id` = lc.`content_id` AND ag.`content_type_guid` = 'fisheyemediagal' AND UPPER( ag.`title` ) LIKE ? ) )";
		$bind = [ '%'.strtoupper( $find ).'%', '%'.strtoupper( $find ).'%', '%'.strtoupper( $find ).'%' ];
	}
	$whereFind = $where; // the search alone, for the folder report (the to-do filter below must not shrink it)
	if( $onlyTodo ) {
		$where .= " AND NOT EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` fx WHERE fx.`content_id` = lc.`content_id` AND fx.`item` IN ('ebook','audiobook','extra') AND fx.`end_date` IS NULL )";
	}
	$rows = $gBitDb->getAll( "SELECT FIRST ".LOAD_BOOK_FILES_LIST." lc.`content_id`, lc.`title` FROM `".BIT_DB_PREFIX."liberty_content` lc WHERE lc.`content_type_guid` = 'fisheyebook' $where ORDER BY lc.`title`", $bind ) ?: [];
	foreach( $rows as $row ) {
		$book = FisheyeImage::lookup( [ 'content_id' => (int)$row['content_id'] ] );
		if( !$book || !( $book instanceof FisheyeBook ) ) {
			continue;
		}
		$preview = FisheyeBookFiles::preview( $book );
		$have = (int)$gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` IN ('ebook','audiobook','extra') AND `end_date` IS NULL", [ (int)$row['content_id'] ] );
		$authors = [];
		foreach( $gBitDb->getAll( "SELECT `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'author' AND `end_date` IS NULL ORDER BY `xorder`", [ (int)$row['content_id'] ] ) ?: [] as $a ) {
			$authors[] = (string)$a['xkey_ext'];
		}
		if( $preview['folder'] !== null ) {
			$matchedFolders[rtrim( $preview['folder'], '/' )] = true;
		}
		$books[] = [ 'id' => (int)$row['content_id'], 'title' => (string)$row['title'], 'url' => $book->getDisplayUrl(), 'authors' => implode( ', ', $authors ), 'folder' => $preview['folder'],
			'ebook' => $preview['ebook'], 'audiobook' => $preview['audiobook'], 'extra' => $preview['extra'], 'have' => $have ];
	}
}

// The report below compares the folders with every book the search matches, not just the rows shown above (an author can have more than the list holds).
$totalMatching = count( $books );
if( $find !== '' && $root !== '' && is_dir( $root ) ) {
	$all = $gBitDb->getAll( "SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."liberty_content` lc WHERE lc.`content_type_guid` = 'fisheyebook' $whereFind", $bind ) ?: [];
	$totalMatching = count( $all );
	foreach( $all as $row ) {
		$book = FisheyeImage::lookup( [ 'content_id' => (int)$row['content_id'] ] );
		if( $book instanceof FisheyeBook && ( $folder = FisheyeBookFiles::folderFor( $book ) ) ) {
			$matchedFolders[rtrim( $folder['rel'], '/' )] = true;
		}
	}
}

// Folders under the authors that match the search which no book resolved to: the books still to catalogue.
$unmatched = [];
if( $find !== '' && $root !== '' && is_dir( $root ) ) {
	foreach( array_diff( scandir( $root ) ?: [], [ '.', '..' ] ) as $authorDir ) {
		if( !is_dir( $root.$authorDir ) || stripos( $authorDir, $find ) === false ) {
			continue;
		}
		foreach( array_diff( scandir( $root.$authorDir ) ?: [], [ '.', '..' ] ) as $second ) {
			if( !is_dir( $root.$authorDir.'/'.$second ) || $second[0] === '.' ) {
				continue;
			}
			// a series folder holds book folders; a book folder directly under the author holds files
			$bookDirs = array_filter( array_diff( scandir( $root.$authorDir.'/'.$second ) ?: [], [ '.', '..' ] ), fn( $d ) => is_dir( $root.$authorDir.'/'.$second.'/'.$d ) && !in_array( strtolower( $d ), [ 'pictures', 'audio' ], true ) );
			foreach( $bookDirs ?: [] as $bookDir ) {
				if( !isset( $matchedFolders[$authorDir.'/'.$second.'/'.$bookDir] ) ) {
					$unmatched[] = $authorDir.'/'.$second.'/'.$bookDir;
				}
			}
		}
	}
}

$gBitSmarty->assign( 'books', $books );
$gBitSmarty->assign( 'totalMatching', $totalMatching );
$gBitSmarty->assign( 'unmatched', $unmatched );
$gBitSmarty->assign( 'find', $find );
$gBitSmarty->assign( 'onlyTodo', $onlyTodo );
$gBitSmarty->assign( 'libraryRoot', $root );
$gBitSmarty->assign( 'listLimit', LOAD_BOOK_FILES_LIST );
$gBitSmarty->assign( 'batch', LOAD_BOOK_FILES_BATCH );
$gBitSmarty->assign( 'message', $message );
$gBitSmarty->assign( 'errors', $errors );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_book_files.tpl', KernelTools::tra( 'Book Files from the Library' ), [ 'display_mode' => 'edit' ] );

<?php
/**
 * Adds books to the Library from a list of ISBNs - the by-hand counterpart of load_books.php, which loads from a catalogue
 * spreadsheet. Two steps, so nothing is written until the result has been seen:
 *
 *   1. Look up: each ISBN is read from Open Library (FisheyeBookCatalogue::rowFromIsbn()) and listed with the title and authors
 *      it gives and where the book would be filed (FisheyeBookLoader::filingFor()). An ISBN Open Library cannot place gets a title
 *      and author to type in, and is loaded from those.
 *   2. Load the ticked books (FisheyeBookLoader::addRows()): the book, its credits and cover, its series from Wikidata, and the
 *      filing - an author who has or now earns a gallery of their own gets it (books already loaded under that author are
 *      brought in and taken out of their Category gallery), anyone else is filed under the Category.
 *
 * A list is best kept to one author at a time. At most ADD_BOOKS_MAX ISBNs are taken per press.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

const ADD_BOOKS_MAX = 20;

$topGalleryId = FisheyeGallery::getTopGalleryId( 'Library' );

// detoxify() HTML-escapes every $_REQUEST value; undone for the free text.
$decode = fn( $pValue ) => htmlspecialchars_decode( trim( (string)$pValue ), ENT_NOQUOTES );
$category = $decode( $_REQUEST['category'] ?? 'Fiction' );
$typed = preg_split( '/[\s,;]+/', $decode( $_REQUEST['isbns'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
$ticked = array_map( $decode, (array)( $_REQUEST['book'] ?? [] ) );
$loading = !empty( $_REQUEST['add_books'] ) && $ticked;
$wanted = array_values( array_unique( $loading ? $ticked : $typed ) );
$overflow = max( 0, count( $wanted ) - ADD_BOOKS_MAX );
$wanted = array_slice( $wanted, 0, ADD_BOOKS_MAX );

$listing = [];       // the look-up step
$results = [];       // the load step
$errors = [];

if( $wanted && ( $loading || !empty( $_REQUEST['look_up'] ) ) ) {
	$manualTitle = (array)( $_REQUEST['manual_title'] ?? [] );
	$manualAuthor = (array)( $_REQUEST['manual_author'] ?? [] );
	$rows = [];
	foreach( $wanted as $raw ) {
		$one = FisheyeBookCatalogue::rowFromIsbn( $raw, $category );
		$isbn = FisheyeBook::normaliseIsbn( $raw );
		// What is typed in by hand stands in for, or completes, what Open Library did not give.
		$title = $decode( $manualTitle[$raw] ?? '' );
		$author = $decode( $manualAuthor[$raw] ?? '' );
		if( isset( $one['row'] ) && ( $title !== '' || $author !== '' ) ) {
			$one['row']['title'] = $title !== '' ? $title : $one['row']['title'];
			$one['row']['authors'] = $author !== '' ? [ $author ] : $one['row']['authors'];
			unset( $one['error'] );
		} elseif( !isset( $one['row'] ) && $isbn['isbn13'] !== '' && $title !== '' && $author !== '' ) {
			$one['row'] = [ 'ref' => '', 'title' => $title, 'authors' => [ $author ], 'author_role' => 'author', 'publisher' => '', 'isbn13' => $isbn['isbn13'],
				'isbn10' => $isbn['isbn10'], 'published' => '', 'category' => $category !== '' ? [ $category ] : [], 'copies' => '', 'paid' => '', 'note' => [], 'external' => [] ];
			unset( $one['error'] );
		}
		$one['raw'] = $raw;
		$rows[] = $one;
	}
	$usable = array_keys( array_filter( $rows, fn( $r ) => isset( $r['row'] ) && !isset( $r['error'] ) ) );
	$filing = $usable ? FisheyeBookLoader::filingFor( array_column( array_intersect_key( $rows, array_flip( $usable ) ), 'row' ) ) : [];
	$filingByIndex = array_combine( $usable, $filing ) ?: [];

	if( $loading ) {
		$toLoad = [];
		foreach( $usable as $i ) {
			if( empty( $filingByIndex[$i]['loaded'] ) ) {
				$toLoad[] = $rows[$i]['row'];
			}
		}
		if( !$topGalleryId ) {
			$errors[] = KernelTools::tra( 'There is no top-level Library gallery to file books under.' );
		} elseif( $toLoad ) {
			$results = FisheyeBookLoader::addRows( $toLoad );
		}
		foreach( $rows as $i => $one ) {
			if( !in_array( $i, $usable, true ) ) {
				$errors[] = $one['raw'].' - '.( $one['error'] ?? KernelTools::tra( 'not loaded' ) );
			}
		}
	} else {
		foreach( $rows as $i => $one ) {
			$listing[] = [ 'raw' => $one['raw'], 'isbn' => $one['isbn'], 'error' => $one['error'] ?? '', 'row' => $one['row'] ?? null, 'filing' => $filingByIndex[$i] ?? null,
				'manual_title' => $decode( $manualTitle[$one['raw']] ?? '' ), 'manual_author' => $decode( $manualAuthor[$one['raw']] ?? '' ) ];
		}
	}
}

$gBitSmarty->assign( 'hasTopGallery', (bool)$topGalleryId );
$topGalleryUrlHash = [ 'gallery_id' => $topGalleryId ];
$gBitSmarty->assign( 'topGalleryUrl', $topGalleryId ? FisheyeGallery::getDisplayUrlFromHash( $topGalleryUrlHash ) : null );
$gBitSmarty->assign( 'category', $category );
$gBitSmarty->assign( 'isbnText', $decode( $_REQUEST['isbns'] ?? '' ) );
$gBitSmarty->assign( 'maxIsbns', ADD_BOOKS_MAX );
$gBitSmarty->assign( 'overflow', $overflow );
$gBitSmarty->assign( 'listing', $listing );
$gBitSmarty->assign( 'results', $results );
$gBitSmarty->assign( 'loadErrors', $errors );

$gBitSystem->display( 'bitpackage:fisheyemedia/add_books.tpl', KernelTools::tra( 'Add Books' ), [ 'display_mode' => 'edit' ] );

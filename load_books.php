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

// Review mode (review_group): the books of one group, as read from the sheet, listed with their ISBN so each can be looked up and typed in
// before anything is created - the Process buttons on the front page open this instead of loading blind.
$reviewKey = !empty( $_REQUEST['review_group'] ) ? htmlspecialchars_decode( trim( (string)$_REQUEST['review_group'] ), ENT_NOQUOTES ) : null;
if( $reviewKey !== null && !isset( $groups[$reviewKey] ) ) {
	$reviewKey = null;
}

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
		// ISBNs typed on the review list go onto their rows first; a number that fails its check digit is reported and that book is left out.
		$typedErrors = [];
		foreach( (array)( $_REQUEST['isbn'] ?? [] ) as $typedIndex => $typedIsbn ) {
			$typedIsbn = htmlspecialchars_decode( trim( (string)$typedIsbn ), ENT_NOQUOTES );
			$typedIndex = (int)$typedIndex;
			if( $typedIsbn === '' || !isset( $groups[$groupKey]['rows'][$typedIndex] ) ) {
				continue;
			}
			$normalised = FisheyeBook::normaliseIsbn( $typedIsbn );
			if( $normalised['isbn13'] === '' ) {
				$typedErrors[] = $groups[$groupKey]['rows'][$typedIndex]['title'].': '.KernelTools::tra( 'not a valid ISBN' ).' ('.$typedIsbn.')';
				if( $pickedRows !== null ) {
					$pickedRows = array_values( array_diff( $pickedRows, [ $typedIndex ] ) );
				}
				continue;
			}
			$groups[$groupKey]['rows'][$typedIndex]['isbn13'] = $normalised['isbn13'];
			$groups[$groupKey]['rows'][$typedIndex]['isbn10'] = $normalised['isbn10'];
		}
		$result = FisheyeBookLoader::loadGroup( $groups[$groupKey], $pickedRows, $useSeries, $splitChosen, LOAD_BOOKS_BATCH );
		$result['errors'] = array_merge( $typedErrors, $result['errors'] ?? [] );
	}
}

// The combined list (every group's books read from the sheet, one list): the ticked rows are "<group>_<row>", and the ISBNs typed beside
// them come back as isbn[<group>_<row>]. Each group is loaded in turn, at most LOAD_BOOKS_BATCH books in all per press.
$results = [];
$groupKeys = array_keys( $groups );
$findText = htmlspecialchars_decode( trim( (string)( $_REQUEST['find'] ?? '' ) ), ENT_NOQUOTES );
if( !empty( $_REQUEST['process_all'] ) ) {
	$byGroup = [];
	foreach( (array)( $_REQUEST['book'] ?? [] ) as $pick ) {
		$parts = explode( '_', (string)$pick );
		if( count( $parts ) === 2 && isset( $groupKeys[(int)$parts[0]] ) ) {
			$byGroup[(int)$parts[0]][] = (int)$parts[1];
		}
	}
	$typedErrors = [];
	foreach( (array)( $_REQUEST['isbn'] ?? [] ) as $pickId => $typedIsbn ) {
		$typedIsbn = htmlspecialchars_decode( trim( (string)$typedIsbn ), ENT_NOQUOTES );
		$parts = explode( '_', (string)$pickId );
		if( $typedIsbn === '' || count( $parts ) !== 2 || !isset( $groupKeys[(int)$parts[0]] ) || !isset( $groups[$groupKeys[(int)$parts[0]]]['rows'][(int)$parts[1]] ) ) {
			continue;
		}
		[ $g, $ri ] = [ (int)$parts[0], (int)$parts[1] ];
		$normalised = FisheyeBook::normaliseIsbn( $typedIsbn );
		if( $normalised['isbn13'] === '' ) {
			$typedErrors[] = $groups[$groupKeys[$g]]['rows'][$ri]['title'].': '.KernelTools::tra( 'not a valid ISBN' ).' ('.$typedIsbn.')';
			if( isset( $byGroup[$g] ) ) {
				$byGroup[$g] = array_values( array_diff( $byGroup[$g], [ $ri ] ) );
			}
			continue;
		}
		$groups[$groupKeys[$g]]['rows'][$ri]['isbn13'] = $normalised['isbn13'];
		$groups[$groupKeys[$g]]['rows'][$ri]['isbn10'] = $normalised['isbn10'];
	}
	if( !$topContentId ) {
		$results[] = [ 'created' => 0, 'already' => 0, 'errors' => [ KernelTools::tra( 'There is no top-level Library gallery to file books under.' ) ], 'group' => '', 'details' => [] ];
	} else {
		$left = LOAD_BOOKS_BATCH;
		ksort( $byGroup );
		foreach( $byGroup as $g => $indexes ) {
			if( $left <= 0 || !$indexes ) {
				continue;
			}
			$one = FisheyeBookLoader::loadGroup( $groups[$groupKeys[$g]], $indexes, $useSeries, $splitChosen, $left );
			$left -= $one['created'] + $one['already'] + count( $one['errors'] );
			$results[] = $one;
		}
		if( $typedErrors ) {
			$results[] = [ 'created' => 0, 'already' => 0, 'errors' => $typedErrors, 'group' => '', 'details' => [] ];
		}
	}
}

// Opening an author's gallery files the books already loaded that credit them (a co-author's, mostly).
$filedHere = 0;
if( $authorGallery ) {
	$filedHere = FisheyeBookCredits::fileCreditedBooks( $catalogue['rows'], (int)$authorGallery->mContentId, $authorGallery->getTitle() );
}

// The address of the Library gallery named for a group, or '' while it has not been made - the page links to the artist's gallery from the
// group list, the review list and the completed message.
$galleryUrlFor = function( string $pName ): string {
	$contentId = FisheyeBookCredits::libraryGalleryFor( $pName );
	if( !$contentId ) {
		return '';
	}
	$gallery = new FisheyeGallery( null, $contentId );
	$gallery->load();
	return $gallery->isValid() ? (string)$gallery->getDisplayUrl() : '';
};

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
		$pending[] = [ 'key' => $key, 'kind' => $group['kind'], 'name' => $group['name'], 'total' => count( $group['rows'] ), 'loaded' => $loaded, 'url' => $galleryUrlFor( $group['name'] ) ];
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
			'coauthors' => array_slice( (array)( $row['authors'] ?? [] ), 1 ), 'series' => $seriesPosition[$rowIndex] ?? '', 'ticked' => $nextBatch-- > 0,
			'terms' => rawurlencode( trim( $row['title'].' '.( $row['authors'][0] ?? '' ) ) ), 'titleTerm' => rawurlencode( $row['title'] ), 'authorTerm' => rawurlencode( (string)( $row['authors'][0] ?? '' ) ) ];
	}
	$thisAuthor = mb_strtolower( FisheyeBookCredits::canonicalName( $authorGallery->getTitle() ) );
	foreach( $catalogue['rows'] as $row ) {
		$names = array_map( fn( $n ) => mb_strtolower( FisheyeBookCredits::canonicalName( (string)$n ) ), (array)( $row['authors'] ?? [] ) );
		if( ( $names[0] ?? '' ) !== $thisAuthor && in_array( $thisAuthor, $names, true ) ) {
			$coCredited[] = [ 'title' => $row['title'], 'primary' => FisheyeBookCatalogue::primaryAuthor( $row ), 'loaded' => (bool)FisheyeBook::findContentId( $row ) ];
		}
	}
}

$reviewName = '';
$reviewUrl = '';
if( !$authorGallery && $reviewKey !== null ) {
	$reviewName = $groups[$reviewKey]['name'];
	$reviewUrl = $galleryUrlFor( $reviewName );
	$booksTotal = $booksLoaded = 0;
	$nextBatch = LOAD_BOOKS_BATCH;
	foreach( $groups[$reviewKey]['rows'] as $rowIndex => $row ) {
		$booksTotal++;
		if( FisheyeBook::findContentId( $row ) ) {
			$booksLoaded++;
			continue;
		}
		$firstAuthor = (string)( $row['authors'][0] ?? '' );
		$bookList[] = [ 'index' => $rowIndex, 'title' => $row['title'], 'year' => substr( (string)( $row['published'] ?? '' ), 0, 4 ),
			'isbn' => (string)( $row['isbn13'] ?? '' ), 'coauthors' => array_slice( (array)( $row['authors'] ?? [] ), 1 ), 'series' => '',
			'ticked' => $nextBatch-- > 0, 'terms' => rawurlencode( trim( $row['title'].' '.$firstAuthor ) ),
			'titleTerm' => rawurlencode( $row['title'] ), 'authorTerm' => rawurlencode( $firstAuthor ) ];
	}
}

// The combined list: every book still to load across all groups (narrowed by the find box), grouped by author, the first LOAD_BOOKS_BATCH ticked.
$allList = [];
$allTotal = $allLoaded = 0;
if( !$authorGallery && $reviewKey === null ) {
	$nextBatch = LOAD_BOOKS_BATCH;
	$needle = mb_strtolower( $findText );
	foreach( $groups as $groupKey => $group ) {
		$g = array_search( $groupKey, $groupKeys, true );
		foreach( $group['rows'] as $rowIndex => $row ) {
			$allTotal++;
			if( FisheyeBook::findContentId( $row ) ) {
				$allLoaded++;
				continue;
			}
			$names = implode( ', ', (array)( $row['authors'] ?? [] ) );
			if( $needle !== '' && mb_strpos( mb_strtolower( $row['title'].' '.$names.' '.$group['name'] ), $needle ) === false ) {
				continue;
			}
			$firstAuthor = (string)( $row['authors'][0] ?? '' );
			$allList[] = [ 'id' => $g.'_'.$rowIndex, 'group' => $group['name'], 'groupUrl' => '', 'kind' => $group['kind'], 'title' => $row['title'],
				'year' => substr( (string)( $row['published'] ?? '' ), 0, 4 ), 'authors' => $names, 'isbn' => (string)( $row['isbn13'] ?? '' ),
				'ticked' => $nextBatch-- > 0, 'terms' => rawurlencode( trim( $row['title'].' '.$firstAuthor ) ),
				'titleTerm' => rawurlencode( $row['title'] ), 'authorTerm' => rawurlencode( $firstAuthor ) ];
		}
	}
	$urls = [];
	foreach( $allList as &$listed ) {
		$urls[$listed['group']] ??= $galleryUrlFor( $listed['group'] );
		$listed['groupUrl'] = $urls[$listed['group']];
	}
	unset( $listed );
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
$gBitSmarty->assign( 'reviewKey', $reviewKey );
$gBitSmarty->assign( 'reviewName', $reviewName );
$gBitSmarty->assign( 'reviewUrl', $reviewUrl );
$gBitSmarty->assign( 'booksTotal', $booksTotal );
$gBitSmarty->assign( 'booksLoaded', $booksLoaded );
$gBitSmarty->assign( 'onlyKey', $onlyKey );
$gBitSmarty->assign( 'useSeries', $useSeries );
$gBitSmarty->assign( 'seriesPlan', $seriesPlanView );
$gBitSmarty->assign( 'seriesWikidataFailed', $authorGallery && $useSeries && isset( $groups[$onlyKey] ) && $seriesPlanView === null );
$gBitSmarty->assign( 'coCredited', $coCredited );
$gBitSmarty->assign( 'authorInCatalogue', $onlyKey !== null && isset( $groups[$onlyKey] ) );
if( $result ) {
	$results[] = $result;
}
$gBitSmarty->assign( 'results', $results );
$gBitSmarty->assign( 'allList', $allList );
$gBitSmarty->assign( 'allTotal', $allTotal );
$gBitSmarty->assign( 'allLoaded', $allLoaded );
$gBitSmarty->assign( 'findText', $findText );
$gBitSmarty->assign( 'result', $result );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_books.tpl', KernelTools::tra( 'Add books from a sheet' ), [ 'display_mode' => 'edit' ] );

<?php
/**
 * Tidies the Library's books that have a cover, from Open Library: each is looked up (FisheyeBook::reloadFromOpenLibrary()) and gets
 * what it is missing - edition and work ids, pages, format, date, language, publisher, edition name, its description, genres and the
 * links to Wikipedia, Goodreads, LibraryThing, WorldCat, Google Books and the Internet Archive. Blanks only: nothing the catalogue
 * or an editor entered is replaced, so a book can be run again without harm. The same action as 'Reload from Open Library' on a
 * book's own edit page.
 *
 * One press runs as many books as fit in LOAD_BOOK_DETAILS_BUDGET seconds, then the page sends itself again until the list is
 * done (a Stop link ends it); the run walks the books in content id order, so a book Open Library cannot add to is passed over
 * rather than asked again and again - and once asked, a book is marked (the 'ol_details_checked' / 'ol_covers_checked' content preference), so a book
 * Open Library has no description, genre or cover for does not stay on the list for ever; 'ask again' lifts the mark. Two jobs, chosen on the page: 'details' - the books whose cover is in place get their blanks
 * filled in; 'covers' - the books with no image yet are looked up for a cover as well (Open Library, then Google Books). A book no source has a
 * cover for waits for a photograph or scan (their list is a link at the foot of the page).
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

// A press runs for a minute or more. PHP holds the visitor's session file locked until the request ends, so a second press (or any other
// page opened meanwhile) would wait for this one to finish - release it now; nothing below writes to the session.
if( !empty( $_REQUEST['fetch'] ) ) {
	session_write_close();
}

// Seconds a press may spend asking Open Library - a batch of about 40 s, as the other bulk pages (the web server gives up on a page at 150 s), and the delay before the page sends itself again.
const LOAD_BOOK_DETAILS_BUDGET = 40;
// Rows of the no-cover list shown.
const LOAD_BOOK_DETAILS_NOCOVER_SHOWN = 100;
// Books per press for the placeholder-cover replacement, which also fetches covers.
const LOAD_BOOK_DETAILS_BATCH = 10;

/** Content preference that marks a book already asked about, per job. */
const LOAD_BOOK_DETAILS_MARK = [ 'details' => 'ol_details_checked', 'covers' => 'ol_covers_checked' ];

/**
 * The books worth asking Open Library about, in content id order after $pAfter, leaving out those already asked about for this job
 * (unless $pAgain).
 *  'details': an ISBN and a cover in place, and something Open Library might hold still missing - its edition id, a description, a genre,
 *             or the thumbnail attachment that shows the cover.
 *  'covers':  an ISBN and no image yet (the book's tile is blank), whatever else it has.
 *
 * @return list<int>
 */
function load_book_details_pending( int $pAfter = 0, string $pJob = 'details', bool $pAgain = false ): array {
	global $gBitDb;
	$live = "x.`end_date` IS NULL";
	$has = fn( string $pItem ) => "EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` x WHERE x.`content_id` = lc.`content_id` AND x.`item` = '$pItem' AND $live )";
	$job = $pJob === 'covers'
		? "NOT ".$has( 'image' )
		: $has( 'image' )." AND ( NOT ".$has( 'ol_edition' )." OR NOT ".$has( 'genre' )." OR lc.`data` IS NULL OR CHAR_LENGTH( lc.`data` ) = 0
		    OR NOT EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_attachments` a WHERE a.`content_id` = lc.`content_id` ) )";
	$mark = $pAgain ? '' : " AND NOT EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_content_prefs` p WHERE p.`content_id` = lc.`content_id` AND p.`pref_name` = '".LOAD_BOOK_DETAILS_MARK[$pJob === 'covers' ? 'covers' : 'details']."' )";
	$rows = $gBitDb->getCol(
		"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."liberty_content` lc
		 WHERE lc.`content_type_guid` = 'fisheyebook' AND lc.`content_id` > ? AND ".$has( 'isbn' )." AND $job$mark
		 ORDER BY lc.`content_id`", [ $pAfter ]
	) ?: [];
	return array_map( 'intval', $rows );
}

$after = max( 0, (int)( $_REQUEST['after'] ?? 0 ) );
$job = ( $_REQUEST['job'] ?? '' ) === 'covers' ? 'covers' : 'details';
$again = !empty( $_REQUEST['again'] );
$pending = load_book_details_pending( $after, $job, $again );
$allPending = $after ? count( load_book_details_pending( 0, $job, $again ) ) : count( $pending );
$otherPending = count( load_book_details_pending( 0, $job === 'covers' ? 'details' : 'covers', $again ) );
$doneBefore = (int)( $_REQUEST['done'] ?? 0 );
$changedBefore = (int)( $_REQUEST['changed'] ?? 0 );

$results = null;
$more = false;
$busy = false;
$pressSeconds = 0.0;
$secondsBefore = (float)( $_REQUEST['secs'] ?? 0 );
if( !empty( $_REQUEST['fetch'] ) ) {
	// One run at a time, and a second press does not queue behind the first (it would then run its own batch on top of the first's and
	// outlast the web server's limit): it is told a run is going and tries again shortly.
	$lock = @fopen( sys_get_temp_dir().'/fisheyemedia_book_details.lock', 'c' );
	if( $lock && !flock( $lock, LOCK_EX | LOCK_NB ) ) {
		$busy = true;
	} else {
		ignore_user_abort( true );
		$results = [];
		$started = microtime( true );
		$last = $after;
		foreach( $pending as $contentId ) {
			if( microtime( true ) - $started > LOAD_BOOK_DETAILS_BUDGET ) {
				$more = true;
				break;
			}
			$last = $contentId;
			$book = FisheyeImage::lookup( [ 'content_id' => $contentId ] );
			if( !$book || !$book->isValid() || !method_exists( $book, 'reloadFromOpenLibrary' ) ) {
				continue;
			}
			$found = $book->reloadFromOpenLibrary();
			// Asked and answered (even "nothing more here"): marked, so it is not asked again - unless Open Library did not answer.
			if( empty( $found['error'] ) ) {
				$book->storePreference( LOAD_BOOK_DETAILS_MARK[$job], (string)time() );
			}
			$results[] = [ 'title' => $book->getTitle(), 'url' => $book->getDisplayUrl(), 'matched' => $found['matched'], 'items' => $found['items'], 'error' => $found['error'] ?? '' ];
		}
		$pressSeconds = microtime( true ) - $started;
		$after = $last;
		$doneBefore += count( $results );
		$changedBefore += count( array_filter( $results, fn( $r ) => $r['items'] ) );
		$pending = load_book_details_pending( $after, $job, $again );
		// Stop when Open Library stops answering rather than asking the rest: a run of errors is the service, not the books.
		$errors = count( array_filter( $results, fn( $r ) => $r['error'] ) );
		$more = $pending && $errors < max( 3, count( $results ) / 2 ) && ( $more || $results );
		if( $lock ) {
			flock( $lock, LOCK_UN );
		}
	}
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
	$pending = load_book_details_pending( $after, $job, $again );
}

// Books with no image at all (no thumbnail attachment): the ones that need a cover of your own - a photograph or a scan. Title
// order, with the author, year and ISBN so the right copy is picked up. The first image added to a book becomes its tile.
$noCover = [];
foreach( $gBitDb->getAll(
	"SELECT lc.`content_id`, lc.`title`, lc.`event_time` FROM `".BIT_DB_PREFIX."liberty_content` lc
	 WHERE lc.`content_type_guid` = 'fisheyebook'
	 AND NOT EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_attachments` a WHERE a.`content_id` = lc.`content_id` )
	 ORDER BY lc.`title`"
) ?: [] as $row ) {
	$noCover[(int)$row['content_id']] = [ 'content_id' => (int)$row['content_id'], 'title' => $row['title'], 'year' => $row['event_time'] ? gmdate( 'Y', (int)$row['event_time'] ) : '', 'authors' => [], 'isbn' => '' ];
}
if( $noCover ) {
	$ids = implode( ',', array_keys( $noCover ) );
	foreach( $gBitDb->getAll( "SELECT `content_id`, `item`, `xkey`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` IN ( $ids ) AND `end_date` IS NULL AND `item` IN ( 'author', 'isbn' ) ORDER BY `xorder`" ) ?: [] as $x ) {
		if( $x['item'] === 'author' && (string)$x['xkey_ext'] !== '' ) {
			$noCover[(int)$x['content_id']]['authors'][] = (string)$x['xkey_ext'];
		} elseif( $x['item'] === 'isbn' ) {
			$noCover[(int)$x['content_id']]['isbn'] = (string)$x['xkey'];
		}
	}
}

$gBitSmarty->assign( 'noCover', array_slice( array_values( $noCover ), 0, LOAD_BOOK_DETAILS_NOCOVER_SHOWN ) );
$gBitSmarty->assign( 'noCoverCount', count( $noCover ) );
$gBitSmarty->assign( 'noCoverShown', min( count( $noCover ), LOAD_BOOK_DETAILS_NOCOVER_SHOWN ) );
$gBitSmarty->assign( 'blankCount', count( $blankBooks ) );
$gBitSmarty->assign( 'replaced', $replaced );
$gBitSmarty->assign( 'pendingCount', count( $pending ) );
$gBitSmarty->assign( 'allPending', $allPending );
$gBitSmarty->assign( 'after', $after );
$gBitSmarty->assign( 'job', $job );
$gBitSmarty->assign( 'again', $again );
$gBitSmarty->assign( 'otherPending', $otherPending );
$gBitSmarty->assign( 'doneCount', $doneBefore );
$gBitSmarty->assign( 'changedCount', $changedBefore );
$gBitSmarty->assign( 'more', $more );
$gBitSmarty->assign( 'busy', $busy );
$gBitSmarty->assign( 'pressSeconds', round( $pressSeconds, 1 ) );
$gBitSmarty->assign( 'totalSeconds', round( $secondsBefore + $pressSeconds, 1 ) );
$gBitSmarty->assign( 'pressBooks', is_array( $results ) ? count( $results ) : 0 );
$gBitSmarty->assign( 'showNoCover', !empty( $_REQUEST['nocover'] ) );
$gBitSmarty->assign( 'batchSize', LOAD_BOOK_DETAILS_BATCH );
$gBitSmarty->assign( 'results', $results );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_book_details.tpl', KernelTools::tra( 'Book Details from Open Library' ), [ 'display_mode' => 'edit' ] );

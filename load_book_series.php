<?php
/**
 * Proposes an author's series from Wikidata and Open Library - the web-sourced counterpart of
 * load_series.php's folder-driven proposal, with the same review step because the match is by title.
 *
 * For an author gallery whose contact has a Wikidata id it reads the author's works in a series
 * (FisheyeSeries::wikidataWorks()), adds the series text Open Library gave each edition, and proposes which
 * of the gallery's own books make up which series (FisheyeSeries::proposeFromSources() - read-only), with the
 * source of each suggestion and the order it would be filed in. Only the series that are ticked are made: a
 * series gallery under the author (or under its umbrella series), the books moved into it in the chosen order.
 * A book moved into a series leaves the author's own list; it stays wherever else it is filed (a co-author's).
 *
 * "Look up Open Library series text" asks Open Library again for the books that have none stored - the
 * editions loaded before the details fetch kept a series.
 *
 * Nothing is written until the create button is pressed, and what is made is recomputed from Wikidata and the
 * database at that moment - the form only says which series, what to call them and how to order them.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;
use Bitweaver\Fisheye\FisheyeImage;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

// A series with at least this many proposed books is preticked.
const LOAD_BOOK_SERIES_PRETICK_MIN = 2;
// Open Library lookups per press - each is a couple of requests.
const LOAD_BOOK_SERIES_LOOKUP_BATCH = 10;

$gContent = FisheyeGallery::lookup( [ 'gallery_id' => $_REQUEST['gallery_id'] ?? 0 ] );
if( !$gContent || !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No gallery exists with the given ID' ) );
}
$authorContentId = (int)$gContent->mContentId;
$decode = fn( $pValue ) => htmlspecialchars_decode( trim( (string)$pValue ), ENT_NOQUOTES );

$contactId = (int)$gContent->getPreference( 'author_contact_id' );
$qid = $contactId ? (string)$gBitDb->getOne( "SELECT `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'wikidata' AND `end_date` IS NULL", [ $contactId ] ) : '';

/**
 * The books directly in the author's gallery, with the series text Open Library gave each.
 */
function load_book_series_books( int $pAuthorContentId ): array {
	global $gBitDb;
	$rows = $gBitDb->getAll(
		"SELECT lc.`content_id`, lc.`title`, lc.`event_time` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` fgim
		 INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = fgim.`item_content_id`
		 WHERE fgim.`gallery_content_id` = ? AND lc.`content_type_guid` = 'fisheyebook' ORDER BY lc.`content_id`",
		[ $pAuthorContentId ]
	);
	$books = [];
	foreach( $rows as $r ) {
		$series = $gBitDb->getOne( "SELECT `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'series' AND `end_date` IS NULL", [ (int)$r['content_id'] ] );
		$books[] = [ 'content_id' => (int)$r['content_id'], 'title' => (string)$r['title'], 'event_time' => (int)$r['event_time'], 'ol_series' => (string)$series ];
	}
	return $books;
}

$lookup = null;
if( !empty( $_REQUEST['lookup_series'] ) ) {
	$lookup = [ 'done' => 0, 'with' => 0, 'errors' => [] ];
	foreach( load_book_series_books( $authorContentId ) as $book ) {
		if( $lookup['done'] >= LOAD_BOOK_SERIES_LOOKUP_BATCH ) {
			break;
		}
		if( $book['ol_series'] !== '' ) {
			continue;
		}
		$item = FisheyeImage::lookup( [ 'content_id' => $book['content_id'] ] );
		if( $item && $item->isValid() && method_exists( $item, 'reloadFromOpenLibrary' ) ) {
			$found = $item->reloadFromOpenLibrary();
			$lookup['done']++;
			if( !empty( $found['error'] ) ) {
				$lookup['errors'][] = $book['title'].' - '.$found['error'];
			}
		}
	}
	$lookup['with'] = count( array_filter( load_book_series_books( $authorContentId ), fn( $b ) => $b['ol_series'] !== '' ) );
}

// A series that has lost its place under any author (saving it on the Edit page used to do that) is offered here
// to be put back under this author.
$attached = null;
if( !empty( $_REQUEST['attach_series'] ) ) {
	$attached = 0;
	foreach( array_keys( (array)$_REQUEST['attach_series'] ) as $orphanId ) {
		$orphanId = (int)$orphanId;
		if( $gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ? AND `content_type_guid` = 'fisheyeseries'", [ $orphanId ] )
			&& !$gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` WHERE `item_content_id` = ?", [ $orphanId ] )
			&& $gContent->addItem( $orphanId ) ) {
			$attached++;
		}
	}
}
$orphans = $gBitDb->getAll(
	"SELECT lc.`content_id`, lc.`title` FROM `".BIT_DB_PREFIX."liberty_content` lc
	 WHERE lc.`content_type_guid` = 'fisheyeseries' AND NOT EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` fgim WHERE fgim.`item_content_id` = lc.`content_id` )
	 ORDER BY lc.`title`" );

$works = $qid !== '' ? FisheyeSeries::wikidataWorks( $qid ) : null;
// The umbrella choices last posted (a trilogy kept apart, or folded into its long series), else the defaults.
$umbrellas = $works !== null ? FisheyeSeries::umbrellas( $works ) : [];
$splitChosen = isset( $_REQUEST['series_form'] ) ? array_map( 'strval', array_keys( (array)( $_REQUEST['split'] ?? [] ) ) )
	: array_column( array_filter( $umbrellas, fn( $u ) => $u['default'] ), 'key' );

$result = null;
if( !empty( $_REQUEST['create_series'] ) && $works !== null ) {
	$result = [ 'created' => [], 'errors' => [] ];
	$proposal = FisheyeSeries::proposeFromSources( load_book_series_books( $authorContentId ), $works, $splitChosen );
	$byKey = [];
	foreach( $proposal['series'] as $entry ) {
		$byKey[$entry['key']] = $entry;
	}
	$made = [];
	// The proposal lists an umbrella before what is inside it, so a parent's gallery exists when its child is made.
	$posted = [];
	foreach( (array)( $_REQUEST['series_create'] ?? [] ) as $i => $ticked ) {
		$posted[$decode( $_REQUEST['series_key'][$i] ?? '' )] = $i;
	}
	foreach( $proposal['series'] as $entry ) {
		if( !isset( $posted[$entry['key']] ) ) {
			continue;
		}
		$i = $posted[$entry['key']];
		$name = $decode( $_REQUEST['series_name'][$i] ?? '' ) ?: $entry['name'];
		$order = $decode( $_REQUEST['series_order'][$i] ?? '' ) === 'number' && $entry['by_number'] ? 'by_number' : 'by_date';
		$parentContentId = $entry['parent'] !== null && isset( $made[$entry['parent']] ) ? $made[$entry['parent']] : $authorContentId;
		$into = FisheyeSeries::findOrCreateUnder( $name, $parentContentId );
		if( !$into['series'] ) {
			$result['errors'][] = $name.' - '.$into['error'];
			continue;
		}
		$series = $into['series'];
		$made[$entry['key']] = (int)$series->mContentId;
		$one = [ 'already' => !$into['created'] ];
		$moved = 0;
		$ordered = [];
		foreach( $entry[$order] as $book ) {
			$series->addItem( $book['content_id'] );
			if( $series->isInGallery( $series->mContentId, $book['content_id'] ) ) {
				// Leaves the author's own list only once it really is in the series; a book that was never
				// directly there (an earlier run) has nothing to remove.
				$gContent->removeItem( $book['content_id'] );
				$ordered[] = $book['content_id'];
				$moved++;
			}
		}
		$series->setOrder( $ordered );
		$result['created'][] = [ 'name' => $name, 'moved' => $moved, 'merged' => !empty( $one['already'] ), 'nested' => $parentContentId !== $authorContentId ];
	}
}

$proposal = $works !== null ? FisheyeSeries::proposeFromSources( load_book_series_books( $authorContentId ), $works, $splitChosen ) : null;
$addYear = fn( array $book ) => $book + [ 'year' => substr( (string)$book['date'], 0, 4 ) ];
if( $proposal ) {
	foreach( $proposal['series'] as $i => $entry ) {
		foreach( [ 'books', 'by_date', 'by_number' ] as $field ) {
			if( $proposal['series'][$i][$field] !== null ) {
				$proposal['series'][$i][$field] = array_map( $addYear, $proposal['series'][$i][$field] );
			}
		}
	}
}
$directBooks = load_book_series_books( $authorContentId );

$authorUrlHash = [ 'gallery_id' => $gContent->mGalleryId ];
$gBitSmarty->assign( 'authorUrl', FisheyeGallery::getDisplayUrlFromHash( $authorUrlHash ) );
$gBitSmarty->assign( 'authorTitle', $gContent->getTitle() );
$gBitSmarty->assign( 'galleryId', $gContent->mGalleryId );
$gBitSmarty->assign( 'qid', $qid );
$gBitSmarty->assign( 'hasContact', (bool)$contactId );
$gBitSmarty->assign( 'wikidataFailed', $qid !== '' && $works === null );
$gBitSmarty->assign( 'wikidataWorks', $works === null ? 0 : count( $works ) );
$gBitSmarty->assign( 'proposal', $proposal );
$gBitSmarty->assign( 'directCount', count( $directBooks ) );
$gBitSmarty->assign( 'noSeriesText', count( array_filter( $directBooks, fn( $b ) => $b['ol_series'] === '' ) ) );
$gBitSmarty->assign( 'preTickMin', LOAD_BOOK_SERIES_PRETICK_MIN );
$gBitSmarty->assign( 'result', $result );
$gBitSmarty->assign( 'lookup', $lookup );
$gBitSmarty->assign( 'orphans', $orphans );
$gBitSmarty->assign( 'umbrellas', $umbrellas );
$gBitSmarty->assign( 'splitChosen', $splitChosen );
$gBitSmarty->assign( 'attached', $attached );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_book_series.tpl', KernelTools::tra( 'Series from Wikidata' ), [ 'display_mode' => 'edit' ] );

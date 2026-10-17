<?php
/**
 * The authors pass for the Library's books - turns the author names on the catalogue into contacts, ten
 * people at a time, so every book can link to its author's page. Run it BEFORE loading books: it works
 * from the uploaded catalogue spreadsheet, and the book loader then links each credit to the contact and
 * files a pen name's books with the real name's. Run later, or from an author's gallery, it works from
 * the credits of the books already loaded instead, and links those. The books counterpart of contactwiki's
 * load_wiki_people.php, using Open Library as its source:
 *
 *   - each person is looked up from their own books: the ISBN goes to Open Library, which names the work's
 *     authors, so a namesake (Michael Scott, Peter Jackson) is told apart by the book rather than guessed at.
 *     An author found this way is pre-chosen. With no ISBN to go on (or no hit) Open Library's author search
 *     by name is offered, nothing pre-chosen.
 *   - a contact already answering to the name (or to an other-name Open Library supplied earlier, a pen name)
 *     is offered too, and pre-chosen when Open Library found nobody.
 *   - the author's Open Library record carries the Wikidata id, so a contact is made from Wikidata when it
 *     has an item (ContactWikiTrait::createFromOpenLibrary()) and topped up with Open Library's own ids,
 *     dates, biography and portrait; otherwise it is made from Open Library alone.
 *   - a person with nothing found is offered as a name-only writer, pre-chosen, to be upgraded later.
 *
 * From an author's own gallery (gallery_id) it is that gallery's authors only - usually the one - and they
 * are looked up straight away. Otherwise the page opens on a local list of the names - no requests - to tick
 * from, and only the ticked ones (ten at a time) are looked up. Nothing is created until the proposals are
 * reviewed and submitted, and what is linked is recomputed from the database at that moment, not taken from
 * the form.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\Contactwiki\ContactWikiIndividual as Wiki;
use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPackage( 'contactwiki' );
$gBitSystem->verifyPermission( 'p_contact_update' );

// People looked up per press - Open Library takes a few requests each, one at a time.
const LOAD_AUTHORS_BATCH = 10;
// Names shown per page of the local list.
const LOAD_AUTHORS_LIST_PAGE = 30;

// detoxify() HTML-escapes every $_REQUEST value - undone here, as load_series.php does.
$decode = fn( $pValue ) => htmlspecialchars_decode( trim( (string)$pValue ), ENT_NOQUOTES );

// From an author's gallery: only the names credited on its books, looked up on arrival.
$gallery = !empty( $_REQUEST['gallery_id'] ) ? \Bitweaver\Fisheye\FisheyeGallery::lookup( [ 'gallery_id' => (int)$_REQUEST['gallery_id'] ] ) : null;
if( $gallery && !$gallery->isValid() ) {
	$gallery = null;
}
$galleryContentId = $gallery ? (int)$gallery->mContentId : null;
// The spreadsheet when there is one and no single gallery was asked for; else the credits of the loaded books.
$catalogue = $galleryContentId ? [ 'rows' => [], 'file' => null ] : FisheyeBookCatalogue::cached();
$fromSheet = !empty( $catalogue['rows'] );
$loadPeople = fn() => $fromSheet ? FisheyeBookCredits::sheetPeople( $catalogue['rows'] ) : FisheyeBookCredits::unlinkedPeople( $galleryContentId );
$people = $loadPeople();
// From an author's gallery whose author already has a contact (made earlier, or by another pass): link it now.
if( $gallery && ( $existingContactId = FisheyeBookCredits::authorContactFor( $gallery->getTitle() ) ) ) {
	FisheyeBookCredits::linkGalleryToContact( $galleryContentId, $existingContactId );
}
$start = max( 0, (int)( $_REQUEST['start'] ?? 0 ) );

$result = null;
if( !empty( $_REQUEST['create_authors'] ) ) {
	$result = [ 'done' => [], 'errors' => [], 'skipped' => 0 ];
	foreach( (array)( $_REQUEST['choice'] ?? [] ) as $i => $choice ) {
		$name = $decode( $_REQUEST['person'][$i] ?? '' );
		$choice = $decode( $choice );
		$person = $people[FisheyeBookCredits::nameKey( $name )] ?? null;
		if( !$person ) {
			continue;
		}
		if( $choice === '' || $choice === 'skip' ) {
			$result['skipped']++;
			continue;
		}
		if( str_starts_with( $choice, 'ol:' ) ) {
			$made = Wiki::createFromOpenLibrary( substr( $choice, 3 ) );
		} elseif( str_starts_with( $choice, 'contact:' ) ) {
			$existing = new Wiki( null, (int)substr( $choice, 8 ) );
			$existing->load();
			if( $existing->isValid() ) {
				// An existing contact taken as the author: tagged as a writer, and given the Open Library id the
				// person's books pointed to, so the name counts as done from now on.
				$existing->markAsWriter();
				if( preg_match( '/^OL\d+A$/', $hint = $decode( $_REQUEST['olhint'][$i] ?? '' ) ) ) {
					$existing->applyOpenLibraryData( $hint );
				}
				$made = [ 'content' => $existing ];
			} else {
				$made = [ 'error' => KernelTools::tra( 'No such contact' ) ];
			}
		} else {
			$made = Wiki::createNameOnly( $person['name'], [ 'author' ] );
		}
		if( empty( $made['content'] ) ) {
			$result['errors'][] = $person['name'].' - '.( $made['error'] ?? KernelTools::tra( 'could not be created' ) );
			continue;
		}
		$contact = $made['content'];
		FisheyeBookCredits::rememberCreditNames( $contact, $person );
		$olRow = \Bitweaver\Liberty\LibertyContent::lookupXrefByItem( $contact->mContentId, 'openlibrary', $contact->mContentTypeGuid );
		$xkey = $contact->getWikidataQid() ?: (string)( $olRow['xkey_ext'] ?? '' );
		// Credits on books already loaded - from the sheet, a person's credit rows are looked up here.
		$creditIds = $fromSheet ? ( FisheyeBookCredits::unlinkedPeople()[FisheyeBookCredits::nameKey( $person['name'] )]['xref_ids'] ?? [] ) : $person['xref_ids'];
		$linked = FisheyeCredits::linkRows( $creditIds, (int)$contact->mContentId, $xkey, null, [ 'fisheyebook' ] );
		// File the contact on the author's gallery: this one when asked from a gallery, else the Library gallery
		// of that name (as the contact's name or as the credit was written).
		if( $gallery && array_intersect( Wiki::nameForms( $person['name'] ), Wiki::nameForms( $gallery->getTitle() ) ) ) {
			FisheyeBookCredits::linkGalleryToContact( $galleryContentId, (int)$contact->mContentId );
		} elseif( !$gallery ) {
			foreach( array_unique( [ FisheyeBookCredits::contactName( (int)$contact->mContentId ), $person['name'] ] ) as $galleryTitle ) {
				if( $libraryGalleryId = FisheyeBookCredits::libraryGalleryFor( $galleryTitle ) ) {
					FisheyeBookCredits::linkGalleryToContact( $libraryGalleryId, (int)$contact->mContentId );
				}
			}
		}
		$result['done'][] = [ 'name' => $person['name'], 'contact' => FisheyeBookCredits::contactName( (int)$contact->mContentId ), 'url' => $contact->getDisplayUrl(), 'linked' => $linked, 'books' => count( $person['books'] ) ];
	}
	// Whoever is now linked drops out of the list; anyone left for now stays on it.
	FisheyeBookCredits::resetCache();
	$people = $loadPeople();
}

// Who to look up: from a gallery, its people on arrival; from the list, the ones ticked.
$pick = [];
foreach( (array)( $_REQUEST['pick'] ?? [] ) as $pickedName ) {
	$pick[FisheyeBookCredits::nameKey( $decode( $pickedName ) )] = true;
}
$toLookUp = null;
if( $galleryContentId && !$result ) {
	$toLookUp = array_slice( $people, 0, LOAD_AUTHORS_BATCH, true );
} elseif( !empty( $_REQUEST['scan'] ) && $pick ) {
	$toLookUp = array_slice( array_intersect_key( $people, $pick ), 0, LOAD_AUTHORS_BATCH, true );
}
$batch = null;
if( $toLookUp ) {
	$index = Wiki::nameIndex();
	$batch = [];
	foreach( $toLookUp as $key => $person ) {
		$batch[] = FisheyeBookCredits::proposeFor( $person + [ 'key' => $key ], $index );
	}
}

// The local list: a page of the names still without a contact, the first few ticked.
$listPeople = [];
foreach( array_slice( $people, $start, LOAD_AUTHORS_LIST_PAGE, true ) as $person ) {
	$listPeople[] = [ 'name' => $person['name'], 'bookCount' => count( $person['books'] ), 'roles' => array_keys( array_diff_key( $person['roles'], [ 'author' => 1 ] ) ),
		'also' => implode( ', ', array_diff( $person['variants'] ?? [], [ $person['name'] ] ) ), 'titles' => implode( ', ', array_slice( array_values( $person['books'] ), 0, 3 ) ), 'preticked' => count( $listPeople ) < LOAD_AUTHORS_BATCH ];
}
$gBitSmarty->assign( 'listPeople', $listPeople );
$gBitSmarty->assign( 'prevStart', $start > 0 ? max( 0, $start - LOAD_AUTHORS_LIST_PAGE ) : -1 );
$gBitSmarty->assign( 'nextStart', $start + LOAD_AUTHORS_LIST_PAGE < count( $people ) ? $start + LOAD_AUTHORS_LIST_PAGE : -1 );

$gBitSmarty->assign( 'galleryId', $gallery ? $gallery->mGalleryId : 0 );
$gBitSmarty->assign( 'galleryTitle', $gallery ? $gallery->getTitle() : '' );
$gBitSmarty->assign( 'galleryUrl', $gallery ? $gallery->getDisplayUrl() : '' );
$gBitSmarty->assign( 'fromSheet', $fromSheet );
$gBitSmarty->assign( 'sheetFile', $catalogue['file'] );
$gBitSmarty->assign( 'unlinkedCount', count( $people ) );
$gBitSmarty->assign( 'start', $start );
$gBitSmarty->assign( 'batchSize', LOAD_AUTHORS_BATCH );
$gBitSmarty->assign( 'batch', $batch );
$gBitSmarty->assign( 'result', $result );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_authors.tpl', KernelTools::tra( 'Authors from Open Library' ), [ 'display_mode' => 'edit' ] );

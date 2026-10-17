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
 * are looked up straight away. Nothing else is looked up until the button is pressed, and nothing is created until the list is reviewed and
 * submitted. What is linked is recomputed from the database at that moment, not taken from the form.
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

// People looked at per press - Open Library takes a few requests each, one at a time.
const LOAD_AUTHORS_BATCH = 10;

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
		$person = $people[mb_strtolower( $name )] ?? null;
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
			$made = $existing->isValid() ? [ 'content' => $existing ] : [ 'error' => KernelTools::tra( 'No such contact' ) ];
		} else {
			$made = Wiki::createNameOnly( $person['name'], [ 'author' ] );
		}
		if( empty( $made['content'] ) ) {
			$result['errors'][] = $person['name'].' - '.( $made['error'] ?? KernelTools::tra( 'could not be created' ) );
			continue;
		}
		$contact = $made['content'];
		$olRow = \Bitweaver\Liberty\LibertyContent::lookupXrefByItem( $contact->mContentId, 'openlibrary', $contact->mContentTypeGuid );
		$xkey = $contact->getWikidataQid() ?: (string)( $olRow['xkey_ext'] ?? '' );
		// Credits on books already loaded - from the sheet, a person's credit rows are looked up here.
		$creditIds = $fromSheet ? ( FisheyeBookCredits::unlinkedPeople()[mb_strtolower( $person['name'] )]['xref_ids'] ?? [] ) : $person['xref_ids'];
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
	// Whoever is now linked drops out of the list; the ones left over are what the next batch starts after.
	FisheyeBookCredits::resetCache();
	$left = $loadPeople();
	$submitted = array_map( fn( $i ) => mb_strtolower( $decode( $_REQUEST['person'][$i] ?? '' ) ), array_keys( (array)( $_REQUEST['choice'] ?? [] ) ) );
	$start += count( array_intersect( $submitted, array_keys( $left ) ) );
	$people = $left;
}

$batch = null;
if( !empty( $_REQUEST['scan'] ) || ( $galleryContentId && !$result ) ) {
	$index = Wiki::nameIndex();
	$batch = [];
	foreach( array_slice( $people, $start, LOAD_AUTHORS_BATCH, true ) as $key => $person ) {
		$batch[] = FisheyeBookCredits::proposeFor( $person + [ 'key' => $key ], $index );
	}
}

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

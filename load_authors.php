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
 * "Also load their books" (on by default when working from the sheet) loads the books of each author made
 * who has only one or two of their own as primary author, in the same press: a one-book author's book is
 * filed under its Category gallery, a two-book author's under their own Library gallery, as the book loader
 * would. Bigger authors keep the book loader's tick-list and series choices.
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
// Books loaded per press of "Create selected" for authors with at most LOAD_AUTHORS_BOOKS_MAX of their own
// (as primary author): every one is an Open Library request or two, so the rest wait for the book loader.
const LOAD_AUTHORS_BOOKS_PER_PRESS = 10;
const LOAD_AUTHORS_BOOKS_MAX = 2;
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
// A catalogue uploaded here is read straight away, so the authors pass does not need the book loader first.
$import = $galleryContentId ? null : FisheyeBookCatalogue::importUploaded();
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

// Authors made earlier have a contact but no gallery: one press makes them, with the books already loaded.
$galleriesMade = [];
if( $fromSheet && !empty( $_REQUEST['make_galleries'] ) ) {
	foreach( FisheyeBookCredits::authorsWithoutGallery( $catalogue['rows'] ) as $author ) {
		$made = FisheyeBookCredits::findOrCreateLibraryGallery( $author['name'] );
		if( $made['content_id'] ) {
			FisheyeBookCredits::linkGalleryToContact( $made['content_id'], $author['contact_id'] );
			$filed = FisheyeBookCredits::fileCreditedBooks( $catalogue['rows'], $made['content_id'], $author['name'] );
			$authorContact = new Wiki( null, $author['contact_id'] );
			$authorContact->load();
			$authorGallery = new \Bitweaver\Fisheye\FisheyeGallery( null, $made['content_id'] );
			$authorGallery->load();
			$galleriesMade[] = [ 'name' => $author['name'], 'filed' => $filed, 'contact' => FisheyeBookCredits::contactName( $author['contact_id'] ),
				'contactUrl' => $authorContact->isValid() ? $authorContact->getDisplayUrl() : '', 'galleryUrl' => $authorGallery->getDisplayUrl() ];
		}
	}
}
$withoutGallery = $fromSheet ? FisheyeBookCredits::authorsWithoutGallery( $catalogue['rows'] ) : [];

$result = null;
if( !empty( $_REQUEST['create_authors'] ) ) {
	$result = [ 'done' => [], 'errors' => [], 'skipped' => 0 ];
	$loadBooks = $fromSheet && !empty( $_REQUEST['load_books'] );
	$bookBudget = LOAD_AUTHORS_BOOKS_PER_PRESS;
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
				// An existing contact taken as the author: tagged as a book author, and given the Open Library id the
				// person's books pointed to, so the name counts as done from now on.
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
		$contact->markAsBookAuthor();
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
		// A new author with enough books credited on the catalogue (as author or co-author) gets a Library gallery
		// now, with the books already loaded that credit them, so the books can be loaded from it (its Add Books
		// icon) straight after.
		$galleryUrl = '';
		if( $fromSheet && count( $person['books'] ) >= FisheyeBookCatalogue::MIN_AUTHOR_BOOKS ) {
			FisheyeBookCredits::resetCache();
			$galleryTitle = FisheyeBookCredits::canonicalName( $person['name'] );
			$made = FisheyeBookCredits::findOrCreateLibraryGallery( $galleryTitle );
			if( $made['content_id'] ) {
				FisheyeBookCredits::linkGalleryToContact( $made['content_id'], (int)$contact->mContentId );
				FisheyeBookCredits::fileCreditedBooks( $catalogue['rows'], $made['content_id'], $galleryTitle );
				$libraryGallery = new \Bitweaver\Fisheye\FisheyeGallery( null, $made['content_id'] );
				$libraryGallery->load();
				$galleryUrl = $libraryGallery->getDisplayUrl();
			} elseif( $made['error'] ) {
				$result['errors'][] = $made['error'];
			}
		}
		// Their own books, when there are only a few: loaded now, filed as the book loader would file them.
		$booksLoaded = 0;
		if( $loadBooks && $bookBudget > 0 ) {
			FisheyeBookCredits::resetCache();
			$mineKey = mb_strtolower( FisheyeBookCredits::canonicalName( $person['name'] ) );
			$mine = array_filter( $catalogue['rows'], fn( $row ) => mb_strtolower( FisheyeBookCredits::canonicalName( FisheyeBookCatalogue::primaryAuthor( $row ) ) ) === $mineKey );
			if( $mine && count( $mine ) <= LOAD_AUTHORS_BOOKS_MAX ) {
				if( count( $mine ) >= FisheyeBookCatalogue::MIN_AUTHOR_BOOKS ) {
					$group = [ 'kind' => 'author', 'name' => FisheyeBookCredits::canonicalName( $person['name'] ), 'rows' => $mine ];
				} else {
					$category = reset( $mine )['category'][0] ?? '';
					$group = [ 'kind' => 'category', 'name' => $category !== '' ? $category : KernelTools::tra( 'Uncategorised' ), 'rows' => $mine ];
				}
				$loaded = FisheyeBookLoader::loadGroup( $group, null, false, null, $bookBudget );
				$booksLoaded = $loaded['created'];
				$bookBudget -= $booksLoaded;
				foreach( $loaded['errors'] as $error ) {
					$result['errors'][] = $error;
				}
			}
		}
		$result['done'][] = [ 'name' => $person['name'], 'booksLoaded' => $booksLoaded, 'contact' => FisheyeBookCredits::contactName( (int)$contact->mContentId ), 'url' => $contact->getDisplayUrl(), 'linked' => $linked, 'books' => count( $person['books'] ), 'gallery' => $galleryUrl ];
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
$gBitSmarty->assign( 'import', $import );
$gBitSmarty->assign( 'galleriesMade', $galleriesMade );
$gBitSmarty->assign( 'withoutGallery', $withoutGallery );
$gBitSmarty->assign( 'fromSheet', $fromSheet );
$gBitSmarty->assign( 'sheetFile', $catalogue['file'] );
$gBitSmarty->assign( 'unlinkedCount', count( $people ) );
$gBitSmarty->assign( 'start', $start );
$gBitSmarty->assign( 'batchSize', LOAD_AUTHORS_BATCH );
$gBitSmarty->assign( 'booksPerPress', LOAD_AUTHORS_BOOKS_PER_PRESS );
$gBitSmarty->assign( 'batch', $batch );
$gBitSmarty->assign( 'result', $result );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_authors.tpl', KernelTools::tra( 'Authors from Open Library' ), [ 'display_mode' => 'edit' ] );

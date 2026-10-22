<?php
/**
 * The people on books that are still plain names - the survey the authors pass (load_authors.php) works
 * from, as FisheyeCredits is for films and programs.
 *
 * A book credit is a liberty_xref row (author/editor/illustrator/translator/narrator) whose `xkey_ext` is
 * the credited name; once matched to a contact it also carries `xref` = the contact's content_id and `xkey`
 * = its Wikidata Q-id (or Open Library id when it has no Wikidata item). Reads liberty_xref directly, never
 * a content item's role-filtered mXrefInfo, so it sees every row whoever runs it. Linking goes through
 * FisheyeCredits::linkRows(), which never overwrites a link made by hand.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\Contactwiki\ContactWikiIndividual as Wiki;
use Bitweaver\Fisheye\FisheyeGallery;

class FisheyeBookCredits {

	/** @var array<string,array>|null ContactWikiIndividual::nameIndex(), once per request (see resetCache()) */
	private static ?array $nameIndex = null;
	/** @var array<string,list<int>>|null lower-cased pen name or birth name => the contacts carrying it */
	private static ?array $otherNames = null;
	/** @var array<int,true>|null contacts that are authors: they carry an Open Library id or the writer tag */
	private static ?array $authorContacts = null;
	/** @var array<string,array>|null the name index again, keyed by nameKey() */
	private static ?array $squashIndex = null;

	// Credits that stand in for a person and never are one - not offered, never linked.
	const PLACEHOLDER_NAMES = [ 'various', 'various authors', 'various artists', 'various contributors', 'anonymous', 'anon', 'unknown', 'uncredited', 'n a' ];

	/**
	 * A name reduced for comparing spellings of one name: letters and digits only, so "Reader's Digest" and
	 * "Readers Digest", "Mc Caffrey" and "McCaffrey" are the same key. Wider than contactwiki's own
	 * normalising, which keeps the word breaks.
	 */
	public static function nameKey( string $pName ): string {
		return str_replace( ' ', '', Wiki::normaliseName( $pName ) );
	}

	/** Whether a credited name is a placeholder ("Various", "Anonymous") rather than someone. */
	public static function isPlaceholder( string $pName ): bool {
		return in_array( Wiki::normaliseName( $pName ), self::PLACEHOLDER_NAMES, true );
	}

	/** Forget what the contact lookups have read - after contacts have been created or changed in this request. */
	public static function resetCache(): void {
		self::$nameIndex = self::$otherNames = self::$authorContacts = self::$squashIndex = null;
	}

	/**
	 * The author contact a credited name belongs to, or null when there is none or it is not certain.
	 * A pen name or birth name a contact carries is decisive; otherwise the name must be the contact's own
	 * and the contact an author (an Open Library id or the book author tag; for an organisation credited as author,
	 * the Publisher tag), so a namesake who is an actor is left alone. Two different contacts answering to the name is no answer.
	 */
	public static function authorContactFor( string $pName ): ?int {
		global $gBitDb;
		$name = trim( preg_replace( '/\s+/u', ' ', $pName ) );
		if( $name === '' || self::isPlaceholder( $name ) ) {
			return null;
		}
		self::squashIndex();
		if( self::$otherNames === null ) {
			self::$otherNames = [];
			foreach( $gBitDb->getAll( "SELECT `content_id`, COALESCE( NULLIF( `xkey_ext`, '' ), `xkey` ) AS `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `item` IN ( 'penname', 'birthname' ) AND `end_date` IS NULL AND COALESCE( NULLIF( `xkey_ext`, '' ), `xkey` ) IS NOT NULL" ) ?: [] as $row ) {
				self::$otherNames[self::nameKey( (string)$row['xkey_ext'] )][] = (int)$row['content_id'];
			}
		}
		if( self::$authorContacts === null ) {
			self::$authorContacts = [];
			foreach( $gBitDb->getCol( "SELECT DISTINCT `content_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `item` IN ( 'openlibrary', 'WP14', 'WB07' ) AND `end_date` IS NULL" ) ?: [] as $id ) {
				self::$authorContacts[(int)$id] = true;
			}
		}
		$found = [];
		foreach( self::$otherNames[self::nameKey( $name )] ?? [] as $id ) {
			$found[$id] = true;
		}
		foreach( Wiki::nameForms( $name ) as $form ) {
			foreach( self::$squashIndex[str_replace( ' ', '', $form )] ?? [] as $contact ) {
				if( isset( self::$authorContacts[$contact['content_id']] ) ) {
					$found[$contact['content_id']] = true;
				}
			}
		}
		return count( $found ) === 1 ? (int)array_key_first( $found ) : null;
	}

	/**
	 * The contact name index (Wiki::nameIndex()) keyed by nameKey() form, built once per request.
	 *
	 * @return array<string,array<int,array>>
	 */
	private static function squashIndex(): array {
		if( self::$squashIndex === null ) {
			self::$nameIndex ??= Wiki::nameIndex();
			self::$squashIndex = [];
			foreach( self::$nameIndex as $form => $contacts ) {
				foreach( $contacts as $contact ) {
					self::$squashIndex[str_replace( ' ', '', $form )][$contact['content_id']] = $contact;
				}
			}
		}
		return self::$squashIndex;
	}

	/**
	 * Whether two names are the same allowing for a slip or an initial - "Phillip Pullman" and "Philip
	 * Pullman", "Todd J. McCaffrey" and "Todd McCaffrey" - by the edit distance between their nameKey()s.
	 * Short names must match exactly.
	 */
	public static function similarNames( string $pA, string $pB, int $pAllowed = 2 ): bool {
		$a = self::nameKey( $pA );
		$b = self::nameKey( $pB );
		return $a === $b || ( strlen( $a ) >= 8 && strlen( $b ) >= 8 && levenshtein( $a, $b ) <= $pAllowed );
	}

	/**
	 * After a person is linked to a contact: remember each spelling they were credited under that the contact
	 * does not already answer to as one of its pen names, so the credit finds the contact next time.
	 */
	public static function rememberCreditNames( object $pContact, array $pPerson ): void {
		global $gBitDb;
		$own = array_map( fn( $form ) => str_replace( ' ', '', $form ),
			Wiki::nameForms( (string)$gBitDb->getOne( "SELECT `title` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ?", [ (int)$pContact->mContentId ] ) ) );
		foreach( $pPerson['variants'] ?? [ $pPerson['name'] ] as $variant ) {
			if( !in_array( self::nameKey( $variant ), $own, true ) ) {
				$pContact->addOtherName( $variant );
			}
		}
	}

	/**
	 * A contact's name as it reads on a book - "Forename Surname" from its stored "Surname, Forename".
	 */
	public static function displayName( string $pTitle ): string {
		return preg_match( '/^([^,]+),\s*(.+)$/u', trim( $pTitle ), $m ) ? $m[2].' '.$m[1] : trim( $pTitle );
	}

	/**
	 * A wiki contact loaded as its own class - an individual or an organisation (group) - or null when the id is neither.
	 * Loading an organisation as an individual fatals, so anything that holds a contact_id of either kind goes through here.
	 */
	public static function loadContact( int $pContentId ): ?object {
		global $gBitDb;
		$guid = $gBitDb->getOne( "SELECT `content_type_guid` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ?", [ $pContentId ] );
		$class = $guid === 'contactwikigroup' ? \Bitweaver\Contactwiki\ContactWikiGroup::class : ( $guid === 'contactwikiindi' ? Wiki::class : null );
		if( !$class ) {
			return null;
		}
		$contact = new $class( null, $pContentId );
		$contact->load();
		return $contact->isValid() ? $contact : null;
	}

	/**
	 * A contact's name as it reads on a book, from its stored title (not getTitle(), which a contact
	 * overrides to "Contact - <name>").
	 */
	public static function contactName( int $pContactId ): string {
		global $gBitDb;
		return self::displayName( (string)$gBitDb->getOne( "SELECT `title` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ?", [ $pContactId ] ) );
	}

	/**
	 * The name a credited author is filed under: their contact's name when they have one (so a pen name's
	 * books and the real name's books land in one gallery), else the name as written.
	 */
	public static function canonicalName( string $pName ): string {
		global $gBitDb;
		$contactId = self::authorContactFor( $pName );
		$title = $contactId ? $gBitDb->getOne( "SELECT `title` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ?", [ $contactId ] ) : null;
		return $title ? self::displayName( (string)$title ) : trim( $pName );
	}

	/**
	 * The external id a credit row carries beside its link to a contact: the Wikidata Q-id, else the Open
	 * Library id, else nothing.
	 */
	public static function contactKey( int $pContactId ): string {
		global $gBitDb;
		$ids = [];
		foreach( $gBitDb->getAll( "SELECT `item`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` IN ( 'wikidata', 'openlibrary' ) AND `end_date` IS NULL", [ $pContactId ] ) ?: [] as $row ) {
			$ids[$row['item']] = (string)$row['xkey_ext'];
		}
		return $ids['wikidata'] ?? $ids['openlibrary'] ?? '';
	}

	/**
	 * Link a loaded book's unlinked credits to the author contacts their names belong to (authorContactFor()).
	 * Never touches a credit already linked, by hand or otherwise.
	 *
	 * @return int  credits linked
	 */
	public static function linkBookCredits( int $pBookContentId ): int {
		global $gBitDb;
		class_exists( FisheyeBook::class );
		$rows = $gBitDb->getAll(
			"SELECT `xref_id`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref`
			 WHERE `content_id` = ? AND `end_date` IS NULL AND `item` IN ( '".implode( "', '", FISHEYEBOOK_CREDIT_ITEMS )."' )
			 AND `xkey_ext` IS NOT NULL AND ( `xref` IS NULL OR `xref` = 0 )", [ $pBookContentId ] ) ?: [];
		$byContact = [];
		foreach( $rows as $row ) {
			if( $contactId = self::authorContactFor( (string)$row['xkey_ext'] ) ) {
				$byContact[$contactId][] = (int)$row['xref_id'];
			}
		}
		$linked = 0;
		foreach( $byContact as $contactId => $xrefIds ) {
			$linked += FisheyeCredits::linkRows( $xrefIds, $contactId, self::contactKey( $contactId ), null, [ 'fisheyebook' ] );
		}
		return $linked;
	}

	/**
	 * The people credited on the catalogue sheet who have no author contact yet, most books first - the
	 * authors pass before any book is loaded. Same shape as unlinkedPeople(), but the books are the sheet's
	 * rows (numbered) and there are no credit rows to link yet.
	 *
	 * @param list<array> $pRows  FisheyeBookCatalogue::cached()['rows']
	 * @return array<string,array{name:string,roles:array<string,int>,books:array<int,string>,isbns:array<string,string>,xref_ids:list<int>}>
	 */
	public static function sheetPeople( array $pRows, bool $pIncludeLinked = false ): array {
		$people = [];
		foreach( $pRows as $n => $row ) {
			foreach( (array)( $row['authors'] ?? [] ) as $name ) {
				$name = trim( preg_replace( '/\s+/u', ' ', (string)$name ) );
				if( $name === '' ) {
					continue;
				}
				if( self::isPlaceholder( $name ) ) {
					continue;
				}
				$p = &$people[self::nameKey( $name )];
				$p ??= [ 'name' => $name, 'variants' => [], 'roles' => [], 'books' => [], 'isbns' => [], 'xref_ids' => [] ];
				$p['variants'][$name] = ( $p['variants'][$name] ?? 0 ) + 1;
				$role = (string)( $row['author_role'] ?? '' ) ?: 'author';
				$p['roles'][$role] = ( $p['roles'][$role] ?? 0 ) + 1;
				$p['books'][$n] = (string)( $row['title'] ?? '' );
				if( !empty( $row['isbn13'] ) ) {
					$p['isbns'][(string)$row['isbn13']] = (string)( $row['title'] ?? '' );
				}
				unset( $p );
			}
		}
		foreach( $people as &$person ) {
			arsort( $person['variants'] );
			$person['name'] = (string)array_key_first( $person['variants'] );
			$person['variants'] = array_keys( $person['variants'] );
		}
		unset( $person );
		if( !$pIncludeLinked ) {
			$people = array_filter( $people, fn( $p ) => self::authorContactFor( $p['name'] ) === null );
		}
		uasort( $people, fn( $a, $b ) => [ count( $b['books'] ), $a['name'] ] <=> [ count( $a['books'] ), $b['name'] ] );
		return $people;
	}

	/**
	 * Every credited name with at least one unlinked credit row, most books first - or, given an author's
	 * gallery, only the names credited on the books in it (and in its series).
	 *
	 * @return array<string,array{name:string,variants:list<string>,roles:array<string,int>,books:array<int,string>,isbns:array<string,string>,xref_ids:list<int>}>
	 *         keyed by nameKey() (spellings of one name together, the commonest as 'name'); isbns is ISBN-13 => the book's title, for the books that have one
	 */
	public static function unlinkedPeople( ?int $pGalleryContentId = null ): array {
		global $gBitDb;
		// FISHEYEBOOK_CREDIT_ITEMS is defined with FisheyeBook - make sure that file has been loaded.
		class_exists( FisheyeBook::class );
		$bind = [];
		$inGallery = '';
		if( $pGalleryContentId ) {
			$inGallery = " AND x.`content_id` IN ( SELECT m.`item_content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
				WHERE m.`gallery_content_id` = ? OR m.`gallery_content_id` IN ( SELECT s.`item_content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` s WHERE s.`gallery_content_id` = ? ) )";
			$bind = [ $pGalleryContentId, $pGalleryContentId ];
		}
		$rows = $gBitDb->getAll(
			"SELECT x.`xref_id`, x.`content_id`, x.`item`, x.`xkey_ext`, lc.`title`
			 FROM `".BIT_DB_PREFIX."liberty_xref` x
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = x.`content_id`
			 WHERE lc.`content_type_guid` = 'fisheyebook' AND x.`end_date` IS NULL
			 AND x.`item` IN ( '".implode( "', '", FISHEYEBOOK_CREDIT_ITEMS )."' )
			 AND x.`xkey_ext` IS NOT NULL AND ( x.`xref` IS NULL OR x.`xref` = 0 )".$inGallery,
			$bind
		) ?: [];
		$people = [];
		foreach( $rows as $row ) {
			$name = trim( preg_replace( '/\s+/u', ' ', (string)$row['xkey_ext'] ) );
			if( $name === '' ) {
				continue;
			}
			if( self::isPlaceholder( $name ) ) {
				continue;
			}
			$p = &$people[self::nameKey( $name )];
			$p ??= [ 'name' => $name, 'variants' => [], 'roles' => [], 'books' => [], 'isbns' => [], 'xref_ids' => [] ];
			$p['variants'][$name] = ( $p['variants'][$name] ?? 0 ) + 1;
			$p['roles'][$row['item']] = ( $p['roles'][$row['item']] ?? 0 ) + 1;
			$p['books'][(int)$row['content_id']] = (string)$row['title'];
			$p['xref_ids'][] = (int)$row['xref_id'];
			unset( $p );
		}
		// The ISBN of each book, so a person can be found from the book rather than from the name alone.
		$bookIds = [];
		foreach( $people as $person ) {
			$bookIds += $person['books'];
		}
		$isbnByBook = [];
		foreach( array_chunk( array_keys( $bookIds ), 300 ) as $chunk ) {
			$isbnRows = $gBitDb->getAll(
				"SELECT `content_id`, `xkey` FROM `".BIT_DB_PREFIX."liberty_xref`
				 WHERE `item` = 'isbn' AND `end_date` IS NULL AND `xkey` IS NOT NULL AND `content_id` IN ( ".implode( ',', array_fill( 0, count( $chunk ), '?' ) )." )",
				$chunk ) ?: [];
			foreach( $isbnRows as $isbnRow ) {
				$isbnByBook[(int)$isbnRow['content_id']] = (string)$isbnRow['xkey'];
			}
		}
		foreach( $people as &$person ) {
			arsort( $person['variants'] );
			$person['name'] = (string)array_key_first( $person['variants'] );
			$person['variants'] = array_keys( $person['variants'] );
			foreach( $person['books'] as $contentId => $title ) {
				if( !empty( $isbnByBook[$contentId] ) ) {
					$person['isbns'][$isbnByBook[$contentId]] = $title;
				}
			}
		}
		unset( $person );
		uasort( $people, fn( $a, $b ) => [ count( $b['books'] ), $a['name'] ] <=> [ count( $a['books'] ), $b['name'] ] );
		return $people;
	}

	/**
	 * The Wikidata id of the contact an author name belongs to, or '' when there is no (certain) contact or it has none.
	 */
	public static function authorQid( string $pName ): string {
		global $gBitDb;
		$contactId = self::authorContactFor( $pName );
		return $contactId ? (string)$gBitDb->getOne( "SELECT `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'wikidata' AND `end_date` IS NULL", [ $contactId ] ) : '';
	}

	/**
	 * Record on an author's gallery which contact is the author: the contact's content_id, kept as the gallery's
	 * 'author_contact_id' preference, so the gallery's title can link straight to the contact page with no lookup
	 * (FisheyeMediaGallery::getAuthorContactUrl()). Idempotent.
	 */
	public static function linkGalleryToContact( int $pGalleryContentId, int $pContactId ): void {
		$gallery = new FisheyeGallery( null, $pGalleryContentId );
		$gallery->load();
		if( $gallery->isValid() && (int)$gallery->getPreference( 'author_contact_id' ) !== $pContactId ) {
			$gallery->storePreference( 'author_contact_id', (string)$pContactId );
		}
	}

	/**
	 * The author gallery directly under the top-level Library gallery with this title, if there is one.
	 */
	public static function libraryGalleryFor( string $pTitle ): ?int {
		global $gBitDb;
		$topGalleryId = FisheyeGallery::getTopGalleryId( 'Library' );
		$topContentId = $topGalleryId ? (int)$gBitDb->getOne( "SELECT `content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery` WHERE `gallery_id` = ?", [ $topGalleryId ] ) : 0;
		if( !$topContentId ) {
			return null;
		}
		$id = $gBitDb->getOne(
			"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` fgim
			 INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = fgim.`item_content_id`
			 WHERE fgim.`gallery_content_id` = ? AND lc.`title` = ? AND lc.`content_type_guid` IN ( 'fisheyemediagal', 'fisheyegallery' )",
			[ $topContentId, $pTitle ]
		);
		return $id ? (int)$id : null;
	}

	/**
	 * The Library gallery with this title, made (library layout, directly under the top-level Library gallery)
	 * when it is not there yet.
	 *
	 * @return array{content_id:?int,created:bool,error:?string}
	 */
	public static function findOrCreateLibraryGallery( string $pTitle ): array {
		$existing = self::libraryGalleryFor( $pTitle );
		if( $existing ) {
			return [ 'content_id' => $existing, 'created' => false, 'error' => null ];
		}
		$topGalleryId = FisheyeGallery::getTopGalleryId( 'Library' );
		if( !$topGalleryId ) {
			return [ 'content_id' => null, 'created' => false, 'error' => \Bitweaver\KernelTools::tra( 'There is no top-level Library gallery to file books under.' ) ];
		}
		$gallery = new FisheyeMediaGallery();
		$storeHash = [ 'title' => $pTitle, 'gallery_pagination' => FISHEYE_PAGINATION_LIBRARY_GRID ];
		if( !$gallery->store( $storeHash ) ) {
			return [ 'content_id' => null, 'created' => false, 'error' => $pTitle.' - '.implode( '; ', $gallery->mErrors ) ];
		}
		$gallery->storePreference( 'gallery_pagination', FISHEYE_PAGINATION_LIBRARY_GRID );
		$gallery->addToGalleries( [ $topGalleryId ] );
		return [ 'content_id' => (int)$gallery->mContentId, 'created' => true, 'error' => null ];
	}

	/**
	 * File a book in the Library gallery of every author credited on it who has one - a co-author's gallery
	 * carries the book beside the primary author's. Adding is idempotent, so it can be run over any book again.
	 *
	 * @param list<string> $pAuthorNames  the names as credited
	 * @return int  galleries the book was added to
	 */
	public static function fileInAuthorGalleries( int $pBookContentId, array $pAuthorNames ): int {
		$added = 0;
		$seen = [];
		foreach( $pAuthorNames as $name ) {
			$title = self::canonicalName( (string)$name );
			if( $title === '' || isset( $seen[mb_strtolower( $title )] ) ) {
				continue;
			}
			$seen[mb_strtolower( $title )] = true;
			if( !( $galleryContentId = self::libraryGalleryFor( $title ) ) ) {
				continue;
			}
			$gallery = new FisheyeGallery( null, $galleryContentId );
			$gallery->load();
			if( !self::isInSeriesOf( $pBookContentId, $galleryContentId ) && $gallery->addItem( $pBookContentId ) ) {
				$added++;
			}
		}
		return $added;
	}

	/**
	 * Whether a book already sits in one of the series galleries under this author's gallery. Filing it in the author's
	 * own list as well would put it at the top level again beside its series.
	 */
	public static function isInSeriesOf( int $pBookContentId, int $pGalleryContentId ): bool {
		global $gBitDb;
		return (bool)(int)$gBitDb->getOne(
			"SELECT COUNT(*) FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` inSeries
			 INNER JOIN `".BIT_DB_PREFIX."fisheye_gallery_image_map` underGallery ON underGallery.`item_content_id` = inSeries.`gallery_content_id`
			 INNER JOIN `".BIT_DB_PREFIX."liberty_content` series ON series.`content_id` = inSeries.`gallery_content_id`
			 WHERE inSeries.`item_content_id` = ? AND underGallery.`gallery_content_id` = ? AND series.`content_type_guid` = 'fisheyeseries'",
			[ $pBookContentId, $pGalleryContentId ]
		);
	}

	/**
	 * File every book already loaded that credits this author (as primary author or co-author) in the
	 * author's gallery - used when the gallery is made, or when a co-author's gallery is opened, so books
	 * loaded earlier under another author's name turn up here too.
	 *
	 * @param list<array> $pRows  catalogue rows
	 * @return int  books added
	 */
	public static function fileCreditedBooks( array $pRows, int $pGalleryContentId, string $pAuthorName ): int {
		$gallery = new FisheyeGallery( null, $pGalleryContentId );
		$gallery->load();
		$key = mb_strtolower( self::canonicalName( $pAuthorName ) );
		$added = 0;
		foreach( $pRows as $row ) {
			$credited = array_filter( (array)( $row['authors'] ?? [] ), fn( $n ) => mb_strtolower( self::canonicalName( (string)$n ) ) === $key );
			if( !$credited || !( $bookId = FisheyeBook::findContentId( $row ) ) ) {
				continue;
			}
			// A book already in one of this author's series stays there, not in the author's own list as well.
			if( !self::isInSeriesOf( (int)$bookId, $pGalleryContentId ) && $gallery->addItem( $bookId ) ) {
				$added++;
			}
		}
		return $added;
	}

	/**
	 * Authors on the catalogue that already have a contact but no Library gallery, and enough credited books
	 * to deserve one - the ones made before the authors pass filed a gallery with the contact.
	 *
	 * @return array<string,array{name:string,contact_id:int,books:int}>  keyed by gallery title
	 */
	public static function authorsWithoutGallery( array $pRows ): array {
		$out = [];
		foreach( self::sheetPeople( $pRows, true ) as $person ) {
			$contactId = self::authorContactFor( $person['name'] );
			$title = self::canonicalName( $person['name'] );
			if( $contactId && count( $person['books'] ) >= FisheyeBookCatalogue::MIN_AUTHOR_BOOKS && !self::libraryGalleryFor( $title ) ) {
				$out[$title] = [ 'name' => $title, 'contact_id' => $contactId, 'books' => count( $person['books'] ) ];
			}
		}
		return $out;
	}

	/**
	 * What to offer for one person: contacts already answering to the name, and the Open Library authors
	 * they may be - found from the person's own books where there is an ISBN (the work's authors are named
	 * by Open Library, so a namesake is told apart by the book), by name search otherwise. Read-only; a few
	 * requests to Open Library, one at a time.
	 *
	 * @param array $pPerson  an unlinkedPeople() entry
	 * @param array $pIndex   ContactWikiIndividual::nameIndex()
	 * @return array  the person plus candidates, contacts, default ('ol:<id>', 'contact:<id>', 'new' or 'skip'), bookTitles, bookCount
	 */
	public static function proposeFor( array $pPerson, array $pIndex ): array {
		global $gBitDb;
		$forms = Wiki::nameForms( $pPerson['name'] );

		// Contacts already answering to this name - their own name, or a pen name or birth name they carry.
		$contacts = [];
		foreach( $forms as $form ) {
			foreach( $pIndex[$form] ?? [] as $contact ) {
				if( $contact['content_type_guid'] === 'contactwikiindi' ) {
					$contacts[$contact['content_id']] = $contact;
				}
			}
		}
		$other = $gBitDb->getAll(
			"SELECT lc.`content_id`, lc.`title` FROM `".BIT_DB_PREFIX."liberty_xref` x JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = x.`content_id`
			 WHERE x.`item` IN ( 'penname', 'birthname' ) AND x.`end_date` IS NULL AND LOWER( COALESCE( NULLIF( x.`xkey_ext`, '' ), x.`xkey` ) ) = ? AND lc.`content_type_guid` = 'contactwikiindi'", [ mb_strtolower( $pPerson['name'] ) ] ) ?: [];
		foreach( $other as $row ) {
			$contacts[(int)$row['content_id']] ??= [ 'content_id' => (int)$row['content_id'], 'title' => $row['title'], 'qid' => null, 'alias' => true ];
		}

		// Contacts with a very similar name (a slip, an initial) - offered, never chosen for you.
		$similar = [];
		$key = self::nameKey( $pPerson['name'] );
		if( strlen( $key ) >= 8 ) {
			foreach( self::squashIndex() as $squashed => $found ) {
				if( abs( strlen( $squashed ) - strlen( $key ) ) <= 2 && $squashed !== $key && levenshtein( $squashed, $key ) <= 2 ) {
					foreach( $found as $contact ) {
						if( $contact['content_type_guid'] === 'contactwikiindi' && !isset( $contacts[$contact['content_id']] ) ) {
							$similar[$contact['content_id']] = $contact + [ 'similar' => true ];
						}
					}
				}
			}
		}

		// Open Library, from the person's own books first.
		$candidates = [];
		foreach( array_slice( $pPerson['isbns'], 0, 3, true ) as $isbn => $title ) {
			usleep( 300000 );
			$data = Wiki::fetchOpenLibraryJson( '/search.json?'.http_build_query( [ 'q' => 'isbn:'.$isbn, 'fields' => 'key,title,author_key,author_name', 'limit' => 1 ], '', '&' ) );
			$doc = $data['docs'][0] ?? [];
			foreach( (array)( $doc['author_key'] ?? [] ) as $n => $olid ) {
				// The ISBN already ties the book, so the name may differ by a slip or an initial.
				if( self::similarNames( (string)( $doc['author_name'][$n] ?? '' ), $pPerson['name'] ) ) {
					$candidates[$olid] = [ 'olid' => $olid, 'via' => 'isbn', 'book' => $title ];
				}
			}
			if( $candidates ) {
				break;
			}
		}
		$clustered = false;
		if( !$candidates ) {
			usleep( 300000 );
			$named = [];
			foreach( Wiki::searchOpenLibraryAuthors( $pPerson['name'] ) as $hit ) {
				$keys = array_map( [ self::class, 'nameKey' ], array_merge( [ $hit['name'] ], $hit['alternate_names'] ) );
				if( $hit['work_count'] > 0 && in_array( self::nameKey( $pPerson['name'] ), $keys, true ) && count( $named ) < 8 ) {
					$named[$hit['olid']] = [ 'olid' => $hit['olid'], 'via' => 'name', 'book' => $hit['top_work'], 'works' => $hit['work_count'], 'born' => $hit['birth_date'] ];
				}
			}
			// Open Library often holds several records for one author (a lower-case "hall caine", a record per
			// spelling). Records that do not contradict the biggest one on birth date are taken as copies of it and
			// folded into it; one that gives a different birth date is a namesake and stays a choice of its own.
			$best = $named ? reset( $named ) : null;
			if( $best ) {
				$copies = [];
				foreach( array_slice( $named, 1, null, true ) as $olid => $other ) {
					if( $other['born'] === '' || $other['born'] === $best['born'] ) {
						$copies[$olid] = $other;
					}
				}
				$namesakes = array_diff_key( array_slice( $named, 1, null, true ), $copies );
				$candidates[$best['olid']] = $best + [ 'copies' => array_values( $copies ) ];
				foreach( $namesakes as $olid => $other ) {
					$candidates[$olid] = $other + [ 'copies' => [] ];
				}
				// Sure enough to pre-choose: no namesakes, and the biggest record dwarfs its copies.
				$clustered = $copies && !$namesakes && $best['works'] >= 3 * max( array_column( $copies, 'works' ) );
			}
		}
		foreach( $candidates as $olid => $candidate ) {
			usleep( 300000 );
			$author = Wiki::fetchOpenLibraryAuthor( $olid ) ?: [];
			$qid = !empty( $author['remote_ids']['wikidata'] ) ? strtoupper( $author['remote_ids']['wikidata'] ) : null;
			$held = Wiki::findContactByOpenLibraryId( $olid ) ?: ( $qid ? Wiki::findContactByWikidataQid( $qid ) : null );
			$candidates[$olid] += [ 'name' => (string)( $author['name'] ?? $olid ), 'born' => (string)( $author['birth_date'] ?? '' ), 'died' => (string)( $author['death_date'] ?? '' ),
				'qid' => $qid, 'held' => $held['title'] ?? null ];
		}

		$isbnHits = array_filter( $candidates, fn( $c ) => $c['via'] === 'isbn' );
		if( count( $isbnHits ) === 1 ) {
			$default = 'ol:'.array_key_first( $isbnHits );
		} elseif( $clustered ) {
			$default = 'ol:'.array_key_first( $candidates );
		} elseif( !$candidates && count( $contacts ) === 1 ) {
			$default = 'contact:'.array_key_first( $contacts );
		} elseif( !$candidates && !$contacts && !$similar ) {
			$default = 'new';
		} else {
			$default = 'skip';
		}
		return $pPerson + [ 'candidates' => array_values( $candidates ), 'contacts' => array_values( $contacts + $similar ), 'default' => $default,
			'olhint' => count( $isbnHits ) === 1 ? (string)array_key_first( $isbnHits ) : '',
			'bookTitles' => array_slice( array_values( $pPerson['books'] ), 0, 6 ), 'bookCount' => count( $pPerson['books'] ) ];
	}
}

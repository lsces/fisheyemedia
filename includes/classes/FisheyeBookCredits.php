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

	/** Forget what the contact lookups have read - after contacts have been created or changed in this request. */
	public static function resetCache(): void {
		self::$nameIndex = self::$otherNames = self::$authorContacts = null;
	}

	/**
	 * The author contact a credited name belongs to, or null when there is none or it is not certain.
	 * A pen name or birth name a contact carries is decisive; otherwise the name must be the contact's own
	 * and the contact an author (an Open Library id or the writer tag), so a namesake who is an actor
	 * is left alone. Two different contacts answering to the name is no answer.
	 */
	public static function authorContactFor( string $pName ): ?int {
		global $gBitDb;
		$name = trim( preg_replace( '/\s+/u', ' ', $pName ) );
		if( $name === '' ) {
			return null;
		}
		self::$nameIndex ??= Wiki::nameIndex();
		if( self::$otherNames === null ) {
			self::$otherNames = [];
			foreach( $gBitDb->getAll( "SELECT `content_id`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `item` IN ( 'penname', 'birthname' ) AND `end_date` IS NULL AND `xkey_ext` IS NOT NULL" ) ?: [] as $row ) {
				self::$otherNames[mb_strtolower( trim( (string)$row['xkey_ext'] ) )][] = (int)$row['content_id'];
			}
		}
		if( self::$authorContacts === null ) {
			self::$authorContacts = [];
			foreach( $gBitDb->getCol( "SELECT DISTINCT `content_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `item` IN ( 'openlibrary', 'WP11' ) AND `end_date` IS NULL" ) ?: [] as $id ) {
				self::$authorContacts[(int)$id] = true;
			}
		}
		$found = [];
		foreach( self::$otherNames[mb_strtolower( $name )] ?? [] as $id ) {
			$found[$id] = true;
		}
		foreach( Wiki::nameForms( $name ) as $form ) {
			foreach( self::$nameIndex[$form] ?? [] as $contact ) {
				if( $contact['content_type_guid'] === 'contactwikiindi' && isset( self::$authorContacts[$contact['content_id']] ) ) {
					$found[$contact['content_id']] = true;
				}
			}
		}
		return count( $found ) === 1 ? (int)array_key_first( $found ) : null;
	}

	/**
	 * A contact's name as it reads on a book - "Forename Surname" from its stored "Surname, Forename".
	 */
	public static function displayName( string $pTitle ): string {
		return preg_match( '/^([^,]+),\s*(.+)$/u', trim( $pTitle ), $m ) ? $m[2].' '.$m[1] : trim( $pTitle );
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
	public static function sheetPeople( array $pRows ): array {
		$people = [];
		foreach( $pRows as $n => $row ) {
			foreach( (array)( $row['authors'] ?? [] ) as $name ) {
				$name = trim( preg_replace( '/\s+/u', ' ', (string)$name ) );
				if( $name === '' ) {
					continue;
				}
				$p = &$people[mb_strtolower( $name )];
				$p ??= [ 'name' => $name, 'roles' => [], 'books' => [], 'isbns' => [], 'xref_ids' => [] ];
				$role = (string)( $row['author_role'] ?? '' ) ?: 'author';
				$p['roles'][$role] = ( $p['roles'][$role] ?? 0 ) + 1;
				$p['books'][$n] = (string)( $row['title'] ?? '' );
				if( !empty( $row['isbn13'] ) ) {
					$p['isbns'][(string)$row['isbn13']] = (string)( $row['title'] ?? '' );
				}
				unset( $p );
			}
		}
		$people = array_filter( $people, fn( $p ) => self::authorContactFor( $p['name'] ) === null );
		uasort( $people, fn( $a, $b ) => [ count( $b['books'] ), $a['name'] ] <=> [ count( $a['books'] ), $b['name'] ] );
		return $people;
	}

	/**
	 * Every credited name with at least one unlinked credit row, most books first - or, given an author's
	 * gallery, only the names credited on the books in it (and in its series).
	 *
	 * @return array<string,array{name:string,roles:array<string,int>,books:array<int,string>,isbns:array<string,string>,xref_ids:list<int>}>
	 *         keyed by the lower-cased name; isbns is ISBN-13 => the book's title, for the books that have one
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
			$p = &$people[mb_strtolower( $name )];
			$p ??= [ 'name' => $name, 'roles' => [], 'books' => [], 'isbns' => [], 'xref_ids' => [] ];
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
			 WHERE x.`item` IN ( 'penname', 'birthname' ) AND x.`end_date` IS NULL AND LOWER( x.`xkey_ext` ) = ? AND lc.`content_type_guid` = 'contactwikiindi'", [ mb_strtolower( $pPerson['name'] ) ] ) ?: [];
		foreach( $other as $row ) {
			$contacts[(int)$row['content_id']] ??= [ 'content_id' => (int)$row['content_id'], 'title' => $row['title'], 'qid' => null, 'alias' => true ];
		}

		// Open Library, from the person's own books first.
		$candidates = [];
		foreach( array_slice( $pPerson['isbns'], 0, 3, true ) as $isbn => $title ) {
			usleep( 300000 );
			$data = Wiki::fetchOpenLibraryJson( '/search.json?'.http_build_query( [ 'q' => 'isbn:'.$isbn, 'fields' => 'key,title,author_key,author_name', 'limit' => 1 ], '', '&' ) );
			$doc = $data['docs'][0] ?? [];
			foreach( (array)( $doc['author_key'] ?? [] ) as $n => $olid ) {
				if( in_array( Wiki::normaliseName( (string)( $doc['author_name'][$n] ?? '' ) ), $forms, true ) ) {
					$candidates[$olid] = [ 'olid' => $olid, 'via' => 'isbn', 'book' => $title ];
				}
			}
			if( $candidates ) {
				break;
			}
		}
		if( !$candidates ) {
			usleep( 300000 );
			foreach( Wiki::searchOpenLibraryAuthors( $pPerson['name'] ) as $hit ) {
				$names = array_map( [ Wiki::class, 'normaliseName' ], array_merge( [ $hit['name'] ], $hit['alternate_names'] ) );
				if( $hit['work_count'] > 0 && array_intersect( $forms, $names ) && count( $candidates ) < 3 ) {
					$candidates[$hit['olid']] = [ 'olid' => $hit['olid'], 'via' => 'name', 'book' => $hit['top_work'] ];
				}
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
		} elseif( !$candidates && count( $contacts ) === 1 ) {
			$default = 'contact:'.array_key_first( $contacts );
		} elseif( !$candidates && !$contacts ) {
			$default = 'new';
		} else {
			$default = 'skip';
		}
		return $pPerson + [ 'candidates' => array_values( $candidates ), 'contacts' => array_values( $contacts ), 'default' => $default,
			'bookTitles' => array_slice( array_values( $pPerson['books'] ), 0, 6 ), 'bookCount' => count( $pPerson['books'] ) ];
	}
}

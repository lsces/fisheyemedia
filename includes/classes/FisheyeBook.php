<?php
/**
 * Book - one EDITION of a book, extends FisheyeMediaImage with content_type_guid='fisheyebook'.
 *
 * The same unit as a music album (a release): the ISBN is the edition's key, and several editions of
 * one title are separate records tied together by their work ids (ol_work / wikidata_work), the way
 * album releases share a release-group. Two copies of one edition are 'copies' on one record, not two
 * records. Print, ebook and audiobook editions are each their own record - 'format' tells them apart.
 *
 * Like an album, a book has no LibertyMime attachment of its own; everything about it is liberty_xref
 * rows, written through reconcileXrefItem() so a re-run never wipes hand edits or contact links:
 *   - isbn            one row: xkey = ISBN-13, xkey_ext = ISBN-10
 *   - author/editor/illustrator/translator/narrator   credit rows (name in xkey_ext, contact in xref/xkey)
 *   - publisher       one credit row;   imprint = the line as printed
 *   - plain text      format, edition, published, pages, language, copies, paid
 *   - repeatable text category, subject, note
 *   - external ids    ol_edition, ol_work, wikidata_work, google_books, isfdb, gutenberg (id in xkey)
 *
 * event_time carries the published date for calendar sorting; the 'published' item keeps the
 * precision it was known to (a year, or a full date) that a timestamp cannot.
 *
 * Series are galleries (FisheyeSeries), not xrefs - registerFromRow() can link the new book into one
 * at a position. Vocabulary: the fisheyebook groups/items of the site's media xref scheme.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeGallery;

define( 'FISHEYEBOOK_CONTENT_TYPE_GUID', 'fisheyebook' );

// Single-valued plain-text items - the value lives in xkey_ext, as the album's label/barcode do.
// Longest side kept for a cover brought in from elsewhere: the 400px 'medium' size the Library shows; a higher-resolution original is archived separately.
const FISHEYEBOOK_COVER_MAX = 400;

const FISHEYEBOOK_TEXT_ITEMS = [ 'format', 'edition', 'published', 'pages', 'language', 'copies', 'paid', 'imprint' ];
// Repeatable plain-text items - one row per value.
const FISHEYEBOOK_LIST_ITEMS = [ 'category', 'subject', 'note' ];
// People - one credit row per person, name in xkey_ext, contact in xref/xkey once one exists.
const FISHEYEBOOK_CREDIT_ITEMS = [ 'author', 'editor', 'illustrator', 'translator', 'narrator' ];
// External identifiers - the bare id in xkey, rendered as a link by the item's own cross_ref_href.
const FISHEYEBOOK_EXTERNAL_ITEMS = [ 'ol_edition', 'ol_work', 'wikidata_work', 'google_books', 'isfdb', 'gutenberg' ];

class FisheyeBook extends FisheyeMediaImage {

	public function __construct( $pImageId = null, $pContentId = null ) {
		parent::__construct( $pImageId, $pContentId );
		$this->mContentTypeGuid = FISHEYEBOOK_CONTENT_TYPE_GUID;
		$this->registerContentType( FISHEYEBOOK_CONTENT_TYPE_GUID, [
			'content_type_guid' => FISHEYEBOOK_CONTENT_TYPE_GUID,
			'content_name'      => 'Book',
			'handler_class'     => 'FisheyeBook',
			'handler_package'   => 'fisheyemedia',
			'handler_file'      => 'FisheyeBook.php',
			'maintainer_url'    => 'https://www.bitweaver.org',
		] );
	}

	/**
	 * Same reason as FisheyeFilm::getEditUrl(): fisheye's own edit.php is the gallery edit page.
	 * The page itself (edit_book.php) is still to be written.
	 *
	 * @param int|null $pContentId
	 * @param array|null $pMixed  extra query params to append (content_id itself is skipped)
	 * @return string
	 */
	public function getEditUrl( $pContentId = null, $pMixed = null ) {
		$contentId = \Bitweaver\BitBase::verifyId( $pContentId ) ? $pContentId : $this->mContentId;
		$ret = FISHEYEMEDIA_PKG_URL.'edit_book.php?content_id='.$contentId;
		foreach( (array)$pMixed as $key => $value ) {
			if( $key !== 'content_id' ) {
				$ret .= '&'.$key.'='.$value;
			}
		}
		return $ret;
	}

	/**
	 * The page (view_book.php) is still to be written.
	 *
	 * @return string
	 */
	public function getDisplayUrl( $pContentId = null, $pMixed = null ) {
		$contentId = \Bitweaver\BitBase::verifyId( $pContentId ) ? $pContentId : $this->mContentId;
		return FISHEYEMEDIA_PKG_URL.'view_book.php?content_id='.$contentId;
	}

	// ---- ISBN ---------------------------------------------------------------------------------

	/**
	 * Whether a 13-digit string is a valid ISBN-13 (check digit included).
	 */
	public static function isbn13IsValid( string $pIsbn ): bool {
		if( strlen( $pIsbn ) !== 13 || !ctype_digit( $pIsbn ) ) {
			return false;
		}
		$sum = 0;
		for( $i = 0; $i < 12; $i++ ) {
			$sum += (int)$pIsbn[$i] * ( $i % 2 === 0 ? 1 : 3 );
		}
		return ( $sum + (int)$pIsbn[12] ) % 10 === 0;
	}

	/**
	 * Whether a 10-character string is a valid ISBN-10 (an X check digit allowed).
	 */
	public static function isbn10IsValid( string $pIsbn ): bool {
		if( !preg_match( '/^\d{9}[\dXx]$/', $pIsbn ) ) {
			return false;
		}
		$sum = 0;
		for( $i = 0; $i < 10; $i++ ) {
			$sum += ( 10 - $i ) * ( strtoupper( $pIsbn[$i] ) === 'X' ? 10 : (int)$pIsbn[$i] );
		}
		return $sum % 11 === 0;
	}

	/**
	 * ISBN-10 to its 978- ISBN-13 (the check digit recomputed, not carried over).
	 */
	public static function isbn10To13( string $pIsbn10 ): string {
		$core = '978'.substr( $pIsbn10, 0, 9 );
		$sum = 0;
		for( $i = 0; $i < 12; $i++ ) {
			$sum += (int)$core[$i] * ( $i % 2 === 0 ? 1 : 3 );
		}
		return $core.( ( 10 - $sum % 10 ) % 10 );
	}

	/**
	 * A 978- ISBN-13 to its ISBN-10; empty for a 979- number, which has none.
	 */
	public static function isbn13To10( string $pIsbn13 ): string {
		if( !str_starts_with( $pIsbn13, '978' ) || strlen( $pIsbn13 ) !== 13 ) {
			return '';
		}
		$core = substr( $pIsbn13, 3, 9 );
		$sum = 0;
		for( $i = 0; $i < 9; $i++ ) {
			$sum += ( 10 - $i ) * (int)$core[$i];
		}
		$check = ( 11 - $sum % 11 ) % 11;
		return $core.( $check === 10 ? 'X' : (string)$check );
	}

	/**
	 * Sort out what a book's ISBN text really is. Hyphens and spaces are ignored. A URL is returned
	 * untouched under 'url' (catalogues sometimes hold a download link in the ISBN column).
	 *
	 * 'isbn13'/'isbn10' are only ever set to values that pass their check digit. A 13-digit number
	 * that fails is first tried as '978' glued onto an ISBN-10 with its old check digit left on (a
	 * common slip) and, if the tail is a valid ISBN-10, repaired to the correct ISBN-13 and flagged;
	 * otherwise it is left unusable.
	 *
	 * @return array{isbn13:string,isbn10:string,url:string,flags:list<string>}
	 */
	public static function normaliseIsbn( string $pRaw ): array {
		$ret = [ 'isbn13' => '', 'isbn10' => '', 'url' => '', 'flags' => [] ];
		$raw = trim( $pRaw );
		if( $raw === '' ) {
			$ret['flags'][] = 'no-isbn';
			return $ret;
		}
		if( preg_match( '#^https?://#i', $raw ) ) {
			$ret['url'] = $raw;
			$ret['flags'][] = 'isbn-was-url';
			return $ret;
		}
		$digits = preg_replace( '/[\s-]/', '', $raw );
		if( self::isbn13IsValid( $digits ) ) {
			$ret['isbn13'] = $digits;
			$ret['isbn10'] = self::isbn13To10( $digits );
		} elseif( self::isbn10IsValid( $digits ) ) {
			$ret['isbn10'] = strtoupper( $digits );
			$ret['isbn13'] = self::isbn10To13( $ret['isbn10'] );
			$ret['flags'][] = 'isbn10-converted';
		} elseif( strlen( $digits ) === 13 && str_starts_with( $digits, '978' ) && self::isbn10IsValid( substr( $digits, 3 ) ) ) {
			$ret['isbn10'] = strtoupper( substr( $digits, 3 ) );
			$ret['isbn13'] = self::isbn10To13( $ret['isbn10'] );
			$ret['flags'][] = 'isbn-repaired';
		} elseif( strlen( $digits ) === 13 && str_starts_with( $digits, '987' ) && self::isbn13IsValid( '978'.substr( $digits, 3 ) ) ) {
			// The 978 prefix typed the wrong way round.
			$ret['isbn13'] = '978'.substr( $digits, 3 );
			$ret['isbn10'] = self::isbn13To10( $ret['isbn13'] );
			$ret['flags'][] = 'isbn-prefix-swapped';
		} else {
			$ret['flags'][] = strlen( $digits ) === 13 ? 'isbn-checkdigit-fails' : 'isbn-unrecognised';
		}
		return $ret;
	}

	// ---- published date -----------------------------------------------------------------------

	/**
	 * A sortable timestamp for a 'published' text such as '1995', '1995-03' or '1995-03-02' (a leading
	 * 'c.'/'c' for an estimate is ignored). Year-only and year-month take the first day. Native
	 * gmmktime(), so a negative timestamp for a pre-1970 book is fine - event_time is 64-bit. Taken as
	 * printed, proleptic Gregorian: an Old Style date before September 1752 may be some days out, which
	 * only matters to day-level sorting - the 'published' text itself stays as printed.
	 *
	 * @return int|null  null when nothing usable is there, so a book with no date never gets a spurious
	 *                   1 January 1970 (event_time's column default of 0)
	 */
	public static function publishedToTimestamp( string $pPublished ): ?int {
		$text = trim( preg_replace( '/^(c\.?|ca\.?|~)\s*/i', '', trim( $pPublished ) ), " ()\t" );
		if( !preg_match( '/^(\d{3,4})(?:-(\d{1,2})(?:-(\d{1,2}))?)?$/', $text, $m ) ) {
			return null;
		}
		$year = (int)$m[1];
		$month = isset( $m[2] ) ? (int)$m[2] : 1;
		$day = isset( $m[3] ) ? (int)$m[3] : 1;
		if( $year < 1 || $month < 1 || $month > 12 || $day < 1 || $day > 31 || !checkdate( $month, $day, $year ) ) {
			return null;
		}
		return gmmktime( 0, 0, 0, $month, $day, $year );
	}

	// ---- finding and creating ----------------------------------------------------------------

	/**
	 * The existing book matching a row, if any: by ISBN-13 when it has one, otherwise by title plus its
	 * first author's name (a book with no ISBN has nothing firmer, and two different books sharing both
	 * title and first author are the same book here).
	 *
	 * @return int|null  its content_id
	 */
	public static function findContentId( array $pRow ): ?int {
		global $gBitDb;
		$isbn13 = (string)( $pRow['isbn13'] ?? '' );
		if( $isbn13 !== '' ) {
			$id = $gBitDb->getOne(
				"SELECT lx.`content_id` FROM `".BIT_DB_PREFIX."liberty_xref` lx
				 INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = lx.`content_id`
				 WHERE lc.`content_type_guid` = ? AND lx.`item` = 'isbn' AND lx.`xkey` = ? AND lx.`end_date` IS NULL",
				[ FISHEYEBOOK_CONTENT_TYPE_GUID, $isbn13 ]
			);
			return $id ? (int)$id : null;
		}
		$title = trim( (string)( $pRow['title'] ?? '' ) );
		$authors = array_values( array_filter( (array)( $pRow['authors'] ?? [] ) ) );
		if( $title === '' ) {
			return null;
		}
		// A row that names its Wikidata work finds the book that holds it, whatever the title or author is spelt.
		if( ( $qid = (string)( $pRow['external']['wikidata_work'] ?? '' ) ) !== '' ) {
			$id = $gBitDb->getOne(
				"SELECT lx.`content_id` FROM `".BIT_DB_PREFIX."liberty_xref` lx
				 INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = lx.`content_id`
				 WHERE lc.`content_type_guid` = ? AND lx.`item` = 'wikidata_work' AND lx.`xkey` = ? AND lx.`end_date` IS NULL",
				[ FISHEYEBOOK_CONTENT_TYPE_GUID, $qid ]
			);
			if( $id ) {
				return (int)$id;
			}
		}
		$sql = "SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."liberty_content` lc
				WHERE lc.`content_type_guid` = ? AND lc.`title` = ?";
		$bind = [ FISHEYEBOOK_CONTENT_TYPE_GUID, $title ];
		if( $authors ) {
			$sql .= " AND EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` lx WHERE lx.`content_id` = lc.`content_id`
						AND lx.`item` = 'author' AND lx.`end_date` IS NULL AND lx.`xkey_ext` = ? )";
			$bind[] = $authors[0];
		}
		$id = $gBitDb->getOne( $sql, $bind );
		return $id ? (int)$id : null;
	}

	/**
	 * A book with the same title and first author as a row but not matched by findContentId() - on a row
	 * with an ISBN that means a different ISBN (another edition, or a mistyped number). Shown on the load
	 * lists so a second copy of a book is a choice, not an accident.
	 *
	 * @return array|null  content_id, title and isbn of the look-alike
	 */
	public static function findLookalike( array $pRow ): ?array {
		global $gBitDb;
		$title = trim( (string)( $pRow['title'] ?? '' ) );
		$authors = array_values( array_filter( (array)( $pRow['authors'] ?? [] ) ) );
		if( $title === '' || !$authors ) {
			return null;
		}
		$found = $gBitDb->getRow(
			"SELECT lc.`content_id`, lc.`title`, ( SELECT MIN( ix.`xkey` ) FROM `".BIT_DB_PREFIX."liberty_xref` ix
				WHERE ix.`content_id` = lc.`content_id` AND ix.`item` = 'isbn' AND ix.`end_date` IS NULL ) AS `isbn`
			 FROM `".BIT_DB_PREFIX."liberty_content` lc
			 WHERE lc.`content_type_guid` = ? AND lc.`title` = ?
			 AND EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` lx WHERE lx.`content_id` = lc.`content_id`
				AND lx.`item` = 'author' AND lx.`end_date` IS NULL AND lx.`xkey_ext` = ? )
			 ORDER BY lc.`content_id`",
			[ FISHEYEBOOK_CONTENT_TYPE_GUID, $title, $authors[0] ]
		);
		return $found ? [ 'id' => (int)$found['content_id'], 'title' => $found['title'], 'isbn' => (string)$found['isbn'] ] : null;
	}

	/**
	 * Register one book from a plain row (the shape of the clean catalogue sheet), link it into a
	 * gallery if given, and write its xrefs. Idempotent: a book already registered (see
	 * findContentId()) is reported, not duplicated.
	 *
	 * Row keys, all optional but 'title': isbn13, isbn10; title; description;
	 * authors (list of names) with author_role (author, editor, ...); publisher; every name in
	 * FISHEYEBOOK_TEXT_ITEMS; category/subject/note as strings or lists; published (as known) -
	 * event_time is derived from it; external => [ ol_work => ..., wikidata_work => ... ].
	 *
	 * @param array $pRow
	 * @param int $pGalleryContentId  a series or author gallery to link the book into, 0 for none
	 * @param float|null $pPosition   its item_position in that gallery (a float, so a missed volume can
	 *                                be slotted in between two others later)
	 * @return array 'already'=>content_id, or 'created'=>content_id plus 'linked'=>bool and 'counts', or
	 *               'error'=>string
	 */
	public static function registerFromRow( array $pRow, int $pGalleryContentId = 0, ?float $pPosition = null ): array {
		$title = trim( (string)( $pRow['title'] ?? '' ) );
		if( $title === '' ) {
			return [ 'error' => 'A book needs a title.' ];
		}
		global $gBitDb;
		if( !$gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref_item` WHERE `content_type_guid` IN ( 'fisheyebook', 'fisheyemedia' ) AND `item` = 'isbn'" ) ) {
			// Checked before anything is created, so an unapplied scheme leaves no half-made books behind.
			return [ 'error' => 'The book xref scheme is not applied to this site - apply it from liberty/admin/admin_local_scheme.php first.' ];
		}
		if( $existing = self::findContentId( $pRow ) ) {
			return [ 'already' => $existing ];
		}

		$book = new FisheyeBook();
		$storeHash = [ 'title' => $title ];
		if( !empty( $pRow['description'] ) ) {
			$storeHash['edit'] = self::textToHtml( (string)$pRow['description'] );
		}
		$timestamp = self::publishedToTimestamp( (string)( $pRow['published'] ?? '' ) );
		if( $timestamp !== null ) {
			$storeHash['event_time'] = $timestamp;
		}
		if( !$book->store( $storeHash ) ) {
			return [ 'error' => implode( '; ', $book->mErrors ) ];
		}
		$book->load();

		$linked = false;
		if( $pGalleryContentId ) {
			$gallery = new FisheyeGallery( null, $pGalleryContentId );
			$gallery->load();
			$linked = $gallery->addItem( $book->mContentId, $pPosition );
		}

		$counts = $book->reconcileBookXrefs( $pRow );
		if( $book->mErrors ) {
			// The xref scheme is not applied (or a restore wiped it): the book exists but holds none of its rows.
			return [ 'error' => $pRow['title'].' - '.implode( '; ', array_unique( $book->mErrors ) ) ];
		}
		// An author who already has a contact (by name, pen name or birth name) is linked at once.
		$counts['credits_linked'] = FisheyeBookCredits::linkBookCredits( (int)$book->mContentId );
		return [ 'created' => $book->mContentId, 'linked' => $linked, 'counts' => $counts ];
	}

	/**
	 * Bring this book's xrefs into line with a row - everything through reconcileXrefItem(), so an
	 * unchanged value is left alone, a changed one archived and replaced, a hand-edited row never
	 * touched, and a contact link on a credit survives.
	 *
	 * @return array<string,int>  counts: inserted/archived/unchanged/kept_local
	 */
	public function reconcileBookXrefs( array $pRow ): array {
		$counts = [];
		$tally = function( array $pCounts ) use ( &$counts ) {
			foreach( $pCounts as $key => $n ) {
				$counts[$key] = ( $counts[$key] ?? 0 ) + $n;
			}
		};

		// ISBN: one row, the ISBN-13 as the key (xkey), the ISBN-10 beside it (xkey_ext).
		$isbn13 = (string)( $pRow['isbn13'] ?? '' );
		$isbn10 = (string)( $pRow['isbn10'] ?? '' );
		$wantedIsbn = [];
		if( $isbn13 !== '' || $isbn10 !== '' ) {
			$wanted = [ 'key' => '', 'xorder' => 0 ];
			if( $isbn13 !== '' ) {
				$wanted['xkey'] = $isbn13;
			}
			if( $isbn10 !== '' ) {
				$wanted['xkey_ext'] = $isbn10;
			}
			$wantedIsbn[] = $wanted;
		}
		$tally( $this->reconcileXrefItem( 'isbn', $wantedIsbn, null ) );

		foreach( FISHEYEBOOK_TEXT_ITEMS as $item ) {
			$value = trim( (string)( $pRow[$item] ?? '' ) );
			$tally( $this->reconcileXrefItem( $item, $value === '' ? [] : [ [ 'key' => '', 'xorder' => 0, 'xkey_ext' => $value ] ], null ) );
		}

		foreach( FISHEYEBOOK_LIST_ITEMS as $item ) {
			$wanted = [];
			foreach( array_values( array_filter( array_map( 'trim', (array)( $pRow[$item] ?? [] ) ) ) ) as $value ) {
				$wanted[] = [ 'key' => mb_strtolower( $value ), 'xorder' => count( $wanted ) + 1, 'xkey_ext' => $value ];
			}
			$tally( $this->reconcileXrefItem( $item, $wanted, fn( array $pXrefRow ) => mb_strtolower( (string)$pXrefRow['xkey_ext'] ) ) );
		}

		foreach( FISHEYEBOOK_EXTERNAL_ITEMS as $item ) {
			$value = trim( (string)( $pRow['external'][$item] ?? '' ) );
			$tally( $this->reconcileXrefItem( $item, $value === '' ? [] : [ [ 'key' => '', 'xorder' => 0, 'xkey' => $value ] ], null ) );
		}

		// People. The role the row names ('editor' for a catalogue's "Ed:" entries) goes to that
		// credit item, every other credit item reconciles to empty. Rows carry a 'source' so a credit
		// added by hand is told apart and left alone; a contact link made later survives a re-run
		// ($pKeepLinks), the name in xkey_ext staying as display text until then.
		$role = in_array( $pRow['author_role'] ?? '', FISHEYEBOOK_CREDIT_ITEMS, true ) ? $pRow['author_role'] : 'author';
		$creditKey = fn( array $pXrefRow ) => 'name:'.mb_strtolower( (string)$pXrefRow['xkey_ext'] );
		foreach( FISHEYEBOOK_CREDIT_ITEMS as $item ) {
			$wanted = [];
			if( $item === $role ) {
				foreach( array_values( array_filter( array_map( 'trim', (array)( $pRow['authors'] ?? [] ) ) ) ) as $name ) {
					$ids = (array)( $pRow['author_ids'][$name] ?? [] );
					$one = [ 'key' => 'name:'.mb_strtolower( $name ), 'xorder' => count( $wanted ) + 1, 'xkey_ext' => $name, 'data' => [ 'source' => 'import' ] ];
					if( $ids ) {
						// The Wikidata id when Open Library has it, else its own: the key a credit linked to a contact carries (contactKey()).
						$one['xkey'] = $ids['qid'] ?: $ids['olid'];
						$one['data'] += array_filter( [ 'olid' => $ids['olid'] ?? '', 'qid' => $ids['qid'] ?? '' ] );
					}
					$wanted[] = $one;
				}
			}
			$tally( $this->reconcileXrefItem( $item, $wanted, $creditKey, true, true ) );
		}

		$publisher = trim( (string)( $pRow['publisher'] ?? '' ) );
		$tally( $this->reconcileXrefItem( 'publisher',
			$publisher === '' ? [] : [ [ 'key' => 'name:'.mb_strtolower( $publisher ), 'xorder' => 1, 'xkey_ext' => $publisher, 'data' => [ 'source' => 'import' ] ] ],
			$creditKey, true, true ) );

		return $counts;
	}

	/**
	 * Set the published date of an existing book from a text such as '2017': the 'published' row and event_time (which dates it in a series and the
	 * calendar) together, so the two cannot disagree. A text that is not a date is refused.
	 */
	public function setPublished( string $pPublished ): bool {
		$pPublished = trim( $pPublished );
		$timestamp = self::publishedToTimestamp( $pPublished );
		if( $pPublished === '' || $timestamp === null || !$this->isValid() ) {
			return false;
		}
		$storeHash = [ 'content_id' => $this->mContentId, 'title' => $this->getTitle(), 'edit' => (string)$this->getField( 'data' ), 'event_time' => $timestamp ];
		if( !$this->store( $storeHash ) ) {
			return false;
		}
		$this->reconcileXrefItem( 'published', [ [ 'key' => '', 'xorder' => 0, 'xkey_ext' => $pPublished ] ], null );
		return true;
	}

	// ---- Images -------------------------------------------------------------------------------
	// A book keeps its covers and scans in its own storage/attachments/<branch>/, as an album does:
	// each an 'image' row (the Images tab), the chosen one also its thumbnail. The artwork helpers
	// (storeArtworkXref() and friends) are FisheyeMediaTrait's.

	private function getImageStorageBranchPath(): string {
		return STORAGE_PKG_PATH.\Bitweaver\Liberty\liberty_mime_get_storage_branch( [ 'attachment_id' => $this->mContentId ] );
	}

	public function getExtraImagePath( string $pRelativePath ): string {
		return $this->getImageStorageBranchPath().$pRelativePath;
	}

	/** Its existence is what turns on the Images tab's upload (supportsAddImage()). */
	public function getImageStorageRoot(): string {
		return $this->getImageStorageBranchPath();
	}

	public function replaceXrefFile( string $pItem, string $pXkeyExt, string $pTmpPath ): bool {
		if( $pItem !== 'image' || $pXkeyExt === '' || basename( $pXkeyExt ) !== $pXkeyExt ) {
			return false;
		}
		return move_uploaded_file( $pTmpPath, $this->getImageStorageBranchPath().$pXkeyExt );
	}

	public function deleteXrefFile( string $pItem, string $pXkeyExt ): bool {
		if( $pItem !== 'image' || $pXkeyExt === '' || basename( $pXkeyExt ) !== $pXkeyExt ) {
			return false;
		}
		$path = $this->getImageStorageBranchPath().$pXkeyExt;
		return is_file( $path ) && @unlink( $path );
	}

	/**
	 * Called after an xref row is added by hand: the first image added to a book with no thumbnail yet becomes its thumbnail,
	 * so there is no separate Set as Thumbnail press (the tile copy is made then and there). A book that already has one is
	 * left alone - a later image only adds to the Images tab.
	 */
	public function xrefAdded( string $pItem, string $pXkeyExt ): void {
		if( $pItem === 'image' && $pXkeyExt !== '' && !$this->hasThumbnail() ) {
			$this->promoteImageToThumbnail( $pXkeyExt );
		}
	}

	/** A book's tile is its thumbs/ files; an attachment row without them (a failed or never-made tile) counts as none. */
	public function hasThumbnail(): bool {
		return (bool)$this->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_attachments` WHERE `content_id` = ?", [ $this->mContentId ] )
			&& !empty( glob( $this->getImageStorageBranchPath().'thumbs/*' ) );
	}

	/**
	 * Make one of the book's stored images its thumbnail (the Images tab's Set as Thumbnail) -
	 * regenerates thumbs/ from the chosen file, as FisheyeAlbum::promoteImageToThumbnail() does.
	 */
	public function promoteImageToThumbnail( string $pRelativePath ): bool {
		$branchPath = $this->getImageStorageBranchPath();
		if( basename( $pRelativePath ) !== $pRelativePath || !is_file( $branchPath.$pRelativePath ) ) {
			return false;
		}
		// No thumbnail attachment yet (a first attempt that failed, or a book with no Open Library cover): there is nothing
		// to regenerate, so make it - attachThumbnail() stores the attachment and renders the thumbs from this file.
		if( !$this->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_attachments` WHERE `content_id` = ?", [ $this->mContentId ] ) ) {
			return $this->attachThumbnail( $branchPath.$pRelativePath );
		}
		foreach( glob( $branchPath.'thumbs/*' ) ?: [] as $oldThumb ) {
			@unlink( $oldThumb );
		}
		$fileHash = [ 'type' => 'image/jpeg', 'source_file' => $branchPath.$pRelativePath, 'dest_branch' => \Bitweaver\Liberty\liberty_mime_get_storage_branch( [ 'attachment_id' => $this->mContentId ] ) ];
		$ok = \Bitweaver\Liberty\liberty_generate_thumbnails( $fileHash );
		$this->load();
		return $ok;
	}

	/**
	 * Make a supplied picture the book's front cover and its tile, replacing the cover it had: the first image (the one that
	 * became the tile) and any cover fetched from Open Library or Google Books. Spine, back and other images the book holds
	 * are kept. The old image rows are expunged with their files, and the tile is rebuilt from the new file.
	 *
	 * @param string $pSourceFile local path of the new picture
	 * @param string $pSource     where it came from - stored on the image row, so a later pass can tell it has been done
	 * @param int    $pMax        longest side kept (FISHEYEBOOK_COVER_MAX); a smaller picture is stored as it is
	 * @return string|null the stored file name, null when the picture could not be stored
	 */
	public function replaceCover( string $pSourceFile, string $pSource, int $pMax = FISHEYEBOOK_COVER_MAX ): ?string {
		$old = $this->mDb->getAll( "SELECT `xref_id`, `xkey_ext`, `data` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'image' AND `end_date` IS NULL ORDER BY `xorder`", [ $this->mContentId ] ) ?: [];
		$fileName = $this->storeArtworkXref( $pSourceFile, 'front-cover', $pSource, [ 'types' => [ 'Front' ] ], $pMax );
		if( $fileName === null ) {
			return null;
		}
		$newRowId = (int)$this->mDb->getOne( "SELECT MAX(`xref_id`) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'image' AND `xkey_ext` = ? AND `end_date` IS NULL", [ $this->mContentId, $fileName ] );
		foreach( $old as $index => $row ) {
			$data = (string)$row['data'];
			$isCover = $index === 0 || str_contains( $data, 'openlibrary:' ) || str_contains( $data, 'googlebooks:' ) || str_contains( $data, '"Front"' );
			if( !$isCover || (int)$row['xref_id'] === $newRowId ) {
				continue;
			}
			$stepHash = [ 'xref_id' => (int)$row['xref_id'], 'expunge' => 3 ];
			if( $this->stepXref( $stepHash ) && $row['xkey_ext'] !== $fileName ) {
				@unlink( $this->getExtraImagePath( '' ).$row['xkey_ext'] );
			}
		}
		$this->attachThumbnail( $this->getExtraImagePath( '' ).$fileName );
		return $fileName;
	}

	/**
	 * Every way an xref row is stored by hand (the Add form, the tab grid's edit) comes through here. An ISBN row is checked and
	 * normalised first: whichever number was typed - ISBN-10 or ISBN-13, hyphens or not, in the `isbn`, `xkey_ext` or `xkey`
	 * field - is stored as the ISBN-13 in xkey and the ISBN-10 beside it in xkey_ext, so the row always works for lookups, for
	 * Reload from Open Library and for matching a catalogue row. A number that fails its check digit is refused with a message.
	 */
	public function storeXref( &$pParamHash ): bool {
		if( ( $pParamHash['item'] ?? '' ) === 'isbn' ) {
			$typed = '';
			foreach( [ 'isbn', 'xkey_ext', 'xkey' ] as $field ) {
				if( trim( (string)( $pParamHash[$field] ?? '' ) ) !== '' ) {
					$typed = trim( (string)$pParamHash[$field] );
					break;
				}
			}
			$isbn = self::normaliseIsbn( $typed );
			if( $isbn['isbn13'] === '' ) {
				$this->mErrors['isbn'] = \Bitweaver\KernelTools::tra( 'That is not a valid ISBN: it should be an ISBN-10 or ISBN-13 with a correct check digit.' );
				return false;
			}
			unset( $pParamHash['isbn'] );
			$pParamHash['xkey'] = $isbn['isbn13'];
			$pParamHash['xkey_ext'] = $isbn['isbn10'];
			$pParamHash['edit'] = '';                           // the row carries no data; this also clears a leftover 'entered' value
		}
		return parent::storeXref( $pParamHash );
	}

	/**
	 * What Wikidata says about this book once it is filed in a series, written to the book itself so the metadata does not depend
	 * on Open Library having it: the `series` item as "<series>, #<number>" (just the name when Wikidata gives no number) and the
	 * `wikidata_work` id. Each only where the book has nothing yet - Open Library's own text and anything entered by hand are
	 * never replaced.
	 *
	 * @param int|string|null $pOrdinal  the number Wikidata gives the book in the series
	 * @return list<string>  the items written
	 */
	public function recordWikidataSeries( string $pSeriesName, $pOrdinal = null, string $pWorkQ = '' ): array {
		$has = fn( string $pItem ): bool => (bool)(int)$this->mDb->getOne(
			"SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = ? AND `end_date` IS NULL", [ $this->mContentId, $pItem ] );
		$written = [];
		if( trim( $pSeriesName ) !== '' && !$has( 'series' ) ) {
			$text = trim( $pSeriesName ).( $pOrdinal !== null && $pOrdinal !== '' ? ', #'.$pOrdinal : '' );
			$hash = [ 'content_id' => $this->mContentId, 'item' => 'series', 'xkey_ext' => $text, 'edit' => json_encode( [ 'source' => 'wikidata' ] ) ];
			if( $this->storeXref( $hash ) ) {
				$written[] = 'series';
			}
		}
		if( preg_match( '/^Q\d+$/', $pWorkQ ) && !$has( 'wikidata_work' ) ) {
			$hash = [ 'content_id' => $this->mContentId, 'item' => 'wikidata_work', 'xkey' => $pWorkQ, 'edit' => json_encode( [ 'source' => 'wikidata' ] ) ];
			if( $this->storeXref( $hash ) ) {
				$written[] = 'wikidata_work';
			}
		}
		return $written;
	}

	// ---- Open Library -------------------------------------------------------------------------

	/**
	 * Add what Open Library knows about this edition, found by its ISBN: the edition and work ids, page
	 * count, format, publication date, language, publisher, and a cover (also the thumbnail). Fills
	 * blanks only - a value the catalogue or an editor already gave is never replaced, and a book that
	 * already has an image keeps it - so it is safe to run again and again.
	 *
	 * @return array{matched:bool, items:list<string>, error?:string}
	 */
	/** @var bool set when the cover server gave no response - the rest of the request skips covers */
	private static bool $coversDown = false;

	/**
	 * A Google Books cover by ISBN, the largest real one: zoom 0 (about 600px), then 2 (300px), then 1 (128px). Where Google
	 * has no picture at a size it answers with a stand-in instead of an error - a grey "image not available" page, a
	 * different size at each zoom - so each answer is checked (isBlankCover()) and a stand-in passed over for the next
	 * size. Zoom 3 is not asked for: it answers with a plain blank-book picture. Null when there is no real cover.
	 */
	private static function fetchGoogleCover( string $pWiki, string $pIsbn ): ?string {
		foreach( [ 0, 2, 1 ] as $zoom ) {
			$bytes = $pWiki::fetchOpenLibraryImage( 'https://books.google.com/books/content?vid=ISBN'.rawurlencode( $pIsbn ).'&printsec=frontcover&img=1&zoom='.$zoom );
			if( $bytes !== null && !self::isBlankCover( $bytes ) ) {
				return $bytes;
			}
		}
		return null;
	}

	/**
	 * The books whose stored Google Books cover is a "no cover" stand-in (isBlankCover()) - covers fetched before the check
	 * existed. Reads each stored file, so only ever the few books whose cover came from Google.
	 *
	 * @return list<int>
	 */
	public static function booksWithBlankGoogleCover(): array {
		global $gBitDb;
		$blank = [];
		foreach( $gBitDb->getAll( "SELECT `content_id`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `item` = 'image' AND `end_date` IS NULL AND `data` LIKE '%googlebooks:%' ORDER BY `content_id`" ) ?: [] as $row ) {
			$book = \Bitweaver\Fisheye\FisheyeImage::lookup( [ 'content_id' => (int)$row['content_id'] ] );
			if( $book && $book->isValid() && method_exists( $book, 'dropBlankGoogleCover' ) ) {
				$file = $book->getExtraImagePath( '' ).$row['xkey_ext'];
				if( is_file( $file ) && self::isBlankCover( (string)file_get_contents( $file ) ) ) {
					$blank[(int)$row['content_id']] = (int)$row['content_id'];
				}
			}
		}
		return array_values( $blank );
	}

	/**
	 * Remove this book's Google Books cover if it is a "no cover" stand-in: the image row, its file and the thumbnail made from it,
	 * so the tile shows a plain name tile (and the book counts as having no cover) until a real one is found. False if
	 * there was nothing blank to remove.
	 */
	public function dropBlankGoogleCover(): bool {
		$dropped = false;
		foreach( $this->mDb->getAll( "SELECT `xref_id`, `xkey_ext`, `data` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'image' AND `end_date` IS NULL", [ $this->mContentId ] ) ?: [] as $row ) {
			if( !str_contains( (string)$row['data'], 'googlebooks:' ) ) {
				continue;
			}
			$file = $this->getExtraImagePath( '' ).$row['xkey_ext'];
			if( !is_file( $file ) || !self::isBlankCover( (string)file_get_contents( $file ) ) ) {
				continue;
			}
			$stepHash = [ 'xref_id' => (int)$row['xref_id'], 'expunge' => 3 ];
			if( $this->stepXref( $stepHash ) ) {
				@unlink( $file );
				$dropped = true;
			}
		}
		if( $dropped ) {
			\Bitweaver\Liberty\LibertyMime::load();
			foreach( array_keys( (array)$this->mStorage ) as $attachmentId ) {
				$this->expungeAttachment( $attachmentId );
			}
		}
		return $dropped;
	}

	/**
	 * Whether an image is a "no cover" stand-in rather than a cover: shrunk to a small grid, almost every cell is white (the
	 * grey "image not available" page) or none has any colour at all (a flat grey one). A real cover has colour or ink.
	 */
	public static function isBlankCover( string $pBytes ): bool {
		if( !function_exists( 'imagecreatefromstring' ) || !( $im = @imagecreatefromstring( $pBytes ) ) ) {
			return false;
		}
		$small = imagescale( $im, 24, 24 );
		if( !$small ) {
			return false;
		}
		$white = 0;
		$colourful = 0;
		for( $y = 0; $y < 24; $y++ ) {
			for( $x = 0; $x < 24; $x++ ) {
				$rgb = imagecolorat( $small, $x, $y );
				$r = ( $rgb >> 16 ) & 255;
				$g = ( $rgb >> 8 ) & 255;
				$b = $rgb & 255;
				if( min( $r, $g, $b ) >= 225 ) {
					$white++;
				}
				if( max( $r, $g, $b ) - min( $r, $g, $b ) > 45 ) {
					$colourful++;
				}
			}
		}
		return $white / 576 > 0.78 || $colourful === 0;
	}

	/**
	 * Ask Wikidata about this book through its author: the series it belongs to, its number in it and the work's own id, written
	 * to the book where it has none (see recordWikidataSeries() - nothing already there is replaced). The book's series gallery
	 * is not touched; that is the series page's job.
	 *
	 * @return array{matched:bool,items:list<string>,error?:string}
	 */
	public function reloadFromWikidata(): array {
		$summary = [ 'matched' => false, 'items' => [] ];
		$wiki = '\\Bitweaver\\Contactwiki\\ContactWikiIndividual';
		if( !class_exists( $wiki ) ) {
			$summary['error'] = \Bitweaver\KernelTools::tra( 'The contactwiki package is needed to ask Wikidata.' );
			return $summary;
		}
		$author = (string)$this->mDb->getOne( "SELECT `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'author' AND `end_date` IS NULL ORDER BY `xorder`", [ $this->mContentId ] );
		if( trim( $author ) === '' ) {
			$summary['error'] = \Bitweaver\KernelTools::tra( 'This book has no author to look up.' );
			return $summary;
		}
		$qid = FisheyeBookCredits::authorQid( $author );
		if( $qid === '' ) {
			$summary['error'] = \Bitweaver\KernelTools::tra( 'The author is not linked to a contact that has a Wikidata item, so Wikidata cannot be asked.' );
			return $summary;
		}
		$published = (string)$this->mDb->getOne( "SELECT `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'published' AND `end_date` IS NULL", [ $this->mContentId ] );
		$plan = FisheyeSeries::planForRows( [ [ 'title' => $this->getTitle(), 'published' => $published ] ], $qid );
		if( $plan === null ) {
			$summary['error'] = \Bitweaver\KernelTools::tra( 'Wikidata did not answer, so nothing was changed' ).( ( $e = $wiki::getLastFetchError() ) ? ': '.$e : '' );
			return $summary;
		}
		if( !isset( $plan['assign'][0] ) ) {
			return $summary;                              // Wikidata knows the author's works but not this one in a series
		}
		$summary['matched'] = true;
		foreach( $this->recordWikidataSeries( $plan['assign'][0], $plan['meta'][0]['ordinal'] ?? null, (string)( $plan['meta'][0]['work_q'] ?? '' ) ) as $item ) {
			$summary['items'][] = $item.': '.( $item === 'series' ? $plan['assign'][0].( ( $plan['meta'][0]['ordinal'] ?? null ) !== null ? ', #'.$plan['meta'][0]['ordinal'] : '' ) : $plan['meta'][0]['work_q'] );
		}
		return $summary;
	}

	/** The book's ISBNs (13 then 10) and Open Library's edition for the first that has one. @return array{isbns:list<string>,edition:?array,error:string} */
	private function openLibraryEditionForIsbns(): array {
		$ret = [ 'isbns' => [], 'edition' => null, 'error' => '' ];
		$wiki = '\\Bitweaver\\Contactwiki\\ContactWikiIndividual';
		if( !class_exists( $wiki ) ) {
			$ret['error'] = \Bitweaver\KernelTools::tra( 'The contactwiki package is needed to ask Open Library.' );
			return $ret;
		}
		$isbnRow = $this->mDb->getRow( "SELECT `xkey`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'isbn' AND `end_date` IS NULL", [ $this->mContentId ] );
		$ret['isbns'] = array_values( array_filter( [ trim( (string)( $isbnRow['xkey'] ?? '' ) ), trim( (string)( $isbnRow['xkey_ext'] ?? '' ) ) ] ) );
		if( !$ret['isbns'] ) {
			$ret['error'] = \Bitweaver\KernelTools::tra( 'This book has no ISBN to look up.' );
			return $ret;
		}
		foreach( $ret['isbns'] as $isbn ) {
			usleep( 300000 );
			$found = $wiki::fetchOpenLibraryJson( '/isbn/'.rawurlencode( $isbn ).'.json' );
			if( str_starts_with( (string)( $found['key'] ?? '' ), '/books/' ) ) {
				$ret['edition'] = $found;
				return $ret;
			}
		}
		if( ( $fetchError = $wiki::getLastFetchError() ) && !str_contains( $fetchError, 'HTTP 404' ) ) {
			$ret['error'] = \Bitweaver\KernelTools::tra( 'Open Library did not answer, so nothing was changed' ).': '.$fetchError;
		}
		return $ret;
	}

	/** The ISBN the stored cover was fetched for ('openlibrary:<isbn>' / 'googlebooks:<isbn>' in its row), or '' when it did not come from a lookup. */
	private function coverSourceIsbn(): string {
		foreach( $this->mDb->getAll( "SELECT `data` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'image' AND `end_date` IS NULL ORDER BY `xorder`", [ $this->mContentId ] ) ?: [] as $row ) {
			if( preg_match( '/(?:openlibrary|googlebooks):([0-9Xx]{10,13})/', (string)$row['data'], $m ) ) {
				return $m[1];
			}
		}
		return '';
	}

	/**
	 * Without asking anyone: whether the book's details look to have been loaded for a different ISBN than it has now (the cover was
	 * fetched for another one). A hint only - checkOpenLibraryIsbn() is the real comparison.
	 */
	public function isbnChangedHint(): ?string {
		$source = $this->coverSourceIsbn();
		if( $source === '' ) {
			return null;
		}
		$isbnRow = $this->mDb->getRow( "SELECT `xkey`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'isbn' AND `end_date` IS NULL", [ $this->mContentId ] );
		$current = array_filter( [ (string)( $isbnRow['xkey'] ?? '' ), (string)( $isbnRow['xkey_ext'] ?? '' ) ] );
		return $current && !in_array( $source, $current, true )
			? sprintf( \Bitweaver\KernelTools::tra( 'The cover was fetched for ISBN %s, but this book\'s ISBN is now %s.' ), $source, reset( $current ) )
			: null;
	}

	/**
	 * Asks Open Library about the book's current ISBN and sets what it holds beside what is stored, writing nothing: the edition (id,
	 * language, publisher, year, pages, format), the cover's ISBN and the start of the description.
	 *
	 * @return array{error:string,found:bool,rows:list<array{label:string,stored:string,found:string,differs:bool}>,cover:string,description:string}
	 */
	public function checkOpenLibraryIsbn(): array {
		$look = $this->openLibraryEditionForIsbns();
		$ret = [ 'error' => $look['error'], 'found' => (bool)$look['edition'], 'rows' => [], 'cover' => '', 'description' => '' ];
		if( !$look['edition'] ) {
			return $ret;
		}
		$fields = FisheyeBookCatalogue::fromOpenLibraryEdition( $look['edition'] );
		$stored = fn( string $pItem ) => trim( (string)$this->mDb->getOne( "SELECT COALESCE( `xkey`, `xkey_ext` ) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = ? AND `end_date` IS NULL ORDER BY `xorder`", [ $this->mContentId, $pItem ] ) );
		foreach( [ 'ol_edition' => 'Edition', 'language' => 'Language', 'publisher' => 'Publisher', 'published' => 'Published', 'pages' => 'Pages', 'format' => 'Format' ] as $item => $label ) {
			$value = $fields[$item] ?? null;
			$found = $value === null ? '' : trim( (string)( $value['name'] ?? $value['xkey'] ?? $value['xkey_ext'] ?? '' ) );
			$was = $stored( $item );
			$ret['rows'][] = [ 'label' => $label, 'stored' => $was, 'found' => $found, 'differs' => mb_strtolower( $was ) !== mb_strtolower( $found ) ];
		}
		$coverId = (int)( $look['edition']['covers'][0] ?? 0 );
		$ret['cover'] = $coverId > 0 ? 'https://covers.openlibrary.org/b/id/'.$coverId.'-M.jpg?default=false' : '';
		// The description Open Library would give (the edition's, else the work's - as the fill does); the stored one is on the edit page already.
		$wiki = '\\Bitweaver\\Contactwiki\\ContactWikiIndividual';
		$text = fn( $pValue ): string => is_array( $pValue ) ? (string)( $pValue['value'] ?? '' ) : (string)$pValue;
		$foundText = self::cleanOpenLibraryText( $text( $look['edition']['description'] ?? '' ) );
		if( $foundText === '' && ( $workKey = (string)( $look['edition']['works'][0]['key'] ?? '' ) ) !== '' ) {
			usleep( 300000 );
			$work = $wiki::fetchOpenLibraryJson( $workKey.'.json' );
			$foundText = is_array( $work ) ? self::cleanOpenLibraryText( $text( $work['description'] ?? '' ) ) : '';
		}
		if( mb_strlen( $foundText ) < 30 || preg_match( '/^\W*(duplicate|dupe|see )/i', $foundText ) ) {
			$foundText = '';
		}
		$ret['description'] = $foundText;
		$ret['rows'][] = [ 'label' => 'Cover fetched for ISBN', 'stored' => $this->coverSourceIsbn(), 'found' => (string)$look['isbns'][0], 'differs' => !in_array( $this->coverSourceIsbn(), $look['isbns'], true ) ];
		return $ret;
	}

	/**
	 * The book's ISBN was changed to another edition: the details that belong to an edition (its id, language, publisher, year, pages, format,
	 * edition name and the ids on other services, and the cover) are replaced by those of the edition the ISBN now names. What an editor owns
	 * is left alone - the title, series, authors, genres and anything else - and the description only when $pDescription is true. The old
	 * rows are archived (the History tab keeps them), not deleted, and nothing is touched unless Open Library has the edition.
	 *
	 * @return array{matched:bool,items:list<string>,error?:string}
	 */
	public function replaceFromOpenLibrary( bool $pDescription = false ): array {
		$summary = [ 'matched' => false, 'items' => [] ];
		$look = $this->openLibraryEditionForIsbns();
		if( $look['error'] !== '' ) {
			$summary['error'] = $look['error'];
			return $summary;
		}
		if( !$look['edition'] ) {
			$summary['error'] = \Bitweaver\KernelTools::tra( 'Open Library has no edition with this ISBN, so nothing was changed.' );
			return $summary;
		}
		$summary['matched'] = true;

		// 1. The old edition rows out (archived). A publisher only where it came from Open Library; a hand-entered one stays.
		$archived = [];
		foreach( $this->mDb->getAll(
			"SELECT `xref_id`, `item`, `data` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `end_date` IS NULL AND `item` IN ('ol_edition','pages','format','published','language','publisher','edition','google_books','goodreads','librarything','oclc','internet_archive')",
			[ $this->mContentId ] ) ?: [] as $row ) {
			if( $row['item'] === 'publisher' && !str_contains( (string)$row['data'], 'openlibrary' ) ) {
				continue;
			}
			$stepHash = [ 'xref_id' => (int)$row['xref_id'], 'expunge' => 3 ];
			if( $this->stepXref( $stepHash ) ) {
				$archived[$row['item']] = true;
			}
		}
		if( $archived ) {
			$summary['items'][] = 'replaced: '.implode( ', ', array_keys( $archived ) );
		}

		// 2. The description, if asked: emptied so the fill below writes the new edition's, and put back if there is none.
		$oldDescription = trim( (string)$this->mDb->getOne( "SELECT `data` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ?", [ $this->mContentId ] ) );
		if( $pDescription && $oldDescription !== '' ) {
			$emptyHash = [ 'content_id' => $this->mContentId, 'title' => $this->getTitle(), 'edit' => '' ];
			$this->store( $emptyHash );
		}

		// 3. The fill: edition rows and ids (their rows are gone, so they are added), then the work's description when empty.
		foreach( $this->reloadFromOpenLibrary( false )['items'] as $line ) {
			$summary['items'][] = $line;
		}
		if( $pDescription && $oldDescription !== '' && trim( (string)$this->mDb->getOne( "SELECT `data` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ?", [ $this->mContentId ] ) ) === '' ) {
			$backHash = [ 'content_id' => $this->mContentId, 'title' => $this->getTitle(), 'edit' => $oldDescription ];
			$this->store( $backHash );
			$summary['items'][] = 'description: Open Library has none for this edition - the old one kept';
		}

		// 4. The cover: the edition's own cover id, then by ISBN, then Google Books; the old cover is archived only once a new one is stored.
		$wiki = '\\Bitweaver\\Contactwiki\\ContactWikiIndividual';
		$candidates = [];
		if( (int)( $look['edition']['covers'][0] ?? 0 ) > 0 ) {
			$candidates[] = [ 'url' => 'https://covers.openlibrary.org/b/id/'.(int)$look['edition']['covers'][0].'-L.jpg?default=false', 'source' => 'openlibrary:'.$look['isbns'][0] ];
		}
		foreach( $look['isbns'] as $isbn ) {
			$candidates[] = [ 'url' => 'https://covers.openlibrary.org/b/isbn/'.$isbn.'-L.jpg?default=false', 'source' => 'openlibrary:'.$isbn ];
		}
		foreach( $look['isbns'] as $isbn ) {
			$candidates[] = [ 'url' => '', 'isbn' => $isbn, 'source' => 'googlebooks:'.$isbn ];
		}
		foreach( $candidates as $candidate ) {
			usleep( 300000 );
			$bytes = $candidate['url'] !== '' ? $wiki::fetchOpenLibraryImage( $candidate['url'] ) : self::fetchGoogleCover( $wiki, $candidate['isbn'] );
			if( $bytes === null ) {
				continue;
			}
			$tmp = tempnam( sys_get_temp_dir(), 'olcover_' );
			file_put_contents( $tmp, $bytes );
			$stored = $this->replaceCover( $tmp, $candidate['source'] );
			@unlink( $tmp );
			if( $stored !== null ) {
				$summary['items'][] = 'cover: '.$candidate['source'];
				break;
			}
		}
		$this->load();
		return $summary;
	}

	/**
	 * @param bool $pCover  false to leave covers alone - the bulk Local Imports fill asks for the text and ids only
	 */
	public function reloadFromOpenLibrary( bool $pCover = true ): array {
		$summary = [ 'matched' => false, 'items' => [] ];
		$wiki = '\Bitweaver\Contactwiki\ContactWikiIndividual';
		if( !class_exists( $wiki ) ) {
			$summary['error'] = \Bitweaver\KernelTools::tra( 'The contactwiki package is needed to ask Open Library.' );
			return $summary;
		}
		$isbnRow = $this->mDb->getRow( "SELECT `xkey`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'isbn' AND `end_date` IS NULL", [ $this->mContentId ] );
		$isbns = array_values( array_filter( [ trim( (string)( $isbnRow['xkey'] ?? '' ) ), trim( (string)( $isbnRow['xkey_ext'] ?? '' ) ) ] ) );
		if( !$isbns ) {
			$summary['error'] = \Bitweaver\KernelTools::tra( 'This book has no ISBN to look up.' );
			return $summary;
		}
		$edition = null;
		foreach( $isbns as $isbn ) {
			usleep( 300000 );
			$found = $wiki::fetchOpenLibraryJson( '/isbn/'.rawurlencode( $isbn ).'.json' );
			if( str_starts_with( (string)( $found['key'] ?? '' ), '/books/' ) ) {
				$edition = $found;
				break;
			}
		}
		// No edition and the last request did not simply come back "not found" (HTTP 404): Open Library never answered, or refused,
		// so this is not "no such edition" and nothing about the book is known.
		if( !$edition && ( $fetchError = $wiki::getLastFetchError() ) && !str_contains( $fetchError, 'HTTP 404' ) ) {
			$summary['error'] = \Bitweaver\KernelTools::tra( 'Open Library did not answer, so nothing was changed' ).': '.$fetchError;
			return $summary;
		}
		if( $edition ) {
			$summary['matched'] = true;
			$has = fn( string $pItem ): bool => (bool)(int)$this->mDb->getOne(
				"SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = ? AND `end_date` IS NULL", [ $this->mContentId, $pItem ] );
			foreach( FisheyeBookCatalogue::fromOpenLibraryEdition( $edition ) as $item => $value ) {
				if( $has( $item ) ) {
					continue;
				}
				if( $item === 'publisher' ) {
					// A credit row, like the catalogue's own: the name in xkey_ext, marked as from Open Library.
					$this->reconcileXrefItem( 'publisher', [ [ 'key' => 'name:'.mb_strtolower( $value['name'] ), 'xorder' => 1, 'xkey_ext' => $value['name'], 'data' => [ 'source' => 'openlibrary' ] ] ],
						fn( array $pXrefRow ) => 'name:'.mb_strtolower( (string)$pXrefRow['xkey_ext'] ), true, true );
					$summary['items'][] = 'publisher: '.$value['name'];
				} else {
					$this->reconcileXrefItem( $item, [ $value + [ 'key' => '', 'xorder' => 0 ] ], null );
					$summary['items'][] = $item.': '.( $value['xkey'] ?? $value['xkey_ext'] );
				}
			}
		}

		// What the work record holds: the description, genres, and the work-level ids and links.
		foreach( $this->fillFromOpenLibraryWork( $edition ) as $line ) {
			$summary['items'][] = $line;
		}
		if( !$pCover ) {
			return $summary;
		}

		// A cover, for a book with no image yet. Open Library by the edition's own cover id first (not rate limited, unlike
		// the by-ISBN route), then Open Library by ISBN, then Google Books by ISBN (zoom=0 is the large one) - a second
		// source for when archive.org, which serves the Open Library covers, is not answering. covers.openlibrary.org goes
		// quiet now and then (the connection just hangs): the first Open Library cover to get no response at all marks
		// it down for a few minutes - here and on the next presses - so ten books do not each wait out several timeouts.
		// Google can answer an unknown ISBN with another book's cover, so it is the last resort and the row says it came from there.
		$hasImage = (bool)$this->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'image' AND `end_date` IS NULL", [ $this->mContentId ] );
		if( !$hasImage ) {
			$candidates = [];
			foreach( (array)( $edition['covers'] ?? [] ) as $coverId ) {
				if( (int)$coverId > 0 ) {
					$candidates[] = [ 'url' => 'https://covers.openlibrary.org/b/id/'.(int)$coverId.'-L.jpg?default=false', 'source' => 'openlibrary:'.$isbns[0], 'ol' => true ];
					break;
				}
			}
			foreach( $isbns as $isbn ) {
				$candidates[] = [ 'url' => 'https://covers.openlibrary.org/b/isbn/'.$isbn.'-L.jpg?default=false', 'source' => 'openlibrary:'.$isbn, 'ol' => true ];
			}
			foreach( $isbns as $isbn ) {
				$candidates[] = [ 'url' => '', 'isbn' => $isbn, 'source' => 'googlebooks:'.$isbn, 'ol' => false ];
			}
			$olDown = self::$coversDown || ( function_exists( 'apcu_fetch' ) && apcu_fetch( 'fm_ol_covers_down' ) );
			$olSkipped = false;
			foreach( $candidates as $candidate ) {
				if( $candidate['ol'] && $olDown ) {
					$olSkipped = true;
					continue;
				}
				usleep( 300000 );
				$bytes = $candidate['ol'] ? $wiki::fetchOpenLibraryImage( $candidate['url'] ) : self::fetchGoogleCover( $wiki, $candidate['isbn'] );
				if( $bytes === null ) {
					if( $candidate['ol'] && str_contains( (string)$wiki::getLastFetchError(), 'no response' ) ) {
						$olDown = self::$coversDown = true;
						$olSkipped = true;
						if( function_exists( 'apcu_store' ) ) {
							apcu_store( 'fm_ol_covers_down', 1, 300 );
						}
					}
					continue;
				}
				$tmp = tempnam( sys_get_temp_dir(), 'olcover_' );
				file_put_contents( $tmp, $bytes );
				if( $this->storeArtworkXref( $tmp, 'openlibrary-cover', $candidate['source'], [ 'types' => [ 'Front' ] ] ) ) {
					$summary['items'][] = 'image: '.( $candidate['ol'] ? 'Open Library' : 'Google Books' ).' cover'.( $this->attachThumbnail( $tmp ) ? ' (thumbnail)' : ' - no thumbnail: '.$this->mThumbnailError );
					$hasImage = true;
				}
				@unlink( $tmp );
				break;
			}
			if( !$hasImage && $olSkipped ) {
				$summary['items'][] = 'image: Open Library covers are not answering and Google Books had none - left for the Book Details page';
			}
		} elseif( !$this->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_attachments` WHERE `content_id` = ?", [ $this->mContentId ] ) ) {
			// The cover is stored but the tile's thumbnail never attached (a failed first attempt) - tried again from the
			// stored file, so a Reload fixes it rather than skipping because an image row exists.
			$stored = $this->mDb->getOne( "SELECT `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'image' AND `end_date` IS NULL ORDER BY `xorder`", [ $this->mContentId ] );
			$file = $stored ? $this->getExtraImagePath( '' ).$stored : '';
			if( $file !== '' && is_file( $file ) ) {
				$summary['items'][] = 'image: thumbnail'.( $this->attachThumbnail( $file ) ? ' attached' : ' not attached: '.$this->mThumbnailError );
			} else {
				$summary['items'][] = 'image: thumbnail not attached: the stored cover file is missing';
			}
		}
		return $summary;
	}

	/**
	 * A description as the HTML a contact's is: paragraphs in <p>...</p> (a blank line starts one, a single line break inside one is <br />),
	 * the text escaped. Text that already carries HTML tags is returned as it is, so it is safe to apply twice.
	 */
	public static function textToHtml( string $pText ): string {
		$text = trim( str_replace( [ "\r\n", "\r" ], "\n", $pText ) );
		if( $text === '' || preg_match( '#</?(p|br|div|ul|ol|li|h[1-6]|strong|em|b|i|a|span|table)\b#i', $text ) ) {
			return $text;
		}
		$out = [];
		foreach( preg_split( '/\n\s*\n/', $text ) as $paragraph ) {
			$paragraph = trim( $paragraph );
			if( $paragraph !== '' ) {
				$out[] = '<p>'.nl2br( htmlspecialchars( $paragraph, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' ), true ).'</p>';
			}
		}
		return implode( "\n", $out );
	}

	/**
	 * Open Library's text as plain paragraphs: line endings normalised, the "----------" footer it appends to a description
	 * (and its "([source][1])" references) dropped.
	 */
	public static function cleanOpenLibraryText( string $pText ): string {
		$text = str_replace( [ "\r\n", "\r" ], "\n", $pText );
		$text = preg_split( '/\n\s*-{5,}\s*\n/', $text )[0];
		$text = preg_replace( [ '/\(\[source\]\[\d+\]\)/', '/^\s*\[\d+\]:\s*\S+.*$/m', '/\n{3,}/' ], [ '', '', "\n\n" ], $text );
		return trim( $text );
	}

	/** Genre spellings that mean the same thing => the one name used, so a listing by genre does not split one genre in two. Lower case keys. */
	const GENRE_ALIASES = [
		'sci-fi' => 'Science Fiction', 'scifi' => 'Science Fiction', 'sf' => 'Science Fiction', 'science-fiction' => 'Science Fiction', 'science fiction' => 'Science Fiction',
		'fantasy fiction' => 'Fantasy', 'epic' => 'Epic Fantasy', 'high fantasy' => 'Epic Fantasy', 'humor' => 'Humour', 'humorous' => 'Humour',
		'mysteries' => 'Mystery', 'detective and mystery' => 'Mystery', 'thrillers' => 'Thriller', 'suspense' => 'Thriller', 'historical' => 'Historical Fiction',
		'horror tales' => 'Horror', 'ghost' => 'Horror', 'biography & autobiography' => 'Biography', 'biographies' => 'Biography',
	];

	/** The name a genre is stored under: spelling variants folded together, otherwise as written (an all-lower-case name gets capitals). */
	public static function canonicalGenre( string $pName ): string {
		$name = trim( html_entity_decode( $pName, ENT_QUOTES | ENT_HTML5 ) );
		// A name already written with capitals ("Coming of Age") is left as it is; an all-lower-case one is capitalised.
		return self::GENRE_ALIASES[mb_strtolower( $name )] ?? ( $name === mb_strtolower( $name ) ? mb_convert_case( $name, MB_CASE_TITLE ) : $name );
	}

	/** Google's two-letter language codes for the languages the library holds => the name used (as Open Library's three-letter ones are). */
	const GOOGLE_LANGUAGES = [ 'en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish', 'it' => 'Italian', 'cy' => 'Welsh', 'la' => 'Latin', 'ga' => 'Irish' ];

	/**
	 * A Google Books volume record as the values a book takes - nothing is read from or written to the database. Description: Google's
	 * carries HTML (<p>, <br>, <b>), turned to plain paragraphs. Genres: its categories ("Fiction / Fantasy / Epic") split into parts, less
	 * the generic ones, folded through canonicalGenre().
	 *
	 * @return array{description:string,genres:list<string>,items:array<string,array{xkey?:string,xkey_ext?:string}>,publisher:string}
	 */
	public static function parseGoogleVolume( array $pVolume ): array {
		$info = (array)( $pVolume['volumeInfo'] ?? [] );
		$ret = [ 'description' => '', 'genres' => [], 'items' => [], 'publisher' => trim( (string)( $info['publisher'] ?? '' ) ) ];
		if( !empty( $info['description'] ) ) {
			$text = preg_replace( [ '#<\s*br\s*/?>#i', '#</\s*p\s*>#i' ], "\n\n", (string)$info['description'] );
			$text = self::cleanOpenLibraryText( html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5 ) );
			if( mb_strlen( $text ) >= 30 && !preg_match( '/^\W*(duplicate|dupe|see )/i', $text ) ) {
				$ret['description'] = $text;
			}
		}
		$genres = [];
		foreach( (array)( $info['categories'] ?? [] ) as $category ) {
			foreach( preg_split( '#\s*/\s*#', (string)$category ) as $part ) {
				if( !in_array( mb_strtolower( trim( $part ) ), [ '', 'fiction', 'general', 'juvenile fiction', 'young adult fiction', 'literary collections' ], true ) ) {
					$genres[mb_strtolower( self::canonicalGenre( $part ) )] = self::canonicalGenre( $part );
				}
			}
		}
		$ret['genres'] = array_slice( array_values( $genres ), 0, 6 );
		if( (int)( $info['pageCount'] ?? 0 ) > 0 ) {
			$ret['items']['pages'] = [ 'xkey_ext' => (string)(int)$info['pageCount'] ];
		}
		if( isset( self::GOOGLE_LANGUAGES[strtolower( (string)( $info['language'] ?? '' ) )] ) ) {
			$ret['items']['language'] = [ 'xkey_ext' => self::GOOGLE_LANGUAGES[strtolower( (string)$info['language'] )] ];
		}
		if( preg_match( '/^\d{4}(-\d{2}(-\d{2})?)?$/', (string)( $info['publishedDate'] ?? '' ) ) ) {
			$ret['items']['published'] = [ 'xkey_ext' => (string)$info['publishedDate'] ];
		}
		if( preg_match( '/^[A-Za-z0-9_-]{4,20}$/', (string)( $pVolume['id'] ?? '' ) ) ) {
			$ret['items']['google_books'] = [ 'xkey' => (string)$pVolume['id'] ];
		}
		return $ret;
	}

	/**
	 * What Google Books holds about this book, by ISBN, for what is still blank - after Open Library has had its turn: the description,
	 * genres (from Google's categories, "Fiction / Fantasy / Epic" - every part but "Fiction" and "General"), pages, language, publication
	 * date, publisher and Google's own id. Needs the Google Books API key (Media Library Settings): without one Google answers every
	 * request with "quota exceeded". Nothing already there is replaced.
	 *
	 * @return array{matched:bool,items:list<string>,error:string,quota:bool}  quota: Google refused for the day, or is failing - stop asking
	 */
	public function fillFromGoogleBooks(): array {
		global $gBitSystem;
		$ret = [ 'matched' => false, 'items' => [], 'error' => '', 'quota' => false ];
		$key = trim( (string)$gBitSystem->getConfig( 'fisheyemedia_google_books_api_key' ) );
		if( $key === '' ) {
			$ret['error'] = \Bitweaver\KernelTools::tra( 'No Google Books API key is set (Media Library Settings).' );
			return $ret;
		}
		$isbnRow = $this->mDb->getRow( "SELECT `xkey`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'isbn' AND `end_date` IS NULL", [ $this->mContentId ] );
		$isbns = array_values( array_filter( [ trim( (string)( $isbnRow['xkey'] ?? '' ) ), trim( (string)( $isbnRow['xkey_ext'] ?? '' ) ) ] ) );
		// A book with no ISBN may hold a Google Books volume id instead (a classic found by title): that volume is read as it is.
		$volumeId = trim( (string)$this->mDb->getOne( "SELECT `xkey` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'google_books' AND `end_date` IS NULL", [ $this->mContentId ] ) );
		if( !$isbns && $volumeId === '' ) {
			$ret['error'] = \Bitweaver\KernelTools::tra( 'This book has no ISBN to look up.' );
			return $ret;
		}
		$context = stream_context_create( [ 'http' => [ 'timeout' => 15, 'ignore_errors' => true, 'header' => "Accept: application/json\r\nUser-Agent: bitweaver-fisheyemedia\r\n" ] ] );
		$volume = null;
		// One request: [ status, body ]. Google's refusals (quota, a key restricted to other addresses or APIs, a key not valid) stop the run with
		// Google's own wording; its own failures (5xx) stop it too - the next request would fail the same way.
		$get = function( string $pUrl ) use ( $context ): array {
			usleep( 200000 );
			$body = @file_get_contents( $pUrl, false, $context );
			$status = 0;
			foreach( (array)( function_exists( 'http_get_last_response_headers' ) ? http_get_last_response_headers() : ( $http_response_header ?? [] ) ) as $header ) {
				if( preg_match( '#^HTTP/\S+\s+(\d{3})#', (string)$header, $m ) ) {
					$status = (int)$m[1];
				}
			}
			return [ $status, $body === false ? '' : $body ];
		};
		$refusal = function( int $pStatus, string $pBody ) use ( &$ret ): bool {
			if( $pStatus === 429 || $pStatus === 403 || $pStatus === 400 ) {
				$why = (string)( json_decode( $pBody, true )['error']['message'] ?? '' );
				$ret['quota'] = true;
				$ret['error'] = 'Google Books refused the request (HTTP '.$pStatus.')'.( $why !== '' ? ': '.preg_replace( '/\s+/', ' ', mb_substr( $why, 0, 300 ) ) : ' - the key is out of quota for today, or not allowed for the Books API' );
				return true;
			}
			if( $pStatus >= 500 ) {
				$ret['quota'] = true;
				$ret['error'] = 'Google Books is not answering (HTTP '.$pStatus.') - try again later';
				return true;
			}
			return false;
		};
		$ownIsbn = fn( array $pVolume ): bool => (bool)array_intersect( $isbns, array_column( (array)( $pVolume['volumeInfo']['industryIdentifiers'] ?? [] ), 'identifier' ) );
		if( !$isbns ) {
			[ $status, $body ] = $get( 'https://www.googleapis.com/books/v1/volumes/'.rawurlencode( $volumeId ).'?'.http_build_query( [ 'key' => $key ] ) );
			if( $refusal( $status, $body ) ) {
				return $ret;
			}
			$candidate = $status === 200 ? json_decode( $body, true ) : null;
			$volume = is_array( $candidate ) && !empty( $candidate['volumeInfo'] ) ? $candidate : null;
		}
		foreach( $isbns as $isbn ) {
			// The ISBN's volume id from Google's older view-API lookup (it has no quota, and its search sibling has had long spells of 503),
			// then the one volume by id with the key. Only the book itself is taken: its own ISBN among the identifiers Google lists.
			[ $status, $body ] = $get( 'https://books.google.com/books?bibkeys=ISBN:'.rawurlencode( $isbn ).'&jscmd=viewapi&callback=f' );
			if( $status === 200 && preg_match( '#books\.google\.com/books\?id=([A-Za-z0-9_-]{4,20})#', $body, $m ) ) {
				[ $status, $body ] = $get( 'https://www.googleapis.com/books/v1/volumes/'.$m[1].'?'.http_build_query( [ 'key' => $key ] ) );
				if( $refusal( $status, $body ) ) {
					return $ret;
				}
				$candidate = $status === 200 ? json_decode( $body, true ) : null;
				if( is_array( $candidate ) && $ownIsbn( $candidate ) ) {
					$volume = $candidate;
					break;
				}
			}
			// Not found that way: the search, as before. It is the part of Google's API that has had spells of 503 and answers 429 from the
			// shared anonymous quota, so any failure of it only means "not found here" - it never stops the run (the keyed volume fetch above does).
			[ $status, $body ] = $get( 'https://www.googleapis.com/books/v1/volumes?'.http_build_query( [ 'q' => 'isbn:'.$isbn, 'maxResults' => 1, 'key' => $key ] ) );
			if( $status !== 200 ) {
				continue;
			}
			$candidate = $status === 200 ? ( json_decode( $body, true )['items'][0] ?? null ) : null;
			if( is_array( $candidate ) && $ownIsbn( $candidate ) ) {
				$volume = $candidate;
				break;
			}
		}
		if( !$volume ) {
			return $ret;
		}
		$ret['matched'] = true;
		$parsed = self::parseGoogleVolume( $volume );
		$has = fn( string $pItem ): bool => (bool)(int)$this->mDb->getOne(
			"SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = ? AND `end_date` IS NULL", [ $this->mContentId, $pItem ] );

		if( $parsed['description'] !== '' && trim( (string)$this->mDb->getOne( "SELECT `data` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ?", [ $this->mContentId ] ) ) === '' ) {
			$storeHash = [ 'content_id' => $this->mContentId, 'title' => $this->getTitle(), 'edit' => self::textToHtml( $parsed['description'] ) ];
			if( $this->store( $storeHash ) ) {
				$ret['items'][] = 'description: '.mb_substr( $parsed['description'], 0, 60 ).( mb_strlen( $parsed['description'] ) > 60 ? '...' : '' );
			}
		}
		if( $parsed['genres'] && !$has( 'genre' ) ) {
			$wanted = [];
			foreach( $parsed['genres'] as $genre ) {
				$wanted[] = [ 'key' => mb_strtolower( $genre ), 'xorder' => count( $wanted ) + 1, 'xkey_ext' => $genre ];
			}
			$this->reconcileXrefItem( 'genre', $wanted, fn( array $pXrefRow ) => mb_strtolower( (string)$pXrefRow['xkey_ext'] ) );
			$ret['items'][] = 'genre: '.implode( ', ', $parsed['genres'] );
		}
		foreach( $parsed['items'] as $item => $value ) {
			if( !$has( $item ) ) {
				$this->reconcileXrefItem( $item, [ $value + [ 'key' => '', 'xorder' => 0, 'data' => [ 'source' => 'googlebooks' ] ] ], null );
				$ret['items'][] = $item.': '.( $value['xkey'] ?? $value['xkey_ext'] );
			}
		}
		if( $parsed['publisher'] !== '' && !$has( 'publisher' ) ) {
			$this->reconcileXrefItem( 'publisher', [ [ 'key' => 'name:'.mb_strtolower( $parsed['publisher'] ), 'xorder' => 1, 'xkey_ext' => $parsed['publisher'], 'data' => [ 'source' => 'googlebooks' ] ] ],
				fn( array $pXrefRow ) => 'name:'.mb_strtolower( (string)$pXrefRow['xkey_ext'] ), true, true );
			$ret['items'][] = 'publisher: '.$parsed['publisher'];
		}
		return $ret;
	}

	/** Whether the book names its Wikidata work - a classic, loaded and filled from Wikidata, not from an ISBN. */
	public function isClassic(): bool {
		return (bool)(int)$this->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'wikidata_work' AND `end_date` IS NULL", [ $this->mContentId ] );
	}

	/**
	 * Fill a newly loaded book from where its identifiers point: Wikidata for a classic (its work id, no ISBN involved), Open Library by ISBN for the rest.
	 *
	 * @return array{matched:bool,items:list<string>,error?:string}
	 */
	public function fillDetails(): array {
		if( $this->isClassic() ) {
			return $this->fillFromWikidata();
		}
		$stored = fn( string $pItem ): string => trim( (string)$this->mDb->getOne( "SELECT `xkey` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = ? AND `end_date` IS NULL", [ $this->mContentId, $pItem ] ) );
		if( !(int)$this->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'isbn' AND `end_date` IS NULL", [ $this->mContentId ] ) ) {
			// No ISBN: a Gutenberg ebook may be a Wikidata work's (then the book is a classic after all); a Google Books volume is read as it is.
			if( ( $ebook = $stored( 'gutenberg' ) ) !== '' && ( $qid = FisheyeBookClassics::wikidataForGutenberg( $ebook ) ) !== '' ) {
				$this->setWorkIds( [ 'wikidata_work' => $qid ] );
				return $this->fillFromWikidata();
			}
			if( $stored( 'google_books' ) !== '' ) {
				return $this->fillFromGoogleBooks();
			}
		}
		return $this->reloadFromOpenLibrary();
	}

	/**
	 * Give the book the work identifiers it has none of (the catalogue's Wikidata / Gutenberg / OL work cells, for a book loaded before the cell was
	 * filled): a value already stored, entered by hand or found by an earlier lookup, is never replaced.
	 *
	 * @param array<string,string> $pIds  item (wikidata_work, gutenberg, ol_work) => value
	 * @return list<string>  the items stored
	 */
	public function setWorkIds( array $pIds ): array {
		$set = [];
		foreach( array_intersect_key( $pIds, array_flip( [ 'wikidata_work', 'gutenberg', 'ol_work' ] ) ) as $item => $value ) {
			if( $value !== '' && !(int)$this->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = ? AND `end_date` IS NULL", [ $this->mContentId, $item ] ) ) {
				$this->reconcileXrefItem( $item, [ [ 'key' => '', 'xorder' => 0, 'xkey' => $value ] ], null );
				$set[] = $item;
			}
		}
		return $set;
	}

	/**
	 * What a Wikidata work item says that a book keeps: the original publication date (the earliest P577 that is not deprecated, as a year, month
	 * or day according to its precision - the first printing of a serial is the date Wikidata gives for a Dickens novel, the book may be later),
	 * the Project Gutenberg ebook id (P2034) and the Open Library work id (P648). Blank for whatever the item does not hold. Reads nothing but $pEntity.
	 *
	 * @param array $pEntity  a Wikidata entity (ContactWikiIndividual::fetchWikidataEntity())
	 * @return array{published:string,gutenberg:string,ol_work:string}
	 */
	public static function wikidataWorkFacts( array $pEntity ): array {
		$values = function( string $pProperty ) use ( $pEntity ): array {
			$out = [];
			foreach( (array)( $pEntity['claims'][$pProperty] ?? [] ) as $statement ) {
				if( ( $statement['rank'] ?? '' ) !== 'deprecated' && isset( $statement['mainsnak']['datavalue']['value'] ) ) {
					$out[] = $statement['mainsnak']['datavalue']['value'];
				}
			}
			return $out;
		};
		$published = '';
		$earliest = '';
		foreach( $values( 'P577' ) as $time ) {
			if( is_array( $time ) && preg_match( '/^\+(\d{4})-(\d\d)-(\d\d)/', (string)( $time['time'] ?? '' ), $m ) && ( $earliest === '' || $m[1].$m[2].$m[3] < $earliest ) ) {
				$earliest = $m[1].$m[2].$m[3];
				$precision = (int)( $time['precision'] ?? 9 );
				$published = $precision >= 11 && $m[3] !== '00' ? $m[1].'-'.$m[2].'-'.$m[3] : ( $precision >= 10 && $m[2] !== '00' ? $m[1].'-'.$m[2] : $m[1] );
			}
		}
		$gutenberg = (string)( $values( 'P2034' )[0] ?? '' );
		$workId = (string)( $values( 'P648' )[0] ?? '' );
		return [ 'published' => $published, 'gutenberg' => preg_match( '/^\d+$/', $gutenberg ) ? $gutenberg : '', 'ol_work' => preg_match( '/^OL\d+W$/', $workId ) ? $workId : '' ];
	}

	/**
	 * Wikidata first, for a book that names its Wikidata work (a classic, older than its ISBNs - the catalogue's Wikidata column, or the book's
	 * own `wikidata_work` id). The item is read once and gives the work's original publication date, its Project Gutenberg id and its Open
	 * Library work id. Each fills only a blank, so an id given in the catalogue or entered by hand is kept (a wrong Open Library work is
	 * overridden by naming the right one); the date replaces a stored one only when $pReplaceDate says the original date wins. With a work id
	 * in hand the Open Library work record then adds the description, genres and links (fillFromOpenLibraryWork()). $pLook reports all of this
	 * and stores nothing.
	 *
	 * @return array{matched:bool,items:list<string>,error:string}
	 */
	public function fillFromWikidata( bool $pLook = false, bool $pReplaceDate = false ): array {
		$summary = [ 'matched' => false, 'items' => [], 'error' => '' ];
		$wiki = '\\Bitweaver\\Contactwiki\\ContactWikiIndividual';
		if( !class_exists( $wiki ) ) {
			$summary['error'] = \Bitweaver\KernelTools::tra( 'The contactwiki package is needed to ask Wikidata.' );
			return $summary;
		}
		$stored = fn( string $pItem ): string => trim( (string)$this->mDb->getOne(
			"SELECT COALESCE( `xkey`, `xkey_ext` ) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = ? AND `end_date` IS NULL ORDER BY `xorder`", [ $this->mContentId, $pItem ] ) );
		$qid = strtoupper( $stored( 'wikidata_work' ) );
		if( !preg_match( '/^Q\d+$/', $qid ) ) {
			$summary['error'] = \Bitweaver\KernelTools::tra( 'This book has no Wikidata work id.' );
			return $summary;
		}
		usleep( 300000 );
		$entity = $wiki::fetchWikidataEntity( $qid );
		if( !is_array( $entity ) ) {
			$summary['error'] = \Bitweaver\KernelTools::tra( 'Wikidata did not answer, so nothing was changed' ).( ( $e = $wiki::getLastFetchError() ) ? ': '.$e : '' );
			return $summary;
		}
		$summary['matched'] = true;
		$facts = self::wikidataWorkFacts( $entity );
		$setId = function( string $pItem, string $pValue ) use ( $stored, $pLook, &$summary ) {
			if( $pValue !== '' && $stored( $pItem ) === '' ) {
				$summary['items'][] = $pItem.': '.$pValue;
				if( !$pLook ) {
					$this->reconcileXrefItem( $pItem, [ [ 'key' => '', 'xorder' => 0, 'xkey' => $pValue ] ], null );
				}
			}
		};

		$original = $facts['published'];
		$was = $stored( 'published' );
		if( $original !== '' ) {
			if( $was === '' || ( $pReplaceDate && $was !== $original ) ) {
				$summary['items'][] = 'published: '.$original.( $was !== '' ? ' (was '.$was.')' : '' );
				if( !$pLook ) {
					$this->setPublished( $original );
				}
			} elseif( substr( $was, 0, 4 ) !== substr( $original, 0, 4 ) ) {
				$summary['items'][] = 'published: Wikidata gives '.$original.', the book has '.$was.' (kept)';
			}
		}
		$setId( 'gutenberg', $facts['gutenberg'] );
		$workId = $facts['ol_work'];
		$setId( 'ol_work', $workId );

		// The descriptive text is Open Library's, from the work.
		if( !$pLook ) {
			array_push( $summary['items'], ...$this->fillFromOpenLibraryWork() );
		} elseif( ( $workId = $stored( 'ol_work' ) ?: $workId ) !== '' ) {
			usleep( 300000 );
			$work = $wiki::fetchOpenLibraryJson( '/works/'.$workId.'.json' );
			$text = fn( $pValue ): string => is_array( $pValue ) ? (string)( $pValue['value'] ?? '' ) : (string)$pValue;
			$description = is_array( $work ) ? self::cleanOpenLibraryText( $text( $work['description'] ?? '' ) ) : '';
			$summary['items'][] = !is_array( $work ) ? 'Open Library did not give work '.$workId
				: ( mb_strlen( $description ) < 30 ? 'Open Library work '.$workId.' has no description' : 'description from Open Library work '.$workId.' ('.mb_strlen( $description ).' characters, '.count( (array)( $work['subjects'] ?? [] ) ).' subjects)' );
		}
		return $summary;
	}

	/** Words in an Open Library subject that name a genre (lower case) => the genre it is filed as. Used when the work has no genre tags. */
	const SUBJECT_GENRES = [
		'science fiction' => 'Science Fiction', 'fantasy' => 'Fantasy', 'horror' => 'Horror', 'ghost' => 'Horror', 'detective' => 'Mystery', 'mystery' => 'Mystery',
		'thriller' => 'Thriller', 'suspense' => 'Thriller', 'historical fiction' => 'Historical Fiction', 'humor' => 'Humour', 'humour' => 'Humour',
		'satire' => 'Humour', 'romance' => 'Romance', 'western' => 'Western', 'dystopia' => 'Dystopian', 'adventure' => 'Adventure', 'war stories' => 'War',
		'short stories' => 'Short Stories', 'biograph' => 'Biography', 'autobiograph' => 'Biography', 'travel' => 'Travel',
	];

	/**
	 * What the book's Open Library work record adds to the edition's, each only where the book has nothing yet (nothing entered by hand or
	 * from the catalogue is replaced): the description (the edition seldom has one), genres (Open Library's own genre tags, else genre words
	 * in its subjects), the Wikidata work id, a Wikipedia link, and any service id the edition did not carry. One request for the work -
	 * from the edition just read, or the book's stored work id.
	 *
	 * @param ?array $pEdition  the edition record when the caller has just read it
	 * @return list<string>  a line for each thing stored
	 */
	public function fillFromOpenLibraryWork( ?array $pEdition = null ): array {
		$done = [];
		$wiki = '\Bitweaver\Contactwiki\ContactWikiIndividual';
		if( !class_exists( $wiki ) ) {
			return $done;
		}
		$workKey = (string)( $pEdition['works'][0]['key'] ?? '' );
		if( $workKey === '' && ( $stored = (string)$this->mDb->getOne( "SELECT `xkey` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'ol_work' AND `end_date` IS NULL", [ $this->mContentId ] ) ) !== '' ) {
			$workKey = '/works/'.$stored;
		}
		if( $workKey === '' ) {
			return $done;
		}
		usleep( 300000 );
		$work = $wiki::fetchOpenLibraryJson( $workKey.'.json' );
		if( !is_array( $work ) ) {
			return $done;
		}
		$has = fn( string $pItem ): bool => (bool)(int)$this->mDb->getOne(
			"SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = ? AND `end_date` IS NULL", [ $this->mContentId, $pItem ] );
		$text = fn( $pValue ): string => is_array( $pValue ) ? (string)( $pValue['value'] ?? '' ) : (string)$pValue;

		// The description: the edition's if it has one, else the work's.
		if( trim( (string)$this->mDb->getOne( "SELECT `data` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ?", [ $this->mContentId ] ) ) === '' ) {
			$description = self::cleanOpenLibraryText( $text( $pEdition['description'] ?? '' ) ) ?: self::cleanOpenLibraryText( $text( $work['description'] ?? '' ) );
			// Open Library marks a work it holds twice with a description of just "(Duplicate)" - and some are a bare "See ..." or a few
			// words: not a description of the book, so nothing is stored.
			if( mb_strlen( $description ) < 30 || preg_match( '/^\W*(duplicate|dupe|see )/i', $description ) ) {
				$description = '';
			}
			$storeHash = [ 'content_id' => $this->mContentId, 'title' => $this->getTitle(), 'edit' => self::textToHtml( $description ) ];
			if( $description !== '' && $this->store( $storeHash ) ) {
				$done[] = 'description: '.mb_substr( $description, 0, 60 ).( mb_strlen( $description ) > 60 ? '...' : '' );
			}
		}

		// Genres.
		if( !$has( 'genre' ) ) {
			$genres = [];
			foreach( (array)( $work['genres'] ?? [] ) as $tag ) {
				if( preg_match( '#^/tags/(OL\d+T)$#', (string)$tag ) ) {
					$name = self::openLibraryTagName( (string)$tag );
					if( $name !== '' ) {
						$genres[mb_strtolower( self::canonicalGenre( $name ) )] = self::canonicalGenre( $name );
					}
				}
			}
			if( !$genres ) {
				foreach( (array)( $work['subjects'] ?? [] ) as $subject ) {
					foreach( self::SUBJECT_GENRES as $word => $genre ) {
						if( str_contains( mb_strtolower( (string)$subject ), $word ) ) {
							$genres[mb_strtolower( $genre )] = $genre;
						}
					}
				}
			}
			$wanted = [];
			foreach( array_slice( array_values( $genres ), 0, 6 ) as $genre ) {
				$wanted[] = [ 'key' => mb_strtolower( $genre ), 'xorder' => count( $wanted ) + 1, 'xkey_ext' => $genre ];
			}
			if( $wanted ) {
				$this->reconcileXrefItem( 'genre', $wanted, fn( array $pXrefRow ) => mb_strtolower( (string)$pXrefRow['xkey_ext'] ) );
				$done[] = 'genre: '.implode( ', ', array_column( $wanted, 'xkey_ext' ) );
			}
		}

		// Ids and links that belong to the work.
		$ids = (array)( $work['identifiers'] ?? [] );
		$found = [];
		if( preg_match( '/^Q\d+$/', strtoupper( (string)( $ids['wikidata'][0] ?? '' ) ) ) ) {
			$found['wikidata_work'] = strtoupper( (string)$ids['wikidata'][0] );
		}
		foreach( [ 'goodreads', 'librarything' ] as $service ) {
			if( preg_match( '/^\d{1,12}$/', (string)( $ids[$service][0] ?? '' ) ) ) {
				$found[$service] = (string)$ids[$service][0];
			}
		}
		foreach( (array)( $work['links'] ?? [] ) as $link ) {
			if( preg_match( '#^https?://en\.wikipedia\.org/wiki/([^\s?\#]{1,200})$#', (string)( $link['url'] ?? '' ), $m ) ) {
				$found['wikipedia'] = $m[1];
				break;
			}
		}
		foreach( $found as $item => $id ) {
			if( !$has( $item ) ) {
				$this->reconcileXrefItem( $item, [ [ 'key' => '', 'xorder' => 0, 'xkey' => $id, 'data' => [ 'source' => 'openlibrary' ] ] ], null );
				$done[] = $item.': '.$id;
			}
		}
		return $done;
	}

	/**
	 * The name of an Open Library tag (/tags/OL169T -> "Fantasy"), remembered for a day - a handful of tags cover every book.
	 */
	private static function openLibraryTagName( string $pTagKey ): string {
		static $names = [];
		if( isset( $names[$pTagKey] ) ) {
			return $names[$pTagKey];
		}
		$cacheKey = 'fm_ol_tag_'.basename( $pTagKey );
		if( function_exists( 'apcu_fetch' ) && ( $cached = apcu_fetch( $cacheKey ) ) !== false ) {
			return $names[$pTagKey] = (string)$cached;
		}
		usleep( 300000 );
		$tag = '\Bitweaver\Contactwiki\ContactWikiIndividual'::fetchOpenLibraryJson( $pTagKey.'.json' );
		$name = ( ( $tag['tag_type'] ?? '' ) === 'genres' ) ? trim( (string)( $tag['name'] ?? '' ) ) : '';
		if( function_exists( 'apcu_store' ) && is_array( $tag ) ) {
			apcu_store( $cacheKey, $name, 86400 );
		}
		return $names[$pTagKey] = $name;
	}


	/** The Extras button counts the book's files (ebooks, audio books, other) along with its pictures. */
	public function getExtrasCounts(): array {
		$counts = parent::getExtrasCounts();
		$counts['extras'] += (int)$this->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` IN ('ebook','audiobook','extra') AND `end_date` IS NULL", [ $this->mContentId ] );
		return $counts;
	}

	/**
	 * The book's files lead the plain Extras strip: an ebook is simply another item on it, shown with the book's cover and a label saying what it
	 * is, and opens (downloads) through view_book_file.php; an audio book plays in the browser. Not a tab of its own.
	 */
	public function getExtrasGroups(): array {
		$extras = parent::getExtrasGroups();
		$rows = $this->mDb->getAll( "SELECT `xref_id`, `item`, `xkey`, `xkey_ext`, `xref`, `data` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` IN ('ebook','audiobook','extra') AND `end_date` IS NULL ORDER BY `item`, `xorder`", [ $this->mContentId ] ) ?: [];
		// An Audio folder not yet made into an album: editors get Load Audio Book on the strip.
		$loadLinks = [];
		if( $this->hasUpdatePermission() && array_filter( FisheyeBookFiles::audioFolders( $this ), fn( $f ) => !$f['linked'] ) ) {
			$loadLinks[] = [ 'title' => 'Load Audio Book', 'url' => FISHEYEMEDIA_PKG_URL.'load_audio_book.php?content_id='.$this->mContentId, 'icon' => 'audio-x-generic' ];
		}
		if( !$rows && !$loadLinks ) {
			return $extras;
		}
		$cover = (string)$this->getThumbnailUrl( 'small' );
		$files = [];
		foreach( $rows as $row ) {
			if( $row['item'] === 'audiobook' && $row['xref'] ) {
				// an audio book that is an album: one entry, the album's own page
				$album = new FisheyeAlbum( null, (int)$row['xref'] );
				$album->load();
				if( $album->isValid() ) {
					$files[] = [ 'thumb' => (string)( $album->getThumbnailUrl( 'small' ) ?: $cover ), 'url' => $album->getDisplayUrl(), 'title' => $album->getTitle(), 'label' => trim( (string)$row['xkey'] ) !== '' ? $row['xkey'] : 'Audio book' ];
					continue;
				}
			}
			$data = json_decode( (string)$row['data'], true ) ?: [];
			$format = strtoupper( (string)( $data['format'] ?? pathinfo( (string)$row['xkey_ext'], PATHINFO_EXTENSION ) ) );
			$label = $row['item'] === 'audiobook' ? 'Audio book '.$format.( !empty( $data['tracks'] ) && $data['tracks'] > 1 ? ' ('.(int)$data['tracks'].' parts)' : '' ) : ( $row['item'] === 'ebook' ? 'Ebook '.$format : $format );
			if( !empty( $data['edition'] ) ) {
				$label .= ' - '.$data['edition'];
			}
			$files[] = [ 'thumb' => $cover, 'url' => FISHEYEMEDIA_PKG_URL.'view_book_file.php?xref_id='.(int)$row['xref_id'], 'title' => basename( rtrim( (string)$row['xkey_ext'], '/' ) ), 'label' => $label ];
		}
		if( $extras['groups'] ) {
			$extras['groups'][0]['items'] = array_merge( $files, $extras['groups'][0]['items'] );
			$extras['groups'][0]['links'] = array_merge( $extras['groups'][0]['links'] ?? [], $loadLinks );
		} else {
			$extras['groups'][] = [ 'id' => 'pictures', 'kind' => 'Extras', 'items' => $files, 'links' => $loadLinks ];
		}
		return $extras;
	}
}

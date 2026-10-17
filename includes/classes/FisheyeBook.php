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
 *   - isbn            one row: xkey = ISBN-13, xkey_ext = ISBN-10, data {entered, note}
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
	 * otherwise it is left unusable. 'entered' keeps the original text whenever the stored ISBN is
	 * not simply that text.
	 *
	 * @return array{isbn13:string,isbn10:string,entered:string,url:string,flags:list<string>}
	 */
	public static function normaliseIsbn( string $pRaw ): array {
		$ret = [ 'isbn13' => '', 'isbn10' => '', 'entered' => '', 'url' => '', 'flags' => [] ];
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
			$ret['entered'] = $raw;
			$ret['flags'][] = 'isbn-repaired';
		} else {
			$ret['entered'] = $raw;
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
	 * Register one book from a plain row (the shape of the clean catalogue sheet), link it into a
	 * gallery if given, and write its xrefs. Idempotent: a book already registered (see
	 * findContentId()) is reported, not duplicated.
	 *
	 * Row keys, all optional but 'title': isbn13, isbn10, isbn_entered, isbn_note; title; description;
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
		if( $existing = self::findContentId( $pRow ) ) {
			return [ 'already' => $existing ];
		}

		$book = new FisheyeBook();
		$storeHash = [ 'title' => $title ];
		if( !empty( $pRow['description'] ) ) {
			$storeHash['edit'] = $pRow['description'];
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

		// ISBN: one row, the ISBN-13 as the key (xkey), the ISBN-10 beside it (xkey_ext), what was
		// originally entered and any remark in data.
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
			$data = array_filter( [ 'entered' => (string)( $pRow['isbn_entered'] ?? '' ), 'note' => (string)( $pRow['isbn_note'] ?? '' ) ] );
			if( $data ) {
				$wanted['data'] = $data;
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
					$wanted[] = [ 'key' => 'name:'.mb_strtolower( $name ), 'xorder' => count( $wanted ) + 1, 'xkey_ext' => $name, 'data' => [ 'source' => 'import' ] ];
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
	 * Make one of the book's stored images its thumbnail (the Images tab's Set as Thumbnail) -
	 * regenerates thumbs/ from the chosen file, as FisheyeAlbum::promoteImageToThumbnail() does.
	 */
	public function promoteImageToThumbnail( string $pRelativePath ): bool {
		$branchPath = $this->getImageStorageBranchPath();
		if( basename( $pRelativePath ) !== $pRelativePath || !is_file( $branchPath.$pRelativePath ) ) {
			return false;
		}
		foreach( glob( $branchPath.'thumbs/*' ) ?: [] as $oldThumb ) {
			@unlink( $oldThumb );
		}
		$fileHash = [ 'type' => 'image/jpeg', 'source_file' => $branchPath.$pRelativePath, 'dest_branch' => \Bitweaver\Liberty\liberty_mime_get_storage_branch( [ 'attachment_id' => $this->mContentId ] ) ];
		$ok = \Bitweaver\Liberty\liberty_generate_thumbnails( $fileHash );
		$this->load();
		return $ok;
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
	public function reloadFromOpenLibrary(): array {
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

		// A cover - by ISBN, then by the edition's own cover id. Only for a book with no image yet.
		if( !$this->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'image' AND `end_date` IS NULL", [ $this->mContentId ] ) ) {
			$urls = array_map( fn( $isbn ) => 'https://covers.openlibrary.org/b/isbn/'.$isbn.'-L.jpg?default=false', $isbns );
			foreach( (array)( $edition['covers'] ?? [] ) as $coverId ) {
				if( (int)$coverId > 0 ) {
					$urls[] = 'https://covers.openlibrary.org/b/id/'.(int)$coverId.'-L.jpg?default=false';
					break;
				}
			}
			foreach( $urls as $url ) {
				usleep( 300000 );
				$bytes = $wiki::fetchOpenLibraryImage( $url );
				if( $bytes === null ) {
					continue;
				}
				$tmp = tempnam( sys_get_temp_dir(), 'olcover_' );
				file_put_contents( $tmp, $bytes );
				if( $this->storeArtworkXref( $tmp, 'openlibrary-cover', 'openlibrary:'.$isbns[0], [ 'types' => [ 'Front' ] ] ) ) {
					$summary['items'][] = 'image: Open Library cover'.( $this->attachThumbnail( $tmp ) ? ' (thumbnail)' : '' );
				}
				@unlink( $tmp );
				break;
			}
		}
		return $summary;
	}

}

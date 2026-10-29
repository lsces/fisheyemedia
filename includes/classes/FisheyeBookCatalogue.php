<?php
/**
 * Reader for a book catalogue spreadsheet (OpenDocument .ods) - turns the 'Books' sheet into plain
 * rows in the shape FisheyeBook::registerFromRow() takes. Each uploaded sheet is archived under the package's
 * import path (storage/fisheyemedia/books/, timestamped, as health and stock archive theirs) and the
 * newest archived sheet is the catalogue a loader page works through a batch at a time.
 *
 * The sheet is flat, one book per row, with these header names (any order, extra columns ignored):
 *   Ref, Collection, Category, ISBN-13, ISBN-10, Authors (' | ' between people),
 *   Author role, Title, Year, Published (an ISO date, blank when only a year is known), Date precision,
 *   Copies, Paid GBP, Source URL, Notes (';' between notes), Flags.
 * Only Title is required.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

class FisheyeBookCatalogue {
	/** An author needs at least this many books in the catalogue to get a gallery of their own. */
	public const MIN_AUTHOR_BOOKS = 2;


	const NS_TABLE  = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
	const NS_TEXT   = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
	const SHEET     = 'Books';

	// Header text => row key.
	const COLUMNS = [
		'Ref' => 'ref', 'Collection' => 'collection', 'Category' => 'category', 'ISBN-13' => 'isbn13',
		'ISBN-10' => 'isbn10', 'Authors' => 'authors',
		'Author role' => 'author_role', 'Publisher' => 'publisher', 'Title' => 'title', 'Year' => 'year', 'Published' => 'published_iso',
		'Date precision' => 'precision', 'Copies' => 'copies', 'Paid GBP' => 'paid',
		'Source URL' => 'source_url', 'Notes' => 'notes', 'Flags' => 'flags',
	];

	/**
	 * The folder uploaded sheets are archived in.
	 */
	public static function archiveDir(): string {
		return FISHEYEMEDIA_IMPORT_PATH.'books/';
	}

	/**
	 * The newest archived sheet, if any has been uploaded yet.
	 */
	public static function latestArchive(): ?string {
		$files = array_merge( glob( self::archiveDir().'*.ods' ) ?: [], glob( self::archiveDir().'*.csv' ) ?: [] );
		sort( $files, SORT_NATURAL );
		return $files ? end( $files ) : null;
	}

	/**
	 * Check an uploaded .ods or .csv reads as a catalogue and, if it does, archive it as the newest sheet.
	 *
	 * @return array{rows:int,error:?string,file:?string}
	 */
	public static function importFile( string $pPath ): array {
		$parsed = self::readBooks( $pPath );
		if( isset( $parsed['error'] ) ) {
			return [ 'rows' => 0, 'error' => $parsed['error'], 'file' => null ];
		}
		\Bitweaver\KernelTools::mkdir_p( self::archiveDir() );
		$dest = self::archiveDir().'Books Catalogue '.date( 'Ymd-His' ).( self::isOds( $pPath ) ? '.ods' : '.csv' );
		$ok = is_uploaded_file( $pPath ) ? move_uploaded_file( $pPath, $dest ) : copy( $pPath, $dest );
		return $ok
			? [ 'rows' => count( $parsed['rows'] ), 'error' => null, 'file' => basename( $dest ) ]
			: [ 'rows' => 0, 'error' => 'The spreadsheet could not be archived under storage.', 'file' => null ];
	}

	/**
	 * Split the catalogue rows into the groups they are filed under - 'a|<author>' galleries for
	 * authors with enough books, 'c|<category>' galleries for the rest.
	 *
	 * @return array<string,array{kind:string,name:string,rows:list<array>}>
	 */
	public static function plan( array $pRows ): array {
		$perAuthor = [];
		foreach( $pRows as $row ) {
			$author = FisheyeBookCredits::canonicalName( FisheyeBookCatalogue::primaryAuthor( $row ) );
			if( $author !== '' ) {
				$perAuthor[mb_strtolower( $author )] = ( $perAuthor[mb_strtolower( $author )] ?? 0 ) + 1;
			}
		}
		$groups = [];
		foreach( $pRows as $row ) {
			$author = FisheyeBookCredits::canonicalName( FisheyeBookCatalogue::primaryAuthor( $row ) );
			if( $author !== '' && $perAuthor[mb_strtolower( $author )] >= self::MIN_AUTHOR_BOOKS ) {
				$key = 'a|'.mb_strtolower( $author );
				$groups[$key] ??= [ 'kind' => 'author', 'name' => $author, 'rows' => [] ];
			} else {
				$category = $row['category'][0] ?? '';
				$name = $category !== '' ? $category : \Bitweaver\KernelTools::tra( 'Uncategorised' );
				$key = 'c|'.mb_strtolower( $name );
				$groups[$key] ??= [ 'kind' => 'category', 'name' => $name, 'rows' => [] ];
			}
			$groups[$key]['rows'][] = $row;
		}
		// Authors before categories, the biggest first so the main authors come up top.
		uasort( $groups, fn( $a, $b ) => [ $a['kind'], -count( $a['rows'] ), mb_strtolower( $a['name'] ) ] <=> [ $b['kind'], -count( $b['rows'] ), mb_strtolower( $b['name'] ) ] );
		return $groups;
	}

	/**
	 * Handle a catalogue upload posted by either loader page ('catalogue_file'), or null when none was sent.
	 *
	 * @return array{rows:int,error:?string,file:?string}|null
	 */
	public static function importUploaded(): ?array {
		if( empty( $_FILES['catalogue_file']['tmp_name'] ) ) {
			return null;
		}
		if( !is_uploaded_file( $_FILES['catalogue_file']['tmp_name'] ) || !in_array( strtolower( pathinfo( (string)$_FILES['catalogue_file']['name'], PATHINFO_EXTENSION ) ), [ 'ods', 'csv' ], true ) ) {
			return [ 'rows' => 0, 'error' => \Bitweaver\KernelTools::tra( 'Please choose an .ods spreadsheet or a .csv file.' ), 'file' => null ];
		}
		return self::importFile( $_FILES['catalogue_file']['tmp_name'] );
	}

	/**
	 * The newest archived sheet's rows, or none when nothing has been uploaded yet.
	 *
	 * @return array{imported:int,file:?string,rows:list<array>}
	 */
	public static function cached(): array {
		$file = self::latestArchive();
		$parsed = $file ? self::readBooks( $file ) : [];
		return [ 'imported' => $file ? (int)filemtime( $file ) : 0, 'file' => $file ? basename( $file ) : null, 'rows' => $parsed['rows'] ?? [] ];
	}

	/** An OpenDocument spreadsheet is a zip file; anything else is read as a csv. Judged from the content, since an upload has a temp name. */
	public static function isOds( string $pPath ): bool {
		$fh = @fopen( $pPath, 'rb' );
		$magic = $fh ? fread( $fh, 2 ) : '';
		if( $fh ) {
			fclose( $fh );
		}
		return $magic === 'PK';
	}

	/**
	 * Read the catalogue into rows for FisheyeBook::registerFromRow(): the 'Books' sheet of an .ods, or a plain .csv (comma, semicolon or tab separated,
	 * header row first, columns in any order and extra ones ignored - the same columns as the sheet).
	 *
	 * @return array{rows:list<array>}|array{error:string}
	 */
	public static function readBooks( string $pPath ): array {
		if( !self::isOds( $pPath ) ) {
			$cellRows = self::csvCellRows( $pPath );
			return $cellRows === null ? [ 'error' => 'That file could not be read as an .ods spreadsheet or a .csv file.' ] : self::rowsFromCells( $cellRows );
		}
		$zip = new \ZipArchive();
		if( $zip->open( $pPath ) !== true || ( $xml = $zip->getFromName( 'content.xml' ) ) === false ) {
			return [ 'error' => 'That is not an OpenDocument spreadsheet (.ods).' ];
		}
		$zip->close();
		$doc = new \DOMDocument();
		if( !@$doc->loadXML( $xml, LIBXML_NONET ) ) {
			return [ 'error' => 'The spreadsheet could not be read.' ];
		}

		$sheet = null;
		foreach( $doc->getElementsByTagNameNS( self::NS_TABLE, 'table' ) as $table ) {
			if( $table->getAttributeNS( self::NS_TABLE, 'name' ) === self::SHEET ) {
				$sheet = $table;
				break;
			}
		}
		if( !$sheet ) {
			return [ 'error' => 'There is no sheet named "'.self::SHEET.'" in that spreadsheet.' ];
		}

		$cellRows = [];
		foreach( $sheet->getElementsByTagNameNS( self::NS_TABLE, 'table-row' ) as $tableRow ) {
			$cells = [];
			foreach( $tableRow->childNodes as $cell ) {
				if( !( $cell instanceof \DOMElement ) || !in_array( $cell->localName, [ 'table-cell', 'covered-table-cell' ], true ) ) {
					continue;
				}
				$text = [];
				foreach( $cell->getElementsByTagNameNS( self::NS_TEXT, 'p' ) as $p ) {
					$text[] = $p->textContent;
				}
				$repeat = min( max( 1, (int)$cell->getAttributeNS( self::NS_TABLE, 'number-columns-repeated' ) ), 40 );
				for( $i = 0; $i < $repeat; $i++ ) {
					$cells[] = trim( implode( "\n", $text ) );
				}
			}
			$cellRows[] = $cells;
		}
		return self::rowsFromCells( $cellRows );
	}

	/** A csv file as rows of trimmed cells, or null when it cannot be read. The separator is taken from the header line; a leading BOM is dropped. */
	private static function csvCellRows( string $pPath ): ?array {
		$fh = @fopen( $pPath, 'rb' );
		if( !$fh ) {
			return null;
		}
		$first = (string)fgets( $fh );
		rewind( $fh );
		$first = preg_replace( '/^\xEF\xBB\xBF/', '', $first );
		$delimiter = ',';
		foreach( [ ';' => substr_count( $first, ';' ), "\t" => substr_count( $first, "\t" ) ] as $candidate => $count ) {
			if( $count > substr_count( $first, $delimiter ) ) {
				$delimiter = $candidate;
			}
		}
		$rows = [];
		while( ( $line = fgetcsv( $fh, 0, $delimiter, '"', '' ) ) !== false ) {
			if( $rows === [] && isset( $line[0] ) ) {
				$line[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string)$line[0] );
			}
			$rows[] = array_map( fn( $c ) => trim( (string)$c ), $line );
		}
		fclose( $fh );
		return $rows ?: null;
	}

	/** Rows of cells (the first with any content is the header) to catalogue rows. */
	private static function rowsFromCells( array $pCellRows ): array {
		$header = null;
		$rows = [];
		$seen = [];
		foreach( $pCellRows as $cells ) {
			if( !array_filter( $cells, fn( $c ) => $c !== '' ) ) {
				continue;
			}
			if( $header === null ) {
				$header = [];
				foreach( $cells as $i => $name ) {
					if( isset( self::COLUMNS[$name] ) ) {
						$header[$i] = self::COLUMNS[$name];
					}
				}
				if( !in_array( 'title', $header, true ) ) {
					return [ 'error' => 'The catalogue has no Title column.' ];
				}
				continue;
			}
			$raw = [];
			foreach( $header as $i => $key ) {
				$raw[$key] = $cells[$i] ?? '';
			}
			if( ( $raw['title'] ?? '' ) !== '' ) {
				$row = self::toRegisterRow( $raw );
				// The same edition listed under two collections is one book: keep the first row.
				$key = $row['isbn13'] !== '' ? $row['isbn13'] : mb_strtolower( $row['title'].'|'.( $row['authors'][0] ?? '' ) );
				if( !isset( $seen[$key] ) ) {
					$seen[$key] = true;
					$rows[] = $row;
				}
			}
		}
		return [ 'rows' => $rows ];
	}

	/**
	 * One sheet row (by key) to the shape FisheyeBook::registerFromRow() takes.
	 */
	public static function toRegisterRow( array $pRaw ): array {
		$authors = array_values( array_filter( array_map( 'trim', explode( '|', (string)( $pRaw['authors'] ?? '' ) ) ) ) );

		// Published as known: a full date when the sheet has one, otherwise just the year.
		$published = trim( (string)( $pRaw['published_iso'] ?? '' ) );
		if( $published === '' && ( $pRaw['year'] ?? '' ) !== '' ) {
			$published = (string)(int)(float)$pRaw['year'];
		}

		$notes = array_values( array_filter( array_map( 'trim', explode( ';', (string)( $pRaw['notes'] ?? '' ) ) ) ) );
		$external = [];
		$url = trim( (string)( $pRaw['source_url'] ?? '' ) );
		if( $url !== '' ) {
			if( preg_match( '#gutenberg\.org/ebooks/(\d+)#i', $url, $m ) ) {
				$external['gutenberg'] = $m[1];
			} else {
				$notes[] = $url;
			}
		}

		$copies = (int)( $pRaw['copies'] ?? 1 );

		return [
			'ref'          => (string)( $pRaw['ref'] ?? '' ),
			'title'        => trim( (string)$pRaw['title'] ),
			'authors'      => $authors,
			'author_role'  => trim( (string)( $pRaw['author_role'] ?? '' ) ),
			'publisher'    => trim( (string)( $pRaw['publisher'] ?? '' ) ),
			'isbn13'       => trim( (string)( $pRaw['isbn13'] ?? '' ) ),
			'isbn10'       => trim( (string)( $pRaw['isbn10'] ?? '' ) ),
			'published'    => $published,
			'category'     => trim( (string)( $pRaw['category'] ?? '' ) ) !== '' ? [ trim( $pRaw['category'] ) ] : [],
			'copies'       => $copies > 1 ? (string)$copies : '',
			'paid'         => trim( (string)( $pRaw['paid'] ?? '' ) ),
			'note'         => $notes,
			'external'     => $external,
		];
	}

	/**
	 * A row's primary author - the first one named, the person whose gallery it is filed under.
	 */
	public static function primaryAuthor( array $pRow ): string {
		return (string)( $pRow['authors'][0] ?? '' );
	}

	// Open Library's three-letter language codes for the languages the library holds.
	const OPENLIBRARY_LANGUAGES = [ 'eng' => 'English', 'fre' => 'French', 'ger' => 'German', 'spa' => 'Spanish', 'ita' => 'Italian', 'wel' => 'Welsh', 'lat' => 'Latin', 'gle' => 'Irish' ];

	/**
	 * What an Open Library edition record says, as the values a book's xref items take - nothing is
	 * written here. Items: ol_edition and ol_work (ids, in 'xkey'), pages, format, published and language
	 * (text, in 'xkey_ext'), and publisher (a name).
	 *
	 * @param array $pEdition  /books/OL...M.json
	 * @return array<string,array{xkey?:string,xkey_ext?:string,name?:string}>  item => value
	 */
	public static function fromOpenLibraryEdition( array $pEdition ): array {
		$ret = [];
		if( !empty( $pEdition['key'] ) ) {
			$ret['ol_edition'] = [ 'xkey' => basename( (string)$pEdition['key'] ) ];
		}
		if( !empty( $pEdition['works'][0]['key'] ) ) {
			$ret['ol_work'] = [ 'xkey' => basename( (string)$pEdition['works'][0]['key'] ) ];
		}
		if( (int)( $pEdition['number_of_pages'] ?? 0 ) > 0 ) {
			$ret['pages'] = [ 'xkey_ext' => (string)(int)$pEdition['number_of_pages'] ];
		}
		foreach( [ 'format' => 'physical_format', 'published' => 'publish_date' ] as $item => $field ) {
			if( trim( (string)( $pEdition[$field] ?? '' ) ) !== '' ) {
				$ret[$item] = [ 'xkey_ext' => trim( (string)$pEdition[$field] ) ];
			}
		}
		// "Dragonriders of Pern (5)" - the series text as Open Library holds it, split later by FisheyeSeries::parseSeriesText().
		if( trim( (string)( $pEdition['series'][0] ?? '' ) ) !== '' ) {
			$ret['series'] = [ 'xkey_ext' => trim( (string)$pEdition['series'][0] ) ];
		}
		$code = basename( (string)( $pEdition['languages'][0]['key'] ?? '' ) );
		if( $code !== '' ) {
			$ret['language'] = [ 'xkey_ext' => self::OPENLIBRARY_LANGUAGES[$code] ?? $code ];
		}
		if( trim( (string)( $pEdition['publishers'][0] ?? '' ) ) !== '' ) {
			$ret['publisher'] = [ 'name' => trim( (string)$pEdition['publishers'][0] ) ];
		}
		// "New Ed edition", "Second printing" ...
		if( trim( (string)( $pEdition['edition_name'] ?? '' ) ) !== '' ) {
			$ret['edition'] = [ 'xkey_ext' => trim( (string)$pEdition['edition_name'] ) ];
		}
		// Ids on other services, each shown as a link by its scheme item (cross_ref_href): Google Books, Goodreads, LibraryThing,
		// WorldCat (OCLC number) and the Internet Archive's scan of the book.
		$ids = (array)( $pEdition['identifiers'] ?? [] );
		foreach( [ 'google_books' => $ids['google'][0] ?? '', 'goodreads' => $ids['goodreads'][0] ?? '', 'librarything' => $ids['librarything'][0] ?? '',
			'oclc' => $pEdition['oclc_numbers'][0] ?? '', 'internet_archive' => $pEdition['ocaid'] ?? '' ] as $item => $id ) {
			// Goodreads, LibraryThing and WorldCat ids are numbers - Open Library holds the odd one that is not (an edition id typed into the wrong box).
			$pattern = in_array( $item, [ 'goodreads', 'librarything', 'oclc' ], true ) ? '/^\d{1,12}$/' : '/^[A-Za-z0-9_.-]{1,32}$/';
			if( preg_match( $pattern, trim( (string)$id ) ) ) {
				$ret[$item] = [ 'xkey' => trim( (string)$id ) ];
			}
		}
		return $ret;
	}

	/**
	 * A book row (the shape FisheyeBook::registerFromRow() takes) from an ISBN alone, out of Open Library - nothing is
	 * written. The authors come from the edition, else from its work.
	 *
	 * @return array{isbn:string,row?:array,error?:string}  'isbn' is the ISBN-13 (or the text as typed when it is not one)
	 */
	public static function rowFromIsbn( string $pRaw, string $pCategory = '' ): array {
		$isbn = FisheyeBook::normaliseIsbn( $pRaw );
		if( $isbn['isbn13'] === '' ) {
			return [ 'isbn' => trim( $pRaw ), 'error' => \Bitweaver\KernelTools::tra( 'Not a valid ISBN' ) ];
		}
		$ret = [ 'isbn' => $isbn['isbn13'] ];
		$wiki = '\\Bitweaver\\Contactwiki\\ContactWikiIndividual';
		if( !class_exists( $wiki ) ) {
			return $ret + [ 'error' => \Bitweaver\KernelTools::tra( 'The contactwiki package is needed to ask Open Library.' ) ];
		}
		$edition = null;
		foreach( array_filter( [ $isbn['isbn13'], $isbn['isbn10'] ] ) as $try ) {
			usleep( 300000 );
			$found = $wiki::fetchOpenLibraryJson( '/isbn/'.rawurlencode( $try ).'.json' );
			if( str_starts_with( (string)( $found['key'] ?? '' ), '/books/' ) ) {
				$edition = $found;
				break;
			}
		}
		if( !$edition ) {
			$why = (string)$wiki::getLastFetchError();
			return $ret + [ 'error' => $why !== '' && !str_contains( $why, 'HTTP 404' ) ? \Bitweaver\KernelTools::tra( 'Open Library did not answer' ).': '.$why : \Bitweaver\KernelTools::tra( 'Open Library has no edition with this ISBN' ) ];
		}
		$authorKeys = array_column( (array)( $edition['authors'] ?? [] ), 'key' );
		if( !$authorKeys && !empty( $edition['works'][0]['key'] ) ) {
			usleep( 300000 );
			$work = $wiki::fetchOpenLibraryJson( $edition['works'][0]['key'].'.json' );
			foreach( (array)( $work['authors'] ?? [] ) as $one ) {
				if( !empty( $one['author']['key'] ) ) {
					$authorKeys[] = $one['author']['key'];
				}
			}
		}
		$authors = [];
		$authorIds = [];
		foreach( $authorKeys as $key ) {
			usleep( 300000 );
			$author = $wiki::fetchOpenLibraryAuthor( basename( (string)$key ) );
			if( !empty( $author['name'] ) ) {
				$name = trim( (string)$author['name'] );
				$authors[] = $name;
				// Who the author is, as Open Library knows them - kept on the credit so the authors pass need not find them again.
				$authorIds[$name] = [ 'olid' => basename( (string)$author['key'] ), 'qid' => strtoupper( trim( (string)( $author['remote_ids']['wikidata'] ?? '' ) ) ) ];
			}
		}
		$title = trim( (string)( $edition['title'] ?? '' ) );
		if( $title === '' || !$authors ) {
			return $ret + [ 'error' => $title === '' ? \Bitweaver\KernelTools::tra( 'Open Library gives no title' ) : \Bitweaver\KernelTools::tra( 'Open Library names no author' ), 'row' => [ 'title' => $title, 'authors' => $authors ] ];
		}
		preg_match( '/\b(\d{4})\b/', (string)( $edition['publish_date'] ?? '' ), $year );
		$ret['row'] = [
			'ref' => '', 'title' => $title, 'authors' => $authors, 'author_role' => 'author',
			'publisher' => trim( (string)( $edition['publishers'][0] ?? '' ) ),
			'isbn13' => $isbn['isbn13'], 'isbn10' => $isbn['isbn10'], 'published' => $year[1] ?? '',
			'category' => $pCategory !== '' ? [ $pCategory ] : [], 'copies' => '', 'paid' => '', 'note' => [], 'external' => [],
			'author_ids' => $authorIds,
		];
		return $ret;
	}


	/**
	 * The first few Open Library matches for a book with no ISBN, to choose from: a search by title and author (English-language works), each
	 * result with one valid ISBN-13 from its editions. Read-only and on request only (one search per call), so a list of books costs nothing until
	 * a row asks.
	 *
	 * @return list<array{title:string,authors:string,year:string,isbn:string}>
	 */
	public static function suggestEditions( string $pTitle, string $pAuthor, int $pLimit = 3 ): array {
		$wiki = '\\Bitweaver\\Contactwiki\\ContactWikiIndividual';
		if( !class_exists( $wiki ) || trim( $pTitle ) === '' ) {
			return [];
		}
		$query = [ 'title' => trim( $pTitle ), 'language' => 'eng', 'limit' => 12, 'fields' => 'key,title,author_name,first_publish_year,isbn' ];
		if( trim( $pAuthor ) !== '' ) {
			$query['author'] = trim( $pAuthor );
		}
		$data = $wiki::fetchOpenLibraryJson( '/search.json?'.http_build_query( $query, '', '&' ) );
		$out = [];
		foreach( $data['docs'] ?? [] as $doc ) {
			$isbn = '';
			foreach( (array)( $doc['isbn'] ?? [] ) as $candidate ) {
				$checked = FisheyeBook::normaliseIsbn( (string)$candidate );
				if( $checked['isbn13'] !== '' ) {
					$isbn = $checked['isbn13'];
					break;
				}
			}
			if( $isbn === '' ) {
				continue;
			}
			$out[] = [ 'title' => (string)( $doc['title'] ?? '' ), 'authors' => implode( ', ', array_slice( array_values( array_unique( (array)( $doc['author_name'] ?? [] ) ) ), 0, 2 ) ),
				'year' => isset( $doc['first_publish_year'] ) ? (string)$doc['first_publish_year'] : '', 'isbn' => $isbn ];
			if( count( $out ) >= $pLimit ) {
				break;
			}
		}
		return $out;
	}
}

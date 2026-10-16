<?php
/**
 * Reader for a book catalogue spreadsheet (OpenDocument .ods) - turns the 'Books' sheet into plain
 * rows in the shape FisheyeBook::registerFromRow() takes. Each uploaded sheet is archived under the package's
 * import path (storage/fisheyemedia/books/, timestamped, as health and stock archive theirs) and the
 * newest archived sheet is the catalogue a loader page works through a batch at a time.
 *
 * The sheet is flat, one book per row, with these header names (any order, extra columns ignored):
 *   Ref, Collection, Category, ISBN-13, ISBN-10, ISBN as entered, Authors (' | ' between people),
 *   Author role, Title, Year, Published (an ISO date, blank when only a year is known), Date precision,
 *   Copies, Paid GBP, Current price GBP, Source URL, Notes (';' between notes), Flags.
 * Only Title is required.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

class FisheyeBookCatalogue {

	const NS_TABLE  = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
	const NS_TEXT   = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
	const SHEET     = 'Books';

	// Header text => row key.
	const COLUMNS = [
		'Ref' => 'ref', 'Collection' => 'collection', 'Category' => 'category', 'ISBN-13' => 'isbn13',
		'ISBN-10' => 'isbn10', 'ISBN as entered' => 'isbn_entered', 'Authors' => 'authors',
		'Author role' => 'author_role', 'Title' => 'title', 'Year' => 'year', 'Published' => 'published_iso',
		'Date precision' => 'precision', 'Copies' => 'copies', 'Paid GBP' => 'paid',
		'Current price GBP' => 'current_price', 'Source URL' => 'source_url', 'Notes' => 'notes', 'Flags' => 'flags',
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
		$files = glob( self::archiveDir().'*.ods' ) ?: [];
		sort( $files, SORT_NATURAL );
		return $files ? end( $files ) : null;
	}

	/**
	 * Check an uploaded .ods reads as a catalogue and, if it does, archive it as the newest sheet.
	 *
	 * @return array{rows:int,error:?string,file:?string}
	 */
	public static function importFile( string $pPath ): array {
		$parsed = self::readBooks( $pPath );
		if( isset( $parsed['error'] ) ) {
			return [ 'rows' => 0, 'error' => $parsed['error'], 'file' => null ];
		}
		\Bitweaver\KernelTools::mkdir_p( self::archiveDir() );
		$dest = self::archiveDir().'Books Catalogue '.date( 'Ymd-His' ).'.ods';
		$ok = is_uploaded_file( $pPath ) ? move_uploaded_file( $pPath, $dest ) : copy( $pPath, $dest );
		return $ok
			? [ 'rows' => count( $parsed['rows'] ), 'error' => null, 'file' => basename( $dest ) ]
			: [ 'rows' => 0, 'error' => 'The spreadsheet could not be archived under storage.', 'file' => null ];
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

	/**
	 * Read the 'Books' sheet of an .ods into rows for FisheyeBook::registerFromRow().
	 *
	 * @return array{rows:list<array>}|array{error:string}
	 */
	public static function readBooks( string $pPath ): array {
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

		$header = null;
		$rows = [];
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
					return [ 'error' => 'The "'.self::SHEET.'" sheet has no Title column.' ];
				}
				continue;
			}
			$raw = [];
			foreach( $header as $i => $key ) {
				$raw[$key] = $cells[$i] ?? '';
			}
			if( ( $raw['title'] ?? '' ) !== '' ) {
				$rows[] = self::toRegisterRow( $raw );
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
			'isbn13'       => trim( (string)( $pRaw['isbn13'] ?? '' ) ),
			'isbn10'       => trim( (string)( $pRaw['isbn10'] ?? '' ) ),
			'isbn_entered' => trim( (string)( $pRaw['isbn_entered'] ?? '' ) ),
			'isbn_note'    => '',
			'published'    => $published,
			'category'     => trim( (string)( $pRaw['category'] ?? '' ) ) !== '' ? [ trim( $pRaw['category'] ) ] : [],
			'copies'       => $copies > 1 ? (string)$copies : '',
			'paid'         => trim( (string)( $pRaw['paid'] ?? '' ) ),
			'current_price' => trim( (string)( $pRaw['current_price'] ?? '' ) ),
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
}

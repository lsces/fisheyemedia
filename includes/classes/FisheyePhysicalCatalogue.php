<?php
/**
 * Reader for a physical-media catalogue spreadsheet (OpenDocument .ods) - the 'Physical' sheet, one row
 * per physical copy of something on the shelves (an LP, a single, a CD, a DVD set), the same flat shape
 * the books catalogue uses. Turns it into plain rows for FisheyePhysical, which matches them to the
 * catalogue and stores the 'physical' xref item. Each uploaded sheet is archived under the package's import
 * path (timestamped, as the books catalogue is) and the newest archived one is what the loader works through.
 *
 * Header names (any order, extra columns ignored):
 *   Kind (album / film / tv - blank means album), Format (LP, 7" single, CD, DVD ...), Owner, Location (where
 *   it is kept - Bookcase, Loft ...), Box (the box or shelf code within it), Artist (the show or franchise for
 *   a film or TV row), Title, Discs, Note, Ref (a catalogue number), Publisher, Year, Source.
 * Only Title is required. Pure - nothing here touches the database.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

class FisheyePhysicalCatalogue {

	const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
	const NS_TEXT  = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
	const SHEET    = 'Physical';

	// Header text => row key.
	const COLUMNS = [
		'Kind' => 'kind', 'Format' => 'format', 'Owner' => 'owner', 'Location' => 'location', 'Box' => 'box',
		'Artist' => 'artist', 'Title' => 'title', 'Discs' => 'discs', 'Note' => 'note', 'Ref' => 'ref',
		'Publisher' => 'publisher', 'Year' => 'year', 'Source' => 'source',
	];

	const KINDS = [ 'album', 'film', 'tv' ];

	public static function archiveDir(): string {
		return FISHEYEMEDIA_IMPORT_PATH.'physical/';
	}

	/**
	 * What a row is, whatever its position in the sheet - so a decision made about it (set aside, linked by
	 * hand) still holds when the sheet is edited and uploaded again.
	 */
	public static function signature( array $pRow ): string {
		return substr( sha1( mb_strtolower( implode( '|', [ $pRow['kind'], $pRow['format'], $pRow['owner'], $pRow['box'], $pRow['artist'], $pRow['title'] ] ) ) ), 0, 16 );
	}

	/**
	 * Decisions kept between visits to the loader page: rows set aside ('skipped': signature => true) and
	 * rows linked by hand ('linked': signature => content_id). A small file beside the archived sheets.
	 *
	 * @return array{skipped:array<string,bool>,linked:array<string,int>}
	 */
	public static function loadState(): array {
		$file = self::archiveDir().'state.json';
		$state = is_file( $file ) ? json_decode( (string)file_get_contents( $file ), true ) : null;
		return [ 'skipped' => (array)( $state['skipped'] ?? [] ), 'linked' => (array)( $state['linked'] ?? [] ) ];
	}

	public static function saveState( array $pState ): bool {
		\Bitweaver\KernelTools::mkdir_p( self::archiveDir() );
		return file_put_contents( self::archiveDir().'state.json', json_encode( $pState, JSON_PRETTY_PRINT ) ) !== false;
	}

	/**
	 * The newest archived sheet, if one has been uploaded yet.
	 */
	public static function latestArchive(): ?string {
		$files = glob( self::archiveDir().'*.ods' ) ?: [];
		sort( $files, SORT_NATURAL );
		return $files ? end( $files ) : null;
	}

	/**
	 * Check an uploaded .ods reads as a physical catalogue and, if it does, archive it as the newest one.
	 *
	 * @return array{rows:int,error:?string,file:?string}
	 */
	public static function importFile( string $pPath ): array {
		$parsed = self::read( $pPath );
		if( isset( $parsed['error'] ) ) {
			return [ 'rows' => 0, 'error' => $parsed['error'], 'file' => null ];
		}
		\Bitweaver\KernelTools::mkdir_p( self::archiveDir() );
		$dest = self::archiveDir().'Physical Catalogue '.date( 'Ymd-His' ).'.ods';
		$ok = is_uploaded_file( $pPath ) ? move_uploaded_file( $pPath, $dest ) : copy( $pPath, $dest );
		return $ok
			? [ 'rows' => count( $parsed['rows'] ), 'error' => null, 'file' => basename( $dest ) ]
			: [ 'rows' => 0, 'error' => 'The spreadsheet could not be archived under storage.', 'file' => null ];
	}

	/**
	 * The newest archived sheet's rows, or none when nothing has been uploaded yet.
	 *
	 * @return array{file:?string,rows:list<array>}
	 */
	public static function cached(): array {
		$file = self::latestArchive();
		$parsed = $file ? self::read( $file ) : [];
		return [ 'file' => $file ? basename( $file ) : null, 'rows' => $parsed['rows'] ?? [] ];
	}

	/**
	 * Read the 'Physical' sheet of an .ods.
	 *
	 * Each row: key ('r' and the sheet row number), kind, format, owner, location, box, artist, title, discs
	 * (int), note, ref, publisher, year, source.
	 *
	 * @return array{rows:list<array>}|array{error:string}
	 */
	public static function read( string $pPath ): array {
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
		$n = 0;
		foreach( $sheet->getElementsByTagNameNS( self::NS_TABLE, 'table-row' ) as $tableRow ) {
			$n++;
			$cells = [];
			foreach( $tableRow->childNodes as $cell ) {
				if( !( $cell instanceof \DOMElement ) || !in_array( $cell->localName, [ 'table-cell', 'covered-table-cell' ], true ) ) {
					continue;
				}
				$text = [];
				foreach( $cell->getElementsByTagNameNS( self::NS_TEXT, 'p' ) as $p ) {
					$text[] = $p->textContent;
				}
				$repeat = min( max( 1, (int)$cell->getAttributeNS( self::NS_TABLE, 'number-columns-repeated' ) ), 20 );
				for( $i = 0; $i < $repeat; $i++ ) {
					$cells[] = trim( implode( ' ', $text ) );
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
			$row = array_fill_keys( self::COLUMNS, '' );
			foreach( $header as $i => $key ) {
				$row[$key] = $cells[$i] ?? '';
			}
			if( $row['title'] === '' ) {
				continue;
			}
			$kind = strtolower( $row['kind'] );
			$row['kind'] = in_array( $kind, self::KINDS, true ) ? $kind : 'album';
			$row['discs'] = (int)$row['discs'];
			$row['key'] = 'r'.$n;
			$rows[] = $row;
		}
		return [ 'rows' => $rows ];
	}
}

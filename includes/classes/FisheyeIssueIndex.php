<?php
/**
 * Applies a title's index sheet to its Magazine Issues. A magazine's folder may hold an IssueIndex.csv beside its issues (the way a book catalogue sits beside the books),
 * one row per issue with the columns Issue, Volume, Published, Publisher, Price, Frequency and "Title as printed" - the facts a file name cannot give, kept by whoever indexed the
 * run (who published it when, what it cost, how often it came out, what it was called on the cover). An issue is matched to its row by its issue number (read from its file
 * name, FisheyeIssueDetails::fromPath()). Only what an issue lacks is added, so a value set by hand or read from the name stands, and the pass can be repeated. The file is
 * read once per title per pass; the mark is the attachment preference extdoc_index ('+n' rows added, '-' nothing to add, '?' the issue is not in the sheet).
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\Liberty\LibertyMime;

class FisheyeIssueIndex {

	/** Issues applied per press: the work is a few small rows each, no text is read. */
	public const BATCH = 200;

	private const PREF = 'extdoc_index';
	private const FILE = 'IssueIndex.csv';

	/** @var array<string,array<string,array<string,string>>> title => issue number => row, kept for the request */
	private static array $sheets = [];

	/** The titles of a kind that have an IssueIndex.csv, and how many issues each lists. @return array<string,int> */
	public static function sheets( string $pKind ): array {
		$root = FisheyeDocsWorkbench::rootFor( $pKind );
		$out = [];
		foreach( $root !== '' ? ( glob( $root.'*/'.self::FILE ) ?: [] ) : [] as $file ) {
			$title = basename( dirname( $file ) );
			$out[$title] = count( self::rows( $pKind, $title ) );
		}
		ksort( $out );
		return $out;
	}

	/** @return array<string,array<string,string>> the sheet's rows keyed by issue number (as printed, no leading zeros) */
	private static function rows( string $pKind, string $pTitle ): array {
		$key = $pKind.'|'.$pTitle;
		if( isset( self::$sheets[$key] ) ) {
			return self::$sheets[$key];
		}
		$rows = [];
		$file = FisheyeDocsWorkbench::rootFor( $pKind ).$pTitle.'/'.self::FILE;
		if( is_file( $file ) && ( $fh = fopen( $file, 'r' ) ) ) {
			$head = array_map( fn( $h ) => strtolower( trim( (string)preg_replace( '/^\xEF\xBB\xBF/', '', (string)$h ) ) ), fgetcsv( $fh, 0, ',', '"', '' ) ?: [] );
			$cols = [ 'issue' => 'issue', 'volume' => 'volume', 'published' => 'published', 'publisher' => 'publisher', 'price' => 'price', 'frequency' => 'frequency', 'title as printed' => 'printed_title' ];
			while( ( $line = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
				$row = [];
				foreach( $head as $i => $name ) {
					if( isset( $cols[$name] ) ) {
						$row[$cols[$name]] = trim( (string)( $line[$i] ?? '' ) );
					}
				}
				if( !empty( $row['issue'] ) ) {
					$rows[ltrim( $row['issue'], '0' )] = $row;
				}
			}
			fclose( $fh );
		}
		return self::$sheets[$key] = $rows;
	}

	/**
	 * The loaded issues of a kind whose title has a sheet, with whether each has been applied.
	 *
	 * @return list<array{attachment_id:int,content_id:int,path:string,mark:?string}>
	 */
	private static function issues( string $pKind ): array {
		$titles = array_keys( self::sheets( $pKind ) );
		return array_values( array_filter( FisheyeArchiveIsbn::books( $pKind, self::PREF, 'fisheyeissue' ), fn( $b ) => $b['path'] !== '' && in_array( explode( '/', $b['path'] )[0], $titles, true ) ) );
	}

	/**
	 * What the sheets cover: per title, the rows in the sheet, the loaded issues and how many are applied or still to do.
	 *
	 * @return array{titles:array<string,array{sheet:int,issues:int}>,loaded:int,read:int,filled:int,todo:int}
	 */
	public static function status( string $pKind ): array {
		$titles = [];
		foreach( self::sheets( $pKind ) as $title => $n ) {
			$titles[$title] = [ 'sheet' => $n, 'issues' => 0 ];
		}
		$issues = self::issues( $pKind );
		foreach( $issues as $b ) {
			$titles[explode( '/', $b['path'] )[0]]['issues']++;
		}
		$read = array_filter( $issues, fn( $b ) => $b['mark'] !== null );
		$filled = array_filter( $read, fn( $b ) => str_starts_with( (string)$b['mark'], '+' ) );
		return [ 'titles' => $titles, 'loaded' => count( $issues ), 'read' => count( $read ), 'filled' => count( $filled ), 'todo' => count( $issues ) - count( $read ) ];
	}

	/**
	 * Apply the sheets to the next issues not yet applied. A press does BATCH; with $pSeconds it keeps going for about that long.
	 *
	 * @return array{read:int,filled:int,none:int,missing:int,errors:list<string>}
	 */
	public static function apply( string $pKind, int $pSeconds = 0 ): array {
		$started = microtime( true );
		$out = [ 'read' => 0, 'filled' => 0, 'none' => 0, 'missing' => 0, 'errors' => [] ];
		$rows = array_values( array_filter( self::issues( $pKind ), fn( $b ) => $b['mark'] === null ) );
		foreach( $pSeconds > 0 ? $rows : array_slice( $rows, 0, self::BATCH ) as $row ) {
			if( $pSeconds > 0 && ( microtime( true ) - $started ) >= $pSeconds ) {
				break;
			}
			$mark = self::tagFile( $pKind, (int)$row['content_id'], (int)$row['attachment_id'], $row['path'] );
			if( $mark === null ) {
				$out['errors'][] = $row['path'].': the sheet could not be applied.';
				continue;
			}
			$out['read']++;
			match( $mark ) { '-' => $out['none']++, '?' => $out['missing']++, default => $out['filled']++ };
		}
		return $out;
	}

	/**
	 * Apply the sheet row for one issue and mark it done.
	 *
	 * @param string $pRel the issue's path under the archive root (Title/.../name.pdf)
	 * @return ?string '+n' rows added, '-' the sheet had nothing the issue lacks, '?' the issue is not in the sheet, null when the Issue could not be written
	 */
	public static function tagFile( string $pKind, int $pContentId, int $pAttachmentId, string $pRel ): ?string {
		$title = explode( '/', $pRel )[0];
		$sheet = self::rows( $pKind, $title );
		if( !$sheet ) {
			return null;
		}
		$number = FisheyeIssueDetails::fromPath( $pRel )['issue_number'];
		$row = $number !== '' ? ( $sheet[$number] ?? null ) : null;
		if( $row === null ) {
			LibertyMime::storeAttachmentPreference( $pAttachmentId, self::PREF, '?' );
			return '?';
		}
		$book = new FisheyeBook( null, $pContentId );   // not loaded: the rows go straight in
		if( !$book->isValid() || $book->mContentTypeGuid !== 'fisheyeissue' ) {
			return null;
		}
		$count = $book->addIssueIndexDetails( $row );
		if( $book->mErrors ) {
			return null;
		}
		$mark = $count ? '+'.$count : '-';
		LibertyMime::storeAttachmentPreference( $pAttachmentId, self::PREF, $mark );
		return $mark;
	}
}

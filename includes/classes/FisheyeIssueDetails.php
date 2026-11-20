<?php
/**
 * Reads what a magazine file's NAME (and the folders above it) says about the issue: its number, volume and date. A scan of a magazine carries nothing of this in
 * itself - its PDF information is the scanner's software and the date it was scanned - but the names are regular: "Title No3610 - 1979-06-01", "Title - Vol 29 No 01",
 * "Title 202508", "Title - March 2022", a bare "7908" in a 1979 folder. The rules are tried in order and the first that fits a part wins; nothing is guessed, and a part
 * no rule fits is left empty. Anything that is not an issue (an Extras folder, an index volume) is given a category instead and no issue number.
 *
 * Numbers are kept as printed without leading zeros ("No0049" is 49). The date is as much as the name gives: "1979-06-01", "2025-08" or "2015". A date range
 * ("March April 2024") is its first month.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\Liberty\LibertyMime;

class FisheyeIssueDetails {

	/** Issues read per press: the work is the file name, nothing is opened. */
	public const BATCH = 100;

	private const PREF = 'extdoc_issue';

	private const MONTHS = [ 'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12 ];
	private const MONTH_RE = '(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)';

	/**
	 * @param string $pRel the file's path under the archive root: Title/.../name.pdf
	 * @return array{issue_number:string,volume:string,published:string,category:string}  empty strings where the name says nothing
	 */
	public static function fromPath( string $pRel ): array {
		$out = [ 'issue_number' => '', 'volume' => '', 'published' => '', 'category' => '' ];
		$parts = array_values( array_filter( explode( '/', $pRel ), fn( $p ) => $p !== '' ) );
		if( count( $parts ) < 2 ) {
			return $out;
		}
		$title = array_shift( $parts );
		$file = pathinfo( array_pop( $parts ), PATHINFO_FILENAME );
		$dirs = $parts;

		// not an issue: an Extras folder, an index volume; a Specials folder holds issues of a kind of their own, which are still read (a name may give a date)
		foreach( $dirs as $dir ) {
			if( preg_match( '/^specials?$/i', $dir ) ) {
				$out['category'] = 'Special';
			}
			if( preg_match( '/^extras?$/i', $dir ) ) {
				$out['category'] = 'Extras';
				return $out;
			}
			if( preg_match( '/^index(es)?$/i', $dir ) ) {
				$out['category'] = 'Index';
				return $out;
			}
		}
		if( preg_match( '/index/i', $file ) ) {   // "Index V165", "98index", "index03"
			$out['category'] = 'Index';
			return $out;
		}

		// the year a folder names, the volume a folder names
		$folderYear = '';
		$folderVolume = '';
		foreach( $dirs as $dir ) {
			if( preg_match( '/^(1[6-9]\d\d|20\d\d)$/', $dir ) ) {
				$folderYear = $dir;
			}
			if( $folderVolume === '' && preg_match( '/^Vol(?:ume)?\.?\s*0*(\d+)\b/i', $dir, $m ) ) {
				$folderVolume = $m[1];
			}
		}

		// cover date, volume and number in the volume: "Title 1950-01 097-1 Jan"
		if( preg_match( '/(\d{4})-(\d{2})\s+0*(\d+)-0*(\d+)\b/', $file, $m ) ) {
			return [ 'issue_number' => $m[4], 'volume' => $m[3], 'published' => $m[1].'-'.$m[2], 'category' => $out['category'] ];
		}

		// the year and month alone as the name (7908 in a 1979 folder, 54-10 in a Vol30 folder, rc9808, 200308)
		if( preg_match( '/^(?:rc)?(\d{2})-?(\d{2})$/i', $file, $m ) && (int)$m[2] >= 1 && (int)$m[2] <= 12 && ( $folderYear === '' || substr( $folderYear, 2, 2 ) === $m[1] || preg_match( '/^54-10$/', $file ) ) ) {
			$century = $folderYear !== '' ? substr( $folderYear, 0, 2 ) : ( (int)$m[1] > 30 ? '19' : '20' );
			$out['published'] = $century.$m[1].'-'.$m[2];
			$out['volume'] = $folderVolume;
			return $out;
		}

		if( preg_match( '/\bNo\.?\s?0*(\d+)\b/i', $file, $m ) || preg_match( '/\bIssue\s+0*(\d+)\b/i', $file, $m ) ) {
			$out['issue_number'] = $m[1];
		}
		if( preg_match( '/\bVol(?:ume)?\.?\s*0*(\d+)\b/i', $file, $m ) ) {
			$out['volume'] = $m[1];
		} elseif( $folderVolume !== '' ) {
			$out['volume'] = $folderVolume;
		}

		// the date, as much as the name gives
		if( preg_match( '/(?<!\d)((?:1[6-9]|20)\d\d)-(\d{2})-(\d{2})(?!\d)/', $file, $m ) ) {
			$out['published'] = $m[1].'-'.$m[2].'-'.$m[3];
		} elseif( preg_match( '/(?<!\d)((?:1[6-9]|20)\d\d)-(\d{2})(?![\d-])/', $file, $m ) && (int)$m[2] >= 1 && (int)$m[2] <= 12 ) {
			$out['published'] = $m[1].'-'.$m[2];
		} elseif( preg_match( '/\b'.self::MONTH_RE.'[a-z]*(?:[ -]+'.self::MONTH_RE.'[a-z]*)?[ ,]+((?:1[6-9]|20)\d\d)\b/i', $file, $m ) ) {
			$out['published'] = $m[3].'-'.sprintf( '%02d', self::MONTHS[strtolower( substr( $m[1], 0, 3 ) )] );
		} elseif( preg_match( '/(?<!\d)((?:1[6-9]|20)\d\d)[ _]?(0[1-9]|1[0-2])(?!\d)/', $file, $m ) ) {   // 202508, "2020 03"
			$out['published'] = $m[1].'-'.$m[2];
		} elseif( preg_match( '/\b(?:Spring|Summer|Autumn|Fall|Winter|Collection|Competition)\s+((?:1[6-9]|20)\d\d)\b/i', $file, $m ) ) {
			$out['published'] = $m[1];
		} elseif( preg_match( '/(?<!\d)((?:1[6-9]|20)\d\d)(?!\d)/', $file, $m ) ) {
			$out['published'] = $m[1];
		}
		return $out;
	}

	/**
	 * What is loaded for a kind and how much has been read: loaded, read, filled (an issue the name gave something for), todo.
	 *
	 * @return array{loaded:int,read:int,filled:int,todo:int}
	 */
	public static function status( string $pKind ): array {
		$issues = FisheyeArchiveIsbn::books( $pKind, self::PREF, 'fisheyeissue' );
		$read = array_filter( $issues, fn( $b ) => $b['mark'] !== null );
		$filled = array_filter( $read, fn( $b ) => str_starts_with( (string)$b['mark'], '+' ) );
		return [ 'loaded' => count( $issues ), 'read' => count( $read ), 'filled' => count( $filled ), 'todo' => count( $issues ) - count( $read ) ];
	}

	/**
	 * Read the next issues of a kind that have not been read. A press does BATCH issues; with $pSeconds it keeps going for about that long.
	 *
	 * @return array{read:int,filled:int,none:int,errors:list<string>}
	 */
	public static function tag( string $pKind, int $pSeconds = 0 ): array {
		$started = microtime( true );
		$out = [ 'read' => 0, 'filled' => 0, 'none' => 0, 'errors' => [] ];
		$rows = array_values( array_filter( FisheyeArchiveIsbn::books( $pKind, self::PREF, 'fisheyeissue' ), fn( $b ) => $b['mark'] === null && $b['path'] !== '' ) );
		foreach( $pSeconds > 0 ? $rows : array_slice( $rows, 0, self::BATCH ) as $row ) {
			if( $pSeconds > 0 && ( microtime( true ) - $started ) >= $pSeconds ) {
				break;
			}
			$mark = self::tagFile( (int)$row['content_id'], (int)$row['attachment_id'], $row['path'] );
			if( $mark === null ) {
				$out['errors'][] = $row['path'].': the details could not be stored.';
				continue;
			}
			$out['read']++;
			$mark === '-' ? $out['none']++ : $out['filled']++;
		}
		return $out;
	}

	/**
	 * Read one issue's file name and add what it gives to the Issue, then mark it read. Used by the repair pass and, at the moment an Issue is made, by the workbench's load.
	 *
	 * @param string $pRel the path under the archive root (Title/.../name.pdf)
	 * @return ?string '+n' when n rows were added, '-' when there was nothing to add, null when the Issue could not be found or written
	 */
	public static function tagFile( int $pContentId, int $pAttachmentId, string $pRel ): ?string {
		$book = new FisheyeBook( null, $pContentId );   // not loaded: the details are written straight in, and a load of an issue reads its whole text
		if( !$book->isValid() || $book->mContentTypeGuid !== 'fisheyeissue' ) {
			return null;
		}
		$added = $book->addIssueDetails( self::fromPath( $pRel ) );
		if( $book->mErrors ) {
			return null;
		}
		$count = array_sum( $added );
		$mark = $count ? '+'.$count : '-';
		LibertyMime::storeAttachmentPreference( $pAttachmentId, self::PREF, $mark );
		return $mark;
	}
}

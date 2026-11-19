<?php
/**
 * Tags the Books (the PDFs and epubs) a folder archive has loaded with their ISBN; plain Images are left alone until the workbench has made them Books. Each loaded PDF or epub is read where it is kept (an epub by FisheyeBookCatalogue::epubIsbns(),
 * a PDF from the text of its first pages) and the first valid number found becomes the item's 'isbn' row - the ISBN-13 in xkey and the ISBN-10 in xkey_ext, as a
 * book's is - for later use (looking up the book's other details). Nothing else about the item is touched. Whether a file has been looked at is kept in the
 * attachment preference extdoc_isbn (the ISBN-13 found, or '-' when there is none), so a press only reads files it has not read yet, a file with an ISBN
 * already set by hand is left alone, and the pass can be repeated as more of an archive is loaded.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\Liberty\LibertyContent;
use Bitweaver\Liberty\LibertyMime;

class FisheyeArchiveIsbn {

	/** Files read per press: a PDF is read with pdftotext, an epub opened as a zip. */
	public const BATCH = 20;

	/** Pages of a PDF searched: the title page, the copyright page and the front matter. */
	private const PDF_PAGES = 14;

	private const PREF = 'extdoc_isbn';

	/** @return list<string> the valid ISBN-13s a PDF's first pages give, in the order found */
	public static function pdfIsbns( string $pPath ): array {
		$text = (string)shell_exec( 'pdftotext -q -f 1 -l '.self::PDF_PAGES.' '.escapeshellarg( $pPath ).' - 2>/dev/null' );
		return self::textIsbns( $text );
	}

	/** @return list<string> */
	public static function textIsbns( string $pText ): array {
		$raw = [];
		if( preg_match_all( '/ISBN(?:[- ]?(?:10|13))?[-: ]*([0-9][0-9Xx\- ]{8,18}[0-9Xx])/i', $pText, $m ) ) {
			$raw = $m[1];
		}
		if( preg_match_all( '/\b(97[89][- ]?\d(?:[- ]?\d){9})\b/', $pText, $m ) ) {
			$raw = array_merge( $raw, $m[1] );
		}
		$found = [];
		foreach( $raw as $value ) {
			$isbn = FisheyeBook::normaliseIsbn( $value );
			if( $isbn['isbn13'] !== '' && !in_array( $isbn['isbn13'], $found, true ) ) {
				$found[] = $isbn['isbn13'];
			}
		}
		return $found;
	}

	/** @return list<string> the valid ISBN-13s a file gives; none for a type that is not read */
	public static function fileIsbns( string $pPath ): array {
		return match( strtolower( pathinfo( $pPath, PATHINFO_EXTENSION ) ) ) {
			'epub' => FisheyeBookCatalogue::epubIsbns( $pPath ),
			'pdf'  => self::pdfIsbns( $pPath ),
			default => [],
		};
	}

	/**
	 * The Books of a kind, each with whether it has been read: those held in place (extdoc_path under the kind) and the PDFs and epubs uploaded into the kind's
	 * galleries (stored with the item, mimepdf/mimeepub). 'path' is the in-place path under the archive root; empty for a stored file.
	 *
	 * @return list<array{attachment_id:int,content_id:int,path:string,mark:?string}>
	 */
	private static function books( string $pKind ): array {
		global $gBitDb;
		$pref = self::PREF;
		$mark = "( SELECT MAX( q.`pref_value` ) FROM `".BIT_DB_PREFIX."liberty_attachment_prefs` q WHERE q.`attachment_id` = a.`attachment_id` AND q.`pref_name` = '$pref' )";
		$rows = $gBitDb->getAll(
			"SELECT p.`attachment_id`, a.`content_id`, p.`pref_value` AS path, $mark AS mark FROM `".BIT_DB_PREFIX."liberty_attachment_prefs` p
			 JOIN `".BIT_DB_PREFIX."liberty_attachments` a ON a.`attachment_id` = p.`attachment_id`
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = a.`content_id` AND lc.`content_type_guid` = 'fisheyebook'
			 WHERE p.`pref_name` = 'extdoc_path' AND p.`pref_value` LIKE ?
			 ORDER BY p.`attachment_id`", [ $pKind.'|%' ] ) ?: [];
		$out = [];
		foreach( $rows as $row ) {
			$out[] = [ 'attachment_id' => (int)$row['attachment_id'], 'content_id' => (int)$row['content_id'], 'path' => substr( (string)$row['path'], strlen( $pKind ) + 1 ), 'mark' => $row['mark'] === null ? null : (string)$row['mark'] ];
		}
		$kind = FisheyeDocsWorkbench::kind();
		FisheyeDocsWorkbench::setKind( $pKind );
		$galleries = FisheyeDocsWorkbench::galleryTreeContentIds();
		FisheyeDocsWorkbench::setKind( $kind );
		if( $galleries ) {
			$marks = implode( ',', array_fill( 0, count( $galleries ), '?' ) );
			foreach( $gBitDb->getAll(
				"SELECT DISTINCT a.`attachment_id`, a.`content_id`, $mark AS mark FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
				 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id` AND lc.`content_type_guid` = 'fisheyebook'
				 JOIN `".BIT_DB_PREFIX."liberty_attachments` a ON a.`content_id` = lc.`content_id`
				 WHERE m.`gallery_content_id` IN ( $marks ) AND a.`attachment_plugin_guid` IN ( 'mimepdf', 'mimeepub' )
				 ORDER BY a.`attachment_id`", $galleries ) ?: [] as $row ) {
				$out[] = [ 'attachment_id' => (int)$row['attachment_id'], 'content_id' => (int)$row['content_id'], 'path' => '', 'mark' => $row['mark'] === null ? null : (string)$row['mark'] ];
			}
		}
		return $out;
	}

	/**
	 * What is loaded for a kind and how much of it has been read: loaded, read, tagged (a file with an ISBN), todo.
	 *
	 * @return array{loaded:int,read:int,tagged:int,todo:int}
	 */
	public static function status( string $pKind ): array {
		$books = self::books( $pKind );
		$read = array_filter( $books, fn( $b ) => $b['mark'] !== null );
		$tagged = array_filter( $read, fn( $b ) => $b['mark'] !== '-' );
		return [ 'loaded' => count( $books ), 'read' => count( $read ), 'tagged' => count( $tagged ), 'todo' => count( $books ) - count( $read ) ];
	}

	/**
	 * Read the next files of a kind that have not been read and tag those with an ISBN. A press reads BATCH files; with $pSeconds it keeps going for about that long.
	 *
	 * @return array{read:int,tagged:int,none:int,kept:int,errors:list<string>}
	 */
	public static function tag( string $pKind, int $pSeconds = 0 ): array {
		global $gBitDb;
		$started = microtime( true );
		$out = [ 'read' => 0, 'tagged' => 0, 'none' => 0, 'kept' => 0, 'errors' => [] ];
		$root = FisheyeDocsWorkbench::rootFor( $pKind );
		if( $root === '' ) {
			$out['errors'][] = 'The archive folder was not found.';
			return $out;
		}
		$rows = array_values( array_filter( self::books( $pKind ), fn( $b ) => $b['mark'] === null ) );
		foreach( $pSeconds > 0 ? $rows : array_slice( $rows, 0, self::BATCH ) as $row ) {
			if( $pSeconds > 0 && ( microtime( true ) - $started ) >= $pSeconds ) {
				break;
			}
			$path = $row['path'];
			$file = $path !== '' ? $root.$path : (string)FisheyeBook::lookup( [ 'content_id' => $row['content_id'] ] )?->ownFilePath();
			if( $file === '' ) {
				$out['errors'][] = 'Book '.$row['content_id'].': its file was not found.';
				continue;   // not marked read: tried again next press
			}
			$found = self::fileIsbns( $file );
			$path = $path !== '' ? $path : basename( $file );
			$out['read']++;
			$mark = '-';
			if( $found ) {
				$cid = (int)$row['content_id'];
				if( $gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'isbn' AND `end_date` IS NULL", [ $cid ] ) ) {
					$out['kept']++;   // a number already there (set by hand) is not replaced
					$mark = '=';
				} else {
					$isbn = FisheyeBook::normaliseIsbn( $found[0] );
					if( LibertyContent::upsertXrefByContentId( $cid, 'isbn', [ 'xkey' => $isbn['isbn13'], 'xkey_ext' => $isbn['isbn10'], 'edit' => '' ] ) ) {
						$out['tagged']++;
						$mark = $isbn['isbn13'];
					} else {
						$out['errors'][] = $path.': the ISBN could not be stored.';
						continue;   // not marked read: tried again next press
					}
				}
			} else {
				$out['none']++;
			}
			LibertyMime::storeAttachmentPreference( (int)$row['attachment_id'], self::PREF, $mark );
		}
		return $out;
	}
}

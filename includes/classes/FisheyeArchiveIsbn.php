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

	/** A page naming this many different ISBNs is a list of other titles (a publisher's catalogue or series list), not the copyright page. */
	private const ADVERT_PAGE_ISBNS = 4;

	/**
	 * The ISBN-13s that belong to the book the text is from. Every valid number is found first; those on a page of other titles (four or more different numbers
	 * on one page, taken from the form feeds pdftotext leaves between pages) are dropped. When what is left carries labels ("(pdf)", "(ebook)", "(paperback)" ...) the print edition is chosen (then an unlabelled number, then a digital edition) and that alone is returned. When nothing settles it, all the remaining numbers are returned, in the order found, so a caller sees
	 * the doubt (more than one) and the first is as good a guess as it was before.
	 *
	 * @return list<string>
	 */
	public static function textIsbns( string $pText ): array {
		$found = [];   // isbn13 => [ 'page' => n, 'label' => rank ]
		$pages = [];   // page => list of isbn13 on it
		foreach( explode( "\f", $pText ) as $page => $text ) {
			$raw = [];
			if( preg_match_all( '/ISBN(?:[- ]?(?:10|13))?[-: ]*([0-9][0-9Xx\- ]{8,18}[0-9Xx])/i', $text, $m, PREG_OFFSET_CAPTURE ) ) {
				$raw = $m[1];
			}
			if( preg_match_all( '/\b(97[89][- ]?\d(?:[- ]?\d){9})\b/', $text, $m, PREG_OFFSET_CAPTURE ) ) {
				$raw = array_merge( $raw, $m[1] );
			}
			usort( $raw, fn( $x, $y ) => $x[1] <=> $y[1] );
			foreach( $raw as [ $value, $at ] ) {
				$isbn = FisheyeBook::normaliseIsbn( $value );
				if( $isbn['isbn13'] === '' ) {
					continue;
				}
				// the label is the words right after the number, up to the next number or line end ("9781119694526 (paperback) | ISBN ..."), else just before it ("H/C (ISBN: ...)")
				$after = (string)preg_split( '/\d{9}|ISBN|\R/i', substr( $text, $at + strlen( $value ), 40 ) )[0];
				$before = (string)array_slice( preg_split( '/\d{9}|\R/', substr( $text, max( 0, $at - 30 ), min( 30, $at ) ) ) ?: [], -1 )[0];
				$rank = 2;
				foreach( [ $after, $before ] as $label ) {
					$rank = match( true ) {
						(bool)preg_match( '/\b(paperback|pbk|softcover|print|printed|hardback|hbk|hardcover|h\/c|cloth)\b/i', $label ) => 1,
						(bool)preg_match( '/\b(pdf|e-?book|epub|e-?isbn|online|digital|kindle)\b/i', $label ) => 3,
						default => 2,
					};
					if( $rank !== 2 ) {
						break;
					}
				}
				$pages[$page][$isbn['isbn13']] = true;
				if( !isset( $found[$isbn['isbn13']] ) || ( $found[$isbn['isbn13']]['rank'] === 2 && $rank !== 2 ) ) {   // a number keeps the label it has anywhere (a DOI repeats it bare)
					$found[$isbn['isbn13']] = [ 'page' => $page, 'rank' => $rank, 'order' => $found[$isbn['isbn13']]['order'] ?? count( $found ) ];
				}
			}
		}
		if( !$found ) {
			return [];
		}
		$book = array_filter( $found, fn( $f ) => count( $pages[$f['page']] ) < self::ADVERT_PAGE_ISBNS );
		$book = $book ?: $found;   // every page is a list: nothing to choose by, keep the doubt
		uasort( $book, fn( $x, $y ) => $x['order'] <=> $y['order'] );
		// the editions named together on one page (the copyright page) are one book: the print edition's number is the one to look a book up by, so a number
		// labelled print comes first, then one with no label at all (the main edition is normally printed unlabelled before its "(eBook)"), then the digital ones
		if( count( $book ) > 1 && count( array_unique( array_column( $book, 'page' ) ) ) === 1 ) {
			$best = min( array_column( $book, 'rank' ) );
			$top = array_filter( $book, fn( $f ) => $f['rank'] === $best );
			if( $best !== 2 || count( $top ) === 1 ) {
				return [ (string)array_key_first( $top ) ];   // the earliest of the best rank
			}
		}
		return array_map( 'strval', array_keys( $book ) );
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
		$read = array_filter( $books, fn( $b ) => $b['mark'] !== null && $b['mark'] !== '*' );
		$tagged = array_filter( $read, fn( $b ) => !in_array( $b['mark'], [ '-', '~' ], true ) );
		return [ 'loaded' => count( $books ), 'read' => count( $read ), 'tagged' => count( $tagged ), 'todo' => count( $books ) - count( $read ) ];
	}

	/**
	 * Read the next files of a kind that have not been read and tag those with an ISBN. A press reads BATCH files; with $pSeconds it keeps going for about that long.
	 *
	 * @return array{read:int,tagged:int,none:int,kept:int,several:int,errors:list<string>}
	 */
	public static function tag( string $pKind, int $pSeconds = 0 ): array {
		$started = microtime( true );
		$out = [ 'read' => 0, 'tagged' => 0, 'none' => 0, 'kept' => 0, 'several' => 0, 'errors' => [] ];
		$root = FisheyeDocsWorkbench::rootFor( $pKind );
		if( $root === '' ) {
			$out['errors'][] = 'The archive folder was not found.';
			return $out;
		}
		$rows = array_values( array_filter( self::books( $pKind ), fn( $b ) => $b['mark'] === null || $b['mark'] === '*' ) );
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
			$path = $path !== '' ? $path : basename( $file );
			$mark = self::tagFile( (int)$row['content_id'], (int)$row['attachment_id'], $file, null, true );
			if( $mark === null ) {
				$out['errors'][] = $path.': the ISBN could not be stored.';
				continue;   // not marked read: tried again next press
			}
			$out['read']++;
			match( $mark ) { '-' => $out['none']++, '=' => $out['kept']++, '~' => $out['several']++, default => $out['tagged']++ };
		}
		return $out;
	}

	/**
	 * Read one file and tag its Book with the ISBN it carries, then mark the file read (the attachment preference). Used by the tag pass and, at the moment a
	 * Book is made from an archive file, by the workbench's load, so the record starts with its ISBN. A number already on the Book (set by hand) is not replaced.
	 *
	 * @return ?string  the ISBN-13 stored, '=' when the Book already had one, '-' when the file gives none, null when the number could not be stored (the file is not marked read)
	 */
	public static function tagFile( int $pContentId, int $pAttachmentId, string $pFile, ?array $pFound = null, bool $pSkipSeveral = false ): ?string {
		global $gBitDb;
		$found = $pFound ?? self::fileIsbns( $pFile );   // a caller that has already read the file passes what it found
		$mark = '-';
		if( $found && $pSkipSeveral && count( $found ) > 1 ) {
			$mark = '~';   // several different ISBNs the reader could not choose between: left for a person ('*' was the mark of a reader that did not rank them, so it is read again)
		} elseif( $found ) {
			if( $gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'isbn' AND `end_date` IS NULL", [ $pContentId ] ) ) {
				$mark = '=';
			} else {
				$isbn = FisheyeBook::normaliseIsbn( $found[0] );
				if( !LibertyContent::upsertXrefByContentId( $pContentId, 'isbn', [ 'xkey' => $isbn['isbn13'], 'xkey_ext' => $isbn['isbn10'], 'edit' => '' ] ) ) {
					return null;
				}
				$mark = $isbn['isbn13'];
			}
		}
		LibertyMime::storeAttachmentPreference( $pAttachmentId, self::PREF, $mark );
		return $mark;
	}
}

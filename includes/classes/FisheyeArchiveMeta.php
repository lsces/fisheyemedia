<?php
/**
 * Reads the details a PDF or epub carries about itself - the author(s), the publisher and the date of this copy - and adds them to the Book a folder archive has
 * loaded from it, so the record starts with the people (and the publisher) its file names, ready to be linked to contacts later. An epub is read from its OPF
 * (creators with their role, publisher, date), a PDF from its document information (Author only: the other fields of a PDF are the software that made it, and its
 * dates are when it was scanned). Names are kept exactly as written; placeholders ('unknown', 'admin', the name of the program that made the file) are skipped.
 * Nothing is replaced: a Book that already holds a credit, a publisher or an edition date (set by hand, or by a catalogue) keeps it, and only what is missing is added.
 * Whether a file has been read is kept in the attachment preference extdoc_meta ('+n' when n rows were added, '-' when there was nothing to add), so the repair
 * pass reads only files not yet seen. The title is not taken: archive titles are tidied by hand and come from the file name.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\Liberty\LibertyMime;

class FisheyeArchiveMeta {

	/** Files read per press: an epub is opened as a zip, a PDF read with pdfinfo. */
	public const BATCH = 40;

	private const PREF = 'extdoc_meta';

	/** OPF creator roles (MARC relator codes) that map to a credit item; a creator with no role is an author, any other role is not a credit. */
	private const ROLES = [ 'aut' => 'author', 'edt' => 'editor', 'ill' => 'illustrator', 'trl' => 'translator', 'nrt' => 'narrator' ];

	/** Values a program or a careless export puts where a person's name belongs. */
	private const PLACEHOLDER = '/^(unknown|admin|administrator|user|owner|author|anonymous|anon|none|n\/a|na|null|untitled|various|windows user|pc|scanner|scan|calibre)$/i';
	/** Notes and fragments typed into an Author field: an address, a bracketed remark, a credit line about the file, a log-in. */
	private const NOT_A_PERSON = '/[@\[\]]|\.\.\.|\b(user|ftp|passed through|scanned|pdf by|converted)\b|^(creator|producer)\s*:/i';
	private const SOFTWARE = '/\b(microsoft|adobe|acrobat|indesign|quarkxpress|pdfcreator|scansnap|calibre|abbyy|wondershare|finereader)\b|\.(pdf|docx?|indd|qxd)\b/i';

	/**
	 * @return array{authors:list<array{name:string,role:string}>,publisher:string,date:string}  what the file gives; empty values when it gives nothing
	 */
	public static function fileMeta( string $pPath ): array {
		$out = [ 'authors' => [], 'publisher' => '', 'date' => '' ];
		match( strtolower( pathinfo( $pPath, PATHINFO_EXTENSION ) ) ) {
			'epub'  => self::epubMeta( $pPath, $out ),
			'pdf'   => self::pdfMeta( $pPath, $out ),
			default => null,
		};
		return $out;
	}

	private static function epubMeta( string $pPath, array &$pOut ): void {
		$zip = new \ZipArchive();
		if( $zip->open( $pPath ) !== true ) {
			return;
		}
		$opf = null;
		$container = $zip->getFromName( 'META-INF/container.xml' );
		if( $container && preg_match( '/full-path="([^"]+\.opf)"/i', $container, $m ) ) {
			$opf = $m[1];
		}
		if( !$opf ) {
			for( $i = 0; $i < $zip->numFiles; $i++ ) {
				if( str_ends_with( strtolower( (string)$zip->getNameIndex( $i ) ), '.opf' ) ) {
					$opf = $zip->getNameIndex( $i );
					break;
				}
			}
		}
		$xml = $opf ? $zip->getFromName( $opf ) : false;
		$zip->close();
		if( !$xml ) {
			return;
		}
		$dom = new \DOMDocument();
		if( !@$dom->loadXML( $xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
			return;
		}
		foreach( $dom->getElementsByTagName( '*' ) as $el ) {
			$text = trim( (string)$el->textContent );
			switch( $el->localName ) {
				case 'creator':
					$role = strtolower( trim( $el->getAttributeNS( 'http://www.idpf.org/2007/opf', 'role' ) ?: $el->getAttribute( 'opf:role' ) ?: $el->getAttribute( 'role' ) ) );
					$credit = $role === '' ? 'author' : ( self::ROLES[$role] ?? '' );
					if( $credit !== '' ) {
						foreach( self::names( $text ) as $name ) {
							$pOut['authors'][] = [ 'name' => $name, 'role' => $credit ];
						}
					}
					break;
				case 'publisher':
					if( $pOut['publisher'] === '' && ( $publisher = self::cleanName( $text ) ) !== '' ) {
						$pOut['publisher'] = $publisher;
					}
					break;
				case 'date':
					if( $pOut['date'] === '' ) {
						$pOut['date'] = self::cleanDate( $text );
					}
					break;
			}
		}
	}

	private static function pdfMeta( string $pPath, array &$pOut ): void {
		$info = (string)shell_exec( 'pdfinfo '.escapeshellarg( $pPath ).' 2>/dev/null' );
		if( preg_match( '/^Author:\s*(\S.*)$/m', $info, $m ) ) {
			foreach( self::names( $m[1], true ) as $name ) {
				$pOut['authors'][] = [ 'name' => $name, 'role' => 'author' ];
			}
		}
	}

	/**
	 * @param bool $pStrict  for a PDF, whose Author field is whatever the program or the person who made it typed: a single word (a user name, a web address) is not a name
	 * @return list<string> the people a creator field names ('A; B', 'A & B', 'A and B', or 'A B, C D, E F'), each cleaned; duplicates dropped
	 */
	private static function names( string $pText, bool $pStrict = false ): array {
		// "First Last, First Last, First Last" is a list; "Last, First" (one comma) is a single name written the other way round
		$protected = preg_replace_callback( '/\([^)]*\)/', fn( $m ) => str_replace( [ '&', ';', ' and ' ], [ "\x01", "\x02", "\x03" ], $m[0] ), $pText );   // "Acme (Wand & Wizard)" is one name
		$parts = array_map( fn( $p ) => str_replace( [ "\x01", "\x02", "\x03" ], [ '&', ';', ' and ' ], $p ), preg_split( '/\s*(?:;|&| and )\s*/i', $protected ) ?: [] );
		$list = [];
		foreach( $parts as $part ) {
			// a degree or suffix after a comma belongs to the name before it ("Jane Doe, M.D."), it is not a person of its own
			$bits = [];
			foreach( array_filter( array_map( 'trim', explode( ',', $part ) ), 'strlen' ) as $bit ) {   // a trailing comma ("..., M.D., and X") leaves nothing
				if( $bits && preg_match( '/^(M\.?\s?D\.?|Ph\.?\s?D\.?|D\.?\s?V\.?\s?M\.?|Jr\.?|Sr\.?|II|III|IV|Esq\.?)$/i', $bit ) ) {
					$bits[count( $bits ) - 1] .= ', '.$bit;
				} else {
					$bits[] = $bit;
				}
			}
			if( count( $bits ) >= 3 && count( array_filter( $bits, fn( $b ) => str_contains( $b, ' ' ) ) ) === count( $bits ) ) {
				$list = array_merge( $list, $bits );
			} else {
				$list[] = $part;
			}
		}
		$out = [];
		foreach( $list as $part ) {
			$name = self::cleanName( $part );
			if( $name !== '' && !( $pStrict && !str_contains( $name, ' ' ) ) && !in_array( $name, $out, true ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	private static function cleanName( string $pName ): string {
		$name = trim( preg_replace( '/\s+/u', ' ', $pName ), " ,;\t" );
		$name = trim( preg_replace( '/^(designer|designers|author|authors|written by|by|editor|credits)\s*:\s*/i', '', $name ) );
		if( mb_strlen( $name ) < 3 || mb_strlen( $name ) > 120 || !preg_match( '/\p{L}/u', $name ) || preg_match( self::PLACEHOLDER, $name ) || preg_match( self::SOFTWARE, $name )
			|| preg_match( self::NOT_A_PERSON, $name ) || substr_count( $name, '(' ) !== substr_count( $name, ')' ) ) {
			return '';
		}
		return $name;
	}

	/** A date as a sensible year, year-month or full date, or '' (Calibre writes 0101-01-01 for an unknown date; a 1 January is taken as the year alone). */
	private static function cleanDate( string $pText ): string {
		if( !preg_match( '/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?/', trim( $pText ), $m ) ) {
			return '';
		}
		$year = (int)$m[1];
		if( $year < 1000 || $year > (int)date( 'Y' ) + 1 ) {
			return '';
		}
		if( isset( $m[2], $m[3] ) && $m[2] === '01' && $m[3] === '01' ) {
			return (string)$year;
		}
		return $m[0];
	}

	/**
	 * What is loaded for a kind and how much has been read: loaded, read, filled (a file that gave something the Book lacked), todo.
	 *
	 * @return array{loaded:int,read:int,filled:int,todo:int}
	 */
	public static function status( string $pKind ): array {
		$books = FisheyeArchiveIsbn::books( $pKind, self::PREF );
		$read = array_filter( $books, fn( $b ) => $b['mark'] !== null );
		$filled = array_filter( $read, fn( $b ) => str_starts_with( (string)$b['mark'], '+' ) );
		return [ 'loaded' => count( $books ), 'read' => count( $read ), 'filled' => count( $filled ), 'todo' => count( $books ) - count( $read ) ];
	}

	/**
	 * Read the next files of a kind that have not been read. A press reads BATCH files; with $pSeconds it keeps going for about that long.
	 *
	 * @return array{read:int,filled:int,none:int,errors:list<string>}
	 */
	public static function tag( string $pKind, int $pSeconds = 0 ): array {
		$started = microtime( true );
		$out = [ 'read' => 0, 'filled' => 0, 'none' => 0, 'errors' => [] ];
		$root = FisheyeDocsWorkbench::rootFor( $pKind );
		if( $root === '' ) {
			$out['errors'][] = 'The archive folder was not found.';
			return $out;
		}
		$rows = array_values( array_filter( FisheyeArchiveIsbn::books( $pKind, self::PREF ), fn( $b ) => $b['mark'] === null ) );
		foreach( $pSeconds > 0 ? $rows : array_slice( $rows, 0, self::BATCH ) as $row ) {
			if( $pSeconds > 0 && ( microtime( true ) - $started ) >= $pSeconds ) {
				break;
			}
			$file = $row['path'] !== '' ? $root.$row['path'] : (string)FisheyeBook::lookup( [ 'content_id' => $row['content_id'] ] )?->ownFilePath();
			if( $file === '' ) {
				$out['errors'][] = 'Book '.$row['content_id'].': its file was not found.';
				continue;   // not marked read: tried again next press
			}
			$mark = self::tagFile( (int)$row['content_id'], (int)$row['attachment_id'], $file );
			if( $mark === null ) {
				$out['errors'][] = ( $row['path'] !== '' ? $row['path'] : basename( $file ) ).': the details could not be stored.';
				continue;
			}
			$out['read']++;
			$mark === '-' ? $out['none']++ : $out['filled']++;
		}
		return $out;
	}

	/**
	 * Read one file and add what it gives to its Book, then mark the file read. Used by the repair pass and, at the moment a Book is made from an archive file,
	 * by the workbench's load (which passes what it has already read).
	 *
	 * @return ?string '+n' when n rows were added, '-' when there was nothing to add, null when the Book could not be found or written
	 */
	public static function tagFile( int $pContentId, int $pAttachmentId, string $pFile, ?array $pMeta = null ): ?string {
		$meta = $pMeta ?? self::fileMeta( $pFile );
		$book = FisheyeBook::lookup( [ 'content_id' => $pContentId ] );
		if( !$book || !$book->isValid() ) {
			return null;
		}
		$added = $book->addArchiveMetadata( $meta );
		if( $book->mErrors ) {
			return null;
		}
		$count = array_sum( $added );
		$mark = $count ? '+'.$count : '-';
		LibertyMime::storeAttachmentPreference( $pAttachmentId, self::PREF, $mark );
		return $mark;
	}
}

<?php
/**
 * The catalogue .csv files kept in the Library tree - Library/<author>/AuthorCatalogue.csv and Library/Various Authors/<series>/SeriesCatalogue.csv - brought up
 * to date from the database without losing a row. A row whose book is in the database (matched by ISBN-13, else title) gets its EMPTY ISBN-13, ISBN-10, Publisher,
 * Year, Authors, Series and Series no cells filled (the series and place the book has been given in the galleries); a cell that already holds something is never changed (a differing ISBN or year is only reported); database books the file lacks are
 * added at the end; a row with no book yet (waiting for Add Books) is left exactly as it is. An author folder with no catalogue gets one written from the database.
 *
 * Nothing is regenerated from the database alone: that would drop every row still waiting to be loaded.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

class FisheyeCatalogueMerge {
	public const AUTHOR_FILE = 'AuthorCatalogue.csv';
	public const SERIES_FILE = 'SeriesCatalogue.csv';
	/** Copies of a catalogue kept before it is rewritten - the newest few, as the database backups keep the newest few. */
	public const BACKUPS_KEPT = 5;
	public const HEADER = [ 'Ref', 'Category', 'ISBN-13', 'ISBN-10', 'Authors', 'Publisher', 'Title', 'Year', 'Series', 'Series no' ];

	/**
	 * Called after Add Books has loaded books for an author: the author's catalogue in the Library folder takes up what is now known (ISBNs established by the
	 * sheet or by Suggest, publisher, year), and one is written when the author has a folder but no catalogue yet. Nothing happens when there is no Library folder
	 * or no folder for the author.
	 *
	 * @return string|null  a line to show with the load result, or null when there was nothing to do
	 */
	public static function updateAuthor( string $pName ): ?string {
		$root = FisheyeBookFiles::libraryRoot();
		$dir = $root !== '' ? $root.str_replace( '/', '-', trim( $pName ) ).'/' : '';
		if( $dir === '' || !is_dir( $dir ) ) {
			return null;
		}
		$path = $dir.self::AUTHOR_FILE;
		$plan = self::plan( [ 'kind' => 'author', 'name' => $pName, 'path' => $path, 'exists' => is_file( $path ) ] );
		if( $plan['error'] ) {
			return self::AUTHOR_FILE.': '.$plan['error'];
		}
		if( !$plan['changes'] ) {
			return null;
		}
		if( $error = self::write( $plan ) ) {
			return self::AUTHOR_FILE.': '.$error;
		}
		return self::AUTHOR_FILE.' '.\Bitweaver\KernelTools::tra( 'updated' ).': '.count( $plan['filled'] ).' '.\Bitweaver\KernelTools::tra( 'cells filled,' ).' '.count( $plan['added'] ).' '.\Bitweaver\KernelTools::tra( 'books added.' );
	}

	/**
	 * The same for a Various Authors series' catalogue (Library/Various Authors/<series>/SeriesCatalogue.csv).
	 *
	 * @return string|null  a line to show, or null when there was nothing to do
	 */
	public static function updateSeries( string $pName ): ?string {
		$root = FisheyeBookFiles::libraryRoot();
		$path = $root !== '' ? $root.FisheyeMediaGallery::VARIOUS_AUTHORS.'/'.str_replace( '/', '-', trim( $pName ) ).'/'.self::SERIES_FILE : '';
		if( $path === '' || !is_file( $path ) ) {
			return null;
		}
		$plan = self::plan( [ 'kind' => 'series', 'name' => $pName, 'path' => $path, 'exists' => true ] );
		if( $plan['error'] ) {
			return self::SERIES_FILE.': '.$plan['error'];
		}
		if( !$plan['changes'] ) {
			return null;
		}
		if( $error = self::write( $plan ) ) {
			return self::SERIES_FILE.': '.$error;
		}
		return self::SERIES_FILE.' '.\Bitweaver\KernelTools::tra( 'updated' ).': '.count( $plan['filled'] ).' '.\Bitweaver\KernelTools::tra( 'cells filled,' ).' '.count( $plan['added'] ).' '.\Bitweaver\KernelTools::tra( 'books added.' );
	}

	/**
	 * Put the file's year into the database for the books given (a catalogue and the database disagreeing on a year, the file being right): each book's
	 * published row and event_time are set together. Recomputed from the file now; only a Year conflict of a listed book is touched.
	 *
	 * @param list<int> $pBookIds
	 * @return int  books changed
	 */
	public static function useFileYears( array $pFile, array $pBookIds ): int {
		$done = 0;
		foreach( self::plan( $pFile )['conflicts'] as $conflict ) {
			if( $conflict[1] !== 'Year' || !in_array( (int)$conflict[4], $pBookIds, true ) ) {
				continue;
			}
			$book = \Bitweaver\Fisheye\FisheyeImage::lookup( [ 'content_id' => (int)$conflict[4] ] );
			if( $book instanceof FisheyeBook && $book->setPublished( $conflict[2] ) ) {
				$done++;
			}
		}
		return $done;
	}

	/**
	 * Add catalogue rows for folders in the Library tree that have no book - the books still to catalogue. A folder directly under the author is a book of that
	 * name; one inside a series folder is a book of that series, numbered after the series' last. The ISBN, publisher and year are left blank for the
	 * workbench to find; the title is the folder's name so the book finds its folder. A folder whose title is already in the file is not added twice.
	 *
	 * @param list<string> $pFolders  paths relative to the author's folder ('Series/Book' or 'Book')
	 * @return array{added:list<string>,error:?string}
	 */
	public static function addFolders( string $pPath, string $pAuthor, array $pFolders ): array {
		$read = is_file( $pPath ) ? self::read( $pPath ) : [ self::HEADER, [] ];
		if( !$read ) {
			return [ 'added' => [], 'error' => \Bitweaver\KernelTools::tra( 'The file could not be read as a catalogue (it needs the usual columns).' ) ];
		}
		[ $head, $body ] = $read;
		$have = [];
		foreach( $body as $row ) {
			$have[self::titleKey( $row['Title'] )] = true;
		}
		$added = [];
		foreach( $pFolders as $rel ) {
			$title = basename( trim( $rel, '/' ) );
			$series = str_contains( trim( $rel, '/' ), '/' ) ? basename( dirname( $rel ) ) : '';
			if( $title === '' || isset( $have[self::titleKey( $title )] ) ) {
				continue;
			}
			$number = 0;
			$at = count( $body );
			foreach( $body as $i => $row ) {
				if( $series !== '' && $row['Series'] === $series ) {
					$number = max( $number, (int)$row['Series no'] );
					$at = $i + 1;
				}
			}
			$new = array_fill_keys( $head, '' );
			$new['Category'] = $series;
			$new['Authors'] = $pAuthor;
			$new['Title'] = $title;
			$new['Series'] = $series;
			$new['Series no'] = $series !== '' ? (string)( $number + 1 ) : '';
			array_splice( $body, $at, 0, [ $new ] );
			$have[self::titleKey( $title )] = true;
			$added[] = $title;
		}
		if( !$added ) {
			return [ 'added' => [], 'error' => null ];
		}
		$error = self::write( [ 'error' => null, 'exists' => is_file( $pPath ), 'path' => $pPath, 'head' => $head, 'body' => $body, 'changes' => count( $added ) ] );
		return [ 'added' => $error ? [] : $added, 'error' => $error ];
	}

	/**
	 * Put the database's value into the catalogue file for the disagreements given (the file is wrong, the database right): only those cells of the
	 * rows named change, nothing else the merge might fill. An ISBN-13 takes its ISBN-10 with it. Recomputed from the file now.
	 *
	 * @param list<string> $pKeys  "<book id>_<column>", as a conflict is keyed on the page
	 * @return array{changed:int,error:?string}
	 */
	public static function useDatabaseValues( array $pFile, array $pKeys ): array {
		$read = is_file( $pFile['path'] ) ? self::read( $pFile['path'] ) : null;
		if( !$read ) {
			return [ 'changed' => 0, 'error' => \Bitweaver\KernelTools::tra( 'The file could not be read as a catalogue (it needs the usual columns).' ) ];
		}
		[ $head, $body ] = $read;
		$changed = 0;
		foreach( self::plan( $pFile )['conflicts'] as $conflict ) {
			if( !in_array( $conflict[4].'_'.$conflict[1], $pKeys, true ) || !isset( $body[$conflict[5]] ) ) {
				continue;
			}
			$body[$conflict[5]][$conflict[1]] = $conflict[3];
			foreach( $conflict[6] as $column => $value ) {
				$body[$conflict[5]][$column] = $value;
			}
			$changed++;
		}
		if( !$changed ) {
			return [ 'changed' => 0, 'error' => null ];
		}
		$error = self::write( [ 'error' => null, 'exists' => true, 'path' => $pFile['path'], 'head' => $head, 'body' => $body, 'changes' => $changed ] );
		return [ 'changed' => $error ? 0 : $changed, 'error' => $error ];
	}

	/** Title as compared: case, punctuation, a leading The/A/An and '&' against 'and' ignored. */
	private static function titleKey( string $pTitle ): string {
		$t = str_replace( '&', ' and ', mb_strtolower( $pTitle ) );
		$t = preg_replace( '/[^a-z0-9 ]/', '', $t );
		$t = preg_replace( '/^(the|a|an) /', '', trim( $t ) );
		return trim( preg_replace( '/\s+/', ' ', $t ) );
	}

	/** The books to compare with: an author folder's gallery, its series, and what is credited to that name; a series folder's gallery under Various Authors. */
	private static function bookIds( string $pKind, string $pName ): array {
		global $gBitDb;
		$ids = [];
		if( $pKind === 'series' ) {
			$va = FisheyeBookCredits::libraryGalleryFor( FisheyeMediaGallery::VARIOUS_AUTHORS );
			$series = $va ? $gBitDb->getOne( "SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
				WHERE m.`gallery_content_id` = ? AND lc.`title` = ?", [ $va, $pName ] ) : null;
			$galleries = $series ? [ (int)$series ] : [];
		} else {
			$author = FisheyeBookCredits::libraryGalleryFor( $pName );
			$galleries = $author ? array_merge( [ (int)$author ], array_map( 'intval', $gBitDb->getCol(
				"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
				 WHERE m.`gallery_content_id` = ? AND lc.`content_type_guid` = 'fisheyeseries'", [ $author ] ) ) ) : [];
			$ids = array_map( 'intval', $gBitDb->getCol(
				"SELECT lx.`content_id` FROM `".BIT_DB_PREFIX."liberty_xref` lx INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = lx.`content_id`
				 WHERE lc.`content_type_guid` = 'fisheyebook' AND lx.`item` = 'author' AND lx.`end_date` IS NULL AND lx.`xkey_ext` = ?", [ $pName ] ) );
		}
		foreach( $galleries as $galleryId ) {
			$ids = array_merge( $ids, array_map( 'intval', $gBitDb->getCol(
				"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
				 WHERE m.`gallery_content_id` = ? AND lc.`content_type_guid` = 'fisheyebook'", [ $galleryId ] ) ) );
		}
		return array_values( array_unique( $ids ) );
	}

	/** What the database holds for these books: id => [ title, isbn13, isbn10, authors[], publisher, year, series, pos ]. */
	private static function details( array $pIds ): array {
		global $gBitDb;
		$books = [];
		if( !$pIds ) {
			return $books;
		}
		$in = implode( ',', array_map( 'intval', $pIds ) );
		foreach( $gBitDb->getAll( "SELECT `content_id`, `title` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` IN ( $in )" ) as $r ) {
			$books[(int)$r['content_id']] = [ 'title' => trim( (string)$r['title'] ), 'isbn13' => '', 'isbn10' => '', 'authors' => [], 'publisher' => '', 'year' => '', 'series' => '', 'pos' => '' ];
		}
		foreach( $gBitDb->getAll( "SELECT `content_id`, `item`, `xkey`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref`
			WHERE `end_date` IS NULL AND `item` IN ( 'isbn', 'author', 'publisher', 'published' ) AND `content_id` IN ( $in ) ORDER BY `xorder`" ) as $r ) {
			$b =& $books[(int)$r['content_id']];
			$ext = trim( (string)$r['xkey_ext'] );
			switch( $r['item'] ) {
				case 'isbn':
					$b['isbn13'] = trim( (string)$r['xkey'] );
					$b['isbn10'] = $ext;
					break;
				case 'author':
					if( $ext !== '' && !in_array( $ext, $b['authors'], true ) ) {
						$b['authors'][] = $ext;
					}
					break;
				case 'publisher':
					$b['publisher'] = $b['publisher'] ?: $ext;
					break;
				case 'published':
					if( !$b['year'] && preg_match( '/\d{4}/', $ext, $m ) ) {
						$b['year'] = $m[0];
					}
					break;
			}
			unset( $b );
		}
		foreach( $gBitDb->getAll( "SELECT m.`item_content_id`, g.`title`, m.`item_position` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
			INNER JOIN `".BIT_DB_PREFIX."liberty_content` g ON g.`content_id` = m.`gallery_content_id`
			WHERE g.`content_type_guid` = 'fisheyeseries' AND m.`item_content_id` IN ( $in )" ) as $r ) {
			$b =& $books[(int)$r['item_content_id']];
			if( $b['series'] === '' ) {
				$b['series'] = trim( (string)$r['title'] );
				$b['pos'] = (float)$r['item_position'] ? (string)(float)$r['item_position'] : '';
			}
			unset( $b );
		}
		return $books;
	}

	/** The file's rows: [ head[], body[ [column => value] ] ], or null when it does not exist / has no Title column. */
	private static function read( string $pPath ): ?array {
		if( !is_file( $pPath ) || !( $fh = fopen( $pPath, 'r' ) ) ) {
			return null;
		}
		$head = null;
		$body = [];
		while( ( $cells = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
			if( $head === null ) {
				$cells[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string)$cells[0] );
				$head = array_map( 'trim', $cells );
				continue;
			}
			if( !array_filter( $cells, fn( $c ) => trim( (string)$c ) !== '' ) ) {
				continue;
			}
			$cells = array_pad( $cells, count( $head ), '' );
			$body[] = array_combine( $head, array_slice( $cells, 0, count( $head ) ) );
		}
		fclose( $fh );
		return $head && !array_diff( self::HEADER, $head ) ? [ $head, $body ] : null;
	}

	/**
	 * What updating a catalogue would do - read-only.
	 *
	 * @return array{kind:string,name:string,path:string,exists:bool,error:?string,head:array,body:array,filled:array,added:array,loose:array,conflicts:array,changes:int}
	 */
	public static function plan( array $pFile ): array {
		$plan = $pFile + [ 'error' => null, 'head' => self::HEADER, 'body' => [], 'filled' => [], 'added' => [], 'loose' => [], 'conflicts' => [], 'changes' => 0 ];
		$books = self::details( self::bookIds( $pFile['kind'], $pFile['name'] ) );
		if( $pFile['exists'] ) {
			if( !( $read = self::read( $pFile['path'] ) ) ) {
				$plan['error'] = \Bitweaver\KernelTools::tra( 'The file could not be read as a catalogue (it needs the usual columns).' );
				return $plan;
			}
			[ $plan['head'], $plan['body'] ] = $read;
		}
		$byIsbn = $byTitle = [];
		foreach( $books as $id => $b ) {
			if( $b['isbn13'] !== '' ) {
				$byIsbn[$b['isbn13']] = $id;
			}
			$byTitle[self::titleKey( $b['title'] )] ??= $id;
		}
		$used = [];
		foreach( $plan['body'] as $ri => &$row ) {
			$isbn = trim( $row['ISBN-13'] );
			$id = $isbn !== '' ? ( $byIsbn[$isbn] ?? null ) : null;
			$id ??= $byTitle[self::titleKey( $row['Title'] )] ?? null;
			if( $id === null || isset( $used[$id] ) ) {
				$plan['loose'][] = $row['Title'];
				continue;
			}
			$used[$id] = true;
			$b = $books[$id];
			$want = [ 'ISBN-13' => $b['isbn13'], 'ISBN-10' => $b['isbn10'], 'Publisher' => $b['publisher'], 'Year' => $b['year'], 'Authors' => implode( '|', $b['authors'] ),
				'Series' => $b['series'], 'Series no' => $b['pos'] ];
			foreach( $want as $col => $val ) {
				if( $val === '' ) {
					continue;
				}
				if( trim( $row[$col] ) === '' ) {
					$row[$col] = $val;
					$plan['filled'][] = [ $row['Title'], $col, $val ];
				} elseif( trim( $row[$col] ) !== $val && in_array( $col, [ 'ISBN-13', 'Year' ], true ) ) {
					// [ title, column, the file's value, the database's value, book id, the row of the file, the other cells that go with this one ]
					$plan['conflicts'][] = [ $row['Title'], $col, trim( $row[$col] ), $val, $id, $ri, $col === 'ISBN-13' && $b['isbn10'] !== '' ? [ 'ISBN-10' => $b['isbn10'] ] : [] ];
				}
			}
		}
		unset( $row );
		uasort( $books, fn( $a, $b ) => [ mb_strtolower( $a['series'] ?: '~' ), $a['title'] ] <=> [ mb_strtolower( $b['series'] ?: '~' ), $b['title'] ] );
		foreach( $books as $id => $b ) {
			if( !isset( $used[$id] ) ) {
				$plan['body'][] = [ 'Ref' => '', 'Category' => $b['series'], 'ISBN-13' => $b['isbn13'], 'ISBN-10' => $b['isbn10'], 'Authors' => implode( '|', $b['authors'] ),
					'Publisher' => $b['publisher'], 'Title' => $b['title'], 'Year' => $b['year'], 'Series' => $b['series'], 'Series no' => $b['pos'] ];
				$plan['added'][] = $b['title'];
			}
		}
		$plan['changes'] = count( $plan['filled'] ) + count( $plan['added'] );
		return $plan;
	}

	/**
	 * Write a planned catalogue: the old file first goes to storage/fisheyemedia/catalogue backups/ (the newest BACKUPS_KEPT per catalogue are kept), then the merged rows are written, Ref renumbered.
	 *
	 * @return string|null  an error, or null on success
	 */
	public static function write( array $pPlan ): ?string {
		if( $pPlan['error'] || !$pPlan['changes'] ) {
			return $pPlan['error'] ?? \Bitweaver\KernelTools::tra( 'nothing to change' );
		}
		if( $pPlan['exists'] ) {
			$keep = FISHEYEMEDIA_IMPORT_PATH.'catalogue backups/';
			\Bitweaver\KernelTools::mkdir_p( $keep );
			$suffix = ' - '.basename( dirname( $pPlan['path'] ) ).' - '.basename( $pPlan['path'] );
			if( !copy( $pPlan['path'], $keep.date( 'Ymd-His' ).$suffix ) ) {
				return \Bitweaver\KernelTools::tra( 'the old file could not be kept, nothing changed' );
			}
			// Only the newest few copies of this catalogue are kept (the names start with the date and time).
			$copies = glob( $keep.'*'.$suffix ) ?: [];
			rsort( $copies );
			foreach( array_slice( $copies, self::BACKUPS_KEPT ) as $old ) {
				@unlink( $old );
			}
		}
		if( !( $fh = fopen( $pPlan['path'], 'w' ) ) ) {
			return \Bitweaver\KernelTools::tra( 'the file could not be written' );
		}
		fputcsv( $fh, $pPlan['head'], ',', '"', '' );
		foreach( array_values( $pPlan['body'] ) as $n => $row ) {
			$row['Ref'] = (string)( $n + 1 );
			fputcsv( $fh, array_map( fn( $c ) => $row[$c] ?? '', $pPlan['head'] ), ',', '"', '' );
		}
		fclose( $fh );
		return null;
	}
}

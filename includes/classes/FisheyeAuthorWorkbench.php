<?php
/**
 * One author at a time, from the author's gallery: the author's books in three steps in the order the information becomes available -
 * load the books the author's AuthorCatalogue.csv names that are not in the database, sort them into series, load the files in their Library
 * folders. The author-level counterpart of FisheyeSeriesWorkbench, and the place the folder-driven and Wikidata/Open Library series proposals
 * (once load_series.php and load_book_series.php) now live: loading a book brings in its work ids and series text, so series are sorted after the
 * books are there, and the catalogue file is only a starting list, not the master - the review here is.
 *
 * A book with several authors is filed in its first author's gallery and beside any co-author's that has one (FisheyeBookLoader), so the books
 * step names where each missing book will be filed, and opening an author files the books already loaded that credit them.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\Liberty\LibertyContent;
use Bitweaver\Fisheye\FisheyeImage;

class FisheyeAuthorWorkbench {
	public const CATALOGUE = 'AuthorCatalogue.csv';
	/** A proposed series with at least this many books is preticked. */
	public const FOLDER_PRETICK_MIN = 3;
	public const SOURCES_PRETICK_MIN = 2;
	/** Open Library lookups per press - each is a couple of requests. */
	public const LOOKUP_BATCH = 10;

	/** The decode every posted value needs: detoxify() HTML-escapes $_REQUEST, and names can hold &, < or >. */
	private static function decode( $pValue ): string {
		return htmlspecialchars_decode( trim( (string)$pValue ), ENT_NOQUOTES );
	}

	/** The author's folder under the Library root (its name), or null: the gallery's title, else the closest spelling. */
	public static function folderFor( string $pTitle ): ?string {
		$root = FisheyeBookFiles::libraryRoot();
		if( $root === '' || !is_dir( $root ) ) {
			return null;
		}
		if( $pTitle !== '' && $pTitle[0] !== '.' && is_dir( $root.$pTitle ) ) {
			return $pTitle;
		}
		$dirs = array_values( array_filter( scandir( $root ) ?: [], fn( $d ) => $d[0] !== '.' && $d !== FisheyeMediaGallery::VARIOUS_AUTHORS && is_dir( $root.$d ) ) );
		return FisheyeSeries::guessAuthorFolder( $pTitle, $dirs );
	}

	/** @return list<array{content_id:int,title:string,event_time:int}>  the books directly in a gallery */
	public static function directBooks( int $pContainerId ): array {
		global $gBitDb;
		$rows = $gBitDb->getAll(
			"SELECT lc.`content_id`, lc.`title`, lc.`event_time` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` fgim
			 INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = fgim.`item_content_id`
			 WHERE fgim.`gallery_content_id` = ? AND lc.`content_type_guid` = 'fisheyebook'", [ $pContainerId ] ) ?: [];
		return array_map( fn( $r ) => [ 'content_id' => (int)$r['content_id'], 'title' => (string)$r['title'], 'event_time' => (int)$r['event_time'] ], $rows );
	}

	/** @return list<array{content_id:int,title:string,count:int}>  the series galleries directly under a gallery */
	public static function children( int $pContainerId ): array {
		global $gBitDb;
		$rows = $gBitDb->getAll(
			"SELECT lc.`content_id`, lc.`title` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` fgim
			 INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = fgim.`item_content_id`
			 WHERE fgim.`gallery_content_id` = ? AND lc.`content_type_guid` = 'fisheyeseries'", [ $pContainerId ] ) ?: [];
		usort( $rows, fn( $a, $b ) => strnatcasecmp( $a['title'], $b['title'] ) );
		return array_map( fn( $r ) => [ 'content_id' => (int)$r['content_id'], 'title' => (string)$r['title'],
			'count' => (int)$gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` WHERE `gallery_content_id` = ?", [ (int)$r['content_id'] ] ) ], $rows );
	}

	/** Every book in a gallery and in the series under it, for the files step. @return list<array{book:int,title:string}> */
	private static function allBooks( int $pContainerId ): array {
		$out = [];
		self::collectBooks( $pContainerId, 3, $out );
		return array_values( $out );
	}

	/** Books keyed by content id, so a book met twice is one and a second series cannot overwrite the first's slots. */
	private static function collectBooks( int $pContainerId, int $pDepth, array &$pOut ): void {
		foreach( self::directBooks( $pContainerId ) as $book ) {
			$pOut[$book['content_id']] = [ 'book' => $book['content_id'], 'title' => $book['title'] ];
		}
		if( $pDepth > 0 ) {
			foreach( self::children( $pContainerId ) as $child ) {
				self::collectBooks( $child['content_id'], $pDepth - 1, $pOut );
			}
		}
	}

	/**
	 * Book folders that are not where the series put the book. The tree follows the galleries (Author/Series/Book), so a book moved into a series
	 * has its folder looked for there; when the folder is still where the book used to be, it is proposed to move. A book is proposed only when exactly
	 * one folder with its name is waiting and the place for it is free.
	 *
	 * @param array $pStatus  from status(), the files step computed
	 * @return list<array{id:int,title:string,from:string,to:string}>  paths relative to the Library root
	 */
	public static function folderMoves( array $pStatus ): array {
		$root = FisheyeBookFiles::libraryRoot();
		$moves = [];
		if( $pStatus['files']['blocked'] || !$pStatus['folder'] || $root === '' ) {
			return $moves;
		}
		foreach( $pStatus['files']['noFolder'] as $missing ) {
			$book = FisheyeImage::lookup( [ 'content_id' => $missing['id'] ] );
			$candidates = $book instanceof FisheyeBook ? FisheyeBookFiles::candidateFolders( $book ) : [];
			if( !$candidates ) {
				continue;
			}
			$to = $candidates[0];
			$name = basename( rtrim( $to, '/' ) );
			$sources = array_values( array_filter( $pStatus['files']['unmatched'], fn( $u ) => basename( $u ) === $name ) );
			if( count( $sources ) === 1 && !is_dir( $root.$to ) && is_dir( $root.$pStatus['folder'].'/'.$sources[0] ) ) {
				$moves[] = [ 'id' => $missing['id'], 'title' => $missing['title'], 'from' => $pStatus['folder'].'/'.$sources[0], 'to' => rtrim( $to, '/' ) ];
			}
		}
		return $moves;
	}

	/**
	 * Move the ticked folders to where their series expects them (see folderMoves()); recomputed now, only a proposed move of a listed book is made.
	 *
	 * @param list<int> $pBookIds
	 * @return array{moved:int,errors:list<string>}
	 */
	public static function applyMoves( array $pStatus, array $pBookIds ): array {
		$root = FisheyeBookFiles::libraryRoot();
		$done = [ 'moved' => 0, 'errors' => [] ];
		foreach( self::folderMoves( $pStatus ) as $move ) {
			if( !in_array( $move['id'], $pBookIds, true ) ) {
				continue;
			}
			$target = $root.$move['to'];
			if( !is_dir( dirname( $target ) ) && !@mkdir( dirname( $target ), 0775, true ) ) {
				$done['errors'][] = $move['title'].' - could not make '.dirname( $move['to'] );
			} elseif( !@rename( $root.$move['from'], $target ) ) {
				$done['errors'][] = $move['title'].' - could not move '.$move['from'];
			} else {
				$done['moved']++;
			}
		}
		return $done;
	}

	/**
	 * Where the author stands.
	 *
	 * @return array<string,mixed>
	 */
	public static function status( int $pContentId ): array {
		global $gBitDb;
		$author = LibertyContent::getLibertyObject( $pContentId );
		$name = $author && $author->isValid() ? (string)$author->getTitle() : '';
		$folder = $name !== '' ? self::folderFor( $name ) : null;
		$abs = $folder !== null ? FisheyeBookFiles::libraryRoot().$folder.'/' : '';
		$csv = $abs !== '' ? $abs.self::CATALOGUE : '';
		$st = [ 'contentId' => $pContentId, 'name' => $name, 'folder' => $folder, 'abs' => $abs, 'error' => null, 'hasCatalogue' => $csv !== '' && is_file( $csv ),
			'parsed' => [], 'rows' => [], 'total' => 0, 'loaded' => 0, 'missing' => [], 'noIsbn' => 0, 'galleryId' => 0, 'galleryUrl' => '', 'qid' => '', 'hasContact' => false,
			'catalogue' => [ 'filled' => 0, 'added' => [], 'conflicts' => [], 'changes' => 0, 'error' => null ], 'direct' => [], 'children' => [], 'proposal' => [ 'series' => [], 'unmatched' => [], 'empty' => [] ], 'singleFolders' => 0, 'moves' => [], 'seriesPending' => false,
			'files' => [ 'blocked' => true, 'todo' => 0, 'books' => [], 'noFolder' => [], 'unmatched' => [] ], 'done' => false, 'next' => 'books' ];
		if( $name === '' ) {
			$st['error'] = 'That is not an author gallery.';
			return $st;
		}
		$st['galleryId'] = (int)$gBitDb->getOne( "SELECT `gallery_id` FROM `".BIT_DB_PREFIX."fisheye_gallery` WHERE `content_id` = ?", [ $pContentId ] );
		$st['galleryUrl'] = (string)$author->getDisplayUrl();
		$contactId = (int)$author->getPreference( 'author_contact_id' );
		$st['hasContact'] = (bool)$contactId;
		$st['qid'] = $contactId ? (string)$gBitDb->getOne( "SELECT `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'wikidata' AND `end_date` IS NULL", [ $contactId ] ) : '';

		// 1. Books: the catalogue's rows against the database. A row with no ISBN is matched by title and first author.
		if( $st['hasCatalogue'] ) {
			$parsed = FisheyeBookCatalogue::readBooks( $csv );
			if( isset( $parsed['error'] ) ) {
				$st['error'] = $parsed['error'];
				return $st;
			}
			$st['parsed'] = $parsed['rows'];
			foreach( $parsed['rows'] as $i => $row ) {
				$id = FisheyeBook::findContentId( $row ) ?? ( $row['isbn13'] !== '' ? FisheyeBook::findContentId( [ 'isbn13' => '' ] + $row ) : null );
				$primary = FisheyeBookCredits::canonicalName( FisheyeBookCatalogue::primaryAuthor( $row ) );
				$st['rows'][$i] = [ 'title' => $row['title'], 'authors' => implode( ', ', $row['authors'] ), 'isbn' => $row['isbn13'], 'book' => $id,
					'filedUnder' => $primary !== '' && mb_strtolower( $primary ) !== mb_strtolower( $name ) ? $primary : '',
					'titleTerm' => rawurlencode( $row['title'] ), 'authorTerm' => rawurlencode( (string)( $row['authors'][0] ?? '' ) ),
					'terms' => rawurlencode( trim( $row['title'].' '.( $row['authors'][0] ?? '' ) ) ) ];
				$st['total']++;
				$st['noIsbn'] += $row['isbn13'] === '' ? 1 : 0;
				if( $id ) {
					$st['loaded']++;
				} else {
					$st['missing'][] = $i;
				}
			}
		}

		$st['catalogue'] = self::catalogueDrift( $folder, $csv, $st['hasCatalogue'] );

		// A missing row that looks like a book the author already has under a fuller title ("His Dark Materials Northern Lights" for "Northern Lights") is
		// shown against it and left unticked, so it is a choice and not a second copy.
		if( $st['missing'] ) {
			$have = self::allBooks( $pContentId );
			foreach( $st['missing'] as $i ) {
				$st['rows'][$i]['maybe'] = self::lookalike( $st['rows'][$i]['title'], $have );
			}
		}

		// 2. Series: the books still directly in the author's gallery, the series under it, and what the folders propose.
		$st['direct'] = self::directBooks( $pContentId );
		$st['children'] = self::children( $pContentId );
		if( $st['direct'] && $abs !== '' && is_dir( $abs ) ) {
			$st['proposal'] = self::annotate( FisheyeSeries::proposeFromFolder( $abs, $st['direct'] ), $st['children'] );
			// A folder holding one of the author's books is just that book, not a series of one: counted, not offered.
			$st['singleFolders'] = count( array_filter( $st['proposal']['series'], fn( $e ) => count( $e['books'] ) < 2 ) );
			$st['proposal']['series'] = array_values( array_filter( $st['proposal']['series'], fn( $e ) => count( $e['books'] ) >= 2 ) );
		}
		$st['seriesPending'] = (bool)$st['proposal']['series'];

		// 3. Files: once the series are sorted, since a book's folder is found through its galleries.
		if( !$st['missing'] && !$st['seriesPending'] && $folder !== null ) {
			$st['files'] = FisheyeSeriesWorkbench::filesStatus( self::allBooks( $pContentId ), $folder.'/' );
			$st['moves'] = self::folderMoves( $st );
		}

		if( $st['missing'] ) {
			$st['next'] = 'books';
		} elseif( $st['seriesPending'] ) {
			$st['next'] = 'series';
		} elseif( $st['files']['todo'] ) {
			$st['next'] = 'files';
		} else {
			$st['next'] = 'done';
			$st['done'] = true;
		}
		return $st;
	}

	/**
	 * What bringing the catalogue file up to date from the database would do (FisheyeCatalogueMerge::plan(), read-only): empty cells it would fill, books
	 * it would add, and values the file and the database disagree on (reported, never changed).
	 */
	public static function catalogueDrift( ?string $pFolder, string $pPath, bool $pExists ): array {
		$none = [ 'filled' => 0, 'added' => [], 'conflicts' => [], 'changes' => 0, 'error' => null ];
		if( $pFolder === null || !$pExists ) {
			return $none;
		}
		$plan = FisheyeCatalogueMerge::plan( [ 'kind' => 'author', 'name' => $pFolder, 'path' => $pPath, 'exists' => true ] );
		return [ 'filled' => count( $plan['filled'] ), 'added' => $plan['added'], 'conflicts' => $plan['conflicts'], 'changes' => $plan['changes'], 'error' => $plan['error'] ];
	}

	/**
	 * The author's book a catalogue title probably is: the same words with a series name in front ("His Dark Materials Northern Lights"), or a
	 * title that is at least 70% the same as the end of one (a typo in either).
	 *
	 * @param list<array{book:int,title:string}> $pBooks
	 * @return ?array{id:int,title:string}
	 */
	private static function lookalike( string $pTitle, array $pBooks ): ?array {
		$key = fn( string $t ): string => preg_replace( '/[^a-z0-9]/', '', mb_strtolower( $t ) );
		$want = $key( $pTitle );
		if( strlen( $want ) < 6 ) {
			return null;
		}
		foreach( $pBooks as $book ) {
			$have = $key( $book['title'] );
			if( strlen( $have ) < strlen( $want ) ) {
				continue;
			}
			$tail = substr( $have, -strlen( $want ) );
			similar_text( $tail, $want, $percent );
			if( $tail === $want || $percent >= 70 ) {
				return [ 'id' => $book['book'], 'title' => $book['title'] ];
			}
		}
		return null;
	}

	/** A folder proposal with the year of each book and the existing series of this author it would go into (the same name, else one the name starts with). */
	private static function annotate( array $pProposal, array $pChildren ): array {
		foreach( $pProposal['series'] as $i => $entry ) {
			$key = FisheyeSeries::seriesKey( $entry['name'] );
			$pProposal['series'][$i]['target'] = 0;
			$pProposal['series'][$i]['similar'] = false;
			$pProposal['series'][$i]['books'] = array_map( fn( $b ) => $b + [ 'year' => $b['event_time'] ? gmdate( 'Y', $b['event_time'] ) : '' ], $entry['books'] );
			foreach( $pChildren as $target ) {
				$targetKey = FisheyeSeries::seriesKey( $target['title'] );
				if( $targetKey === $key ) {
					$pProposal['series'][$i]['target'] = $target['content_id'];
					$pProposal['series'][$i]['similar'] = false;
					break;
				}
				if( !$pProposal['series'][$i]['target'] && $key !== '' && $targetKey !== '' && str_starts_with( $key, $targetKey ) ) {
					$pProposal['series'][$i]['target'] = $target['content_id'];
					$pProposal['series'][$i]['similar'] = true;
				}
			}
		}
		return $pProposal;
	}

	/**
	 * Opening an author files the books already loaded that credit them as a co-author (their first author's gallery keeps them too).
	 * @return int  books filed now
	 */
	public static function fileCredited( array $pStatus ): int {
		if( !$pStatus['parsed'] ) {
			return 0;
		}
		return FisheyeBookCredits::fileCreditedBooks( $pStatus['parsed'], (int)$pStatus['contentId'], $pStatus['name'] );
	}

	/**
	 * Step 2a: make the ticked series from the folders. Recomputed from the disk and the database now; the form only says which folders, what
	 * to call them and where to put them.
	 *
	 * @return array{created:list<array>,errors:list<string>}
	 */
	public static function createFromFolders( array $pStatus, array $pReq ): array {
		$result = [ 'created' => [], 'errors' => [] ];
		$author = LibertyContent::getLibertyObject( (int)$pStatus['contentId'] );
		if( !$author || !$author->isValid() || $pStatus['abs'] === '' ) {
			return $result;
		}
		$byFolder = [];
		foreach( FisheyeSeries::proposeFromFolder( $pStatus['abs'], self::directBooks( (int)$pStatus['contentId'] ) )['series'] as $entry ) {
			$byFolder[$entry['folder']] = $entry;
		}
		$existing = array_column( self::children( (int)$pStatus['contentId'] ), 'content_id' );
		foreach( (array)( $pReq['fs_create'] ?? [] ) as $i => $ticked ) {
			$seriesFolder = self::decode( $pReq['fs_folder'][$i] ?? '' );
			$entry = $byFolder[$seriesFolder] ?? null;
			if( !$entry ) {
				$result['errors'][] = $seriesFolder.' - no longer proposed';
				continue;
			}
			$name = self::decode( $pReq['fs_name'][$i] ?? '' ) ?: $entry['name'];
			$targetId = (int)( $pReq['fs_target'][$i] ?? 0 );
			if( $targetId && in_array( $targetId, $existing, true ) ) {
				$series = new FisheyeSeries( null, $targetId );
				$series->load();
				$name = $series->getTitle();
				$already = true;
			} else {
				$into = FisheyeSeries::findOrCreateUnder( $name, (int)$pStatus['contentId'] );
				if( !$into['series'] ) {
					$result['errors'][] = $name.' - '.$into['error'];
					continue;
				}
				$series = $into['series'];
				$already = !$into['created'];
			}
			$moved = 0;
			foreach( $entry['books'] as $book ) {
				$series->addItem( $book['content_id'] );
				// Leaves the author's own list only once it really is in the series.
				if( $series->isInGallery( $series->mContentId, $book['content_id'] ) ) {
					$author->removeItem( $book['content_id'] );
					$moved++;
				}
			}
			$series->renumberByPublication();
			$result['created'][] = [ 'name' => $name, 'moved' => $moved, 'merged' => $already, 'nested' => false ];
		}
		return $result;
	}

	/** The author's directly-held books with the series text Open Library gave each, for the Wikidata proposal. */
	private static function booksWithSeriesText( int $pContentId ): array {
		global $gBitDb;
		$books = [];
		foreach( self::directBooks( $pContentId ) as $book ) {
			$text = $gBitDb->getOne( "SELECT `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'series' AND `end_date` IS NULL", [ $book['content_id'] ] );
			$books[] = $book + [ 'ol_series' => (string)$text ];
		}
		return $books;
	}

	/**
	 * Step 2b: ask Open Library again about up to LOOKUP_BATCH books with no series text yet (what the Wikidata proposal matches against).
	 * @return array{done:int,with:int,errors:list<string>}
	 */
	public static function lookupSeriesText( int $pContentId ): array {
		$lookup = [ 'done' => 0, 'with' => 0, 'errors' => [] ];
		foreach( self::booksWithSeriesText( $pContentId ) as $book ) {
			if( $lookup['done'] >= self::LOOKUP_BATCH ) {
				break;
			}
			if( $book['ol_series'] !== '' ) {
				continue;
			}
			$item = FisheyeImage::lookup( [ 'content_id' => $book['content_id'] ] );
			if( $item && $item->isValid() && method_exists( $item, 'reloadFromOpenLibrary' ) ) {
				$found = $item->reloadFromOpenLibrary();
				$lookup['done']++;
				if( !empty( $found['error'] ) ) {
					$lookup['errors'][] = $book['title'].' - '.$found['error'];
				}
			}
		}
		$lookup['with'] = count( array_filter( self::booksWithSeriesText( $pContentId ), fn( $b ) => $b['ol_series'] !== '' ) );
		return $lookup;
	}

	/**
	 * The series Wikidata and Open Library propose for the books still in the author's own list. Asks Wikidata, so only when wanted.
	 *
	 * @param ?list<string> $pSplit  umbrella series kept split, null for their defaults
	 * @return array{works:int,failed:bool,proposal:?array,umbrellas:list<array>,split:list<string>,directCount:int,noSeriesText:int}
	 */
	public static function sourcesProposal( array $pStatus, ?array $pSplit ): array {
		$direct = self::booksWithSeriesText( (int)$pStatus['contentId'] );
		$out = [ 'works' => 0, 'failed' => false, 'proposal' => null, 'umbrellas' => [], 'split' => [], 'directCount' => count( $direct ),
			'noSeriesText' => count( array_filter( $direct, fn( $b ) => $b['ol_series'] === '' ) ) ];
		if( $pStatus['qid'] === '' ) {
			return $out;
		}
		$works = FisheyeSeries::wikidataWorks( $pStatus['qid'] );
		if( $works === null ) {
			$out['failed'] = true;
			return $out;
		}
		$out['works'] = count( $works );
		$out['umbrellas'] = FisheyeSeries::umbrellas( $works );
		$out['split'] = $pSplit ?? array_column( array_filter( $out['umbrellas'], fn( $u ) => $u['default'] ), 'key' );
		$proposal = FisheyeSeries::proposeFromSources( $direct, $works, $out['split'] );
		foreach( $proposal['series'] as $i => $entry ) {
			foreach( [ 'books', 'by_date', 'by_number' ] as $field ) {
				if( $proposal['series'][$i][$field] !== null ) {
					$proposal['series'][$i][$field] = array_map( fn( $b ) => $b + [ 'year' => substr( (string)$b['date'], 0, 4 ) ], $proposal['series'][$i][$field] );
				}
			}
		}
		$out['proposal'] = $proposal;
		return $out;
	}

	/**
	 * Step 2b: make the ticked series from the Wikidata / Open Library proposal, recomputed now.
	 * @return array{created:list<array>,errors:list<string>}
	 */
	public static function createFromSources( array $pStatus, array $pReq, ?array $pSplit ): array {
		$result = [ 'created' => [], 'errors' => [] ];
		$author = LibertyContent::getLibertyObject( (int)$pStatus['contentId'] );
		$wanted = self::sourcesProposal( $pStatus, $pSplit );
		if( !$author || !$author->isValid() || !$wanted['proposal'] ) {
			return $result;
		}
		$posted = [];
		foreach( (array)( $pReq['ws_create'] ?? [] ) as $i => $ticked ) {
			$posted[self::decode( $pReq['ws_key'][$i] ?? '' )] = $i;
		}
		$made = [];
		// The proposal lists an umbrella before what is inside it, so a parent's gallery exists when its child is made.
		foreach( $wanted['proposal']['series'] as $entry ) {
			if( !isset( $posted[$entry['key']] ) ) {
				continue;
			}
			$i = $posted[$entry['key']];
			$name = self::decode( $pReq['ws_name'][$i] ?? '' ) ?: $entry['name'];
			$order = self::decode( $pReq['ws_order'][$i] ?? '' ) === 'number' && $entry['by_number'] ? 'by_number' : 'by_date';
			$parentId = $entry['parent'] !== null && isset( $made[$entry['parent']] ) ? $made[$entry['parent']] : (int)$pStatus['contentId'];
			$into = FisheyeSeries::findOrCreateUnder( $name, $parentId );
			if( !$into['series'] ) {
				$result['errors'][] = $name.' - '.$into['error'];
				continue;
			}
			$series = $into['series'];
			$made[$entry['key']] = (int)$series->mContentId;
			$moved = 0;
			$ordered = [];
			foreach( $entry[$order] as $book ) {
				$series->addItem( $book['content_id'] );
				if( $series->isInGallery( $series->mContentId, $book['content_id'] ) ) {
					$author->removeItem( $book['content_id'] );
					$ordered[] = $book['content_id'];
					$moved++;
					$filed = FisheyeImage::lookup( [ 'content_id' => (int)$book['content_id'] ] );
					if( $filed && $filed->isValid() && method_exists( $filed, 'recordWikidataSeries' ) && in_array( 'Wikidata', (array)( $book['sources'] ?? [] ), true ) ) {
						$filed->recordWikidataSeries( $name, $book['wd_ord'] ?? null, (string)( $book['work_q'] ?? '' ) );
					}
				}
			}
			// A series made now takes the order chosen. One that was already there holds books of its own that setOrder() would tie with the new
			// ones (it numbers only the books it is given, from 1), so the whole series is put in publication order instead.
			if( $into['created'] ) {
				$series->setOrder( $ordered );
			} else {
				$series->renumberByPublication();
			}
			$result['created'][] = [ 'name' => $name, 'moved' => $moved, 'merged' => !$into['created'], 'nested' => $parentId !== (int)$pStatus['contentId'] ];
		}
		return $result;
	}
}

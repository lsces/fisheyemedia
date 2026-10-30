<?php
/**
 * One Various Authors series at a time, from its SeriesCatalogue.csv in the Library tree: the books still to load, the series filing, and the
 * files of its books, in that order - the steps the loader, Various Authors and Book Files pages each do for everything at once.
 *
 * Nothing here is new logic: the missing books go through FisheyeBookLoader::loadGroup() (the catalogue's own ISBN, so a row cannot be loaded
 * under another number), the filing is FisheyeVariousAuthors::apply(), the files FisheyeBookFiles::register(). What this adds is the state of
 * each step worked out from the database and the tree, so a series says what is left and what is done and the finished ones can be put away.
 * The order matters: a Various Authors book's folder is found by walking up its galleries, so its files can only be registered once the
 * series is filed.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeImage;

class FisheyeSeriesWorkbench {
	/** Books loaded per press, as the loader pages do. */
	public const BATCH = 10;
	/** Books whose files are registered per press. */
	public const FILES_BATCH = 20;

	/**
	 * Where a series stands.
	 *
	 * @return array{name:string,error:?string,plan:array,parsed:list<array>,rows:list<array>,total:int,loaded:int,missing:list<int>,
	 *               differs:list<array>,filing:array{pending:bool,notes:list<string>},files:array,done:bool,next:string}
	 */
	public static function status( string $pName, string $pFile ): array {
		global $gBitDb;
		$plan = FisheyeVariousAuthors::plan( $pName, $pFile );
		$st = [ 'name' => $pName, 'error' => $plan['error'], 'plan' => $plan, 'parsed' => [], 'rows' => [], 'total' => 0, 'loaded' => 0, 'missing' => [], 'differs' => [],
			'seriesUrl' => '', 'filing' => [ 'pending' => false, 'notes' => [] ], 'files' => [ 'blocked' => true, 'todo' => 0, 'books' => [], 'noFolder' => [], 'unmatched' => [] ], 'done' => false, 'next' => 'books' ];
		if( $plan['error'] ) {
			return $st;
		}
		$parsed = FisheyeBookCatalogue::readBooks( $pFile );
		$st['parsed'] = $parsed['rows'] ?? [];
		// The series' own gallery under Various Authors, once it exists.
		$st['seriesUrl'] = '';
		if( $plan['seriesId'] && ( $seriesGallery = \Bitweaver\Liberty\LibertyContent::getLibertyObject( (int)$plan['seriesId'] ) ) && $seriesGallery->isValid() ) {
			$st['seriesUrl'] = (string)$seriesGallery->getDisplayUrl();
		}
		foreach( $plan['rows'] as $i => $row ) {
			$dbIsbn = $row['book'] ? (string)$gBitDb->getOne( "SELECT MIN( `xkey` ) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'isbn' AND `end_date` IS NULL", [ (int)$row['book'] ] ) : '';
			$st['rows'][$i] = $row + [ 'dbIsbn' => $dbIsbn ];
			$st['total']++;
			if( $row['book'] ) {
				$st['loaded']++;
				if( $row['isbn'] !== '' && $dbIsbn !== '' && $row['isbn'] !== $dbIsbn ) {
					$st['differs'][] = [ 'title' => $row['title'], 'sheet' => $row['isbn'], 'db' => $dbIsbn ];
				}
			} else {
				$st['missing'][] = $i;
			}
		}

		// Filing: what applying the series would still change.
		$notes = [];
		if( !$plan['seriesExists'] ) {
			$notes[] = 'The series gallery under Various Authors is still to be made.';
		} else {
			$loose = 0;
			foreach( $st['rows'] as $row ) {
				if( $row['book'] && !$gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` WHERE `gallery_content_id` = ? AND `item_content_id` = ?", [ $plan['seriesId'], (int)$row['book'] ] ) ) {
					$loose++;
				}
			}
			if( $loose ) {
				$notes[] = $loose.' loaded book'.( $loose === 1 ? ' is' : 's are' ).' not in the series yet.';
			}
		}
		foreach( $plan['authorSeries'] as $own ) {
			if( !$own['content_id'] ) {
				$notes[] = $own['author'].': the author\'s own series gallery is still to be made.';
			} elseif( !$own['linked'] ) {
				$notes[] = $own['author'].': the author\'s series is still to be linked under it.';
			}
			if( $own['toMove'] ) {
				$notes[] = $own['author'].': '.count( $own['toMove'] ).' book'.( count( $own['toMove'] ) === 1 ? '' : 's' ).' loose in their own list could move into their series.';
			}
		}
		$st['filing'] = [ 'pending' => (bool)$notes, 'notes' => $notes ];

		// Files: only once the series is filed, since a book's folder is found through its galleries.
		if( !$st['filing']['pending'] ) {
			$st['files'] = self::filesStatus( $pName, $st['rows'] );
		}

		if( $st['missing'] ) {
			$st['next'] = 'books';
		} elseif( $st['filing']['pending'] ) {
			$st['next'] = 'series';
		} elseif( $st['files']['todo'] ) {
			$st['next'] = 'files';
		} else {
			$st['next'] = 'done';
			$st['done'] = true;
		}
		return $st;
	}

	/** The files step: for each loaded book, its folder, what is in it and how many rows it has already. */
	private static function filesStatus( string $pName, array $pRows ): array {
		global $gBitDb;
		$files = [ 'blocked' => false, 'todo' => 0, 'books' => [], 'noFolder' => [], 'unmatched' => [] ];
		$matched = [];
		foreach( $pRows as $row ) {
			if( !$row['book'] ) {
				continue;
			}
			$book = FisheyeImage::lookup( [ 'content_id' => (int)$row['book'] ] );
			if( !( $book instanceof FisheyeBook ) ) {
				continue;
			}
			$preview = FisheyeBookFiles::preview( $book );
			$have = (int)$gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` IN ('ebook','audiobook','extra') AND `end_date` IS NULL", [ (int)$row['book'] ] );
			if( $preview['folder'] === null ) {
				$files['noFolder'][] = [ 'id' => (int)$row['book'], 'title' => $row['title'] ];
				continue;
			}
			$matched[rtrim( $preview['folder'], '/' )] = true;
			$found = $preview['ebook'] + $preview['audiobook'] + $preview['extra'];
			$todo = $found > 0 && $found !== $have;
			$files['todo'] += $todo ? 1 : 0;
			$files['books'][] = [ 'id' => (int)$row['book'], 'title' => $row['title'], 'folder' => $preview['folder'], 'ebook' => $preview['ebook'], 'audiobook' => $preview['audiobook'],
				'extra' => $preview['extra'], 'have' => $have, 'todo' => $todo ];
		}
		// Folders in the series folder that no loaded book resolved to: the books still to catalogue.
		$base = FisheyeBookFiles::libraryRoot().FisheyeMediaGallery::VARIOUS_AUTHORS.'/'.$pName.'/';
		if( is_dir( $base ) ) {
			foreach( array_diff( scandir( $base ) ?: [], [ '.', '..' ] ) as $dir ) {
				if( $dir[0] !== '.' && is_dir( $base.$dir ) && !in_array( strtolower( $dir ), [ 'pictures', 'audio' ], true ) && !isset( $matched[FisheyeMediaGallery::VARIOUS_AUTHORS.'/'.$pName.'/'.$dir] ) ) {
					$files['unmatched'][] = $dir;
				}
			}
		}
		return $files;
	}

	/**
	 * Step 1: load the picked missing rows (indexes into the catalogue). Each book is filed in its primary author's Library gallery, as Add
	 * books from a sheet does; the series step then puts it in the series.
	 *
	 * @param list<int> $pPicked
	 * @return list<array>  the loader's result per author gallery
	 */
	public static function loadMissing( array $pStatus, array $pPicked ): array {
		$groups = [];
		foreach( $pPicked as $i ) {
			if( !in_array( $i, $pStatus['missing'], true ) || !isset( $pStatus['parsed'][$i] ) ) {
				continue;
			}
			$row = $pStatus['parsed'][$i];
			$author = FisheyeBookCredits::canonicalName( FisheyeBookCatalogue::primaryAuthor( $row ) );
			if( $author !== '' && FisheyeBookCredits::libraryGalleryFor( $author ) ) {
				$groups['a|'.mb_strtolower( $author )] ??= [ 'kind' => 'author', 'name' => $author, 'rows' => [] ];
				$groups['a|'.mb_strtolower( $author )]['rows'][] = $row;
				continue;
			}
			foreach( FisheyeBookCatalogue::plan( [ $row ] ) as $key => $group ) {
				$groups[$key] ??= [ 'kind' => $group['kind'], 'name' => $group['name'], 'rows' => [] ];
				$groups[$key]['rows'][] = $row;
			}
		}
		$results = [];
		$left = self::BATCH;
		foreach( $groups as $group ) {
			if( $left <= 0 ) {
				break;
			}
			$one = FisheyeBookLoader::loadGroup( $group, null, false, null, $left );
			$left -= $one['created'] + $one['already'] + count( $one['errors'] );
			$results[] = $one;
		}
		return $results;
	}

	/** Step 2: file the series, moving the loose books of the authors given. */
	public static function fileSeries( array $pStatus, array $pMoveAuthors ): array {
		return FisheyeVariousAuthors::apply( $pStatus['plan'], $pMoveAuthors );
	}

	/**
	 * Step 3: register the files of the picked books.
	 *
	 * @return array{books:int,files:int,errors:list<string>}
	 */
	public static function registerFiles( array $pBookIds ): array {
		$done = [ 'books' => 0, 'files' => 0, 'errors' => [] ];
		foreach( array_slice( $pBookIds, 0, self::FILES_BATCH ) as $id ) {
			$book = FisheyeImage::lookup( [ 'content_id' => $id ] );
			if( !( $book instanceof FisheyeBook ) ) {
				$done['errors'][] = $id.' - not a book.';
				continue;
			}
			$one = FisheyeBookFiles::register( $book );
			if( $one['folder'] === null ) {
				$done['errors'][] = $book->getTitle().' - no folder found, nothing changed.';
				continue;
			}
			$done['books']++;
			$done['files'] += $one['ebook'] + $one['audiobook'] + $one['extra'];
			foreach( $one['errors'] as $error ) {
				$done['errors'][] = $book->getTitle().' - '.$error;
			}
		}
		return $done;
	}
}

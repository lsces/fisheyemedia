<?php
/**
 * Book series - a gallery of books under their author, extends FisheyeMediaGallery with
 * content_type_guid='fisheyeseries'.
 *
 * A phantom subclass exactly as FisheyeProgram is for a TV show: it exists so a series can carry its
 * own xref vocabulary (a Wikidata id, a status, an ISFDB link) and a description of its own, while
 * remaining a real gallery that holds its books. Books are ordered by their item_position in the
 * gallery (a float, so a missed volume can be slotted in between two others), and a sub-series nests
 * inside its umbrella as another series gallery.
 *
 * Also holds the folder-driven proposal logic: an author's folder of series folders (each holding that
 * series' files) is matched against the author's loaded books by title, to suggest which books make
 * up which series. Proposing never writes anything.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

define( 'FISHEYESERIES_CONTENT_TYPE_GUID', 'fisheyeseries' );

#[\AllowDynamicProperties]
class FisheyeSeries extends FisheyeMediaGallery {

	// A title shorter than this (as a key) is too likely to turn up inside an unrelated file name.
	const MIN_TITLE_KEY_LENGTH = 4;
	// How deep inside a series folder file and folder names are read for titles.
	const FOLDER_SCAN_DEPTH = 4;
	// A folder naming this many of an author's books is a collection (a whole-library or omnibus folder)
	// rather than one series - proposed, but not preticked.
	const COLLECTION_FOLDER_MIN_NAMED = 20;
	// A title this long may be matched with a spelling slip or two ("Descision at Doona").
	const FUZZY_MIN_KEY_LENGTH = 8;

	public function __construct( $pGalleryId = null, $pContentId = null ) {
		parent::__construct( $pGalleryId, $pContentId );
		$this->mContentTypeGuid = FISHEYESERIES_CONTENT_TYPE_GUID;
		$this->registerContentType( FISHEYESERIES_CONTENT_TYPE_GUID, [
			'content_type_guid' => FISHEYESERIES_CONTENT_TYPE_GUID,
			'content_name'      => 'Book Series',
			'handler_class'     => 'FisheyeSeries',
			'handler_package'   => 'fisheyemedia',
			'handler_file'      => 'FisheyeSeries.php',
			'maintainer_url'    => 'https://www.bitweaver.org',
		] );
	}

	/**
	 * A title reduced to what it is compared by: lower case, letters and digits only, with a leading
	 * "The"/"A"/"An" dropped (file names and catalogues disagree about those).
	 */
	public static function titleKey( string $pTitle ): string {
		$title = mb_strtolower( trim( $pTitle ) );
		$title = preg_replace( '/^(the|a|an)\s+/u', '', $title );
		return preg_replace( '/[^\p{L}\p{N}]+/u', '', $title );
	}

	/**
	 * Put this series' books in publication order, positions 1..n - an undated book last. Used when books
	 * are added from a folder, so a late addition lands in the right place whatever order it arrived in.
	 * Overwrites any hand-made order, so only a series just built or extended from a folder is renumbered.
	 *
	 * @return int  how many books were numbered
	 */
	public function renumberByPublication(): int {
		$books = $this->mDb->getAll(
			"SELECT lc.`content_id`, lc.`title`, lc.`event_time` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` fgim
			 INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = fgim.`item_content_id`
			 WHERE fgim.`gallery_content_id` = ? AND lc.`content_type_guid` = 'fisheyebook'",
			[ $this->mContentId ]
		);
		usort( $books, fn( $a, $b ) => [ (int)$a['event_time'] ?: PHP_INT_MAX, mb_strtolower( $a['title'] ) ] <=> [ (int)$b['event_time'] ?: PHP_INT_MAX, mb_strtolower( $b['title'] ) ] );
		foreach( $books as $i => $book ) {
			$this->mDb->getOne(
				"UPDATE `".BIT_DB_PREFIX."fisheye_gallery_image_map` SET `item_position` = ? WHERE `gallery_content_id` = ? AND `item_content_id` = ?",
				[ $i + 1, $this->mContentId, $book['content_id'] ]
			);
		}
		return count( $books );
	}

	/**
	 * The keys a title is looked for by, most exact first: the whole title, then - for a title written in
	 * two forms ("Treaty at Doona / Treaty Planet", "Red Star Rising / Dragonseye", "First Warning: Acorna's
	 * Children") - each part on its own. A later tier is only tried when an earlier one finds nothing.
	 *
	 * @return list<list<string>>
	 */
	public static function titleKeyTiers( string $pTitle ): array {
		$tiers = [ [ self::titleKey( $pTitle ) ] ];
		$parts = preg_split( '~\s*(?:/|:|&|\s-\s)\s*~u', $pTitle );
		if( count( $parts ) > 1 ) {
			$tiers[] = array_values( array_unique( array_map( [ self::class, 'titleKey' ], $parts ) ) );
		}
		return $tiers;
	}

	/**
	 * The folder names directly under the storage root's Library/ - one per author, "Surname, First".
	 *
	 * @return list<string>
	 */
	public static function libraryFolders(): array {
		$root = \Bitweaver\Liberty\mime_film_get_storage_root();
		$dir = $root.'Library/';
		if( empty( $root ) || !is_dir( $dir ) ) {
			return [];
		}
		$folders = [];
		foreach( scandir( $dir ) ?: [] as $entry ) {
			if( !str_starts_with( $entry, '.' ) && is_dir( $dir.$entry ) ) {
				$folders[] = $entry;
			}
		}
		natcasesort( $folders );
		return array_values( $folders );
	}

	/**
	 * The folder most likely to be an author's, by comparing names with the "Surname, First" form turned
	 * round - a close spelling still counts ("McCaffery, Anne" for "Anne McCaffrey"). Null when nothing is
	 * close enough to suggest.
	 *
	 * @param string $pAuthor   the author gallery's title, "First Last" as the catalogue writes it
	 * @param list<string> $pFolders
	 */
	public static function guessAuthorFolder( string $pAuthor, array $pFolders ): ?string {
		$target = self::titleKey( $pAuthor );
		$best = null;
		$bestDistance = PHP_INT_MAX;
		foreach( $pFolders as $folder ) {
			$name = str_contains( $folder, ',' ) ? trim( substr( strrchr( $folder, ',' ), 1 ) ).' '.trim( strstr( $folder, ',', true ) ) : $folder;
			$distance = levenshtein( $target, self::titleKey( $name ) );
			if( $distance < $bestDistance ) {
				$bestDistance = $distance;
				$best = $folder;
			}
		}
		return $bestDistance <= 3 ? $best : null;
	}

	/**
	 * The folders with a file or folder name that holds a title's key allowing a spelling slip - one letter
	 * out for a title of 8+ characters, two for 12+ - found by sliding the key's length along each name.
	 *
	 * @param array<string,list<string>> $pNameKeys  folder => the name keys inside it
	 * @return list<string>
	 */
	private static function fuzzyFolders( string $pKey, array $pNameKeys ): array {
		$length = strlen( $pKey );
		if( $length < self::FUZZY_MIN_KEY_LENGTH ) {
			return [];
		}
		$allowed = $length >= 12 ? 2 : 1;
		$found = [];
		foreach( $pNameKeys as $folder => $names ) {
			foreach( $names as $name ) {
				if( strlen( $name ) < $length - $allowed ) {
					continue;
				}
				for( $i = 0, $last = max( 0, strlen( $name ) - $length ); $i <= $last; $i++ ) {
					if( levenshtein( substr( $name, $i, $length ), $pKey ) <= $allowed ) {
						$found[] = $folder;
						continue 3;
					}
				}
			}
		}
		return $found;
	}

	/**
	 * Suggest which of an author's books make up which series, from the author's folder of series
	 * folders. A book belongs to a folder when its title key appears in any file or folder name inside it.
	 * Many folders may name the same book - a "complete" omnibus folder repeating every series' files -
	 * so a book goes to the matching folder that names the fewest books overall, the most specific one.
	 * Books in a series are ordered by publication date, then title. Reads the disk only.
	 *
	 * @param string $pAuthorFolderAbsolute  the author's folder, trailing slash
	 * @param list<array{content_id:int,title:string,event_time:int}> $pBooks  books directly in the author's gallery
	 * @return array{series:list<array{folder:string,name:string,books:list<array>}>,unmatched:list<array>,empty:list<string>}
	 */
	public static function proposeFromFolder( string $pAuthorFolderAbsolute, array $pBooks ): array {
		$haystacks = [];
		$nameKeys = [];
		foreach( scandir( $pAuthorFolderAbsolute ) ?: [] as $entry ) {
			if( str_starts_with( $entry, '.' ) || !is_dir( $pAuthorFolderAbsolute.$entry ) ) {
				continue;
			}
			$keys = [];
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $pAuthorFolderAbsolute.$entry, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
			$iterator->setMaxDepth( self::FOLDER_SCAN_DEPTH );
			foreach( $iterator as $item ) {
				$keys[] = self::titleKey( pathinfo( $item->getFilename(), PATHINFO_FILENAME ) );
			}
			// '|' can't occur in a title key, so a book's key can never straddle two names.
			$keys = array_values( array_filter( $keys ) );
			$nameKeys[$entry] = $keys;
			$haystacks[$entry] = '|'.implode( '|', $keys ).'|';
		}

		$namedBy = [];       // folder => number of books it names
		$candidates = [];    // book content_id => folders naming it
		foreach( $pBooks as $book ) {
			$matched = [];
			// The whole title first; only if no folder names that, the parts of a split title.
			foreach( self::titleKeyTiers( $book['title'] ) as $keys ) {
				foreach( $haystacks as $folder => $haystack ) {
					foreach( $keys as $key ) {
						if( strlen( $key ) >= self::MIN_TITLE_KEY_LENGTH && str_contains( $haystack, $key ) ) {
							$matched[$folder] = true;
							break;
						}
					}
				}
				if( $matched ) {
					break;
				}
			}
			// Last resort, for a file name with a typo in it: the whole title, a letter or two out.
			if( !$matched ) {
				foreach( self::fuzzyFolders( self::titleKey( $book['title'] ), $nameKeys ) as $folder ) {
					$matched[$folder] = true;
				}
			}
			foreach( array_keys( $matched ) as $folder ) {
				$candidates[$book['content_id']][] = $folder;
				$namedBy[$folder] = ( $namedBy[$folder] ?? 0 ) + 1;
			}
		}

		$byFolder = [];
		$unmatched = [];
		foreach( $pBooks as $book ) {
			$folders = $candidates[$book['content_id']] ?? [];
			if( !$folders ) {
				$unmatched[] = $book;
				continue;
			}
			usort( $folders, fn( $a, $b ) => [ $namedBy[$a], mb_strtolower( $a ) ] <=> [ $namedBy[$b], mb_strtolower( $b ) ] );
			$byFolder[$folders[0]][] = $book;
		}

		$series = [];
		foreach( $byFolder as $folder => $books ) {
			// An undated book goes last, whatever its title.
			usort( $books, fn( $a, $b ) => [ $a['event_time'] ?: PHP_INT_MAX, mb_strtolower( $a['title'] ) ] <=> [ $b['event_time'] ?: PHP_INT_MAX, mb_strtolower( $b['title'] ) ] );
			$series[] = [ 'folder' => $folder, 'name' => $folder, 'books' => $books,
				'named' => $namedBy[$folder] ?? 0, 'collection' => ( $namedBy[$folder] ?? 0 ) >= self::COLLECTION_FOLDER_MIN_NAMED ];
		}
		usort( $series, fn( $a, $b ) => strnatcasecmp( $a['folder'], $b['folder'] ) );
		$empty = array_values( array_diff( array_keys( $haystacks ), array_keys( $byFolder ) ) );
		natcasesort( $empty );

		return [ 'series' => $series, 'unmatched' => $unmatched, 'empty' => array_values( $empty ) ];
	}
}

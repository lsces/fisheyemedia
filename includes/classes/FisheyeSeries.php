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

	/**
	 * Open Library's series text split into a name and a number: "Dragonriders of Pern (5)", "Pern #3",
	 * "Acorna, book 2" or just "Doona". Null for nothing usable.
	 *
	 * @return array{name:string,number:?int}|null
	 */
	public static function parseSeriesText( string $pText ): ?array {
		$text = trim( $pText );
		if( $text === '' ) {
			return null;
		}
		$number = null;
		if( preg_match( '/^(.*?)\s*(?:\(\s*(?:book\s*)?(\d+)\s*\)|#\s*(\d+)|[,;]\s*(?:book|bk\.?|vol\.?|volume|no\.?)?\s*(\d+))\s*$/iu', $text, $m ) && trim( $m[1] ) !== '' ) {
			$text = trim( $m[1] );
			$number = (int)( $m[2] ?: ( $m[3] ?: $m[4] ) );
		}
		return [ 'name' => $text, 'number' => $number ?: null ];
	}

	/** A series name reduced for comparing: titleKey() without a trailing "series", "trilogy" ... */
	public static function seriesKey( string $pName ): string {
		return self::titleKey( preg_replace( '/\s+(series|trilogy|saga|quartet|cycle|books)$/iu', '', trim( $pName ) ) );
	}

	/** A series name as a gallery is called: Wikidata's "Planet Pirates Series" is just "Planet Pirates". */
	public static function seriesName( string $pName ): string {
		return trim( preg_replace( '/\s+series$/iu', '', trim( $pName ) ) );
	}

	/**
	 * An author's works that Wikidata puts in a series: title, series (and its umbrella series, if it is one
	 * part of), the number it carries there and its first publication date. Cached for an hour, as the
	 * proposal and the create step both ask. Null if Wikidata cannot be asked.
	 *
	 * @return list<array{title:string,work_q:string,series_q:string,series:string,ord:?int,date:string,parent:string}>|null
	 */
	public static function wikidataWorks( string $pQid ): ?array {
		$class = '\Bitweaver\Contactwiki\ContactWikiIndividual';
		if( !preg_match( '/^Q\d+$/', $pQid ) || !class_exists( $class ) ) {
			return null;
		}
		$cacheKey = 'fm_wd_works_v2_'.$pQid;
		if( function_exists( 'apcu_fetch' ) && ( $cached = apcu_fetch( $cacheKey ) ) !== false ) {
			return $cached;
		}
		$bindings = $class::wikidataSparql(
			'SELECT ?work ?workLabel ?series ?seriesLabel ?ord ?pub ?parentLabel WHERE { ?work wdt:P50 wd:'.$pQid.' . ?work p:P179 ?s . ?s ps:P179 ?series . '
			.'OPTIONAL { ?s pq:P1545 ?ord } OPTIONAL { ?work wdt:P577 ?pub } OPTIONAL { ?series wdt:P179 ?parent } '
			.'SERVICE wikibase:label { bd:serviceParam wikibase:language "en". } }' );
		if( $bindings === null ) {
			return null;
		}
		$works = [];
		foreach( $bindings as $row ) {
			$title = (string)( $row['workLabel']['value'] ?? '' );
			$series = (string)( $row['seriesLabel']['value'] ?? '' );
			if( $title === '' || $series === '' || preg_match( '/^Q\d+$/', $title ) || preg_match( '/^Q\d+$/', $series ) ) {
				continue;
			}
			$parent = (string)( $row['parentLabel']['value'] ?? '' );
			$key = self::titleKey( $title ).'|'.self::seriesKey( $series );
			$entry = [ 'title' => $title, 'work_q' => basename( (string)( $row['work']['value'] ?? '' ) ), 'series_q' => basename( (string)( $row['series']['value'] ?? '' ) ), 'series' => $series,
				'ord' => isset( $row['ord']['value'] ) && ctype_digit( (string)$row['ord']['value'] ) ? (int)$row['ord']['value'] : null,
				'date' => substr( (string)( $row['pub']['value'] ?? '' ), 0, 10 ), 'parent' => preg_match( '/^Q\d+$/', $parent ) ? '' : $parent ];
			// Several dates or parents for one work and series: the earliest date is the first publication.
			if( isset( $works[$key] ) ) {
				if( $entry['date'] !== '' && ( $works[$key]['date'] === '' || $entry['date'] < $works[$key]['date'] ) ) {
					$works[$key]['date'] = $entry['date'];
				}
				$works[$key]['parent'] = $works[$key]['parent'] ?: $entry['parent'];
				$works[$key]['ord'] ??= $entry['ord'];
				continue;
			}
			$works[$key] = $entry;
		}
		$works = array_values( $works );
		if( $works && function_exists( 'apcu_store' ) ) {
			apcu_store( $cacheKey, $works, 3600 );
		}
		return $works;
	}

	/**
	 * Which series each of an author's books belongs to, from Wikidata's works (wikidataWorks()) and the series
	 * text Open Library gave the edition. Read-only - the proposal for review before anything is made.
	 *
	 * A book in a series and also in that series' umbrella (a trilogy inside a long series) is proposed in the
	 * smaller one only, with that series nested in the umbrella. A book neither source names a series for is
	 * proposed under a series of its own name if it is that (an omnibus), else left unmatched. Within a series
	 * the books follow the numbers one source gives when they are complete, all different and agree with
	 * publication order; otherwise publication date. Both numbers are shown so a disagreement can be seen.
	 *
	 * @param list<array{content_id:int,title:string,event_time:int,ol_series:string}> $pBooks
	 * @param list<array> $pWorks  wikidataWorks()
	 * @param list<string> $pSplit  umbrella series keys (umbrellas()) whose sub-series are kept apart instead of folded in
	 * @return array{series:list<array>,unmatched:list<array>}
	 */
	public static function proposeFromSources( array $pBooks, array $pWorks, array $pSplit = [] ): array {
		$byTitle = [];
		$names = [];
		$parentOf = [];
		foreach( $pWorks as $work ) {
			$byTitle[self::titleKey( $work['title'] )][] = $work;
			$key = self::seriesKey( $work['series'] );
			$names[$key] ??= self::seriesName( $work['series'] );
			if( $work['parent'] !== '' && self::seriesKey( $work['parent'] ) !== $key ) {
				$parentOf[$key] = self::seriesKey( $work['parent'] );
				$names[$parentOf[$key]] ??= self::seriesName( $work['parent'] );
			}
		}

		$series = [];
		$unmatched = [];
		foreach( $pBooks as $book ) {
			$cands = [];
			foreach( self::titleKeyTiers( $book['title'] ) as $tier ) {
				foreach( $tier as $titleKey ) {
					foreach( $byTitle[$titleKey] ?? [] as $work ) {
						$key = self::seriesKey( $work['series'] );
						$cands[$key]['wd_ord'] = $work['ord'];
						$cands[$key]['date'] = $work['date'];
						$cands[$key]['work_q'] = $work['work_q'] ?? '';
						$cands[$key]['sources']['Wikidata'] = true;
					}
				}
				if( $cands ) {
					break;
				}
			}
			$ol = self::parseSeriesText( (string)$book['ol_series'] );
			if( $ol ) {
				$key = self::seriesKey( $ol['name'] );
				$names[$key] ??= self::seriesName( $ol['name'] );
				$cands[$key]['ol_num'] = $ol['number'];
				$cands[$key]['sources']['Open Library'] = true;
			}
			if( !$cands && isset( $names[self::seriesKey( $book['title'] )] ) ) {
				$cands[self::seriesKey( $book['title'] )]['sources']['title is the series name'] = true;
			}
			// A sub-series is folded into its umbrella series: Wikidata's view of a trilogy inside a long series is
			// not the author's own listing, so the book follows the umbrella's order. Only a number the umbrella
			// itself gave is kept.
			$folded = [];
			foreach( $cands as $key => $c ) {
				$top = $key;
				for( $up = $parentOf[$key] ?? null, $guard = 0; $up !== null && $guard < 10 && !in_array( $up, $pSplit, true ); $up = $parentOf[$up] ?? null, $guard++ ) {
					$top = $up;
				}
				if( $top !== $key ) {
					$c['wd_ord'] = null;
				}
				foreach( [ 'wd_ord', 'ol_num', 'date', 'work_q' ] as $field ) {
					$folded[$top][$field] = $folded[$top][$field] ?? ( $c[$field] ?? null );
				}
				$folded[$top]['sources'] = ( $folded[$top]['sources'] ?? [] ) + $c['sources'];
			}
			// A split umbrella gives up a book that one of its sub-series also holds.
			foreach( array_keys( $folded ) as $key ) {
				for( $up = $parentOf[$key] ?? null, $guard = 0; $up !== null && $guard < 10; $up = $parentOf[$up] ?? null, $guard++ ) {
					if( in_array( $up, $pSplit, true ) ) {
						unset( $folded[$up] );
					}
				}
			}
			$cands = $folded;
			if( !$cands ) {
				$unmatched[] = $book;
				continue;
			}
			uksort( $cands, fn( $a, $b ) => [ -count( $cands[$a]['sources'] ), $names[$a] ] <=> [ -count( $cands[$b]['sources'] ), $names[$b] ] );
			$key = array_key_first( $cands );
			$c = $cands[$key];
			$series[$key]['name'] = $names[$key];
			$series[$key]['books'][] = [
				'content_id' => $book['content_id'], 'title' => $book['title'], 'wd_ord' => $c['wd_ord'] ?? null, 'ol_num' => $c['ol_num'] ?? null, 'work_q' => $c['work_q'] ?? '',
				'date' => ( $c['date'] ?? '' ) ?: ( $book['event_time'] ? gmdate( 'Y-m-d', (int)$book['event_time'] ) : '' ),
				'sources' => array_keys( $c['sources'] ), 'also' => array_map( fn( $k ) => $names[$k], array_slice( array_keys( $cands ), 1 ) ),
			];
		}

		$out = [];
		foreach( $series as $key => $entry ) {
			$books = $entry['books'];
			$byDate = $books;
			usort( $byDate, fn( $a, $b ) => [ $a['date'] ?: '9999', mb_strtolower( $a['title'] ) ] <=> [ $b['date'] ?: '9999', mb_strtolower( $b['title'] ) ] );
			$numbered = null;
			foreach( [ 'wd_ord' => 'Wikidata numbers', 'ol_num' => 'Open Library numbers' ] as $field => $label ) {
				$nums = array_column( $books, $field );
				if( count( array_filter( $nums, fn( $n ) => $n !== null ) ) !== count( $books ) || count( array_unique( $nums ) ) !== count( $nums ) ) {
					continue;
				}
				$byNum = $books;
				usort( $byNum, fn( $a, $b ) => $a[$field] <=> $b[$field] );
				// Numbers that disagree with publication order are the messy kind - not trusted by default.
				if( array_column( $byNum, 'content_id' ) === array_column( $byDate, 'content_id' ) ) {
					$numbered = [ 'field' => $field, 'label' => $label, 'books' => $byNum ];
					break;
				}
			}
			$out[] = [ 'key' => $key, 'name' => $entry['name'], 'parent' => null, 'parent_name' => '',
				'by_date' => $byDate, 'by_number' => $numbered ? $numbered['books'] : null, 'number_label' => $numbered['label'] ?? '', 'books' => $numbered ? $numbered['books'] : $byDate,
				'order' => $numbered ? 'number' : 'date' ];
		}
		// An umbrella before what is inside it, then by name.
		usort( $out, fn( $a, $b ) => ( ( $a['parent'] !== null ) <=> ( $b['parent'] !== null ) ) ?: strnatcasecmp( $a['name'], $b['name'] ) );
		return [ 'series' => $out, 'unmatched' => $unmatched ];
	}

	/** A sub-series is "small" when it has no more books than this - a trilogy or quartet, not a long-running series. */
	const SMALL_SUBSERIES_MAX = 5;
	/** An umbrella of at most this many books is kept as its sub-series by default. */
	const SPLIT_UMBRELLA_MAX = 20;

	/**
	 * The umbrella series in an author's Wikidata works (series that other series are part of), each with whether
	 * to keep its sub-series apart by default: two or more sub-series, each a trilogy or quartet, in an umbrella
	 * of no more than SPLIT_UMBRELLA_MAX books (Robin Hobb's four trilogies in the Realm of the Elderlings), as
	 * against one trilogy inside a long series (Pern's Harper Hall), which is better folded in.
	 *
	 * @param list<array> $pWorks  wikidataWorks()
	 * @return list<array{key:string,name:string,default:bool,children:list<string>}>
	 */
	public static function umbrellas( array $pWorks ): array {
		$titles = [];
		$names = [];
		$children = [];
		foreach( $pWorks as $work ) {
			$key = self::seriesKey( $work['series'] );
			$names[$key] ??= self::seriesName( $work['series'] );
			$titles[$key][self::titleKey( $work['title'] )] = true;
			if( $work['parent'] !== '' && self::seriesKey( $work['parent'] ) !== $key ) {
				$parentKey = self::seriesKey( $work['parent'] );
				$names[$parentKey] ??= self::seriesName( $work['parent'] );
				$children[$parentKey][$key] = true;
			}
		}
		$out = [];
		foreach( $children as $umbrella => $kids ) {
			$sizes = array_map( fn( $k ) => count( $titles[$k] ?? [] ), array_keys( $kids ) );
			$out[] = [ 'key' => $umbrella, 'name' => $names[$umbrella], 'children' => array_map( fn( $k ) => $names[$k], array_keys( $kids ) ),
				'default' => count( $kids ) >= 2 && max( $sizes ) <= self::SMALL_SUBSERIES_MAX && array_sum( $sizes ) <= self::SPLIT_UMBRELLA_MAX ];
		}
		usort( $out, fn( $a, $b ) => strnatcasecmp( $a['name'], $b['name'] ) );
		return $out;
	}

	/**
	 * Which series each of a not-yet-loaded author's catalogue rows would be filed in, from Wikidata alone (the
	 * Open Library series text only exists once a book is loaded): row index => series name, plus each series'
	 * rows in the order they would be filed.
	 *
	 * @param list<array> $pRows   the author's catalogue rows
	 * @param ?list<string> $pSplit  umbrella keys to keep apart; null takes umbrellas()' defaults
	 * @return array{assign:array<int,string>,series:list<array{key:string,name:string,order:string,rows:list<int>}>,umbrellas:list<array>,works:int}|null  null if Wikidata could not be asked
	 */
	public static function planForRows( array $pRows, string $pQid, ?array $pSplit = null ): ?array {
		$works = self::wikidataWorks( $pQid );
		if( $works === null ) {
			return null;
		}
		$umbrellas = self::umbrellas( $works );
		$split = $pSplit ?? array_column( array_filter( $umbrellas, fn( $u ) => $u['default'] ), 'key' );
		$books = [];
		foreach( array_values( $pRows ) as $i => $row ) {
			$year = substr( (string)( $row['published'] ?? '' ), 0, 4 );
			$books[] = [ 'content_id' => $i, 'title' => (string)$row['title'], 'event_time' => ctype_digit( $year ) && $year !== '' ? (int)strtotime( $year.'-06-01 UTC' ) : 0, 'ol_series' => '' ];
		}
		$proposal = self::proposeFromSources( $books, $works, $split );
		$assign = [];
		$meta = [];
		$series = [];
		foreach( $proposal['series'] as $entry ) {
			$rows = array_column( $entry['books'], 'content_id' );
			foreach( $rows as $i ) {
				$assign[$i] = $entry['name'];
			}
			foreach( $entry['books'] as $one ) {
				$meta[$one['content_id']] = [ 'ordinal' => $one['wd_ord'] ?? null, 'work_q' => (string)( $one['work_q'] ?? '' ) ];
			}
			$series[] = [ 'key' => $entry['key'], 'name' => $entry['name'], 'order' => $entry['order'], 'rows' => $rows ];
		}
		return [ 'assign' => $assign, 'meta' => $meta, 'series' => $series, 'umbrellas' => $umbrellas, 'split' => $split, 'works' => count( $works ) ];
	}

	/**
	 * The series gallery of this name directly under an author's gallery, made (as Library Grid) if there is not one.
	 *
	 * @return array{series:?FisheyeSeries,created:bool,error:?string}
	 */
	public static function findOrCreateUnder( string $pName, int $pAuthorContentId ): array {
		$made = FisheyeMediaGallery::findOrCreateNestedGallery( $pName, $pAuthorContentId, FISHEYE_PAGINATION_LIBRARY_GRID, self::class );
		if( !empty( $made['error'] ) ) {
			return [ 'series' => null, 'created' => false, 'error' => $made['error'] ];
		}
		$series = new self( null, (int)$made['content_id'] );
		$series->load();
		// The layout is a preference as well as a store() field - without it a new series shows as a plain gallery.
		if( $series->getPreference( 'gallery_pagination' ) !== FISHEYE_PAGINATION_LIBRARY_GRID ) {
			$series->storePreference( 'gallery_pagination', FISHEYE_PAGINATION_LIBRARY_GRID );
		}
		return [ 'series' => $series, 'created' => empty( $made['already'] ), 'error' => null ];
	}

	/**
	 * Put this series' books in the given order, positions 1..n (any book not listed keeps its place after them).
	 *
	 * @param list<int> $pContentIds
	 */
	public function setOrder( array $pContentIds ): void {
		foreach( array_values( $pContentIds ) as $i => $contentId ) {
			$this->mDb->getOne(
				"UPDATE `".BIT_DB_PREFIX."fisheye_gallery_image_map` SET `item_position` = ? WHERE `gallery_content_id` = ? AND `item_content_id` = ?",
				[ $i + 1, $this->mContentId, (int)$contentId ]
			);
		}
	}
}

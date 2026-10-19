<?php
/**
 * Physical copies of media - matches the rows of a physical catalogue (FisheyePhysicalCatalogue: LPs, singles,
 * CDs, DVD sets) to what is already in the library, and stores each copy as a 'physical' xref row on the
 * album, film, show or season it belongs to.
 *
 * A row is matched, never guessed at: albums by artist gallery then title, or - when the artist is spelt
 * differently from its gallery ("Antonio Vivaldi" for "Vivaldi, Antonio Lucio") - by a title distinctive
 * enough to be only one album; films and shows by title. An album that is not in the library yet gets a stub
 * (an album with no tracks, under its artist's gallery, which is created too if need be); registering a real
 * folder of that name later adopts the stub (FisheyeAlbum::registerFromDisk()), so nothing is duplicated.
 * A film or show with no match is reported and left alone - there is nothing to hang a copy on.
 *
 * One 'physical' row per copy, written only when no live row is already the same box, format and owner, so a
 * row can be applied twice. data = {location, box, format, owner, note}; xkey = the format and xkey_ext = the
 * place ("Bookcase L3"), so every row has a value in both and a list of copies reads at a glance.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeGallery;

class FisheyePhysical {

	const ITEM = 'physical';

	/**
	 * A title or name reduced to what it is compared by: lower case, "&" read as "and", a leading
	 * "The"/"A"/"An" and a trailing "(year)" dropped, letters and digits only.
	 */
	public static function key( string $pText ): string {
		$text = mb_strtolower( trim( $pText ) );
		$text = str_replace( '&', ' and ', $text );
		$text = preg_replace( '/\s*\(\d{4}\)\s*$/u', '', $text );
		// "(2007 Remaster)", "(Deluxe Edition)" - a note on the release, not part of its name.
		$text = preg_replace( '/\s*\([^)]*\b(?:remaster(?:ed)?|deluxe|edition|expanded|anniversary|bonus|reissue|version)\b[^)]*\)\s*$/u', '', $text );
		$text = preg_replace( '/^(the|a|an)\s+/u', '', $text );
		return preg_replace( '/[^\p{L}\p{N}]+/u', '', $text );
	}

	/**
	 * The candidate whose key is closest to a wanted key, or null: the same key; else one holding the
	 * other (a "(Remastered)" tail, a composer's name in front) when they are close in length; else a spelling
	 * slip or two in a longer key. Ties go to the first.
	 *
	 * @param array<int,string> $pCandidates  id => key
	 */
	public static function bestMatch( string $pKey, array $pCandidates ): ?int {
		if( $pKey === '' ) {
			return null;
		}
		$id = array_search( $pKey, $pCandidates, true );
		if( $id !== false ) {
			return (int)$id;
		}
		$best = null;
		$bestGap = PHP_INT_MAX;
		$length = strlen( $pKey );
		foreach( $pCandidates as $candidateId => $candidateKey ) {
			$candidateLength = strlen( $candidateKey );
			$short = min( $length, $candidateLength );
			$long = max( $length, $candidateLength );
			if( $short >= 5 && $short / $long >= 0.6 && ( str_contains( $candidateKey, $pKey ) || str_contains( $pKey, $candidateKey ) ) && $long - $short < $bestGap ) {
				$best = (int)$candidateId;
				$bestGap = $long - $short;
			}
		}
		if( $best !== null ) {
			return $best;
		}
		if( $length >= 7 ) {
			$allowed = $length >= 12 ? 2 : 1;
			foreach( $pCandidates as $candidateId => $candidateKey ) {
				if( abs( strlen( $candidateKey ) - $length ) <= $allowed && levenshtein( $candidateKey, $pKey ) <= $allowed ) {
					return (int)$candidateId;
				}
			}
		}
		return null;
	}

	/**
	 * The one candidate a key names whatever artist the row gives - only for a long, distinctive key, and
	 * only when exactly one candidate holds it.
	 *
	 * @param array<int,string> $pCandidates  id => key
	 */
	public static function uniqueMatch( string $pKey, array $pCandidates ): ?int {
		if( strlen( $pKey ) < 12 ) {
			return null;
		}
		$found = [];
		foreach( $pCandidates as $id => $candidateKey ) {
			if( $candidateKey === $pKey || ( strlen( $candidateKey ) >= 12 && ( str_contains( $candidateKey, $pKey ) || str_contains( $pKey, $candidateKey ) ) ) ) {
				$found[] = (int)$id;
			}
		}
		return count( $found ) === 1 ? $found[0] : null;
	}

	/**
	 * The data stored on a copy's row, and the identity it is de-duplicated by.
	 */
	public static function copyData( array $pRow, string $pDefaultLocation ): array {
		$box = trim( (string)$pRow['box'] );
		$location = trim( (string)$pRow['location'] );
		if( $location === '' && $box !== '' ) {
			$location = $pDefaultLocation;
		}
		$note = trim( (string)$pRow['note'] );
		$data = [ 'location' => $location, 'box' => $box, 'format' => (string)$pRow['format'], 'owner' => (string)$pRow['owner'], 'note' => $note ];
		return array_filter( $data, fn( $v ) => $v !== '' );
	}

	private static function copyIdentity( array $pData ): string {
		return mb_strtolower( ( $pData['box'] ?? '' ).'|'.( $pData['format'] ?? '' ).'|'.( $pData['owner'] ?? '' ) );
	}

	/**
	 * Everything a plan needs from the library, read once: artist galleries under Music, albums by artist,
	 * films, shows, and the copies already stored.
	 */
	public static function loadIndex(): array {
		global $gBitDb;
		$prefix = BIT_DB_PREFIX;
		$index = [ 'musicContentId' => null, 'artists' => [], 'albumsByArtist' => [], 'albums' => [], 'films' => [], 'programs' => [], 'titles' => [], 'collections' => [], 'copies' => [] ];

		$musicGalleryId = FisheyeGallery::getTopGalleryId( 'Music' );
		if( $musicGalleryId ) {
			$index['musicContentId'] = (int)$gBitDb->getOne( "SELECT `content_id` FROM `{$prefix}fisheye_gallery` WHERE `gallery_id` = ?", [ $musicGalleryId ] );
			foreach( $gBitDb->getAll(
				"SELECT lc.`content_id`, lc.`title` FROM `{$prefix}fisheye_gallery_image_map` m
				 INNER JOIN `{$prefix}liberty_content` lc ON lc.`content_id` = m.`item_content_id`
				 INNER JOIN `{$prefix}fisheye_gallery` fg ON fg.`content_id` = lc.`content_id`
				 WHERE m.`gallery_content_id` = ?", [ $index['musicContentId'] ] ) as $row ) {
				$index['artists'][(int)$row['content_id']] = [ 'title' => (string)$row['title'], 'key' => self::key( $row['title'] ) ];
			}
		}

		// A gallery nested in an artist's (a box set) counts as the artist's own.
		$parentOf = [];
		foreach( $gBitDb->getAll(
			"SELECT m.`item_content_id` AS child, m.`gallery_content_id` AS parent FROM `{$prefix}fisheye_gallery_image_map` m
			 INNER JOIN `{$prefix}fisheye_gallery` fg ON fg.`content_id` = m.`item_content_id`" ) as $row ) {
			$parentOf[(int)$row['child']] = (int)$row['parent'];
		}
		foreach( $gBitDb->getAll(
			"SELECT lc.`content_id`, lc.`title`, m.`gallery_content_id` AS gallery FROM `{$prefix}liberty_content` lc
			 INNER JOIN `{$prefix}fisheye_gallery_image_map` m ON m.`item_content_id` = lc.`content_id`
			 WHERE lc.`content_type_guid` = 'fisheyealbum'" ) as $row ) {
			$albumId = (int)$row['content_id'];
			$key = self::key( $row['title'] );
			$index['albums'][$albumId] = $key;
			$index['titles'][$albumId] = (string)$row['title'];
			$gallery = (int)$row['gallery'];
			$index['albumsByArtist'][$gallery][$albumId] = $key;
			if( isset( $parentOf[$gallery] ) ) {
				$index['albumsByArtist'][$parentOf[$gallery]][$albumId] = $key;
			}
		}

		foreach( $gBitDb->getAll( "SELECT `content_id`, `title`, `content_type_guid` FROM `{$prefix}liberty_content` WHERE `content_type_guid` IN ('fisheyefilm','fisheyeprogram')" ) as $row ) {
			$index[$row['content_type_guid'] === 'fisheyefilm' ? 'films' : 'programs'][(int)$row['content_id']] = self::key( $row['title'] );
			$index['titles'][(int)$row['content_id']] = (string)$row['title'];
		}

		foreach( $gBitDb->getAll( "SELECT `content_id`, `data` FROM `{$prefix}liberty_xref` WHERE `item` = ? AND `end_date` IS NULL", [ self::ITEM ] ) as $row ) {
			$data = json_decode( (string)$row['data'], true ) ?: [];
			$index['copies'][(int)$row['content_id']][self::copyIdentity( $data )] = true;
		}
		// Collection galleries in Films and TV Shows (Lord of the Rings, Carry On ...) - a boxed set of several
		// films is one copy of the collection, not of any one film in it.
		$index['collections'] = [];
		foreach( [ 'Films', 'TV Shows' ] as $topTitle ) {
			$topId = FisheyeGallery::getTopGalleryId( $topTitle );
			$topContentId = $topId ? (int)$gBitDb->getOne( "SELECT `content_id` FROM `{$prefix}fisheye_gallery` WHERE `gallery_id` = ?", [ $topId ] ) : 0;
			if( !$topContentId ) {
				continue;
			}
			foreach( $gBitDb->getAll(
				"SELECT lc.`content_id`, lc.`title` FROM `{$prefix}fisheye_gallery_image_map` m
				 INNER JOIN `{$prefix}liberty_content` lc ON lc.`content_id` = m.`item_content_id`
				 WHERE m.`gallery_content_id` = ? AND lc.`content_type_guid` IN ( 'fisheyegallery', 'fisheyemediagal' )", [ $topContentId ] ) as $row ) {
				$index['collections'][(int)$row['content_id']] = [ 'title' => (string)$row['title'], 'key' => self::key( $row['title'] ) ];
			}
		}
		$index['state'] = FisheyePhysicalCatalogue::loadState();
		$root = \Bitweaver\Liberty\mime_film_get_storage_root();
		$index['disk'] = $root ? self::diskIndex( $root.'Music/' ) : [];
		return $index;
	}

	/**
	 * The album folders already on disk under Music/, by artist folder - two levels down, so a folder inside a
	 * Studio/Live/Compilation strip counts. A folder here that has no album in the library yet is waiting to
	 * be loaded, and a stub of the same name would be registered over rather than the real thing.
	 *
	 * @return array<string,array{name:string,folders:array<string,string>}>  artist key => folder name and
	 *         album folder key => folder name
	 */
	public static function diskIndex( string $pMusicDir ): array {
		$disk = [];
		foreach( scandir( $pMusicDir ) ?: [] as $artist ) {
			if( str_starts_with( $artist, '.' ) || !is_dir( $pMusicDir.$artist ) ) {
				continue;
			}
			$folders = [];
			foreach( scandir( $pMusicDir.$artist ) ?: [] as $album ) {
				if( str_starts_with( $album, '.' ) || !is_dir( $pMusicDir.$artist.'/'.$album ) ) {
					continue;
				}
				$folders[self::key( $album )] = $album;
				foreach( scandir( $pMusicDir.$artist.'/'.$album ) ?: [] as $inner ) {
					if( !str_starts_with( $inner, '.' ) && is_dir( $pMusicDir.$artist.'/'.$album.'/'.$inner ) ) {
						$folders[self::key( $inner )] = $inner;
					}
				}
			}
			$disk[self::key( $artist )] = [ 'name' => $artist, 'folders' => $folders ];
		}
		return $disk;
	}

	/**
	 * The folder on disk an album row names, if there is one (see diskIndex()).
	 */
	private static function folderOnDisk( array $pRow, array $pIndex ): ?string {
		$disk = $pIndex['disk'] ?? [];
		$artistKeys = array_keys( $disk );
		$artist = self::bestMatch( self::key( $pRow['artist'] ), $artistKeys );
		if( $artist === null ) {
			return null;
		}
		$entry = $disk[$artistKeys[$artist]];
		$folderKeys = array_keys( $entry['folders'] );
		$titleKey = self::key( $pRow['title'] );
		$folder = self::bestMatch( $titleKey, $folderKeys ) ?? self::uniqueMatch( $titleKey, $folderKeys );
		return $folder === null ? null : $entry['name'].'/'.$entry['folders'][$folderKeys[$folder]];
	}

	/**
	 * The films and shows whose titles share the most words with a row - for choosing a link by hand when
	 * nothing matched outright (or several things share the name). Words under three letters and the commonest
	 * filler are ignored. Films come first for a film row and shows first for a TV row.
	 *
	 * @return list<array{id:int,title:string,kind:string}>
	 */
	public static function candidates( array $pRow, array $pIndex, int $pLimit = 6 ): array {
		$words = function( string $pText ): array {
			$stop = [ 'the', 'and', 'dvd', 'set', 'part', 'series', 'season', 'complete', 'collection', 'volume', 'edition' ];
			preg_match_all( '/[\p{L}\p{N}]+/u', mb_strtolower( str_replace( [ "'", "\u{2019}" ], '', $pText ) ), $found );
			return array_values( array_diff( array_unique( array_filter( $found[0], fn( $w ) => mb_strlen( $w ) > 2 ) ), $stop ) );
		};
		$wanted = $words( $pRow['artist'].' '.$pRow['title'] );
		if( !$wanted ) {
			return [];
		}
		// What a row can belong to, the likeliest kind first.
		$artistKeys = array_map( fn( $a ) => $a['key'], $pIndex['artists'] ?? [] );
		$collectionKeys = array_map( fn( $c ) => $c['key'], $pIndex['collections'] ?? [] );
		$pools = match( $pRow['kind'] ) {
			'album' => [ 'album' => $pIndex['albums'] ?? [], 'collection' => $artistKeys ],
			'film' => [ 'film' => $pIndex['films'] ?? [], 'collection' => $collectionKeys, 'show' => $pIndex['programs'] ?? [] ],
			default => [ 'show' => $pIndex['programs'] ?? [], 'collection' => $collectionKeys, 'film' => $pIndex['films'] ?? [] ],
		};
		$scored = [];
		$rank = count( $pools );
		foreach( $pools as $label => $pool ) {
			$rank--;
			foreach( $pool as $id => $key ) {
				$title = $pIndex['titles'][$id] ?? ( $pIndex['artists'][$id]['title'] ?? ( $pIndex['collections'][$id]['title'] ?? '' ) );
				$shared = count( array_intersect( $wanted, $words( $title ) ) );
				if( $shared ) {
					$scored[] = [ $shared, $rank, -strlen( $title ), [ 'id' => (int)$id, 'title' => $title, 'kind' => $label ] ];
				}
			}
		}
		usort( $scored, fn( $a, $b ) => [ $b[0], $b[1], $b[2] ] <=> [ $a[0], $a[1], $a[2] ] );
		return array_slice( array_column( $scored, 3 ), 0, $pLimit );
	}

	/**
	 * " (1 of 3 with this name)" when more than one film or show in the library has the matched one's key.
	 *
	 * @param array<int,string> $pCandidates  id => key
	 */
	private static function sameNameNote( int $pId, array $pCandidates ): string {
		$same = count( array_keys( $pCandidates, $pCandidates[$pId] ?? '', true ) );
		return $same > 1 ? ' (1 of '.$same.' with this name)' : '';
	}

	/**
	 * The stub album (an album with no tracks) in a gallery that a folder name belongs to, if there is one -
	 * matched the way a catalogue row is (FisheyePhysical::bestMatch()), so "The Dark Side of The Moon" finds
	 * "The Dark Side of the Moon". FisheyeAlbum::registerFromDisk() takes it over instead of making a second.
	 */
	public static function stubInGallery( string $pTitle, int $pGalleryContentId ): ?int {
		global $gBitDb;
		$stubs = [];
		foreach( $gBitDb->getAll(
			"SELECT lc.`content_id`, lc.`title` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
			 INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
			 WHERE m.`gallery_content_id` = ? AND lc.`content_type_guid` = 'fisheyealbum'
			   AND NOT EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` x WHERE x.`content_id` = lc.`content_id` AND x.`item` = 'track' AND x.`end_date` IS NULL )",
			[ $pGalleryContentId ] ) as $row ) {
			$stubs[(int)$row['content_id']] = self::key( $row['title'] );
		}
		return $stubs ? self::bestMatch( self::key( $pTitle ), $stubs ) : null;
	}

	/**
	 * The seasons of a show by season number, read on demand.
	 *
	 * @return array<int,int>  season number => content_id
	 */
	private static function seasonsOf( int $pProgramContentId ): array {
		global $gBitDb;
		$seasons = [];
		foreach( $gBitDb->getAll(
			"SELECT lc.`content_id`, lc.`title` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
			 INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
			 WHERE m.`gallery_content_id` = ? AND lc.`content_type_guid` = 'fisheyeseason'", [ $pProgramContentId ] ) as $row ) {
			if( preg_match( '/(\d+)/', (string)$row['title'], $m ) ) {
				$seasons[(int)$m[1]] = (int)$row['content_id'];
			}
		}
		return $seasons;
	}

	/**
	 * Work out what a row would do, writing nothing. Adds to the row:
	 *   status  - 'applied' (already stored), 'matched' (an existing album, film, show or season), 'stub' (an
	 *             album to create under an existing artist), 'newartist' (an album and its artist gallery to
	 *             create), 'ondisk' (an album whose folder is on disk but not loaded - load it, then run again),
	 *             'torip' (an album not in the library, when stubs are not being made), or 'none' (a film or show that
	 *             is not in the library)
	 *   target  - the content_id it attaches to, when matched (or applied)
	 *   artistId - the artist gallery, when there is one
	 *   label   - what it matched, for the review page
	 */
	public static function plan( array $pRow, array $pIndex, string $pDefaultLocation ): array {
		$signature = FisheyePhysicalCatalogue::signature( $pRow );
		// A link made by hand on an earlier visit still holds, unless one is being made now.
		if( empty( $pRow['manual_target'] ) && !empty( $pIndex['state']['linked'][$signature] ) ) {
			$pRow['manual_target'] = (int)$pIndex['state']['linked'][$signature];
		}
		$row = $pRow + [ 'status' => 'none', 'target' => null, 'artistId' => null, 'label' => '', 'signature' => $signature,
			'skipped' => !empty( $pIndex['state']['skipped'][$signature] ) ];
		$identity = self::copyIdentity( self::copyData( $pRow, $pDefaultLocation ) );

		if( $pRow['kind'] === 'album' ) {
			$artistKeys = array_map( fn( $a ) => $a['key'], $pIndex['artists'] );
			$artistId = self::bestMatch( self::key( $pRow['artist'] ), $artistKeys );
			$titleKey = self::key( $pRow['title'] );
			$albums = $artistId ? ( $pIndex['albumsByArtist'][$artistId] ?? [] ) : [];
			$albumId = $artistId ? ( self::bestMatch( $titleKey, $albums ) ?? self::uniqueMatch( $titleKey, $albums ) ) : null;
			$albumId ??= self::uniqueMatch( $titleKey, $pIndex['albums'] );
			$row['artistId'] = $artistId;
			if( !empty( $pRow['manual_target'] ) ) {
				$row['target'] = (int)$pRow['manual_target'];
				$row['status'] = 'matched';
				$row['label'] = 'linked by hand';
			} elseif( $albumId ) {
				$row['target'] = $albumId;
				$row['status'] = 'matched';
				$row['label'] = 'existing album';
			} elseif( $folder = self::folderOnDisk( $pRow, $pIndex ) ) {
				$row['status'] = 'ondisk';
				$row['label'] = 'folder '.$folder.' is on disk but not loaded - load it first';
			} elseif( preg_match( '/^(CD|DVD)\b/i', $pRow['format'] ) ) {
				// A CD or DVD has been ripped, so if it is not in the library the files are just not loaded yet -
				// a stub would only be in the way of loading them.
				$row['status'] = 'ondisk';
				$row['label'] = 'not in the library yet - load its music first, or link it by hand';
			} elseif( empty( $pIndex['makeStubs'] ) ) {
				// Stubs are optional: left off, a record that is not in the library is just a note of something to rip.
				$row['status'] = 'torip';
				$row['label'] = 'not in the library yet - to rip';
			} else {
				$row['status'] = $artistId ? 'stub' : 'newartist';
				$row['label'] = $artistId ? 'new album under '.$pIndex['artists'][$artistId]['title'] : 'new album and artist gallery';
			}
			if( !$row['target'] && $row['status'] !== 'matched' ) {
				$row['candidates'] = self::candidates( $pRow, $pIndex );
			}
		} else {
			$isFilm = $pRow['kind'] === 'film';
			$manual = (int)( $pRow['manual_target'] ?? 0 );
			$programId = null;
			if( $manual ) {
				$targetId = $manual;
				$how = 'linked by hand';
				$programId = isset( $pIndex['programs'][$manual] ) ? $manual : null;
			} elseif( $isFilm ) {
				$targetId = self::bestMatch( self::key( $pRow['title'] ), $pIndex['films'] );
				if( $targetId === null && $pRow['artist'] !== '' ) {
					// A franchise column ("Tron") may be the film itself, the title column only the edition.
					$targetId = self::bestMatch( self::key( $pRow['artist'].' '.$pRow['title'] ), $pIndex['films'] )
						?? self::bestMatch( self::key( $pRow['artist'] ), $pIndex['films'] );
				}
				$how = 'existing film'.( $targetId ? self::sameNameNote( $targetId, $pIndex['films'] ) : '' );
				if( $targetId === null && $pRow['artist'] !== '' ) {
					// No single film, but the franchise is a collection gallery: a set of its films.
					$collectionKeys = array_map( fn( $c ) => $c['key'], $pIndex['collections'] ?? [] );
					$targetId = self::bestMatch( self::key( $pRow['artist'] ), $collectionKeys );
					$how = 'the '.( $targetId ? $pIndex['collections'][$targetId]['title'] : '' ).' collection';
				}
			} else {
				$programId = $targetId = self::bestMatch( self::key( $pRow['artist'] !== '' ? $pRow['artist'] : $pRow['title'] ), $pIndex['programs'] );
				$how = 'existing show'.( $targetId ? self::sameNameNote( $targetId, $pIndex['programs'] ) : '' );
			}
			if( $targetId ) {
				// A show row naming one season goes on that season, when the show has it.
				if( $programId && $pRow['artist'] !== '' && preg_match( '/^(?:complete\s+)?(?:season|series)\s+(\d+)$/i', $pRow['title'], $m ) ) {
					$seasons = self::seasonsOf( $programId );
					if( isset( $seasons[(int)$m[1]] ) ) {
						$targetId = $seasons[(int)$m[1]];
						$how = $manual ? 'linked by hand, season '.$m[1] : 'existing season';
					}
				}
				$row['target'] = $targetId;
				$row['status'] = 'matched';
				$row['label'] = $how;
			}
			if( !$row['target'] || str_contains( $row['label'], 'with this name' ) ) {
				$row['candidates'] = self::candidates( $pRow, $pIndex );
			}
		}

		if( $row['target'] && isset( $pIndex['copies'][$row['target']][$identity] ) ) {
			$row['status'] = 'applied';
		}
		return $row;
	}

	/**
	 * Apply one row: resolve (or create) what it attaches to and store the copy. Re-resolved here from the
	 * library as it is now, not taken from a form.
	 *
	 * @return array{ok:bool,message:string,content_id:?int}
	 */
	public static function apply( array $pRow, array $pIndex, string $pDefaultLocation ): array {
		global $gBitDb;
		$row = self::plan( $pRow, $pIndex, $pDefaultLocation );
		if( $row['status'] === 'applied' ) {
			return [ 'ok' => true, 'message' => 'already stored', 'content_id' => $row['target'] ];
		}
		if( $row['status'] === 'none' ) {
			return [ 'ok' => false, 'message' => 'not in the library - choose what it belongs to', 'content_id' => null ];
		}
		if( in_array( $row['status'], [ 'ondisk', 'torip' ], true ) ) {
			return [ 'ok' => false, 'message' => $row['label'], 'content_id' => null ];
		}

		$made = '';
		if( !$row['target'] ) {
			$artistId = $row['artistId'];
			if( !$artistId ) {
				if( !$pIndex['musicContentId'] ) {
					return [ 'ok' => false, 'message' => 'there is no top-level Music gallery', 'content_id' => null ];
				}
				$gallery = FisheyeMediaGallery::findOrCreateNestedGallery( $row['artist'], (int)$pIndex['musicContentId'], FISHEYE_PAGINATION_MUSIC_GRID );
				if( !empty( $gallery['error'] ) ) {
					return [ 'ok' => false, 'message' => $gallery['error'], 'content_id' => null ];
				}
				$artistId = (int)$gallery['content_id'];
				$made = 'artist gallery and ';
			}
			$album = new FisheyeAlbum();
			$storeHash = [ 'title' => $row['title'] ];
			if( !$album->store( $storeHash ) ) {
				return [ 'ok' => false, 'message' => implode( '; ', $album->mErrors ), 'content_id' => null ];
			}
			$album->load();
			$artistGallery = new FisheyeGallery( null, $artistId );
			$artistGallery->load();
			$artistGallery->addItem( $album->mContentId );
			$row['target'] = (int)$album->mContentId;
			$made .= 'stub album';
		}

		$type = (string)$gBitDb->getOne( "SELECT `content_type_guid` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ?", [ $row['target'] ] );
		$content = match( $type ) {
			'fisheyealbum' => new FisheyeAlbum( null, $row['target'] ),
			'fisheyefilm' => new FisheyeFilm( null, $row['target'] ),
			'fisheyeseason' => new FisheyeSeason( null, $row['target'] ),
			'fisheyeprogram' => new FisheyeProgram( null, $row['target'] ),
			'fisheyemediagal' => new FisheyeMediaGallery( null, $row['target'] ),
			'fisheyegallery' => new FisheyeGallery( null, $row['target'] ),
			default => null,
		};
		if( !$content ) {
			return [ 'ok' => false, 'message' => 'content '.$row['target'].' is not an album, film, show, season or collection', 'content_id' => null ];
		}
		$content->load();
		$data = self::copyData( $row, $pDefaultLocation );
		$order = 1 + (int)$gBitDb->getOne(
			"SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = ? AND `end_date` IS NULL", [ $row['target'], self::ITEM ] );
		$xrefHash = [ 'content_id' => $row['target'], 'item' => self::ITEM, 'fAddXref' => 1, 'xorder' => $order,
			'xkey' => $data['format'] ?? 'copy', 'xkey_ext' => trim( ( $data['location'] ?? '' ).' '.( $data['box'] ?? '' ) ), 'edit' => json_encode( $data, JSON_UNESCAPED_UNICODE ) ];
		if( $xrefHash['xkey_ext'] === '' ) {
			unset( $xrefHash['xkey_ext'] );
		}
		if( !$content->storeXref( $xrefHash ) ) {
			return [ 'ok' => false, 'message' => 'the copy could not be stored'.( $made ? ' (the '.$made.' was created)' : '' ), 'content_id' => $row['target'] ];
		}
		return [ 'ok' => true, 'message' => $made !== '' ? 'created '.$made : 'stored', 'content_id' => $row['target'] ];
	}
}

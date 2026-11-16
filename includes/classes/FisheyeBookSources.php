<?php
/**
 * What the sources hold for one Book, side by side, so the best of each can be chosen: the book as it is stored, its own file (the first page of a PDF, the
 * picture an epub carries), Open Library (the edition its ISBN names, the work's description and genres, the cover), Wikidata (for a book that has its item) and
 * Google Books (a cover). gather() asks the sources and returns what they hold; the page carries it in its form, and apply() writes only what was ticked from
 * what the form carries, so the page that shows the choices and the one that acts on them agree and nothing is kept on the server between them.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;

class FisheyeBookSources {

	/** The edition's own details: xref item => label. */
	public const FIELDS = [ 'publisher' => 'Publisher', 'published' => 'Published', 'pages' => 'Pages', 'format' => 'Format', 'language' => 'Language', 'edition' => 'Edition' ];
	/** The ids on other services that belong to the edition: taken together. */
	public const IDS = [ 'ol_edition', 'ol_work', 'google_books', 'goodreads', 'librarything', 'oclc', 'internet_archive' ];
	public const SOURCES = [ 'now' => 'Stored here', 'file' => 'The book\'s own file', 'ol' => 'Open Library', 'wd' => 'Wikidata', 'google' => 'Google Books' ];

	/** One stored value as text, or ''. */
	private static function stored( FisheyeBook $pBook, string $pItem ): string {
		return trim( (string)$pBook->mDb->getOne( "SELECT COALESCE( `xkey_ext`, `xkey` ) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = ? AND `end_date` IS NULL ORDER BY `xorder`", [ $pBook->mContentId, $pItem ] ) );
	}

	private static function plain( string $pHtml ): string {
		return trim( html_entity_decode( strip_tags( str_replace( [ '</p>', '<br />', '<br>' ], "\n", $pHtml ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	private static function readable( string $pText ): string {
		return mb_strlen( $pText ) < 30 || preg_match( '/^\W*(duplicate|dupe|see )/i', $pText ) ? '' : $pText;
	}

	private static function dataUri( string $pFile, ?string &$pSize ): string {
		$size = @getimagesize( $pFile );
		$pSize = $size ? $size[0].' x '.$size[1] : '';
		return 'data:'.( $size['mime'] ?? 'image/jpeg' ).';base64,'.base64_encode( (string)file_get_contents( $pFile ) );
	}

	/**
	 * Ask the sources. Returns [ 'error' => string, 'fields' => item => [ label, values: source => text ], 'description' => source => text, 'genres' => source => text,
	 * 'ids' => source => text, 'covers' => source => [ label, size ], 'olFound' => bool, 'wdQid' => the book's Wikidata item or '', 'wdFound' => bool ]; the pictures and the Open Library values are kept for apply().
	 */
	public static function gather( FisheyeBook $pBook ): array {
		$dir = rtrim( sys_get_temp_dir(), '/' ).'/fm_avail_'.bin2hex( random_bytes( 6 ) );   // the pictures are worked on here for this request only
		mkdir( $dir, 0770, true );
		$out = [ 'error' => '', 'fields' => [], 'description' => [], 'genres' => [], 'ids' => [], 'covers' => [], 'olFound' => false, 'wdQid' => '', 'wdFound' => false ];
		$keep = [ 'fields' => [], 'description' => '', 'genres' => [], 'ids' => [], 'covers' => [] ];

		// What the book holds now.
		foreach( self::FIELDS as $item => $label ) {
			$out['fields'][$item] = [ 'label' => KernelTools::tra( $label ), 'values' => [] ];
			if( ( $now = self::stored( $pBook, $item ) ) !== '' ) {
				$out['fields'][$item]['values']['now'] = $now;
			}
		}
		if( ( $now = self::plain( (string)$pBook->mDb->getOne( "SELECT `data` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ?", [ $pBook->mContentId ] ) ) ) !== '' ) {
			$out['description']['now'] = $now;   // whatever is stored is the description, including the start of the file's text that a load stores when there is none
		}
		$nowGenres = $pBook->mDb->getCol( "SELECT `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'genre' AND `end_date` IS NULL ORDER BY `xorder`", [ $pBook->mContentId ] ) ?: [];
		if( $nowGenres ) {
			$out['genres']['now'] = implode( ', ', $nowGenres );
		}
		$nowIds = [];
		foreach( self::IDS as $item ) {
			if( ( $v = self::stored( $pBook, $item ) ) !== '' ) {
				$nowIds[] = $item.' '.$v;
			}
		}
		if( $nowIds ) {
			$out['ids']['now'] = implode( ', ', $nowIds );
		}
		// the tile now
		if( $cover = $pBook->getCoverFile() ) {
			copy( $cover, $dir.'/cover_now.jpg' );
			$out['covers']['now'] = [ 'label' => KernelTools::tra( self::SOURCES['now'] ) ];
		}

		// The book's own file.
		if( $path = $pBook->ownFilePath() ) {
			$bytes = '';
			if( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) === 'pdf' ) {
				if( $tmp = FisheyeBook::drawPdfFirstPage( $path ) ) {
					$bytes = (string)file_get_contents( $tmp );
					@unlink( $tmp );
				}
			} else {
				global $gLibertySystem;
				if( !function_exists( '\Bitweaver\Liberty\mime_epub_read' ) ) {
					$gLibertySystem->scanAllPlugins( null, "mime\." );
				}
				$bytes = function_exists( '\Bitweaver\Liberty\mime_epub_read' ) ? (string)( \Bitweaver\Liberty\mime_epub_read( $path )['cover'] ?? '' ) : '';
			}
			if( $bytes !== '' && FisheyeBookCovers::isPicture( $bytes ) ) {
				file_put_contents( $dir.'/cover_file.jpg', $bytes );
				$out['covers']['file'] = [ 'label' => KernelTools::tra( self::SOURCES['file'] ) ];
			}
		}

		// Open Library, and Google Books for a cover.
		$look = $pBook->openLibraryEditionForIsbns();
		$out['error'] = $look['error'];
		$wiki = '\\Bitweaver\\Contactwiki\\ContactWikiIndividual';
		if( $look['edition'] ) {
			$out['olFound'] = true;
			$edition = $look['edition'];
			$fields = FisheyeBookCatalogue::fromOpenLibraryEdition( $edition );
			foreach( self::FIELDS as $item => $label ) {
				if( isset( $fields[$item] ) ) {
					$out['fields'][$item]['values']['ol'] = trim( (string)( $fields[$item]['name'] ?? $fields[$item]['xkey'] ?? $fields[$item]['xkey_ext'] ?? '' ) );
					$keep['fields'][$item]['ol'] = $fields[$item];
				}
			}
			$ids = array_intersect_key( $fields, array_flip( self::IDS ) );
			if( $ids ) {
				$out['ids']['ol'] = implode( ', ', array_map( fn( $k, $v ) => $k.' '.$v['xkey'], array_keys( $ids ), $ids ) );
				$keep['ids'] = $ids;
			}
			// the description (the edition's, else the work's) and genres (the work's)
			$text = fn( $pValue ): string => is_array( $pValue ) ? (string)( $pValue['value'] ?? '' ) : (string)$pValue;
			$description = self::readable( FisheyeBook::cleanOpenLibraryText( $text( $edition['description'] ?? '' ) ) );
			$work = null;
			if( ( $workKey = (string)( $edition['works'][0]['key'] ?? '' ) ) !== '' ) {
				usleep( 300000 );
				$work = $wiki::fetchOpenLibraryJson( $workKey.'.json' );
				if( $description === '' && is_array( $work ) ) {
					$description = self::readable( FisheyeBook::cleanOpenLibraryText( $text( $work['description'] ?? '' ) ) );
				}
			}
			if( $description !== '' ) {
				$out['description']['ol'] = $description;
				$keep['description'] = $description;
			}
			$genres = [];
			foreach( (array)( is_array( $work ) ? ( $work['genres'] ?? [] ) : [] ) as $tag ) {
				if( preg_match( '#^/tags/(OL\d+T)$#', (string)$tag ) && ( $name = FisheyeBook::openLibraryTagName( (string)$tag ) ) !== '' ) {
					$genres[mb_strtolower( FisheyeBook::canonicalGenre( $name ) )] = FisheyeBook::canonicalGenre( $name );
				}
			}
			if( !$genres && is_array( $work ) ) {
				foreach( (array)( $work['subjects'] ?? [] ) as $subject ) {
					foreach( FisheyeBook::SUBJECT_GENRES as $word => $genre ) {
						if( str_contains( mb_strtolower( (string)$subject ), $word ) ) {
							$genres[mb_strtolower( $genre )] = $genre;
						}
					}
				}
			}
			if( $genres = array_slice( array_values( $genres ), 0, 6 ) ) {
				$out['genres']['ol'] = implode( ', ', $genres );
				$keep['genres'] = $genres;
			}
			// the covers
			$urls = [];
			if( (int)( $edition['covers'][0] ?? 0 ) > 0 ) {
				$urls[] = 'https://covers.openlibrary.org/b/id/'.(int)$edition['covers'][0].'-L.jpg?default=false';
			}
			foreach( $look['isbns'] as $isbn ) {
				$urls[] = 'https://covers.openlibrary.org/b/isbn/'.$isbn.'-L.jpg?default=false';
			}
			foreach( $urls as $url ) {
				usleep( 300000 );
				if( ( $bytes = $wiki::fetchOpenLibraryImage( $url ) ) !== null && FisheyeBookCovers::isPicture( $bytes ) ) {
					file_put_contents( $dir.'/cover_ol.jpg', $bytes );
					$out['covers']['ol'] = [ 'label' => KernelTools::tra( self::SOURCES['ol'] ) ];
					break;
				}
			}
		}

		// Wikidata: only for a book that names its item (found by hand on the Look up details page - a title search is not reliable enough to do for it).
		$qid = strtoupper( self::stored( $pBook, 'wikidata_work' ) );
		if( preg_match( '/^Q\d+$/', $qid ) ) {
			$out['wdQid'] = $qid;
			usleep( 300000 );
			$entity = $wiki::fetchWikidataEntity( $qid );
			if( is_array( $entity ) ) {
				$out['wdFound'] = true;
				$claim = function( string $pProperty ) use ( $entity ) {
					foreach( (array)( $entity['claims'][$pProperty] ?? [] ) as $statement ) {
						if( ( $statement['rank'] ?? '' ) !== 'deprecated' && isset( $statement['mainsnak']['datavalue']['value'] ) ) {
							return $statement['mainsnak']['datavalue']['value'];
						}
					}
					return null;
				};
				$label = function( $pValue ) use ( $wiki ): string {
					if( !is_array( $pValue ) || !preg_match( '/^Q\d+$/', (string)( $pValue['id'] ?? '' ) ) ) {
						return '';
					}
					usleep( 300000 );
					$item = $wiki::fetchWikidataEntity( $pValue['id'] );
					return trim( (string)( $item['labels']['en']['value'] ?? '' ) );
				};
				$facts = FisheyeBook::wikidataWorkFacts( $entity );
				$wd = [];
				if( $facts['published'] !== '' ) {
					$wd['published'] = [ 'xkey_ext' => $facts['published'] ];
				}
				if( ( $publisher = $label( $claim( 'P123' ) ) ) !== '' ) {
					$wd['publisher'] = [ 'name' => $publisher ];
				}
				$pages = $claim( 'P1104' );
				if( is_array( $pages ) && (int)ltrim( (string)( $pages['amount'] ?? '' ), '+' ) > 0 ) {
					$wd['pages'] = [ 'xkey_ext' => (string)(int)ltrim( (string)$pages['amount'], '+' ) ];
				}
				if( ( $language = $label( $claim( 'P407' ) ) ) !== '' ) {
					$wd['language'] = [ 'xkey_ext' => $language ];
				}
				foreach( $wd as $item => $value ) {
					$out['fields'][$item]['values']['wd'] = trim( (string)( $value['name'] ?? $value['xkey_ext'] ) );
					$keep['fields'][$item]['wd'] = $value;
				}
				if( is_string( $image = $claim( 'P18' ) ) && $image !== '' && $wiki::downloadCommonsFile( $image, $dir.'/cover_wd.jpg' )
					&& FisheyeBookCovers::isPicture( (string)file_get_contents( $dir.'/cover_wd.jpg' ) ) ) {
					$out['covers']['wd'] = [ 'label' => KernelTools::tra( self::SOURCES['wd'] ) ];
				} else {
					@unlink( $dir.'/cover_wd.jpg' );
				}
			}
		}
		foreach( $look['isbns'] as $isbn ) {
			usleep( 300000 );
			if( ( $bytes = FisheyeBook::fetchGoogleCover( $wiki, $isbn ) ) !== null && FisheyeBookCovers::isPicture( $bytes ) ) {
				file_put_contents( $dir.'/cover_google.jpg', $bytes );
				$out['covers']['google'] = [ 'label' => KernelTools::tra( self::SOURCES['google'] ) ];
				break;
			}
		}
		foreach( $out['covers'] as $src => &$cover ) {
			$cover['uri'] = self::dataUri( $dir.'/cover_'.$src.'.jpg', $cover['size'] );
		}
		unset( $cover );
		array_map( 'unlink', glob( $dir.'/*' ) ?: [] );
		rmdir( $dir );
		$out['payload'] = json_encode( $keep );   // what apply() needs of what was found, carried by the page's form
		return $out;
	}

	/**
	 * Write what was ticked. $pPick: 'fields' => item => source, 'description', 'genres', 'ids', 'cover' => source. A source of 'now' (or none) leaves the book as it is.
	 * The old values of a replaced field are archived, not deleted (the History tab keeps them).
	 *
	 * @return array{items:list<string>,error:string}
	 */
	public static function apply( FisheyeBook $pBook, array $pPick, string $pPayload, array $pCoverUris ): array {
		$out = [ 'items' => [], 'error' => '' ];
		$keep = json_decode( $pPayload, true );
		if( !is_array( $keep ) ) {
			$out['error'] = KernelTools::tra( 'What was found could not be read - open the page again.' );
			return $out;
		}
		$keep += [ 'fields' => [], 'description' => '', 'genres' => [], 'ids' => [] ];
		$keep['ids'] = array_intersect_key( array_map( fn( $v ) => [ 'xkey' => (string)( $v['xkey'] ?? '' ) ], (array)$keep['ids'] ), array_flip( self::IDS ) );
		$keep['genres'] = array_values( array_map( 'strval', (array)$keep['genres'] ) );
		$archive = function( array $pItems ) use ( $pBook ): void {
			foreach( $pBook->mDb->getAll( "SELECT `xref_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `end_date` IS NULL AND `item` IN ( '".implode( "','", array_map( fn( $i ) => preg_replace( '/[^a-z_0-9]/', '', $i ), $pItems ) )."' )", [ $pBook->mContentId ] ) ?: [] as $row ) {
				$stepHash = [ 'xref_id' => (int)$row['xref_id'], 'expunge' => 3 ];
				$pBook->stepXref( $stepHash );
			}
		};

		foreach( (array)( $pPick['fields'] ?? [] ) as $item => $source ) {
			if( !in_array( $source, [ 'ol', 'wd' ], true ) || !isset( self::FIELDS[$item], $keep['fields'][$item][$source] ) ) {
				continue;
			}
			$value = $keep['fields'][$item][$source];
			$archive( [ $item ] );
			if( $item === 'publisher' ) {
				$pBook->reconcileXrefItem( 'publisher', [ [ 'key' => 'name:'.mb_strtolower( $value['name'] ), 'xorder' => 1, 'xkey_ext' => $value['name'], 'data' => [ 'source' => $source === 'wd' ? 'wikidata' : 'openlibrary' ] ] ],
					fn( array $pXrefRow ) => 'name:'.mb_strtolower( (string)$pXrefRow['xkey_ext'] ), true, true );
			} else {
				$pBook->reconcileXrefItem( $item, [ $value + [ 'key' => '', 'xorder' => 0 ] ], null );
			}
			$out['items'][] = $item.': '.( $value['name'] ?? $value['xkey_ext'] ?? $value['xkey'] ?? '' );
		}
		if( ( $pPick['ids'] ?? '' ) === 'ol' && $keep['ids'] ) {
			$archive( self::IDS );
			foreach( $keep['ids'] as $item => $value ) {
				$pBook->reconcileXrefItem( $item, [ $value + [ 'key' => '', 'xorder' => 0 ] ], null );
			}
			$out['items'][] = 'ids: '.implode( ', ', array_keys( $keep['ids'] ) );
		}
		if( ( $pPick['genres'] ?? '' ) === 'ol' && $keep['genres'] ) {
			$wanted = [];
			foreach( $keep['genres'] as $genre ) {
				$wanted[] = [ 'key' => mb_strtolower( $genre ), 'xorder' => count( $wanted ) + 1, 'xkey_ext' => $genre ];
			}
			$pBook->reconcileXrefItem( 'genre', $wanted, fn( array $pXrefRow ) => mb_strtolower( (string)$pXrefRow['xkey_ext'] ) );
			$out['items'][] = 'genre: '.implode( ', ', $keep['genres'] );
		}
		if( ( $pPick['description'] ?? '' ) === 'ol' && $keep['description'] !== '' ) {
			$storeHash = [ 'content_id' => $pBook->mContentId, 'title' => $pBook->getTitle(), 'edit' => FisheyeBook::textToHtml( $keep['description'] ) ];
			if( $pBook->store( $storeHash ) ) {
				$out['items'][] = 'description: '.mb_substr( $keep['description'], 0, 60 ).'...';
			}
		}
		$cover = (string)( $pPick['cover'] ?? '' );
		if( in_array( $cover, [ 'file', 'ol', 'wd', 'google' ], true ) && preg_match( '#^data:image/[a-z+]+;base64,([A-Za-z0-9+/=]+)$#', (string)( $pCoverUris[$cover] ?? '' ), $m ) ) {
			$bytes = base64_decode( $m[1], true );
			$tmp = tempnam( sys_get_temp_dir(), 'fm_cover_' );
			file_put_contents( $tmp, (string)$bytes );
			$stored = $bytes !== false && FisheyeBookCovers::isPicture( $bytes ) ? $pBook->replaceCover( $tmp, [ 'file' => 'file', 'ol' => 'openlibrary:', 'wd' => 'wikidata:', 'google' => 'googlebooks:' ][$cover] ) : null;
			@unlink( $tmp );
			$out['items'][] = $stored !== null ? 'cover: '.self::SOURCES[$cover] : 'the cover could not be stored';
		}
		$pBook->load();
		return $out;
	}
}

<?php
/**
 * The files of a book in the Library tree: ebooks, audio books and anything else sitting in the book's folder, as Extras on the book.
 *
 * The tree is Library/<author>/<series>/<book>/, named after the galleries and the book as the database has them. A book's folder is found by
 * walking up from the book to its series and author galleries - no guessing from the files. Inside it: ebook files at the top (epub, mobi, ...),
 * an Audio/ folder (a single file, or a wrapper folder per recording of several), Pictures/ and the cover pictures (not touched here), and any other
 * file as an 'extra'. Each becomes a liberty_xref row on the book (items ebook / audiobook / extra, xkey_ext = path under the Library root, data =
 * a small JSON blob), written by register() as a rebuild, like a season's featurettes; view_book_file.php serves them by xref_id only.
 *
 * The Library root is the fisheye_library_storage_root setting (its own disk later if wanted), else Library/ under the disk storage root.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

class FisheyeBookFiles {

	public const ITEMS = [ 'ebook', 'audiobook', 'extra' ];
	private const EBOOK = [ 'epub', 'mobi', 'azw', 'azw3', 'prc', 'lit', 'pdf', 'fb2', 'djvu' ];
	private const AUDIO = [ 'm4b', 'm4a', 'mp3', 'flac', 'ogg', 'opus', 'wma' ];
	private const PICTURE = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif', 'tif', 'tiff', 'heic', 'svg' ];
	private const MIME = [ 'epub' => 'application/epub+zip', 'mobi' => 'application/x-mobipocket-ebook', 'azw' => 'application/vnd.amazon.ebook', 'azw3' => 'application/vnd.amazon.ebook',
		'prc' => 'application/x-mobipocket-ebook', 'lit' => 'application/x-ms-reader', 'fb2' => 'application/x-fictionbook+xml', 'djvu' => 'image/vnd.djvu', 'm4b' => 'audio/mp4' ];

	/** The Library folder, with its closing slash; '' when nothing is set up. */
	public static function libraryRoot(): string {
		global $gBitSystem;
		$set = trim( (string)$gBitSystem->getConfig( 'fisheye_library_storage_root', '' ) );
		if( $set !== '' ) {
			return rtrim( $set, '/' ).'/';
		}
		$disk = function_exists( '\\Bitweaver\\Liberty\\mime_film_get_storage_root' ) ? \Bitweaver\Liberty\mime_film_get_storage_root() : '';
		return $disk !== '' ? $disk.'Library/' : '';
	}

	private static function safe( string $pName ): string {
		return trim( str_replace( '/', '-', $pName ) );
	}

	/** The galleries a content item is in: [ [ content_id, title, content_type_guid ], ... ]. */
	private static function parentsOf( int $pContentId ): array {
		global $gBitDb;
		return $gBitDb->getAll(
			"SELECT g.`content_id`, g.`title`, g.`content_type_guid` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
			 JOIN `".BIT_DB_PREFIX."liberty_content` g ON g.`content_id` = m.`gallery_content_id` WHERE m.`item_content_id` = ?", [ $pContentId ] ) ?: [];
	}

	/** Where the book's folder would be, most likely first, relative to the Library root: author/series/book or author/book. */
	public static function candidateFolders( FisheyeBook $pBook ): array {
		$book = self::safe( (string)$pBook->getTitle() );
		$out = [];
		foreach( self::parentsOf( (int)$pBook->mContentId ) as $parent ) {
			if( $parent['content_type_guid'] === 'fisheyeseries' ) {
				foreach( self::parentsOf( (int)$parent['content_id'] ) as $author ) {
					if( $author['content_type_guid'] === 'fisheyemediagal' ) {
						$out[] = self::safe( $author['title'] ).'/'.self::safe( $parent['title'] ).'/'.$book.'/';
					}
				}
			} elseif( $parent['content_type_guid'] === 'fisheyemediagal' ) {
				$out[] = self::safe( $parent['title'] ).'/'.$book.'/';
			}
		}
		return $out;
	}

	/** The book's folder on disk: [ 'rel' => 'Author/Series/Book/', 'abs' => '/.../Author/Series/Book/' ], or null. */
	public static function folderFor( FisheyeBook $pBook ): ?array {
		$root = self::libraryRoot();
		if( $root === '' ) {
			return null;
		}
		foreach( self::candidateFolders( $pBook ) as $rel ) {
			if( is_dir( $root.$rel ) ) {
				return [ 'rel' => $rel, 'abs' => $root.$rel ];
			}
		}
		return null;
	}

	private static function size( int $pBytes ): string {
		return $pBytes >= 1048576 ? round( $pBytes / 1048576, 1 ).' MB' : max( 1, (int)round( $pBytes / 1024 ) ).' KB';
	}

	/**
	 * What is in a book's folder, sorted into kinds.
	 * @return array{ebook:list<array>,audiobook:list<array>,extra:list<array>}  each: rel (under the folder), name, ext, bytes, tracks
	 */
	public static function scan( string $pAbs ): array {
		$found = [ 'ebook' => [], 'audiobook' => [], 'extra' => [] ];
		$natural = function ( array $a ) { natcasesort( $a ); return array_values( $a ); };
		foreach( $natural( array_diff( scandir( $pAbs ) ?: [], [ '.', '..' ] ) ) as $name ) {
			$path = $pAbs.$name;
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if( $name[0] === '.' || in_array( strtolower( $name ), [ 'thumbs.db', 'metadata.opf' ], true ) ) {
				continue;
			}
			if( is_dir( $path ) ) {
				continue; // Pictures/, an Audio folder (a multi-file audio book is an album - audioFolders()) and any other folder: not Extras files
			}
			if( in_array( $ext, self::PICTURE, true ) ) {
				continue; // covers and second covers
			}
			// a single audio file beside the ebook is the whole audio book
			$kind = in_array( $ext, self::EBOOK, true ) ? 'ebook' : ( in_array( $ext, self::AUDIO, true ) ? 'audiobook' : 'extra' );
			$found[$kind][] = [ 'rel' => $name, 'name' => pathinfo( $name, PATHINFO_FILENAME ), 'ext' => $ext, 'bytes' => (int)filesize( $path ), 'tracks' => $kind === 'audiobook' ? 1 : 0 ];
		}
		return $found;
	}

	/** What would be written for this book, without writing it: [ 'folder' => rel|null, 'ebook' => n, 'audiobook' => n, 'extra' => n, 'albums' => Audio folders still to load as albums ]. */
	public static function preview( FisheyeBook $pBook ): array {
		$folder = self::folderFor( $pBook );
		$counts = [ 'folder' => $folder['rel'] ?? null, 'ebook' => 0, 'audiobook' => 0, 'extra' => 0, 'albums' => 0 ];
		if( $folder ) {
			foreach( self::scan( $folder['abs'] ) as $kind => $files ) {
				$counts[$kind] = count( $files );
			}
			// An Audio folder already made into an album has its 'audiobook' row (kept, not rebuilt): it counts as that audio book. One not
			// yet loaded is 'albums' - for Load Audio Book, not a file to scan.
			foreach( self::audioFolders( $pBook ) as $audio ) {
				$audio['linked'] ? $counts['audiobook']++ : $counts['albums']++;
			}
		}
		return $counts;
	}

	/** Rebuild the book's ebook / audiobook / extra rows from its folder. A book with no folder keeps what it has. */
	public static function register( FisheyeBook $pBook ): array {
		$summary = [ 'folder' => null, 'ebook' => 0, 'audiobook' => 0, 'extra' => 0, 'errors' => [] ];
		if( !( $folder = self::folderFor( $pBook ) ) ) {
			return $summary;
		}
		$summary['folder'] = $folder['rel'];
		$files = self::scan( $folder['abs'] );
		// An audio book that is an album (its row's xref is the album's content_id) is not rebuilt from the folder: it stays.
		\Bitweaver\Liberty\LibertyContent::deleteXrefByItem( (int)$pBook->mContentId, [ 'ebook', 'extra' ] );
		$pBook->mDb->query( "DELETE FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'audiobook' AND `xref` IS NULL", [ (int)$pBook->mContentId ] );
		$normTitle = preg_replace( '/[^a-z0-9]/', '', strtolower( (string)$pBook->getTitle() ) );
		foreach( $files as $kind => $list ) {
			$order = 0;
			foreach( $list as $file ) {
				$order++;
				$data = [ 'format' => $file['ext'], 'size' => self::size( $file['bytes'] ) ];
				if( $kind === 'ebook' ) {
					$stem = preg_replace( '/[^a-z0-9]/', '', strtolower( $file['name'] ) );
					$data['edition'] = ( $stem === $normTitle || str_starts_with( $stem, $normTitle ) && strlen( $stem ) <= strlen( $normTitle ) + 20 && count( $list ) === 1 ) ? '' : $file['name'];
				} elseif( $kind === 'audiobook' ) {
					$data['narrator'] = '';
					$data['tracks'] = $file['tracks'];
				} else {
					$data['note'] = '';
				}
				if( count( $list ) > 1 ) {
					$data['multi'] = true;
				}
				$hash = [ 'content_id' => $pBook->mContentId, 'item' => $kind, 'xkey_ext' => $folder['rel'].$file['rel'], 'edit' => json_encode( $data ), 'xorder' => $order ];
				if( $pBook->storeXref( $hash ) ) {
					$summary[$kind]++;
				} else {
					$summary['errors'][] = $file['rel'].': '.implode( '; ', array_map( 'strval', array_values( $pBook->mErrors ) ) );
				}
			}
		}
		return $summary;
	}

	/**
	 * The book's Audio folders (a folder whose name starts "Audio", with at least one audio file in it): each is one audio book, an album.
	 * 'linked' = the album's content_id once its 'audiobook' row exists (the row's xkey_ext is the folder, the xref the album), else null.
	 * @return list<array{rel:string,name:string,tracks:int,bytes:int,linked:?int}>  rel = under the Library root, ending '/'
	 */
	public static function audioFolders( FisheyeBook $pBook ): array {
		$folder = self::folderFor( $pBook );
		if( !$folder ) {
			return [];
		}
		$linked = [];
		foreach( $pBook->mDb->getAll( "SELECT `xkey_ext`, `xref` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'audiobook' AND `xref` IS NOT NULL AND `end_date` IS NULL", [ (int)$pBook->mContentId ] ) ?: [] as $row ) {
			$linked[$row['xkey_ext']] = (int)$row['xref'];
		}
		$ret = [];
		$names = array_filter( scandir( $folder['abs'] ) ?: [], fn( $n ) => $n[0] !== '.' && is_dir( $folder['abs'].$n ) && stripos( $n, 'audio' ) === 0 );
		natcasesort( $names );
		foreach( $names as $name ) {
			$tracks = array_filter( scandir( $folder['abs'].$name ) ?: [], fn( $f ) => is_file( $folder['abs'].$name.'/'.$f ) && in_array( strtolower( pathinfo( $f, PATHINFO_EXTENSION ) ), self::AUDIO, true ) );
			if( !$tracks ) {
				continue;
			}
			$bytes = 0;
			foreach( $tracks as $t ) {
				$bytes += (int)filesize( $folder['abs'].$name.'/'.$t );
			}
			$rel = $folder['rel'].$name.'/';
			$ret[] = [ 'rel' => $rel, 'name' => $name, 'tracks' => count( $tracks ), 'bytes' => $bytes, 'linked' => $linked[$rel] ?? null ];
		}
		return $ret;
	}

	/**
	 * The gallery the audio-book albums are filed under (no folder behind it, just a tidy parent), found by the content_id kept in
	 * the config - never by its name - and made the first time: under the top gallery above the book (the Library).
	 */
	public static function audioBooksGalleryId( FisheyeBook $pBook ): int {
		global $gBitSystem, $gBitDb;
		$id = (int)$gBitSystem->getConfig( 'fisheyemedia_audio_books_gallery', 0 );
		if( $id && $gBitDb->getOne( "SELECT `content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery` WHERE `content_id` = ?", [ $id ] ) ) {
			return $id;
		}
		$top = (int)$pBook->mContentId;
		for( $i = 0; $i < 6; $i++ ) {
			$parent = $gBitDb->getOne( "SELECT `gallery_content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` WHERE `item_content_id` = ?", [ $top ] );
			if( !$parent ) {
				break;
			}
			$top = (int)$parent;
		}
		$gallery = new FisheyeMediaGallery();
		$storeHash = [ 'title' => 'Audio Books', 'gallery_pagination' => FISHEYE_PAGINATION_MUSIC_GRID ];   // store() takes it by reference
		if( !$gallery->store( $storeHash ) ) {
			return 0;
		}
		if( $top !== (int)$pBook->mContentId ) {
			$parentGallery = new \Bitweaver\Fisheye\FisheyeGallery( null, $top );
			$parentGallery->load();
			$parentGallery->addItem( $gallery->mContentId );
		}
		$gBitSystem->storeConfig( 'fisheyemedia_audio_books_gallery', (int)$gallery->mContentId, FISHEYEMEDIA_PKG_NAME );
		return (int)$gallery->mContentId;
	}

	/**
	 * Who reads this recording, from its first track's album tag ("Oliver Twist (read by John Lee)"; also "narrated by" / "performed by"), or ''.
	 */
	private static function narratorFor( string $pAbsFolder ): string {
		$tracks = glob( rtrim( $pAbsFolder, '/' ).'/*.mp3' ) ?: [];
		natcasesort( $tracks );
		if( !$tracks ) {
			return '';
		}
		require_once UTIL_PKG_INCLUDE_PATH.'getid3/getid3/getid3.php';
		$info = ( new \getID3() )->analyze( reset( $tracks ) );
		$album = (string)( $info['tags']['id3v2']['album'][0] ?? $info['tags']['id3v1']['album'][0] ?? '' );
		return preg_match( '/\(\s*(?:read|narrated|performed)\s+by\s+(.+?)\s*\)\s*$/i', $album, $m ) ? trim( $m[1] ) : '';
	}

	/**
	 * A narrator credit on the book (several recordings, several narrators): added once per name, marked as from the audio so a reload of the catalogue's credits
	 * leaves it, and linked to the person's contact when there is one.
	 */
	private static function addNarratorCredit( FisheyeBook $pBook, string $pName ): void {
		$have = $pBook->mDb->getAll( "SELECT `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'narrator' AND `end_date` IS NULL", [ (int)$pBook->mContentId ] ) ?: [];
		foreach( $have as $row ) {
			if( mb_strtolower( trim( (string)$row['xkey_ext'] ) ) === mb_strtolower( $pName ) ) {
				return;
			}
		}
		$hash = [ 'content_id' => $pBook->mContentId, 'item' => 'narrator', 'xkey_ext' => $pName, 'edit' => json_encode( [ 'source' => 'audio' ] ), 'xorder' => count( $have ) + 1 ];
		$pBook->storeXref( $hash );
		FisheyeBookCredits::linkBookCredits( (int)$pBook->mContentId );
	}

	/**
	 * Make one Audio folder an album: the album is made with the music album code (no gallery path involved - its folder is the book's),
	 * filed under the audio books gallery, titled 'Audio Book' (edit it afterwards), then the book's 'audiobook' row is written:
	 * xkey_ext the folder, xref the album's content_id.
	 * @return array{created?:int,error?:string,tracks?:int}
	 */
	public static function loadAudioFolder( FisheyeBook $pBook, string $pRel ): array {
		// One loading press at a time (FisheyeBookLoader::lockPress()): loading a book's folders is slow, a press that is pressed again runs alongside the first, and
		// both would find the folder not yet an album and make one each. Taken before the folder is looked at, so a later press finds what the first made.
		FisheyeBookLoader::lockPress();
		$have = array_values( array_filter( self::audioFolders( $pBook ), fn( $f ) => $f['rel'] === $pRel ) );
		if( !$have ) {
			return [ 'error' => 'No audio folder '.$pRel.' in this book.' ];
		}
		if( $have[0]['linked'] ) {
			return [ 'already' => $have[0]['linked'] ];
		}
		$root = \Bitweaver\Liberty\mime_film_get_storage_root();
		$library = self::libraryRoot();
		if( $root === '' || !str_starts_with( $library, $root ) ) {
			return [ 'error' => 'The Library is not under the disk storage root.' ];
		}
		// Who reads it: the recording's own album tag says "<title> (read by <name>)". Several recordings of one book are then told apart by their titles,
		// and the narrator is on the audio book row and a credit of the book. Without the tag it is the plain default, edited afterwards.
		$narrator = self::narratorFor( $library.$pRel );
		$title = $narrator !== '' ? $pBook->getTitle().' (read by '.$narrator.')' : 'Audio Book'; // the short form (before ' Book') is the row's xkey
		$result = FisheyeAlbum::registerFromDisk( substr( $library, strlen( $root ) ).$pRel, $title, self::audioBooksGalleryId( $pBook ), null, false, null, true );
		if( empty( $result['created'] ) ) {
			return [ 'error' => $result['error'] ?? 'The album was not made.' ];
		}
		$data = [ 'format' => 'mp3', 'size' => self::size( $have[0]['bytes'] ), 'narrator' => $narrator, 'tracks' => $have[0]['tracks'] ];
		$hash = [ 'content_id' => $pBook->mContentId, 'item' => 'audiobook', 'xkey' => $narrator !== '' ? 'Read by '.$narrator : preg_replace( '/\\s+Book$/i', '', $title ), 'xkey_ext' => $pRel, 'xref' => (int)$result['created'], 'edit' => json_encode( $data ), 'xorder' => 1 ];
		if( !$pBook->storeXref( $hash ) ) {
			return [ 'error' => 'The album was made (content_id '.$result['created'].') but the book\'s audiobook row was not: '.implode( '; ', array_map( 'strval', array_values( $pBook->mErrors ) ) ) ];
		}
		// Two readers ("Sean Barrett and Teresa Gallagher") are two credits.
		$narrators = array_values( array_filter( array_map( 'trim', preg_split( '/\s*(?:,|&|\band\b)\s*/iu', $narrator ) ?: [] ) ) );
		foreach( $narrators as $name ) {
			self::addNarratorCredit( $pBook, $name );
		}
		// The album stands alone: the book's description, and a 'book' external link back to the book (xkey = its content_id, shown on the album
		// with the other external links). Both are HTML.
		$album = new FisheyeAlbum( null, (int)$result['created'] );
		$album->load();
		$bookText = trim( (string)$pBook->getField( 'data' ) );
		if( $bookText !== '' ) {
			$descriptionHash = [ 'content_id' => $album->mContentId, 'title' => $album->getTitle(), 'edit' => $bookText ];
			$album->store( $descriptionHash );
		}
		$bookLink = [ 'content_id' => $album->mContentId, 'item' => 'book', 'xkey' => (string)(int)$pBook->mContentId ];
		$album->storeXref( $bookLink );
		// And the reader(s) as the album's own narrator rows (a name now, a contact link when it is made) - where the scheme has the item (it is applied from
		// the Local Scheme page; without it the album simply has no narrator row yet).
		if( !empty( $narrators ) && (int)$album->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref_item` WHERE `content_type_guid` = 'fisheyealbum' AND `item` = 'narrator'" ) ) {
			foreach( $narrators as $i => $name ) {
				$narratorHash = [ 'content_id' => $album->mContentId, 'item' => 'narrator', 'xkey_ext' => $name, 'edit' => json_encode( [ 'source' => 'audio' ] ), 'xorder' => $i + 1 ];
				$album->storeXref( $narratorHash );
			}
		}
		// the one-row-per-file rows an earlier scan made for this folder are replaced by the album
		foreach( $pBook->mDb->getAll( "SELECT `xref_id`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'audiobook' AND `xref` IS NULL", [ (int)$pBook->mContentId ] ) ?: [] as $old ) {
			if( str_starts_with( (string)$old['xkey_ext'], $pRel ) ) {
				$pBook->mDb->query( "DELETE FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `xref_id` = ?", [ (int)$old['xref_id'] ] );
			}
		}
		return [ 'created' => (int)$result['created'], 'tracks' => (int)( $result['tracks'] ?? 0 ) ];
	}

	/**
	 * The real file behind a stored row, or null: only a path under the Library root, only a file. A wrapper folder row (a multi-file audio
	 * book, xkey_ext ending '/') gives its $pTrack'th file (1 = first) in natural order.
	 */
	public static function pathFor( string $pXkeyExt, int $pTrack = 1 ): ?string {
		$root = realpath( self::libraryRoot() );
		if( !$root || $pXkeyExt === '' || str_contains( $pXkeyExt, '..' ) ) {
			return null;
		}
		$path = self::libraryRoot().$pXkeyExt;
		if( str_ends_with( $pXkeyExt, '/' ) && is_dir( $path ) ) {
			$tracks = array_values( array_filter( scandir( $path ) ?: [], fn( $f ) => is_file( $path.$f ) && in_array( strtolower( pathinfo( $f, PATHINFO_EXTENSION ) ), self::AUDIO, true ) ) );
			natcasesort( $tracks );
			$tracks = array_values( $tracks );
			$path = isset( $tracks[$pTrack - 1] ) ? $path.$tracks[$pTrack - 1] : '';
		}
		$real = $path !== '' ? realpath( $path ) : false;
		return $real && is_file( $real ) && str_starts_with( $real, $root.'/' ) ? $real : null;
	}

	public static function mimeFor( string $pPath ): ?string {
		return self::MIME[strtolower( pathinfo( $pPath, PATHINFO_EXTENSION ) )] ?? null;
	}
}

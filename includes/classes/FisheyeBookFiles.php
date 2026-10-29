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
	private const PICTURE = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' ];
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
				if( strtolower( $name ) === 'audio' ) {
					foreach( $natural( array_diff( scandir( $path ) ?: [], [ '.', '..' ] ) ) as $audio ) {
						$audioPath = $path.'/'.$audio;
						if( is_file( $audioPath ) && in_array( strtolower( pathinfo( $audio, PATHINFO_EXTENSION ) ), self::AUDIO, true ) ) {
							$found['audiobook'][] = [ 'rel' => $name.'/'.$audio, 'name' => pathinfo( $audio, PATHINFO_FILENAME ), 'ext' => strtolower( pathinfo( $audio, PATHINFO_EXTENSION ) ), 'bytes' => (int)filesize( $audioPath ), 'tracks' => 1 ];
						} elseif( is_dir( $audioPath ) ) {
							$tracks = array_filter( scandir( $audioPath ) ?: [], fn( $f ) => is_file( $audioPath.'/'.$f ) && in_array( strtolower( pathinfo( $f, PATHINFO_EXTENSION ) ), self::AUDIO, true ) );
							if( $tracks ) {
								$bytes = 0;
								foreach( $tracks as $t ) {
									$bytes += (int)filesize( $audioPath.'/'.$t );
								}
								$found['audiobook'][] = [ 'rel' => $name.'/'.$audio.'/', 'name' => $audio, 'ext' => strtolower( pathinfo( reset( $tracks ), PATHINFO_EXTENSION ) ), 'bytes' => $bytes, 'tracks' => count( $tracks ) ];
							}
						}
					}
				}
				continue; // Pictures/ and any other folder: the raw material, not Extras
			}
			if( in_array( $ext, self::PICTURE, true ) ) {
				continue; // covers and second covers
			}
			$kind = in_array( $ext, self::EBOOK, true ) ? 'ebook' : 'extra';
			$found[$kind][] = [ 'rel' => $name, 'name' => pathinfo( $name, PATHINFO_FILENAME ), 'ext' => $ext, 'bytes' => (int)filesize( $path ), 'tracks' => 0 ];
		}
		return $found;
	}

	/** What would be written for this book, without writing it: [ 'folder' => rel|null, 'ebook' => n, 'audiobook' => n, 'extra' => n ]. */
	public static function preview( FisheyeBook $pBook ): array {
		$folder = self::folderFor( $pBook );
		$counts = [ 'folder' => $folder['rel'] ?? null, 'ebook' => 0, 'audiobook' => 0, 'extra' => 0 ];
		if( $folder ) {
			foreach( self::scan( $folder['abs'] ) as $kind => $files ) {
				$counts[$kind] = count( $files );
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
		\Bitweaver\Liberty\LibertyContent::deleteXrefByItem( (int)$pBook->mContentId, self::ITEMS );
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

<?php
/**
 * The places a book's cover can be looked for by its ISBN, and a safe way to fetch a picture from an address typed in.
 * Used by pick_cover.php: the candidates are listed with their sizes and nothing is stored until one is chosen.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

class FisheyeBookCovers {

	/** Largest picture taken from an address, in bytes. */
	const MAX_BYTES = 15000000;
	/** Seconds each lookup may take. */
	const TIMEOUT = 12;

	/**
	 * Cover addresses worth trying for a book: Open Library by each of its ISBNs, Google Books at its largest size and
	 * Amazon's image-by-ISBN-10 address (not documented by Amazon, and answers with a 1 pixel picture where it has none).
	 *
	 * @param string $pIsbn13 ISBN-13, '' when the book has none
	 * @param string $pIsbn10 ISBN-10, '' when the book has none
	 * @return list<array{key:string,label:string,url:string}>
	 */
	public static function candidates( string $pIsbn13, string $pIsbn10 ): array {
		$list = [];
		foreach( array_unique( array_filter( [ $pIsbn13, $pIsbn10 ] ) ) as $isbn ) {
			$list[] = [ 'key' => 'ol'.$isbn, 'label' => 'Open Library ('.$isbn.')', 'url' => 'https://covers.openlibrary.org/b/isbn/'.$isbn.'-L.jpg?default=false' ];
		}
		if( $pIsbn13 !== '' || $pIsbn10 !== '' ) {
			$list[] = [ 'key' => 'google', 'label' => 'Google Books', 'url' => 'https://books.google.com/books/content?vid=ISBN'.( $pIsbn13 ?: $pIsbn10 ).'&printsec=frontcover&img=1&zoom=0' ];
		}
		if( $pIsbn13 !== '' ) {
			$list[] = [ 'key' => 'abebooks', 'label' => 'AbeBooks', 'url' => 'https://pictures.abebooks.com/isbn/'.$pIsbn13.'-uk.jpg' ];
		}
		if( $pIsbn10 !== '' ) {
			$list[] = [ 'key' => 'amazon', 'label' => 'Amazon', 'url' => 'https://m.media-amazon.com/images/P/'.$pIsbn10.'.01._SCLZZZZZZZ_.jpg' ];
		}
		return $list;
	}

	/**
	 * Fetch several pictures at once from the fixed addresses candidates() gives.
	 *
	 * @param array<string,string> $pUrls key => address
	 * @return array<string,string> key => picture bytes, only for the ones that answered with a real picture (at least 40 pixels each way)
	 */
	public static function fetchMany( array $pUrls ): array {
		$multi = curl_multi_init();
		$handles = [];
		foreach( $pUrls as $key => $url ) {
			$handles[$key] = self::handle( $url );
			curl_multi_add_handle( $multi, $handles[$key] );
		}
		do {
			curl_multi_exec( $multi, $running );
			if( $running ) {
				curl_multi_select( $multi, 1.0 );
			}
		} while( $running );
		$found = [];
		foreach( $handles as $key => $ch ) {
			$bytes = curl_multi_getcontent( $ch );
			if( curl_getinfo( $ch, CURLINFO_HTTP_CODE ) === 200 && self::isPicture( (string)$bytes ) ) {
				$found[$key] = (string)$bytes;
			}
			curl_multi_remove_handle( $multi, $ch );
		}
		curl_multi_close( $multi );
		return $found;
	}

	/**
	 * Fetch whatever an address someone typed or pasted gives. Only http and https, and only to a public address: each
	 * redirect is checked again, so the address cannot lead to this machine or the local network.
	 *
	 * @param string $pError set to the reason when null comes back
	 * @return string|null the body, a picture or a page
	 */
	public static function fetchGuarded( string $pUrl, ?string &$pError = null ): ?string {
		$url = trim( $pUrl );
		for( $hop = 0; $hop < 4; $hop++ ) {
			$parts = parse_url( $url );
			if( !$parts || !in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true ) || empty( $parts['host'] ) ) {
				$pError = 'That is not a web address (it must start http:// or https://).';
				return null;
			}
			$ip = filter_var( $parts['host'], FILTER_VALIDATE_IP ) ? $parts['host'] : gethostbyname( $parts['host'] );
			if( !filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				$pError = 'That address is not a public web site.';
				return null;
			}
			$ch = self::handle( $url, false );
			$body = curl_exec( $ch );
			$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			if( $code >= 300 && $code < 400 && ( $next = curl_getinfo( $ch, CURLINFO_REDIRECT_URL ) ) ) {
				$url = $next;
				continue;
			}
			if( $body === false || $code !== 200 ) {
				$pError = 'The site did not give the page or picture (HTTP '.$code.').';
				return null;
			}
			return (string)$body;
		}
		$pError = 'Too many redirects.';
		return null;
	}

	/** One picture from an address (see fetchGuarded()); null with the reason when it is not a picture at least 40 pixels each way. */
	public static function fetchAddress( string $pUrl, ?string &$pError = null ): ?string {
		$body = self::fetchGuarded( $pUrl, $pError );
		if( $body !== null && !self::isPicture( $body ) ) {
			$pError = 'That address is not a picture, or it is too small to be a cover.';
			return null;
		}
		return $body;
	}

	/**
	 * The pictures behind an address: the picture itself, or - when it is a web page, such as a bookseller's listing - the
	 * pictures the page shows. The page's social-preview image comes first, then the others in page order; a size marker in the
	 * file name (Amazon's and AbeBooks' "._SL300_") is dropped to ask for the full-size picture, and logos, icons and anything
	 * under 120 pixels are left out.
	 *
	 * @param string $pError set to the reason when the list is empty
	 * @return list<array{url:string,bytes:string}> at most $pMax
	 */
	public static function picturesAt( string $pUrl, ?string &$pError = null, int $pMax = 8 ): array {
		$body = self::fetchGuarded( $pUrl, $pError );
		if( $body === null ) {
			return [];
		}
		if( self::isPicture( $body ) ) {
			return [ [ 'url' => trim( $pUrl ), 'bytes' => $body ] ];
		}
		$html = str_replace( '\\/', '/', html_entity_decode( $body, ENT_QUOTES ) );
		$urls = [];
		if( preg_match_all( '#<meta[^>]+(?:og:image|twitter:image)[^>]+content=["\']([^"\']+)#i', $html, $m ) ) {
			$urls = $m[1];
		}
		if( preg_match_all( '#https?://[^"\'\s<>\\)]+?\.(?:jpe?g|png)#i', $html, $m ) ) {
			$urls = array_merge( $urls, $m[0] );
		}
		$base = parse_url( trim( $pUrl ) );
		$found = [];
		foreach( $urls as $url ) {
			$url = preg_replace( '#\._[A-Za-z0-9_,%-]+_(\.(?:jpe?g|png))$#i', '$1', $url );
			if( str_starts_with( $url, '//' ) ) {
				$url = ( $base['scheme'] ?? 'https' ).':'.$url;
			}
			if( isset( $found[$url] ) || preg_match( '#(icon|logo|sprite|/assets?\.|badge|avatar|flag|/cc-|pixel|banner)#i', $url ) ) {
				continue;
			}
			$found[$url] = null;
			if( count( $found ) >= $pMax + 8 ) {
				break;
			}
		}
		$pictures = [];
		foreach( array_keys( $found ) as $url ) {
			$bytes = self::fetchAddress( $url );
			$info = $bytes !== null ? getimagesizefromstring( $bytes ) : false;
			if( $info && $info[0] >= 120 && $info[1] >= 120 ) {
				$pictures[] = [ 'url' => $url, 'bytes' => $bytes ];
				if( count( $pictures ) >= $pMax ) {
					break;
				}
			}
		}
		if( !$pictures ) {
			$pError = 'No usable picture was found at that address.';
		}
		return $pictures;
	}

	/**
	 * What the page shows of a picture: its size in pixels and bytes, and a small copy of it as a data address so the list needs
	 * no second request.
	 *
	 * @return array{width:int,height:int,kb:int,preview:string}
	 */
	public static function describe( string $pBytes ): array {
		$info = getimagesizefromstring( $pBytes );
		$preview = '';
		if( function_exists( 'imagecreatefromstring' ) && ( $im = @imagecreatefromstring( $pBytes ) ) ) {
			$small = imagescale( $im, max( 1, (int)round( 260 * $info[0] / $info[1] ) ), 260 );
			if( $small ) {
				ob_start();
				imagejpeg( $small, null, 80 );
				$preview = 'data:image/jpeg;base64,'.base64_encode( (string)ob_get_clean() );
			}
		}
		return [ 'width' => (int)$info[0], 'height' => (int)$info[1], 'kb' => (int)round( strlen( $pBytes ) / 1024 ), 'preview' => $preview ];
	}

	/** True when the bytes are an image at least 40 pixels each way (the sites' "no cover" answers are 1 pixel). */
	public static function isPicture( string $pBytes ): bool {
		$info = $pBytes !== '' ? @getimagesizefromstring( $pBytes ) : false;
		return $info && $info[0] >= 40 && $info[1] >= 40;
	}

	/** @return \CurlHandle */
	private static function handle( string $pUrl, bool $pFollow = true ) {
		$ch = curl_init( $pUrl );
		curl_setopt_array( $ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => $pFollow,
			CURLOPT_MAXREDIRS => 3,
			CURLOPT_TIMEOUT => self::TIMEOUT,
			CURLOPT_CONNECTTIMEOUT => 6,
			CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; bitweaver-fisheyemedia)',
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_NOPROGRESS => false,
			CURLOPT_PROGRESSFUNCTION => fn( $r, $total, $got ) => $got > self::MAX_BYTES ? 1 : 0,
		] );
		return $ch;
	}
}

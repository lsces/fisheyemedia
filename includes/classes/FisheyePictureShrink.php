<?php
/**
 * A reduced copy of a picture too large for ImageMagick's policy (width and height 8000, area 32 megapixels: the policy refuses the read before one pixel is
 * decoded), made so the thumbnails can still be drawn from it. A JPEG is read at a fraction of its size (libjpeg scales while decoding, so the full picture is
 * never held); any other type goes through GD when its memory need fits. The copy is a temporary JPEG the caller removes. The original is never touched.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

class FisheyePictureShrink {

	/** Longest side of the copy: enough for the largest thumbnail with room to spare. */
	public const LONG_SIDE = 4000;

	/** Whether ImageMagick's policy would refuse this picture (limits kept a little under 8000 px and 32 MP). */
	public static function tooBig( string $pFile ): bool {
		[ $w, $h ] = @getimagesize( $pFile ) ?: [ 0, 0 ];
		return $w > 7900 || $h > 7900 || $w * $h > 31000000;
	}

	/**
	 * @param string $pDest  the file to write (a .jpg)
	 * @return string|null   $pDest, or null when the picture could not be reduced
	 */
	public static function make( string $pFile, string $pDest ): ?string {
		[ $w, $h, $type ] = @getimagesize( $pFile ) ?: [ 0, 0, 0 ];
		if( !$w || !$h ) {
			return null;
		}
		try {
			if( $type === IMAGETYPE_JPEG && extension_loaded( 'imagick' ) ) {
				$im = new \Imagick();
				$im->setOption( 'jpeg:size', self::LONG_SIDE.'x'.self::LONG_SIDE );   // libjpeg scales by 1/2, 1/4 or 1/8 while it decodes
				$im->readImage( $pFile );
				$im->thumbnailImage( self::LONG_SIDE, self::LONG_SIDE, true );
				$im->setImageFormat( 'jpeg' );
				$im->setImageCompressionQuality( 88 );
				$im->writeImage( $pDest );
				$im->clear();
				return is_file( $pDest ) && filesize( $pDest ) > 0 ? $pDest : null;
			}
		} catch( \Throwable $e ) {
			// fall through to GD
		}
		// GD holds about 5 bytes a pixel for the source and then the reduced copy; leave a fifth of the memory limit free
		$limit = self::bytes( (string)ini_get( 'memory_limit' ) );
		if( $limit > 0 && $w * $h * 5 > $limit * 0.8 - memory_get_usage( true ) ) {
			return null;
		}
		$src = match( $type ) {
			IMAGETYPE_JPEG => @imagecreatefromjpeg( $pFile ),
			IMAGETYPE_PNG  => @imagecreatefrompng( $pFile ),
			IMAGETYPE_GIF  => @imagecreatefromgif( $pFile ),
			IMAGETYPE_WEBP => function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $pFile ) : false,
			IMAGETYPE_BMP  => function_exists( 'imagecreatefrombmp' ) ? @imagecreatefrombmp( $pFile ) : false,
			default        => false,
		};
		if( !$src ) {
			return null;
		}
		$scaled = imagescale( $src, $w >= $h ? self::LONG_SIDE : (int)round( $w * self::LONG_SIDE / $h ), $h > $w ? self::LONG_SIDE : (int)round( $h * self::LONG_SIDE / $w ) );
		unset( $src );
		$ok = $scaled && imagejpeg( $scaled, $pDest, 88 );
		unset( $scaled );
		return $ok && is_file( $pDest ) ? $pDest : null;
	}

	/**
	 * Whether the first page of a PDF would be drawn past the policy: the thumbnailer renders at 150 dpi, so a page over 3790 points on a side (or 31 megapixels
	 * at that resolution) is refused. The page is measured as a viewer draws it, inside the CropBox (pdfinfo's "Page size").
	 */
	public static function pdfTooBig( string $pFile ): bool {
		$info = (string)shell_exec( 'pdfinfo -f 1 -l 1 '.escapeshellarg( $pFile ).' 2>/dev/null' );
		if( !preg_match( '/Page(?:\s+1)? size:\s+([\d.]+) x ([\d.]+) pts/', $info, $m ) ) {
			return false;
		}
		$w = (float)$m[1] * 150 / 72;
		$h = (float)$m[2] * 150 / 72;
		return $w > 7900 || $h > 7900 || $w * $h > 31000000;
	}

	/**
	 * The first page of a PDF drawn by pdftoppm (poppler) at a reduced size, long side LONG_SIDE/2, into a JPEG the caller removes.
	 *
	 * @param string $pDest  the file to write (a .jpg)
	 * @return string|null   $pDest, or null when the page could not be drawn
	 */
	public static function pdfPage( string $pFile, string $pDest ): ?string {
		$base = preg_replace( '/\.jpg$/', '', $pDest );
		shell_exec( 'pdftoppm -f 1 -l 1 -jpeg -jpegopt quality=88 -singlefile -scale-to '.( self::LONG_SIDE / 2 ).' '.escapeshellarg( $pFile ).' '.escapeshellarg( $base ).' 2>/dev/null' );
		return is_file( $pDest ) && filesize( $pDest ) > 0 ? $pDest : null;
	}

	private static function bytes( string $pIni ): int {
		$n = (int)$pIni;
		return match( strtolower( substr( trim( $pIni ), -1 ) ) ) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
	}
}

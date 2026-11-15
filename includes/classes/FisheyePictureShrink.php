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

	private static function bytes( string $pIni ): int {
		$n = (int)$pIni;
		return match( strtolower( substr( trim( $pIni ), -1 ) ) ) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
	}
}

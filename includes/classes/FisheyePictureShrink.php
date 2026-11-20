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

	/** Whether ImageMagick's policy would refuse this picture (see liberty_picture_too_big()). */
	public static function tooBig( string $pFile ): bool {
		return \Bitweaver\Liberty\liberty_picture_too_big( $pFile );
	}

	/**
	 * A reduced temporary JPEG of the picture (see liberty_picture_shrink(), which the stored-picture thumbnailer uses as well).
	 *
	 * @param string $pDest  the file to write (a .jpg)
	 * @return string|null   $pDest, or null when the picture could not be reduced
	 */
	public static function make( string $pFile, string $pDest ): ?string {
		return \Bitweaver\Liberty\liberty_picture_shrink( $pFile, $pDest, self::LONG_SIDE );
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
}

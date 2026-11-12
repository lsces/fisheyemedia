<?php
/**
 * Whether a top-level gallery of the media library (Films, Magazines, ...) is one the current user may open, for the menu entry that leads to it.
 * The entry is judged by the gallery's own access - the permission and the protector settings that decide whether its page opens - so the menu and
 * the page can never disagree; a gallery that does not exist on this site simply has no entry.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeGallery;

class FisheyeLibraryMenu {
	/** @var array<string,bool> answers kept for the request: the menu asks once per entry on every page */
	private static array $answers = [];

	/** Smarty modifier library_visible: {if 'Films'|library_visible} - true when the top-level gallery of that title can be opened by the user. */
	public static function visible( $pTitle ): bool {
		$title = (string)$pTitle;
		if( !isset( self::$answers[$title] ) ) {
			$galleryId = $title === '' ? null : FisheyeGallery::getTopGalleryId( $title );
			$visible = false;
			if( $galleryId ) {
				$gallery = new FisheyeGallery( $galleryId );
				// load() is what refuses a gallery the viewer's roles are not allowed to see (isValid() stays true: the object already has its id)
				$visible = $gallery->load() && $gallery->isValid() && $gallery->hasViewPermission();
			}
			self::$answers[$title] = $visible;
		}
		return self::$answers[$title];
	}
}

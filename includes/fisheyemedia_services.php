<?php
/**
 * Service functions fisheyemedia registers (see bit_setup_inc.php). LibertyContent::invokeServices() calls a service by its name inside
 * the Bitweaver\Liberty namespace, so they are declared there.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Liberty;

use Bitweaver\Fisheyemedia\FisheyeMediaGallery;

/**
 * A gallery shown with the Images Grid layout (an item's pictures folder) is given {$folderOwner} - the item it belongs to - for its
 * title to link to. Runs for every content display, so it leaves anything else alone at once.
 */
function fisheyemedia_content_display( $pObject, &$pParamHash ) {
	if( $pObject->isValid() && $pObject->getContentType() === 'fisheyegallery' && $pObject->getLayout() === FISHEYE_PAGINATION_IMAGES_GRID ) {
		global $gBitSmarty;
		$gBitSmarty->assign( 'folderOwner', FisheyeMediaGallery::getPicturesFolderOwner( $pObject ) );
	}
}

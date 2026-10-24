<?php
/**
 * Makes one of an item's pictures-folder images the item's thumbnail (the Images tab's Set as Thumbnail for a picture
 * that lives in the folder rather than in the item's own 'image' rows), then returns to the item's edit page.
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeImage;

require_once '../kernel/includes/setup_inc.php';
global $gBitSystem;

$gBitSystem->verifyPackage( 'fisheye' );

$gContent = FisheyeImage::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() || !method_exists( $gContent, 'promoteFolderImageToThumbnail' ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No item exists with the given ID' ), 'error.tpl' );
}
$gContent->verifyUpdatePermission();

if( !$gContent->promoteFolderImageToThumbnail( (int)( $_REQUEST['image_content_id'] ?? 0 ) ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'That picture could not be made the thumbnail.' ), 'error.tpl' );
}
KernelTools::bit_redirect( $gContent->getEditUrl() );

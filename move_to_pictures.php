<?php
/**
 * Moves one of an item's own 'image' rows into its pictures folder (the Images tab's Move to pictures), then returns to
 * the item's edit page.
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
$gBitSystem->verifyPermission( 'p_fisheye_upload' );

$gContent = FisheyeImage::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() || !method_exists( $gContent, 'moveImageToPictures' ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No item exists with the given ID' ), 'error.tpl' );
}
$gContent->verifyUpdatePermission();

$moved = $gContent->moveImageToPictures( (int)( $_REQUEST['xref_id'] ?? 0 ) );
if( !empty( $moved['error'] ) ) {
	$gBitSystem->fatalError( KernelTools::tra( $moved['error'] ), 'error.tpl' );
}
KernelTools::bit_redirect( $gContent->getEditUrl() );

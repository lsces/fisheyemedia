<?php
/**
 * Adds pictures to an item's pictures folder from the item's own page: finds the folder (Images / <Types> / <title>),
 * makes it when it is not there yet, and hands over to fisheye's normal upload with the folder already chosen.
 * Works for any media item that has the pictures folder methods (book, film, season, album, programme).
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';
global $gBitSystem;

$gBitSystem->verifyPackage( 'fisheye' );
$gBitSystem->verifyPermission( 'p_fisheye_upload' );

// Any content that has a pictures folder - a media item, or a contact.
$gContent = !empty( $_REQUEST['content_id'] ) ? \Bitweaver\Liberty\LibertyBase::getLibertyObject( (int)$_REQUEST['content_id'] ) : null;
if( !$gContent || !$gContent->isValid() || !method_exists( $gContent, 'ensurePicturesFolder' ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No item exists with the given ID' ), 'error.tpl' );
}
$gContent->verifyUpdatePermission();

$folder = $gContent->ensurePicturesFolder();
if( !empty( $folder['error'] ) ) {
	$gBitSystem->fatalError( KernelTools::tra( $folder['error'] ), 'error.tpl' );
}
KernelTools::bit_redirect( FISHEYE_PKG_URL.'upload.php?gallery_id='.$folder['gallery_id'] );

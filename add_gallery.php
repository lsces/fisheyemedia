<?php
/**
 * Add a gallery inside a managed gallery (Library author and series galleries, Documentation galleries - see FisheyeManagedGallery): a title and a
 * description, and the new gallery takes the layout of the one it is made in. Any other gallery is handed to fisheye's own create page with this one as
 * the parent.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\HttpStatusCodes;
use Bitweaver\Fisheye\FisheyeGallery;

require_once '../kernel/includes/setup_inc.php';
global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'fisheye' );

$gParent = FisheyeGallery::lookup( [ 'gallery_id' => (int)( $_REQUEST['gallery_id'] ?? 0 ) ] );
if( !$gParent || !$gParent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No gallery exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gParent->load();
if( !FisheyeManagedGallery::isManaged( $gParent ) ) {
	KernelTools::bit_redirect( FISHEYE_PKG_URL.'edit.php?gallery_additions[]='.$gParent->mGalleryId );
}
$gParent->verifyUpdatePermission();
( new FisheyeGallery() )->verifyCreatePermission();

if( !empty( $_REQUEST['cancelgallery'] ) ) {
	KernelTools::bit_redirect( $gParent->getDisplayUrl() );
}

$feedback = [];
$formTitle = '';
$formEdit = '';
if( !empty( $_REQUEST['savegallery'] ) ) {
	$formTitle = trim( (string)( $_REQUEST['title'] ?? '' ) );
	$formEdit = (string)( $_REQUEST['edit'] ?? '' );
	[ $made, $errors ] = FisheyeManagedGallery::create( $gParent, $formTitle, $formEdit );
	if( $made ) {
		KernelTools::bit_redirect( $made->getDisplayUrl() );
	}
	$feedback['error'] = array_map( fn( $e ) => KernelTools::tra( $e ), $errors );
}

$gBitSmarty->assign( 'gContent', $gParent );
$gBitSmarty->assign( 'feedback', $feedback );
$gBitSmarty->assign( 'formTitle', $formTitle );
$gBitSmarty->assign( 'formEdit', $formEdit );
$gBitSystem->display( 'bitpackage:fisheyemedia/add_gallery.tpl', KernelTools::tra( 'Add gallery' ), [ 'display_mode' => 'edit' ] );

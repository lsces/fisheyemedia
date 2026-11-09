<?php
/**
 * Edit page for a gallery whose place is managed by a loader (Library author and series galleries, Documentation galleries - see
 * FisheyeManagedGallery): title, description, and where it is located. fisheye's own Edit page detaches such a gallery from its parent when it is
 * saved, so the Edit icons of these layouts come here. Any other gallery is handed to fisheye's own Edit page.
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

$gContent = FisheyeGallery::lookup( [ 'gallery_id' => (int)( $_REQUEST['gallery_id'] ?? 0 ) ] );
if( !$gContent || !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No gallery exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->load();
if( !FisheyeManagedGallery::isManaged( $gContent ) ) {
	KernelTools::bit_redirect( FISHEYE_PKG_URL.'edit.php?gallery_id='.$gContent->mGalleryId );
}
$gContent->verifyUpdatePermission();

if( !empty( $_REQUEST['cancelgallery'] ) ) {
	KernelTools::bit_redirect( $gContent->getDisplayUrl() );
}

$feedback = [];
// what the form shows: the gallery as it is, or what was typed when a save was refused
$formTitle = $gContent->getTitle();
$formEdit = (string)$gContent->getField( 'data' );
if( !empty( $_REQUEST['savegallery'] ) ) {
	$formTitle = trim( (string)( $_REQUEST['title'] ?? '' ) );
	$formEdit = (string)( $_REQUEST['edit'] ?? '' );
	// everything is checked before anything is written, so a refused save changes nothing
	$checked = FisheyeManagedGallery::checkMoves( $gContent, (array)( $_REQUEST['parent'] ?? [] ) );
	if( $formTitle === '' ) {
		$feedback['error'][] = KernelTools::tra( 'You must specify a title for this gallery' );
	}
	foreach( $checked['errors'] as $error ) {
		$feedback['error'][] = KernelTools::tra( $error );
	}
	if( empty( $feedback['error'] ) ) {
		// the layout and thumbnail size go back in with the title and description so the grid settings are not reset
		$hash = [
			'title' => $formTitle,
			'edit' => $formEdit,
			'gallery_pagination' => $gContent->getLayout(),
			'thumbnail_size' => $gContent->getField( 'thumbnail_size' ),
		];
		if( $gContent->store( $hash ) ) {
			$moveErrors = FisheyeManagedGallery::move( $gContent, $checked['moves'] );
			if( !$moveErrors ) {
				KernelTools::bit_redirect( $gContent->getDisplayUrl() );
			}
			$feedback['error'] = array_map( fn( $e ) => KernelTools::tra( $e ), $moveErrors );
		} else {
			$feedback['error'] = array_values( array_map( 'strval', $gContent->mErrors ) );
		}
	}
}

$gBitSmarty->assign( 'gContent', $gContent );
$gBitSmarty->assign( 'placements', FisheyeManagedGallery::placements( $gContent ) );
$gBitSmarty->assign( 'feedback', $feedback );
$gBitSmarty->assign( 'formTitle', $formTitle );
$gBitSmarty->assign( 'formEdit', $formEdit );
$gBitSystem->display( 'bitpackage:fisheyemedia/edit_gallery.tpl', KernelTools::tra( 'Edit gallery' ), [ 'display_mode' => 'edit' ] );

<?php
/**
 * Edit page for a book (FisheyeBook) - title, description and the generic xref tabs (the same
 * list/add/edit controls every media type gets), plus delete. 'Reload from Open Library' looks the
 * ISBN up (FisheyeBook::reloadFromOpenLibrary()) and fills in what is blank, cover included.
 * Skeleton of edit_album.php.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeImage;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gContent = FisheyeImage::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No book exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->verifyUpdatePermission();

if( !empty( $_REQUEST['fCancel'] ) ) {
	KernelTools::bit_redirect( $gContent->getDisplayUrl() );
} elseif( !empty( $_REQUEST['fSave'] ) ) {
	$storeHash = [ 'content_id' => $gContent->mContentId, 'title' => trim( $_REQUEST['title'] ?? '' ), 'edit' => trim( $_REQUEST['edit'] ?? '' ) ];
	if( $gContent->store( $storeHash ) ) {
		KernelTools::bit_redirect( $gContent->getDisplayUrl() );
	}
	$gContent->load();
} elseif( !empty( $_REQUEST['fReloadOpenLibrary'] ) ) {
	$olResult = $gContent->reloadFromOpenLibrary();
	if( !empty( $olResult['error'] ) ) {
		$gContent->mErrors['open_library'] = $olResult['error'];
	} elseif( $olResult['items'] ) {
		$olMessage = KernelTools::tra( 'Added from Open Library' );
	} else {
		$olMessage = $olResult['matched'] ? KernelTools::tra( 'Open Library has this edition, but nothing it holds is missing here.' ) : KernelTools::tra( 'Open Library has no edition with this ISBN.' );
	}
} elseif( !empty( $_REQUEST['fReloadWikidata'] ) ) {
	$olResult = $gContent->reloadFromWikidata();
	if( !empty( $olResult['error'] ) ) {
		$gContent->mErrors['wikidata'] = $olResult['error'];
	} elseif( $olResult['items'] ) {
		$olMessage = KernelTools::tra( 'Added from Wikidata' );
	} else {
		$olMessage = $olResult['matched'] ? KernelTools::tra( 'Wikidata has this book in its series, but nothing it holds is missing here.' ) : KernelTools::tra( 'Wikidata does not list this book in a series.' );
	}
} elseif( !empty( $_REQUEST['delete'] ) ) {
	$gContent->hasUserPermission( 'p_fisheye_admin', true );

	if( !empty( $_REQUEST['cancel'] ) ) {
		// user cancelled - just continue on, doing nothing
	} elseif( empty( $_REQUEST['confirm'] ) ) {
		$formHash['delete'] = true;
		$formHash['content_id'] = $gContent->mContentId;
		$gBitSystem->confirmDialog( $formHash,
			[
				'warning' => KernelTools::tra( 'Are you sure you want to delete this book?' ) . ' ' . $gContent->getTitle(),
				'error' => KernelTools::tra( 'This cannot be undone!' ),
			],
		);
	} else {
		$userId = $gContent->getField( 'user_id' );
		if( $gContent->expunge() ) {
			KernelTools::bit_redirect( FISHEYE_PKG_URL.'?user_id='.$userId );
		}
	}
}

$gContent->loadXrefInfo();
$gBitSmarty->assign( 'errors', $gContent->mErrors );
$gBitSmarty->assign( 'olResult', $olResult ?? null );
$gBitSmarty->assign( 'olMessage', $olMessage ?? '' );
$gBitSmarty->assign( 'gXrefInfo', $gContent->mXrefInfo );
$gBitSmarty->assign( 'gContent', $gContent );

$gBitSystem->display( 'bitpackage:fisheyemedia/edit_book.tpl', KernelTools::tra( 'Edit Book: ' ).$gContent->getTitle(), [ 'display_mode' => 'edit' ] );

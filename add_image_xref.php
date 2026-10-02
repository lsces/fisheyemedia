<?php
/**
 * Dedicated "Add Image" page for the Images tab's own group-tab override
 * (templates/xref/view_images_group.tpl) - the generic add_xref.php/add_xref.tpl flow has no
 * file upload at all, so adding a new image previously meant creating a bare row via that
 * generic form, then separately going to edit it to attach a file. One step instead of two: upload straight into a new row, via
 * addImageXrefFile() (FisheyeBase, shared by Film/Season/Program alike).
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\HttpStatusCodes;
use Bitweaver\Liberty\LibertyContent;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gContent = LibertyContent::getLibertyObject( (int)( $_REQUEST['content_id'] ?? 0 ) );
if( !$gContent || !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'Content not found.' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->verifyUpdatePermission();

$errors = [];
if( !empty( $_REQUEST['fCancel'] ) ) {
	KernelTools::bit_redirect( $gContent->getEditUrl() );
} elseif( !empty( $_REQUEST['fAddImage'] ) ) {
	if( !method_exists( $gContent, 'addImageXrefFile' ) ) {
		$errors[] = KernelTools::tra( 'This content type does not support adding images directly.' );
	} else {
		$uploadedFile = null;
		foreach( $_FILES as $file ) {
			if( !empty( $file['tmp_name'] ) && is_uploaded_file( $file['tmp_name'] ) ) {
				$uploadedFile = $file;
				break;
			}
		}
		if( !$uploadedFile ) {
			$errors[] = KernelTools::tra( 'Choose an image file to upload.' );
		} else {
			$relativePath = $gContent->addImageXrefFile( $uploadedFile['tmp_name'], $uploadedFile['name'] );
			if( !$relativePath ) {
				$errors[] = KernelTools::tra( 'Could not save the uploaded image.' );
			} else {
				// Same "next xorder" convention every other multi-row 'image' insert in this
				// package already uses (reloadPlexImages()'s poster/art loop, grabVideoFrameImage()).
				$nextXorder = 1 + (int)$gBitDb->getOne(
					"SELECT COALESCE(MAX(`xorder`),0) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id`=? AND `item`='image'",
					[ $gContent->mContentId ]
				);
				$xrefHash = [ 'content_id' => $gContent->mContentId, 'item' => 'image', 'xkey_ext' => $relativePath, 'xorder' => $nextXorder ];
				if( $gContent->storeXref( $xrefHash ) ) {
					KernelTools::bit_redirect( $gContent->getEditUrl() );
				}
			}
		}
	}
}

$gBitSmarty->assign( 'gContent', $gContent );
$gBitSmarty->assign( 'errors', $errors );

$gBitSystem->display( 'bitpackage:fisheyemedia/xref/add_image_item.tpl', KernelTools::tra( 'Add Image' ), [ 'display_mode' => 'edit' ] );

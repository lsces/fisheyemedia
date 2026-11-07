<?php
/**
 * Edit page for a section of a Local Tutorials course (FisheyeSection): title and description, the tutorial scheme's own tabs (Tutorial Details,
 * Lessons, Extras - see the media scheme), and the actions that make sense for a folder of lesson videos - reload the lessons and their files from
 * the section's folder, refresh the thumbnails, delete. The TV season's page (edit_season.php) is Plex-shaped and is not used for a section.
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeImage;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gContent = FisheyeImage::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() || !( $gContent instanceof FisheyeSection ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No tutorial section exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->verifyUpdatePermission();

$result = null;
$resultLabel = null;
$resultEmptyLabel = KernelTools::tra( 'Nothing found to load in this section\'s folder.' );
$refreshIndex = function() use ( $gBitSystem, $gContent ) {
	if( $gBitSystem->isPackageActive( 'search' ) ) {
		require_once SEARCH_PKG_INCLUDE_PATH.'refresh_functions.php';
		\Bitweaver\Liberty\refresh_index( $gContent );
	}
};

if( !empty( $_REQUEST['fCancel'] ) ) {
	KernelTools::bit_redirect( $gContent->getDisplayUrl() );
} elseif( !empty( $_REQUEST['fSave'] ) ) {
	$storeHash = [ 'content_id' => $gContent->mContentId, 'title' => trim( $_REQUEST['title'] ?? '' ), 'edit' => trim( $_REQUEST['edit'] ?? '' ) ];
	if( $gContent->store( $storeHash ) ) {
		KernelTools::bit_redirect( $gContent->getDisplayUrl() );
	}
	$gContent->load();
} elseif( !empty( $_REQUEST['fReloadEpisodes'] ) ) {
	// Data only: a lesson already registered keeps its thumbnail; a new one gets the full load.
	$result = $gContent->reloadPlexEpisodes( true );
	$resultLabel = KernelTools::tra( 'Lessons loaded from the folder (existing thumbnails kept)' );
	$refreshIndex();
} elseif( !empty( $_REQUEST['fRefreshThumbs'] ) ) {
	$result = $gContent->reloadPlexEpisodes( true, true );
	$resultLabel = KernelTools::tra( 'Lessons loaded and every thumbnail refreshed' );
	$refreshIndex();
} elseif( !empty( $_REQUEST['fReloadFiles'] ) ) {
	$done = $gContent->registerFilesFromDisk();
	$result = [ 'items' => $done['errors'] ?: ( $done['files'] ? [ $done['files'].' '.KernelTools::tra( 'files registered' ) ] : [] ) ];
	$resultLabel = KernelTools::tra( 'Files reloaded from the folder' );
	$resultEmptyLabel = KernelTools::tra( 'No files found in this section\'s folder.' );
} elseif( !empty( $_REQUEST['fGrabFrame'] ) ) {
	// The "Grab Thumbnail from Video" action on the Extras tab (templates/xref/view_images_group.tpl).
	$relativePath = $gContent->grabVideoFrameImage();
	$result = [ 'items' => $relativePath ? [ "frame grab: $relativePath" ] : [] ];
	$resultLabel = KernelTools::tra( 'Grabbed a frame from a lesson video' );
	$resultEmptyLabel = KernelTools::tra( 'Could not grab a frame from any lesson video.' );
} elseif( !empty( $_REQUEST['delete'] ) ) {
	$gContent->hasUserPermission( 'p_fisheye_admin', true );
	if( !empty( $_REQUEST['cancel'] ) ) {
		// user cancelled - just continue on, doing nothing
	} elseif( empty( $_REQUEST['confirm'] ) ) {
		$formHash['delete'] = true;
		$formHash['content_id'] = $gContent->mContentId;
		$gBitSystem->confirmDialog( $formHash, [
			'warning' => KernelTools::tra( 'Are you sure you want to delete this section, including all its lessons and pictures? The files on disk are not touched.' ).' '.$gContent->getTitle(),
			'error' => KernelTools::tra( 'This cannot be undone!' ),
		] );
	} else {
		// back to the course; grabbed before expunge() removes the membership row getParentGalleries() reads
		$redirectUrl = FISHEYE_PKG_URL.'?user_id='.$gContent->getField( 'user_id' );
		if( $parentGalleries = $gContent->getParentGalleries() ) {
			$parentContentId = current( $parentGalleries )['content_id'] ?? null;
			if( $parentContentId && ( $parent = \Bitweaver\Liberty\LibertyBase::getLibertyObject( $parentContentId ) ) ) {
				$redirectUrl = $parent->getDisplayUrl();
			}
		}
		if( $gContent->expunge() ) {
			KernelTools::bit_redirect( $redirectUrl );
		}
	}
}

$gBitSmarty->assign( 'errors', $gContent->mErrors );
$gContent->loadXrefInfo();
$gBitSmarty->assign( 'gXrefInfo', $gContent->mXrefInfo );
$gBitSmarty->assign( 'gContent', $gContent );
$gBitSmarty->assign( 'result', $result );
$gBitSmarty->assign( 'resultLabel', $resultLabel );
$gBitSmarty->assign( 'resultEmptyLabel', $resultEmptyLabel );

$gBitSystem->display( 'bitpackage:fisheyemedia/edit_section.tpl', KernelTools::tra( 'Edit Section: ' ).$gContent->getTitle(), [ 'display_mode' => 'edit' ] );

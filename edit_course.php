<?php
/**
 * Edit page for a Local Tutorials course (FisheyeCourse): title and description, the tutorial scheme's own tabs (Tutorial Details, Extras - see the
 * media scheme), and the actions that make sense for a folder of lesson videos - reload every section's lessons from its folder, grab a picture,
 * delete. The TV show's page (edit_program.php) is Plex-shaped and is not used for a course.
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gContent = FisheyeGallery::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() || !( $gContent instanceof FisheyeCourse ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No tutorial course exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->verifyUpdatePermission();

$result = null;
$resultLabel = null;
if( !empty( $_REQUEST['fCancel'] ) ) {
	KernelTools::bit_redirect( $gContent->getDisplayUrl() );
} elseif( !empty( $_REQUEST['fSave'] ) ) {
	// the description field is named 'edit': LibertyContent::verify() only maps data from $pParamHash['edit']
	$storeHash = [ 'content_id' => $gContent->mContentId, 'title' => trim( $_REQUEST['title'] ?? '' ), 'edit' => trim( $_REQUEST['edit'] ?? '' ) ];
	if( $gContent->store( $storeHash ) ) {
		KernelTools::bit_redirect( $gContent->getDisplayUrl() );
	}
	$gContent->load();
} elseif( !empty( $_REQUEST['fReloadLocalEpisodes'] ) ) {
	// every section's lessons re-read from its folder, then the course gets a picture if it has none
	$lines = [];
	$listHash = [ 'max_records' => -1 ];
	$gContent->loadImages( $listHash );
	foreach( (array)$gContent->mItems as $section ) {
		if( $section instanceof FisheyeSeason ) {
			$section->load();
			$lines[] = $section->getTitle().': '.count( $section->reloadPlexEpisodes( true )['items'] ).' '.KernelTools::tra( 'entries' );
		}
	}
	if( $frame = $gContent->grabFrameIfNoImage() ) {
		$lines[] = KernelTools::tra( 'frame grab' ).": $frame";
	}
	$result = [ 'items' => $lines ];
	$resultLabel = KernelTools::tra( 'Lessons reloaded from the files' );
} elseif( !empty( $_REQUEST['fGrabFrame'] ) ) {
	$relativePath = $gContent->grabVideoFrameImage();
	$result = [ 'items' => $relativePath ? [ "frame grab: $relativePath" ] : [] ];
	$resultLabel = KernelTools::tra( 'Grabbed a frame from a lesson video' );
} elseif( !empty( $_REQUEST['delete'] ) ) {
	$gContent->hasUserPermission( 'p_fisheye_admin', true );
	if( !empty( $_REQUEST['cancel'] ) ) {
		// user cancelled - just continue on, doing nothing
	} elseif( empty( $_REQUEST['confirm'] ) ) {
		$formHash['delete'] = true;
		$formHash['gallery_id'] = $gContent->mGalleryId;
		$gBitSystem->confirmDialog( $formHash, [
			'warning' => KernelTools::tra( 'Are you sure you want to delete this course, including all its sections, lessons and pictures? The files on disk are not touched.' ).' '.$gContent->getTitle(),
			'error' => KernelTools::tra( 'This cannot be undone!' ),
		] );
	} else {
		// back to the gallery it is filed in, grabbed before expunge() removes the membership row getParentGalleries() reads
		$redirectUrl = FISHEYE_PKG_URL.'?user_id='.$gContent->getField( 'user_id' );
		if( $parentGalleries = $gContent->getParentGalleries() ) {
			$urlParamHash = [ 'gallery_id' => key( $parentGalleries ) ];   // by reference: a variable, never a literal
			$redirectUrl = FisheyeGallery::getDisplayUrlFromHash( $urlParamHash );
		}
		$gContent->pRecursiveDelete = true;
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
$gBitSmarty->assign( 'resultEmptyLabel', KernelTools::tra( 'Nothing found to load in this course\'s folder.' ) );

$gBitSystem->display( 'bitpackage:fisheyemedia/edit_course.tpl', KernelTools::tra( 'Edit Course: ' ).$gContent->getTitle(), [ 'display_mode' => 'edit' ] );

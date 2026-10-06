<?php
/**
 * Stub edit page for TV season content - title edit plus the generic liberty xref table
 * (list_xref.tpl / add_xref.php / edit_xref.php) for genre/director/writer/star/rating/duration/
 * imdb/tvdb/tmdb/episodes/images - same reuse-the-generic-table decision as edit_film.php.
 *
 * Hosts 'Reload Images' (FisheyeSeason::reloadPlexImages()), 'Load Episodes'
 * (FisheyeSeason::reloadPlexEpisodes()), and 'Reload Featurettes'
 * (FisheyeSeason::registerFeaturettesFromDisk(), separate from the episode reload since a new
 * Featurettes/ file can turn up independently of any episode change) - no season-level
 * 'Reload Metadata' exists deliberately,
 * since Plex itself has none: it's the TV that toggles to display a selected episode's metadata
 * as you select each. genre/director/writer/star/
 * rating/duration live per-episode instead, fetched by 'Load Episodes' and shown per-episode on
 * view_season.php.
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

// see view_program.php's own identical fix - a bare content_id can resolve to an unrelated
// content type entirely, which isValid() alone doesn't catch.
$gContent = FisheyeImage::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() || !( $gContent instanceof FisheyeSeason ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No season exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->verifyUpdatePermission();

$plexResult = null;
$plexResultLabel = null;
// Shown when $plexResult comes back empty - overridden per action below for the disk-based
// Featurettes/frame-grab actions, neither of which have anything to do with Plex and shouldn't
// blame it for an empty result.
$plexResultEmptyLabel = KernelTools::tra( 'No matching Plex entry found for this season.' );
if( !empty( $_REQUEST['fCancel'] ) ) {
	KernelTools::bit_redirect( $gContent->getDisplayUrl() );
} elseif( !empty( $_REQUEST['fSave'] ) ) {
	// No Plex-sourced season-level description exists to auto-fill this (Plex has nothing at
	// season granularity - see this file's own docblock), but a manual note is still useful -
	// same 'edit' field-name convention as edit_program.php's own description box.
	$storeHash = [ 'content_id' => $gContent->mContentId, 'title' => trim( $_REQUEST['title'] ?? '' ), 'edit' => trim( $_REQUEST['edit'] ?? '' ) ];
	if( $gContent->store( $storeHash ) ) {
		KernelTools::bit_redirect( $gContent->getDisplayUrl() );
	}
	$gContent->load();
} elseif( !empty( $_REQUEST['fReloadImages'] ) ) {
	$plexResult = $gContent->reloadPlexImages();
	$plexResultLabel = KernelTools::tra( 'Images reloaded from Plex' );
} elseif( !empty( $_REQUEST['fReloadEpisodes'] ) ) {
	// Data only: an episode already registered keeps its thumbnail (Reload Images is the tool for images); a new episode gets
	// the full load, thumbnail included.
	$plexResult = $gContent->reloadPlexEpisodes( true );
	$plexResultLabel = KernelTools::tra( 'Episodes loaded from Plex (existing thumbnails kept)' );
	// Episode titles only become searchable via this season's own getExtraIndexWords() hook
	// (LibertyContent::verify()/setIndexData()) - but that only fires on this season's own
	// store(), which a plain episode xref reload never calls. Refresh explicitly here instead of
	// waiting for this season's title to happen to get edited some other time.
	if( $gBitSystem->isPackageActive( 'search' ) ) {
		require_once SEARCH_PKG_INCLUDE_PATH.'refresh_functions.php';
		\Bitweaver\Liberty\refresh_index( $gContent );
	}
} elseif( !empty( $_REQUEST['fReloadFeaturettes'] ) ) {
	$plexResult = $gContent->registerFeaturettesFromDisk();
	$plexResultLabel = KernelTools::tra( 'Featurettes reloaded' );
	$plexResultEmptyLabel = KernelTools::tra( 'No Featurettes/ folder found for this season.' );
} elseif( !empty( $_REQUEST['fGrabFrame'] ) ) {
	// The "Grab Thumbnail from Video" action on the Images tab (templates/xref/
	// view_images_group.tpl) - on-demand version of reloadPlexImages()'s own automatic fallback,
	// callable even when Plex images already exist (a deliberate "add one more" click).
	$relativePath = $gContent->grabVideoFrameImage();
	$plexResult = [ 'items' => $relativePath ? [ "frame grab: $relativePath" ] : [] ];
	$plexResultLabel = KernelTools::tra( 'Grabbed a frame from the episode video' );
	$plexResultEmptyLabel = KernelTools::tra( 'Could not grab a frame from any episode video.' );
} elseif( !empty( $_REQUEST['delete'] ) ) {
	// Same delete flow as edit_film.php's own - safe to wire up properly since
	// LibertyMime::expunge() actually reaches LibertyContent::expunge(). The episode video files
	// themselves are never touched either way.
	$gContent->hasUserPermission( 'p_fisheye_admin', true );

	if( !empty( $_REQUEST['cancel'] ) ) {
		// user cancelled - just continue on, doing nothing
	} elseif( empty( $_REQUEST['confirm'] ) ) {
		$formHash['delete'] = true;
		$formHash['content_id'] = $gContent->mContentId;
		$gBitSystem->confirmDialog( $formHash,
			[
				'warning' => KernelTools::tra( 'Are you sure you want to delete this season, including all its episodes and images?' ) . ' ' . $gContent->getTitle(),
				'error' => KernelTools::tra( 'This cannot be undone!' ),
			],
		);
	} else {
		$userId = $gContent->getField( 'user_id' );
		// Redirect back to the parent show rather than a generic fallback, same reasoning as
		// edit_program.php's own parent-gallery redirect - grab it before expunge() removes the
		// membership row getParentGalleries() itself reads. Uses the real, type-correct show
		// object's own getDisplayUrl() (-> view_program.php) rather than FisheyeGallery's generic
		// getDisplayUrlFromHash() (-> view.php) - same "wrong link" bug already fixed once
		// elsewhere in image_order.tpl, not worth repeating here.
		$parentGalleries = $gContent->getParentGalleries();
		$redirectUrl = FISHEYE_PKG_URL.'?user_id='.$userId;
		if( !empty( $parentGalleries ) ) {
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
$gBitSmarty->assign( 'plexResult', $plexResult );
$gBitSmarty->assign( 'plexResultLabel', $plexResultLabel );
$gBitSmarty->assign( 'plexResultEmptyLabel', $plexResultEmptyLabel );

$gBitSystem->display( 'bitpackage:fisheyemedia/edit_season.tpl', KernelTools::tra( 'Edit Season: ' ).$gContent->getTitle(), [ 'display_mode' => 'edit' ] );

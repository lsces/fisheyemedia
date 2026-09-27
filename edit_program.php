<?php
/**
 * Stub edit page for TV show ("program") content - title edit plus the generic liberty xref
 * table (list_xref.tpl / add_xref.php / edit_xref.php) for genre/cast/rating/duration/imdb/tvdb/
 * tmdb/images, same reuse-the-generic-table decision as edit_film.php - a clone of that page,
 * replacing the inherited generic FisheyeGallery edit.php as this show's real edit page.
 *
 * Also hosts both Plex actions, same split as edit_film.php: 'Reload Metadata'
 * (FisheyeProgram::reloadPlexMetadata()) and 'Reload Images' (FisheyeProgram::reloadPlexImages()).
 * view_program.php stays pure display with no update actions of its own.
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

// see view_program.php's own identical fix - a bare content_id can resolve to an unrelated
// content type entirely, which isValid() alone doesn't catch.
$gContent = FisheyeGallery::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() || !( $gContent instanceof FisheyeProgram ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No show exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->verifyUpdatePermission();

$plexResult = null;
$plexResultLabel = null;
$plexSearchQuery = null;
$plexSearchResults = null;
if( !empty( $_REQUEST['fCancel'] ) ) {
	KernelTools::bit_redirect( $gContent->getDisplayUrl() );
} elseif( !empty( $_REQUEST['fSave'] ) ) {
	// Form field itself is named 'edit' directly (same convention edit_gallery.tpl's own
	// description textarea already uses) - LibertyContent::verify() only maps
	// content_store['data'] from $pParamHash['edit'] (same gotcha reloadPlexMetadata()'s own
	// description-store hit), so this needs no translation, just pass it through.
	$storeHash = [ 'content_id' => $gContent->mContentId, 'title' => trim( $_REQUEST['title'] ?? '' ), 'edit' => trim( $_REQUEST['edit'] ?? '' ) ];
	if( $gContent->store( $storeHash ) ) {
		KernelTools::bit_redirect( $gContent->getDisplayUrl() );
	}
	$gContent->load();
} elseif( !empty( $_REQUEST['fReloadMetadata'] ) ) {
	$plexResult = $gContent->reloadPlexMetadata();
	$plexResultLabel = KernelTools::tra( 'Metadata reloaded from Plex' );
} elseif( !empty( $_REQUEST['fReloadImages'] ) ) {
	$plexResult = $gContent->reloadPlexImages();
	$plexResultLabel = KernelTools::tra( 'Images reloaded from Plex' );
} elseif( !empty( $_REQUEST['fSearchPlex'] ) ) {
	// The manual recovery path for a failed/wrong automatic title match (see
	// FisheyeProgram::registerFromDisk()'s halt and matchPlexShowMetadataItem()'s own docblock) -
	// free-text search against Plex's own local library rather than requiring the folder name or
	// Plex's own title to be edited to match exactly.
	$plexSearchQuery = trim( (string)( $_REQUEST['plex_query'] ?? '' ) );
	$plexSearchResults = FisheyeProgram::searchPlexShows( $plexSearchQuery );
} elseif( !empty( $_REQUEST['fSetPlexMatch'] ) ) {
	$metadataItemId = (int)( $_REQUEST['plex_metadata_item_id'] ?? 0 );
	if( $metadataItemId && $gContent->setPlexMatchOverride( $metadataItemId ) ) {
		// Confirming a match is the point where the halted metadata/image fetch finally runs -
		// fold both into the one alert box edit_program.tpl already shows, rather than needing
		// two separate "now click Reload Metadata, then Reload Images" steps after this.
		$plexResult = $gContent->reloadPlexMetadata();
		$plexResultLabel = KernelTools::tra( 'Plex match confirmed - metadata and images reloaded' );
		$imagesResult = $gContent->reloadPlexImages();
		$plexResult['items'] = array_merge( $plexResult['items'], array_map( fn( $line ) => "image: $line", $imagesResult['items'] ) );
	}
} elseif( !empty( $_REQUEST['fGrabFrame'] ) ) {
	// The "Grab Thumbnail from Video" action on the Images tab (templates/xref/
	// view_images_group.tpl) - same on-demand action as edit_season.php's own fGrabFrame, but
	// grabs from the show's first season with a usable episode file (a show has no video of its
	// own).
	$relativePath = $gContent->grabVideoFrameImage();
	$plexResult = [ 'items' => $relativePath ? [ "frame grab: $relativePath" ] : [] ];
	$plexResultLabel = KernelTools::tra( 'Grabbed a frame from a season episode video' );
} elseif( !empty( $_REQUEST['delete'] ) ) {
	$gContent->hasUserPermission( 'p_fisheye_admin', true );

	if( !empty( $_REQUEST['cancel'] ) ) {
		// user cancelled - just continue on, doing nothing
	} elseif( empty( $_REQUEST['confirm'] ) ) {
		// Same confirmDialog warning flow edit.php uses for a plain gallery - no recurse
		// choice here though, unlike edit.php's: a show only exists to hold its seasons, so
		// "delete this show" always means the whole tree (seasons, episodes, images), never
		// just delisting them.
		$formHash['delete'] = true;
		$formHash['gallery_id'] = $gContent->mGalleryId;
		$gBitSystem->confirmDialog( $formHash,
			[
				'warning' => KernelTools::tra( 'Are you sure you want to delete this show, including all its seasons, episodes and images?' ) . ' ' . $gContent->getTitle(),
				'error' => KernelTools::tra( 'This cannot be undone!' ),
			],
		);
	} else {
		$userId = $gContent->getField( 'user_id' );
		// Grab the parent gallery (e.g. "TV Shows") before expunge() removes the membership row
		// that getParentGalleries() itself reads - same generic top-level redirect edit.php uses
		// for a plain gallery otherwise, which is jarring for a show buried a level down.
		$parentGalleries = $gContent->getParentGalleries();
		$redirectUrl = FISHEYE_PKG_URL.'?user_id='.$userId;
		if( !empty( $parentGalleries ) ) {
			// getDisplayUrlFromHash() takes its param by reference - can't pass an array literal
			// directly, needs a real variable first (same gotcha already hit elsewhere in this
			// codebase, e.g. FisheyeBase::getBreadcrumbTrail()).
			$urlParamHash = [ 'gallery_id' => key( $parentGalleries ) ];
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

// Single-season shows: merge that one season's own real 'episodes' group straight into this
// show's own xref info - same real LibertyXrefGroup/LibertyXref objects loadXrefInfo() already
// produces for the season itself (view_program.php's single-season merge uses the same
// getSingleSeason(), just for its own flattened episode-grid display rather than this generic
// xref group). Its rows belong to the season's own content_id, not the show's - passed through
// separately (xrefContentId) so action_icons.tpl/list_xref.tpl's Edit/Add links target the
// season, not the show, instead of just hiding them.
$episodesContentId = null;
if( $season = $gContent->getSingleSeason() ) {
	$season->loadXrefInfo();
	if( $season->mXrefInfo && isset( $season->mXrefInfo->mGroups['episodes'] ) ) {
		$gContent->mXrefInfo->mGroups['episodes'] = $season->mXrefInfo->mGroups['episodes'];
		$episodesContentId = $season->mContentId;
	}
}
$gBitSmarty->assign( 'episodesContentId', $episodesContentId );

$gBitSmarty->assign( 'gXrefInfo', $gContent->mXrefInfo );
$gBitSmarty->assign( 'gContent', $gContent );
$gBitSmarty->assign( 'plexResult', $plexResult );
$gBitSmarty->assign( 'plexResultLabel', $plexResultLabel );
$gBitSmarty->assign( 'plexSearchQuery', $plexSearchQuery );
$gBitSmarty->assign( 'plexSearchResults', $plexSearchResults );
$gBitSmarty->assign( 'plexHasMatch', $gContent->hasPlexMatch() );

$gBitSystem->display( 'bitpackage:fisheyemedia/edit_program.tpl', KernelTools::tra( 'Edit Show: ' ).$gContent->getTitle(), [ 'display_mode' => 'edit' ] );

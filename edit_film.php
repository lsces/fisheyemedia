<?php
/**
 * Stub edit page for film content - title edit plus the generic liberty xref table
 * (list_xref.tpl / add_xref.php / edit_xref.php, already built for contact/stock - reused as-is
 * rather than bespoke per-field markup) for genre/director/writer/star/rating/duration/imdb/tmdb.
 * Also hosts two Plex actions, kept deliberately separate as different weight/frequency
 * operations: 'Reload Metadata' (FisheyeFilm::reloadPlexMetadata()) for a film
 * imported before that backfill existed, or needs re-syncing after a Plex library update; and
 * 'Reload Images' (FisheyeFilm::reloadPlexImages()) to fetch alternate poster/backdrop images
 * once - see each method's own docblock for detail.
 *
 * Deliberately minimal - no upload/rotate/resize/gallery-picker machinery like edit_image.php
 * has, none of that applies to an externally-stored film file.
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
if( !$gContent || !$gContent->isValid() || !( $gContent instanceof FisheyeFilm ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No film exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->verifyUpdatePermission();

$plexResult = null;
$plexResultLabel = null;
// Shown when $plexResult comes back empty - overridden per action below for the disk-based
// Featurettes reload, which has nothing to do with Plex and shouldn't blame it for an empty result.
$plexResultEmptyLabel = KernelTools::tra( 'No matching Plex entry found for this file.' );
if( !empty( $_REQUEST['fCancel'] ) ) {
	KernelTools::bit_redirect( $gContent->getDisplayUrl() );
} elseif( !empty( $_REQUEST['fSave'] ) ) {
	// Same "description box" tidy as edit_program.php's own fSave - the
	// 'edit' field is what LibertyContent::verify() maps into content_store['data'], already
	// populated on Reload Metadata via reloadPlexMetadata()'s own description-store hash, but
	// edit_film.tpl had no field for it and this handler dropped it silently on manual Save.
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
} elseif( !empty( $_REQUEST['fReloadFeaturettes'] ) ) {
	$relativeFilePath = $gContent->getRelativeFilePath();
	$plexResult = $relativeFilePath !== null ? $gContent->registerFeaturettesFromDisk( $relativeFilePath ) : [ 'items' => [] ];
	$plexResultLabel = KernelTools::tra( 'Featurettes reloaded' );
	$plexResultEmptyLabel = KernelTools::tra( 'No Featurettes/ folder found alongside this file.' );
} elseif( !empty( $_REQUEST['delete'] ) ) {
	// Same delete flow as edit_program.php's own - safe to wire up
	// properly since LibertyMime::expunge() actually reaches LibertyContent::expunge(). The video file itself is never touched either way - see
	// deleteXrefFile()/replaceXrefFile()'s own docblocks, mimefilm's whole point is external,
	// un-owned storage.
	$gContent->hasUserPermission( 'p_fisheye_admin', true );

	if( !empty( $_REQUEST['cancel'] ) ) {
		// user cancelled - just continue on, doing nothing
	} elseif( empty( $_REQUEST['confirm'] ) ) {
		$formHash['delete'] = true;
		$formHash['content_id'] = $gContent->mContentId;
		$gBitSystem->confirmDialog( $formHash,
			[
				'warning' => KernelTools::tra( 'Are you sure you want to delete this film?' ) . ' ' . $gContent->getTitle() . ' ' . KernelTools::tra( '(the video file on disk will not be touched)' ),
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

$gBitSmarty->assign( 'errors', $gContent->mErrors );

$gContent->loadXrefInfo();
$gBitSmarty->assign( 'gXrefInfo', $gContent->mXrefInfo );
$gBitSmarty->assign( 'gContent', $gContent );
$gBitSmarty->assign( 'plexResult', $plexResult );
$gBitSmarty->assign( 'plexResultLabel', $plexResultLabel );
$gBitSmarty->assign( 'plexResultEmptyLabel', $plexResultEmptyLabel );

$gBitSystem->display( 'bitpackage:fisheyemedia/edit_film.tpl', KernelTools::tra( 'Edit Film: ' ).$gContent->getTitle(), [ 'display_mode' => 'edit' ] );

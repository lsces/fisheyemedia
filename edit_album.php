<?php
/**
 * Stub edit page for music album content - title edit plus the generic liberty xref table
 * (list_xref.tpl / add_xref.php / edit_xref.php) for genre/artist/composer/mbid/discogs/tracks/
 * images - same reuse-the-generic-table decision as edit_season.php.
 *
 * Hosts 'Reload Images' (FisheyeAlbum::reloadPlexImages()) and 'Reload Tracks'
 * (FisheyeAlbum::reloadTracks()) - the latter re-scans the album's own folder from scratch (a
 * re-tag in Picard after the fact, or a metadata-schema change promoting a new common tag that
 * only takes effect for already-registered albums via an explicit reload).
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
if( !$gContent || !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No album exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->verifyUpdatePermission();

$plexResult = null;
$plexResultLabel = null;
// Shown when $plexResult comes back empty - overridden for the disk-based track reload below,
// which has nothing to do with Plex and shouldn't blame it for an empty result.
$plexResultEmptyLabel = KernelTools::tra( 'No matching Plex entry found for this album.' );
if( !empty( $_REQUEST['fCancel'] ) ) {
	KernelTools::bit_redirect( $gContent->getDisplayUrl() );
} elseif( !empty( $_REQUEST['fSave'] ) ) {
	$storeHash = [ 'content_id' => $gContent->mContentId, 'title' => trim( $_REQUEST['title'] ?? '' ), 'edit' => trim( $_REQUEST['edit'] ?? '' ) ];
	if( $gContent->store( $storeHash ) ) {
		KernelTools::bit_redirect( $gContent->getDisplayUrl() );
	}
	$gContent->load();
} elseif( !empty( $_REQUEST['fReloadImages'] ) ) {
	$plexResult = $gContent->reloadPlexImages();
	// reloadPlexImages() falls back to the album's own disk/embedded cover when Plex has no match
	// at all (see its own docblock) - label/empty-message reflect whichever actually happened,
	// rather than always crediting (or blaming) Plex for a result that may not involve it.
	if( $plexResult['matched'] ) {
		$plexResultLabel = KernelTools::tra( 'Images reloaded from Plex' );
	} elseif( !empty( $plexResult['items'] ) ) {
		$plexResultLabel = KernelTools::tra( 'Cover reloaded from disk' );
	} else {
		$plexResultLabel = KernelTools::tra( 'Reload Images' );
		$plexResultEmptyLabel = KernelTools::tra( 'No matching Plex entry, and no cover.jpg/embedded cover art found on disk either.' );
	}
} elseif( !empty( $_REQUEST['fFetchDiscogs'] ) ) {
	// Was its own separate batch page (fetch_discogs.php) scanning a whole gallery at once -
	// moved here instead: a per-album action fits this page's own existing Reload Images/Tracks
	// pattern better than a whole extra gallery-level tool for something that's just a one-off
	// lookup per album.
	$discogsResult = $gContent->fetchDiscogsLink();
	if( !empty( $discogsResult['url'] ) ) {
		$plexResult = [ 'items' => [ KernelTools::tra( 'Discogs link found' ).': '.$discogsResult['url'] ] ];
		$plexResultLabel = KernelTools::tra( 'Discogs link found' );
	} else {
		$plexResult = [ 'items' => [] ];
		$plexResultLabel = KernelTools::tra( 'Fetch Discogs Link' );
		$plexResultEmptyLabel = !empty( $discogsResult['error'] ) ? $discogsResult['error']
			: ( !empty( $discogsResult['already'] ) ? KernelTools::tra( 'This album already has a Discogs link.' )
			: KernelTools::tra( 'No Discogs link on MusicBrainz for this album.' ) );
	}
} elseif( !empty( $_REQUEST['fReloadTracks'] ) ) {
	$reloadResult = $gContent->reloadTracks();
	$plexResultLabel = KernelTools::tra( 'Tracks reloaded from disk' );
	if( !empty( $reloadResult['error'] ) ) {
		$plexResult = [ 'items' => [] ];
		$plexResultEmptyLabel = $reloadResult['error'];
	} else {
		$plexResult = [ 'items' => [ ( $reloadResult['tracks'] ?? 0 ).' track(s) re-scanned' ] ];
	}
} elseif( !empty( $_REQUEST['delete'] ) ) {
	// Same delete flow as edit_film.php/edit_program.php's own - the track/cover files on disk
	// are never touched either way, same reasoning as those (external, un-owned storage).
	$gContent->hasUserPermission( 'p_fisheye_admin', true );

	if( !empty( $_REQUEST['cancel'] ) ) {
		// user cancelled - just continue on, doing nothing
	} elseif( empty( $_REQUEST['confirm'] ) ) {
		$formHash['delete'] = true;
		$formHash['content_id'] = $gContent->mContentId;
		$gBitSystem->confirmDialog( $formHash,
			[
				'warning' => KernelTools::tra( 'Are you sure you want to delete this album?' ) . ' ' . $gContent->getTitle() . ' ' . KernelTools::tra( '(the track files on disk will not be touched)' ),
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

$gBitSystem->display( 'bitpackage:fisheyemedia/edit_album.tpl', KernelTools::tra( 'Edit Album: ' ).$gContent->getTitle(), [ 'display_mode' => 'edit' ] );

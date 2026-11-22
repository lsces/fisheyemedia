<?php
/**
 * Change one episode's thumbnail by hand: upload a picture, or take a frame from the episode's own video at a chosen time. Reached from the
 * episode's edit page (templates/xref/fisheyeseason/edit_json-list_fields.tpl); returns to the season's edit page when done. Only this
 * episode's own thumbnail is touched - the rest of its details, and every other episode, stay as they are.
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

$gBitSystem->verifyPackage( 'fisheye' );

$gContent = FisheyeImage::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() || !( $gContent instanceof FisheyeSeason ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No season exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->verifyUpdatePermission();

$xrefId = (int)( $_REQUEST['xref_id'] ?? 0 );
$episode = $gContent->getEpisodeRow( $xrefId );
if( !$episode ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No such episode' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$data = !empty( $episode['data'] ) ? ( json_decode( $episode['data'], true ) ?: [] ) : [];
// the frame offered by default: a quarter of the way in, which is usually past the titles
$defaultSeconds = !empty( $data['duration'] ) ? (int)round( $data['duration'] / 1000 / 4 ) : 60;

$errors = [];
if( !empty( $_REQUEST['fCancel'] ) ) {
	KernelTools::bit_redirect( $gContent->getEditUrl() );
} elseif( !empty( $_REQUEST['fUpload'] ) ) {
	$file = $_FILES['episode_thumb_file'] ?? [];
	if( empty( $file['tmp_name'] ) || !is_uploaded_file( $file['tmp_name'] ) ) {
		$errors['upload'] = KernelTools::tra( 'Choose a picture to upload.' );
	} elseif( !$gContent->setEpisodeThumbnail( $xrefId, $file['tmp_name'] ) ) {
		$errors['upload'] = KernelTools::tra( 'That file could not be used as a thumbnail - is it a picture?' );
	} else {
		KernelTools::bit_redirect( $gContent->getEditUrl() );
	}
} elseif( !empty( $_REQUEST['fGrab'] ) ) {
	$seconds = (int)( $_REQUEST['seconds'] ?? $defaultSeconds );
	if( !$gContent->grabEpisodeThumbnail( $xrefId, $seconds ) ) {
		$errors['grab'] = KernelTools::tra( 'A frame could not be taken from the episode video at that time.' );
	} else {
		KernelTools::bit_redirect( $gContent->getEditUrl() );
	}
}

$gBitSmarty->assign( 'gContent', $gContent );
$gBitSmarty->assign( 'episode', [ 'xref_id' => (int)$episode['xref_id'] ] );
$gBitSmarty->assign( 'episodeTitle', $data['title'] ?? pathinfo( $episode['xkey_ext'], PATHINFO_FILENAME ) );
$gBitSmarty->assign( 'defaultSeconds', $defaultSeconds );
$gBitSmarty->assign( 'durationSeconds', !empty( $data['duration'] ) ? (int)round( $data['duration'] / 1000 ) : 0 );
$gBitSmarty->assign( 'errors', $errors );
$gBitSystem->display( 'bitpackage:fisheyemedia/episode_thumb.tpl', KernelTools::tra( 'Episode Thumbnail' ), [ 'display_mode' => 'edit' ] );

<?php
/**
 * Streams one track's own audio file - same shape as play_episode.php (raw xref file, not a real
 * LibertyMime attachment, so no existing serving route otherwise), just for 'track' xrefs under
 * a FisheyeAlbum instead of 'episode'/'featurette' under a Season/Film.
 *
 * Takes xref_id only, never a raw path - same no-path-traversal-surface reasoning as
 * play_episode.php/view_extra_image.php.
 *
 * Basic HTTP Range support (single range only) - needed for an <audio> element's own seek bar,
 * same reasoning as play_episode.php.
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeImage;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';
global $gBitSystem, $gBitDb;

$gBitSystem->verifyPackage( 'fisheye' );

$xrefId = (int)( $_REQUEST['xref_id'] ?? 0 );
$row = $xrefId ? $gBitDb->getRow(
	"SELECT content_id, xkey_ext FROM `".BIT_DB_PREFIX."liberty_xref` WHERE xref_id = ? AND item = 'track'",
	[ $xrefId ]
) : null;
if( !$row ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No such track' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

$gContent = FisheyeImage::lookup( [ 'content_id' => $row['content_id'] ] );
if( !$gContent || !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No such track' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->verifyViewPermission();

$root = method_exists( $gContent, 'getImageStorageRoot' ) ? $gContent->getImageStorageRoot() : '';
$path = $root.$row['xkey_ext'];
if( empty( $root ) || !is_file( $path ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'Track file not found' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

$mimeType = $gBitSystem->verifyMimeType( $path );
header( 'Content-Disposition: inline; filename="'.basename( $path ).'"' );
\Bitweaver\Liberty\liberty_serve_range_file( $path, $mimeType );

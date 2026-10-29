<?php
/**
 * Serves one of a book's files (an ebook, an audio book track or another extra) by xref_id only, never a path - the play_track.php shape: the
 * row names the book, the book's view permission decides, and the file must lie under the Library root (FisheyeBookFiles::pathFor()). Ebooks and
 * extras download; audio plays in place, with Range for the seek bar. A multi-file audio book takes &track=N.
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
	"SELECT `content_id`, `item`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `xref_id` = ? AND `item` IN ('ebook','audiobook','extra') AND `end_date` IS NULL",
	[ $xrefId ] ) : null;
if( !$row ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No such file' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

$gContent = FisheyeImage::lookup( [ 'content_id' => $row['content_id'] ] );
if( !$gContent || !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No such file' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->verifyViewPermission();

$path = FisheyeBookFiles::pathFor( (string)$row['xkey_ext'], max( 1, (int)( $_REQUEST['track'] ?? 1 ) ) );
if( $path === null ) {
	$gBitSystem->fatalError( KernelTools::tra( 'The file was not found' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

$mimeType = FisheyeBookFiles::mimeFor( $path ) ?? $gBitSystem->verifyMimeType( $path );
header( 'Content-Disposition: '.( $row['item'] === 'audiobook' ? 'inline' : 'attachment' ).'; filename="'.rawurlencode( basename( $path ) ).'"' );
\Bitweaver\Liberty\liberty_serve_range_file( $path, $mimeType );

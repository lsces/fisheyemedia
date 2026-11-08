<?php
/**
 * Serves one of a tutorial section's (or course's, or an author's Extras gallery's) files (an exercise archive, notes, a handout) by xref_id only, never a path - the view_book_file.php shape:
 * the row names the season, the season's view permission and the row's own roles decide, and the file must lie under the tutorials root
 * (FisheyeSection::extraFilePath()). Always a download.
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
	"SELECT `content_id`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `xref_id` = ? AND `item` = 'extra' AND `end_date` IS NULL",
	[ $xrefId ] ) : null;
if( !$row ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No such file' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

$gContent = FisheyeImage::lookup( [ 'content_id' => $row['content_id'] ] );
if( !$gContent || !$gContent->isValid() || !( $gContent instanceof FisheyeSection || $gContent instanceof FisheyeCourse || $gContent instanceof FisheyeExtrasGallery ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No such file' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->verifyViewPermission();
if( !$gContent->isXrefRowVisible( $xrefId ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No such file' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

// An author's Extras gallery holds paths under the Library root; a course or section's are under the tutorials root.
$path = $gContent instanceof FisheyeExtrasGallery ? FisheyeBookFiles::pathFor( (string)$row['xkey_ext'] ) : FisheyeSection::extraFilePath( (string)$row['xkey_ext'] );
if( $path === null ) {
	$gBitSystem->fatalError( KernelTools::tra( 'The file was not found' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

// ?thumb=1: a small picture of the file for the Extras list - the first page of a PDF, drawn once and kept in the section's own storage folder (the file
// itself is never copied or touched); anything else, or a PDF that cannot be drawn, gets the file type's own icon.
if( !empty( $_REQUEST['thumb'] ) ) {
	$thumb = $gContent->getFileThumbFile( (int)$xrefId, $path );
	if( $thumb !== null ) {
		header( 'Content-Type: image/png' );
		header( 'Cache-Control: private, max-age=86400' );
		header( 'Content-Length: '.filesize( $thumb ) );
		readfile( $thumb );
		exit;
	}
	KernelTools::bit_redirect( $gContent->getFileIconUrl( basename( $path ) ) );
}

// An author's Extras file that a browser can show (a PDF, a picture, plain text) opens in place; &download=1 forces a download. Everything else, and every
// tutorial archive, is a download.
$showInline = $gContent instanceof FisheyeExtrasGallery && empty( $_REQUEST['download'] )
	&& in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), [ 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt' ], true );
header( 'Content-Disposition: '.( $showInline ? 'inline' : 'attachment' ).'; filename="'.rawurlencode( basename( $path ) ).'"' );
\Bitweaver\Liberty\liberty_serve_range_file( $path, $gBitSystem->verifyMimeType( $path ) );

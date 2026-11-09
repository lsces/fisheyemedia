<?php
/**
 * Shows one of the Extras files of an author's Extras gallery, a book (its extras and ebooks), a tutorial course or a section inside a page of the site: a PDF in the pdf.js viewer the site already carries, an epub in its epub.js reader
 * (themes/js/pdfjs), so it opens whatever the browser's own PDF setting is and a search result can open it with the search term found and highlighted
 * (&highlight=term, the same hash as liberty's pdf view). The file itself is still served from where it is by view_season_file.php (view_book_file.php for a book), which does the
 * permission and path checks; anything that is not a PDF is handed straight to it (a download).
 *
 * &content_id=N instead of an xref_id opens a PDF or an epub that is itself a fisheye image (the Documentation galleries): the same viewers, fed by liberty's
 * download_file.php for the image's attachment, which does the permission check and serves a mimeextpdf file from where it is.
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeImage;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';
global $gBitSystem, $gBitDb, $gBitSmarty;

$gBitSystem->verifyPackage( 'fisheye' );

$xrefId = (int)( $_REQUEST['xref_id'] ?? 0 );
$contentId = $xrefId ? 0 : (int)( $_REQUEST['content_id'] ?? 0 );
if( $contentId ) {
	$gContent = FisheyeImage::lookup( [ 'content_id' => $contentId ] );
	$attachmentId = $gContent && $gContent->isValid() ? (int)$gContent->getField( 'attachment_id' ) : 0;
	$mime = (string)$gContent->getField( 'mime_type' );
	if( !$attachmentId || !preg_match( '#^application/(pdf|epub\+zip)$#i', $mime ) ) {
		$gBitSystem->fatalError( KernelTools::tra( 'No such file' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
	}
	$gContent->verifyViewPermission();
	$fileUrl = LIBERTY_PKG_URL.'download_file.php?attachment_id='.$attachmentId;
	$name = (string)$gContent->getField( 'file_name' );
	$kind = stripos( $mime, 'epub' ) !== false ? 'epub' : 'pdf';
	// back to the gallery the document is in, not the image's own generic page
	$galleryId = $gBitDb->getOne( "SELECT g.`gallery_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m JOIN `".BIT_DB_PREFIX."fisheye_gallery` g ON g.`content_id` = m.`gallery_content_id` WHERE m.`item_content_id` = ?", [ $contentId ] );
	$galleryUrl = $galleryId ? FISHEYE_PKG_URL.'view.php?gallery_id='.$galleryId : $gContent->getDisplayUrl();
} else {
	$xrefId = (int)( $_REQUEST['xref_id'] ?? 0 );
	$row = $xrefId ? $gBitDb->getRow(
		"SELECT `content_id`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `xref_id` = ? AND `item` IN ('extra','ebook') AND `end_date` IS NULL",
		[ $xrefId ] ) : null;
	$gContent = $row ? FisheyeImage::lookup( [ 'content_id' => $row['content_id'] ] ) : null;
	if( !$gContent || !$gContent->isValid() || !( $gContent instanceof FisheyeExtrasGallery || $gContent instanceof FisheyeBook || $gContent instanceof FisheyeSection || $gContent instanceof FisheyeCourse ) ) {
		$gBitSystem->fatalError( KernelTools::tra( 'No such file' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
	}
	$gContent->verifyViewPermission();
	// Each owner's files are served by its own page and live under its own root.
	$isBook = $gContent instanceof FisheyeBook;
	$isTutorial = $gContent instanceof FisheyeSection || $gContent instanceof FisheyeCourse;
	$filePath = $isTutorial ? FisheyeSection::extraFilePath( (string)$row['xkey_ext'] ) : FisheyeBookFiles::pathFor( (string)$row['xkey_ext'] );
	if( !$gContent->isXrefRowVisible( $xrefId ) || $filePath === null ) {
		$gBitSystem->fatalError( KernelTools::tra( 'No such file' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
	}

	$fileUrl = FISHEYEMEDIA_PKG_URL.( $isBook ? 'view_book_file.php' : 'view_season_file.php' ).'?xref_id='.$xrefId;
	$name = basename( (string)$row['xkey_ext'] );
	$kind = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
	if( !in_array( $kind, [ 'pdf', 'epub' ], true ) ) {
		KernelTools::bit_redirect( $fileUrl );
	}
	$galleryUrl = $gContent->getDisplayUrl();
}
// A PDF opens in pdf.js, an epub in the site's own epub.js reader (themes/js/epubjs-<version>/reader.html); both take the search term to find.
$highlight = trim( (string)( $_REQUEST['highlight'] ?? '' ) );
$viewerSrc = $kind === 'pdf'
	? THEMES_PKG_URL.'js/pdfjs-5.2.133/web/viewer.html?file='.rawurlencode( $fileUrl ).'#zoom=page-width'.( $highlight !== '' ? '&search='.rawurlencode( $highlight ) : '' )
	: THEMES_PKG_URL.'js/epubjs-0.3.93/reader.html?file='.rawurlencode( $fileUrl ).( $highlight !== '' ? '&search='.rawurlencode( $highlight ) : '' );

$gBitSmarty->assign( 'gContent', $gContent );
$gBitSmarty->assign( 'fileName', $name );
$gBitSmarty->assign( 'fileUrl', $fileUrl );
$gBitSmarty->assign( 'viewerSrc', $viewerSrc );
$gBitSmarty->assign( 'galleryUrl', $galleryUrl );
$gBitSystem->display( 'bitpackage:fisheyemedia/view_extra_file.tpl', $name, [ 'display_mode' => 'display' ] );

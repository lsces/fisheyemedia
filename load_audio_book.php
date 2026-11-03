<?php
/**
 * Load Audio Book: turns a book's Audio folder(s) into albums. One Audio folder (Audio, Audio2, ...) is one audio book and one
 * fisheyealbum, made with the music album code and filed under the audio books gallery; the book's 'audiobook' row then names the
 * folder (xkey_ext) and points at the album (xref = its content_id), which is how the album finds its files and how the book's
 * Extras shows it as a single entry. See FisheyeBookFiles::loadAudioFolder().
 *
 * A single audio file beside the ebook is not loaded here: Load Book Files makes that an 'audiobook' row of its own.
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

$book = new FisheyeBook( null, (int)( $_REQUEST['content_id'] ?? 0 ) );
$book->load();
if( !$book->isValid() || empty( $book->getTitle() ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No book exists with the given ID.' ) );
}

$result = null;
if( !empty( $_REQUEST['fImport'] ) ) {
	$result = [ 'imported' => [], 'already' => [], 'errors' => [] ];
	foreach( (array)( $_REQUEST['selected'] ?? [] ) as $rel ) {
		// detoxify() HTML-escapes every request value; the folder is checked against the book's own folders anyway
		$rel = htmlspecialchars_decode( trim( (string)$rel ), ENT_NOQUOTES );
		$one = FisheyeBookFiles::loadAudioFolder( $book, $rel );
		if( !empty( $one['created'] ) ) {
			$result['imported'][] = [ 'folder' => $rel, 'content_id' => $one['created'], 'tracks' => $one['tracks'] ];
		} elseif( !empty( $one['already'] ) ) {
			$result['already'][] = [ 'folder' => $rel, 'content_id' => $one['already'] ];
		} else {
			$result['errors'][] = [ 'folder' => $rel, 'error' => $one['error'] ?? KernelTools::tra( 'Unknown error' ) ];
		}
	}
	$book->load();
}

$folders = FisheyeBookFiles::audioFolders( $book );
// Where the author workbench sent us from (its author gallery), so the page can lead back to it; absent when the page is opened from elsewhere.
$workbenchGalleryId = \Bitweaver\BitBase::verifyId( $_REQUEST['gallery_id'] ?? 0 ) ? (int)$_REQUEST['gallery_id'] : 0;
$gBitSmarty->assign( 'workbenchGalleryId', $workbenchGalleryId );
$gBitSmarty->assign( 'book', $book );
$gBitSmarty->assign( 'folders', $folders );
$gBitSmarty->assign( 'result', $result );
$gBitSystem->display( 'bitpackage:fisheyemedia/load_audio_book.tpl', KernelTools::tra( 'Load Audio Book: ' ).$book->getTitle(), [ 'display_mode' => 'edit' ] );

<?php
/**
 * Look up details for one Book: what the thing is, so its ISBN can be found and stored. It shows the title, the ISBN row if there is one, text links to search for the
 * edition by its title and any extra words typed (AbeBooks lists every edition with its ISBN, Open Library, Wikidata), a box to add the ISBN found (checked and stored as any Book's ISBN is), and the
 * box for the book's Wikidata item, and a button to the Available information page (what the sources hold, to choose from). The cover picker is a button away. Reached from the
 * Book's edit page, whose only lookup button this is.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeImage;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'fisheye' );

$gContent = FisheyeImage::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() || !$gContent instanceof FisheyeBook ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No book exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->verifyUpdatePermission();

// detoxify() HTML-escapes every $_REQUEST value; undone for what is typed.
$decode = fn( $pValue ) => htmlspecialchars_decode( trim( (string)$pValue ), ENT_NOQUOTES );

$message = '';
$errors = [];
$isbnRow = fn() => $gContent->mDb->getRow( "SELECT `xkey`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'isbn' AND `end_date` IS NULL", [ $gContent->mContentId ] );

// The ISBN: added when the book has none, changed when it has one. FisheyeBook::storeXref() checks the check digit and keeps ISBN-13 and ISBN-10.
if( !empty( $_REQUEST['save_isbn'] ) ) {
	$hash = [ 'content_id' => $gContent->mContentId, 'item' => 'isbn', 'isbn' => $decode( $_REQUEST['isbn'] ?? '' ) ];
	if( $existing = $gContent->mDb->getOne( "SELECT `xref_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'isbn' AND `end_date` IS NULL", [ $gContent->mContentId ] ) ) {
		$hash['xref_id'] = (int)$existing;
	}
	if( $gContent->storeXref( $hash ) ) {
		$message = KernelTools::tra( 'The ISBN is saved.' );
	}
}

// The Wikidata item (a Q number, or the address of its page), kept as the book's external link.
if( !empty( $_REQUEST['save_wikidata'] ) ) {
	if( preg_match( '/\bQ\d{1,12}\b/i', $decode( $_REQUEST['wikidata'] ?? '' ), $m ) ) {
		if( \Bitweaver\Liberty\LibertyContent::upsertXrefByContentId( (int)$gContent->mContentId, 'wikidata_work', [ 'xkey' => strtoupper( $m[0] ) ] ) ) {
			$message = KernelTools::tra( 'The Wikidata link is saved.' );
		} else {
			$errors[] = KernelTools::tra( 'The Wikidata link could not be saved.' );
		}
	} else {
		$errors[] = KernelTools::tra( 'Give the Wikidata item as its number (Q followed by digits) or the address of its page.' );
	}
}

$errors = array_merge( $errors, array_values( $gContent->mErrors ) );

// extra words for the searches (D&D 4th, an author ...): what was typed, else the book's first author
$extra = isset( $_REQUEST['extra'] ) ? $decode( $_REQUEST['extra'] ) : (string)$gContent->mDb->getOne( "SELECT `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'author' AND `end_date` IS NULL ORDER BY `xorder`", [ $gContent->mContentId ] );
$row = $isbnRow();

$gContent->load();
$gBitSmarty->assign( 'gContent', $gContent );
$gBitSmarty->assign( 'message', $message );
$gBitSmarty->assign( 'errors', $errors );
$gBitSmarty->assign( 'isbn13', (string)( $row['xkey'] ?? '' ) );
$gBitSmarty->assign( 'isbn10', (string)( $row['xkey_ext'] ?? '' ) );
$gBitSmarty->assign( 'extra', $extra );
$gBitSmarty->assign( 'wikidata', (string)$gContent->mDb->getOne( "SELECT `xkey` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'wikidata_work' AND `end_date` IS NULL", [ $gContent->mContentId ] ) );
$gBitSmarty->assign( 'isbnHint', $gContent->isbnChangedHint() );
$gBitSmarty->assign( 'titleTerm', rawurlencode( $gContent->getTitle() ) );
$gBitSmarty->assign( 'extraTerm', rawurlencode( $extra ) );
$gBitSmarty->assign( 'searchTerm', rawurlencode( trim( $gContent->getTitle().' '.$extra ) ) );
$gBitSystem->display( 'bitpackage:fisheyemedia/look_up_details.tpl', KernelTools::tra( 'Look up details: ' ).$gContent->getTitle(), [ 'display_mode' => 'edit' ] );

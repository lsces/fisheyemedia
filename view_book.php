<?php
/**
 * Dedicated view page for a book (FisheyeBook) - same shape as view_album.php, bucketing this book's
 * own xref data into template-friendly arrays rather than hardcoding which x_group an item lives under.
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeImage;

require_once '../kernel/includes/setup_inc.php';
global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'fisheye' );

$gContent = FisheyeImage::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No book exists with the given ID' ), 'error.tpl' );
}
$gContent->verifyViewPermission();
$gContent->addHit();

foreach( FisheyeBookDetails::collect( $gContent ) as $key => $value ) {
	$gBitSmarty->assign( $key, $value );
}

$gBitSmarty->assign( 'gContent', $gContent );

$gBitSystem->setCanonicalLink( $gContent->getDisplayUrl() );
$gBitSystem->setBrowserTitle( $gContent->getTitle() );
$gBitSystem->display( 'bitpackage:fisheyemedia/view_book.tpl', null, [ 'display_mode' => 'display' ] );

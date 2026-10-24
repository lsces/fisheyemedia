<?php
/**
 * The Extras list for a media item: the pictures (its own and its pictures folder's) and, later, other kinds of material.
 * Answers with just the list (?fragment=1) for the Extras button's popup to load, or as a page of its own otherwise, so the
 * list can be linked to and works without scripting.
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
if( !$gContent || !$gContent->isValid() || !method_exists( $gContent, 'getExtrasGroups' ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No item exists with the given ID' ), 'error.tpl' );
}
$gContent->verifyViewPermission();

$extras = $gContent->getExtrasGroups();
$gBitSmarty->assign( 'gContent', $gContent );
$gBitSmarty->assign( 'extrasGroups', $extras['groups'] );

if( !empty( $_REQUEST['fragment'] ) ) {
	header( 'Content-Type: text/html; charset=utf-8' );
	echo $gBitSmarty->fetch( 'bitpackage:fisheyemedia/extras_list_inc.tpl' );
	die;
}
$gBitSystem->display( 'bitpackage:fisheyemedia/extras.tpl', $gContent->getTitle().' - '.KernelTools::tra( 'Extras' ), [ 'display_mode' => 'display' ] );

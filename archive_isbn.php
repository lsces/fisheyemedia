<?php
/**
 * ISBN tags for a folder archive: reads the loaded PDFs and epubs of the kind and writes an 'isbn' row on each that has one (see FisheyeArchiveIsbn). Recomputed
 * from the database after every press, so it can be repeated as more of the archive is loaded.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

$kind = preg_replace( '/[^a-z]/', '', strtolower( (string)( $_REQUEST['kind'] ?? 'documentation' ) ) );
FisheyeDocsWorkbench::setKind( $kind );
$kind = FisheyeDocsWorkbench::kind();

$messages = [];
$errors = [];
$continue = false;
if( !empty( $_REQUEST['tag_isbn'] ) ) {
	$keepGoing = !empty( $_REQUEST['keep_going'] );
	$done = FisheyeArchiveIsbn::tag( $kind, $keepGoing ? FisheyeDocsWorkbench::KEEP_GOING_SECONDS : 0 );
	$messages[] = $done['read'].' '.KernelTools::tra( 'files read' ).': '.$done['tagged'].' '.KernelTools::tra( 'tagged' ).', '.$done['none'].' '.KernelTools::tra( 'with no ISBN' ).( $done['kept'] ? ', '.$done['kept'].' '.KernelTools::tra( 'already had one' ) : '' ).( $done['several'] ? ', '.$done['several'].' '.KernelTools::tra( 'give only numbers that may belong to other books, left for you to check' ) : '' ).'.';
	$errors = $done['errors'];
	$continue = $keepGoing && $done['read'] > 0 && !$done['errors'];
}

$gBitSmarty->assign( 'kind', $kind );
$gBitSmarty->assign( 'topTitle', FisheyeDocsWorkbench::topTitle() );
$gBitSmarty->assign( 'st', FisheyeArchiveIsbn::status( $kind ) );
$gBitSmarty->assign( 'plainBooks', count( FisheyeDocsWorkbench::plainImageBooks() ) );
$gBitSmarty->assign( 'batch', FisheyeArchiveIsbn::BATCH );
$gBitSmarty->assign( 'keepSeconds', FisheyeDocsWorkbench::KEEP_GOING_SECONDS );
$gBitSmarty->assign( 'keepGoingChecked', !empty( $_REQUEST['keep_going'] ) );
$gBitSmarty->assign( 'continue', $continue );
$gBitSmarty->assign( 'messages', $messages );
$gBitSmarty->assign( 'errors', $errors );
$gBitSystem->display( 'bitpackage:fisheyemedia/archive_isbn.tpl', KernelTools::tra( 'ISBN tags' ), [ 'display_mode' => 'edit' ] );

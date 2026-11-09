<?php
/**
 * Docs workbench: the files in the Documentation folder, one topic at a time (see FisheyeDocsWorkbench). The front page lists every topic and
 * what is left; a topic page lists its files and loads the next few in place, filed in the Documentation gallery tree, and shows what the gallery
 * holds from uploads and which files in the upload sources folder are not in it. Recomputed from the database
 * and the disk after every press, so it can be repeated; nothing is written until a button is pressed.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

$messages = [];
$errors = [];
// detoxify() HTML-escapes every $_REQUEST value; a topic folder is a raw filesystem name.
$topic = htmlspecialchars_decode( trim( (string)( $_REQUEST['topic'] ?? '' ) ), ENT_NOQUOTES );

if( $topic === '' ) {
	$all = FisheyeDocsWorkbench::overview();
	$showAll = !empty( $_REQUEST['all'] );
	$gBitSmarty->assign( 'overview', $showAll ? $all : array_filter( $all, fn( $r ) => !$r['done'] ) );
	$gBitSmarty->assign( 'finished', count( array_filter( $all, fn( $r ) => $r['done'] ) ) );
	$gBitSmarty->assign( 'showAll', $showAll );
	$gBitSmarty->assign( 'root', FisheyeDocsWorkbench::root() );
	$gBitSmarty->assign( 'hasTop', (bool)FisheyeDocsWorkbench::topContentId() );
	$gBitSmarty->assign( 'topTitle', FisheyeDocsWorkbench::TOP_TITLE );
	$gBitSystem->display( 'bitpackage:fisheyemedia/docs_index.tpl', KernelTools::tra( 'Docs workbench' ), [ 'display_mode' => 'admin' ] );
	exit;
}

if( !empty( $_REQUEST['load_files'] ) ) {
	$done = FisheyeDocsWorkbench::loadTopic( $topic );
	if( $done['loaded'] ) {
		$messages[] = $done['loaded'].' '.KernelTools::tra( 'files loaded.' );
	}
	$errors = array_merge( $errors, $done['errors'] );
}

$gBitSmarty->assign( 'st', FisheyeDocsWorkbench::status( $topic ) );
$gBitSmarty->assign( 'uploads', FisheyeDocsWorkbench::uploads( $topic ) );
$gBitSmarty->assign( 'topUrl', FisheyeDocsWorkbench::galleryUrl( FisheyeDocsWorkbench::topContentId() ) );
$gBitSmarty->assign( 'topicUrl', FisheyeDocsWorkbench::galleryUrl( FisheyeDocsWorkbench::topicContentId( $topic ) ) );
$gBitSmarty->assign( 'topTitle', FisheyeDocsWorkbench::TOP_TITLE );
$gBitSmarty->assign( 'batch', FisheyeDocsWorkbench::BATCH );
$gBitSmarty->assign( 'messages', $messages );
$gBitSmarty->assign( 'errors', $errors );
$gBitSystem->display( 'bitpackage:fisheyemedia/docs_workbench.tpl', KernelTools::tra( 'Docs workbench' ), [ 'display_mode' => 'edit' ] );

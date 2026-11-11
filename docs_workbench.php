<?php
/**
 * Docs workbench: the files in the Documentation folder (kind=documentation) or the Magazines folder (kind=magazines), one topic or title at a time (see FisheyeDocsWorkbench). The front page lists every topic and
 * what is left; a topic page lists its files and loads the next few in place, filed in the Documentation gallery tree, and shows what the gallery
 * holds from uploads. Recomputed from the database
 * and the disk after every press, so it can be repeated; nothing is written until a button is pressed.
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
$gBitSmarty->assign( 'kind', $kind );
$gBitSmarty->assign( 'noun', FisheyeDocsWorkbench::noun() );
$gBitSmarty->assign( 'pageTitle', $kind === 'magazines' ? 'Magazines workbench' : 'Docs workbench' );

$messages = [];
$errors = [];
// detoxify() HTML-escapes every $_REQUEST value; a topic folder is a raw filesystem name.
$topic = htmlspecialchars_decode( trim( (string)( $_REQUEST['topic'] ?? '' ) ), ENT_NOQUOTES );

if( $topic === '' ) {
	if( !empty( $_REQUEST['make_top'] ) ) {
		FisheyeDocsWorkbench::makeTop() ? $messages[] = KernelTools::tra( 'The top-level gallery was made.' ) : $errors[] = KernelTools::tra( 'The top-level gallery could not be made.' );
	}
	$gBitSmarty->assign( 'messages', $messages );
	$gBitSmarty->assign( 'errors', $errors );
	$all = FisheyeDocsWorkbench::overview();
	$showAll = !empty( $_REQUEST['all'] );
	$gBitSmarty->assign( 'overview', $showAll ? $all : array_filter( $all, fn( $r ) => !$r['done'] ) );
	$gBitSmarty->assign( 'finished', count( array_filter( $all, fn( $r ) => $r['done'] ) ) );
	$gBitSmarty->assign( 'showAll', $showAll );
	$gBitSmarty->assign( 'root', FisheyeDocsWorkbench::root() );
	$gBitSmarty->assign( 'hasTop', (bool)FisheyeDocsWorkbench::topContentId() );
	$gBitSmarty->assign( 'topTitle', FisheyeDocsWorkbench::topTitle() );
	$gBitSystem->display( 'bitpackage:fisheyemedia/docs_index.tpl', KernelTools::tra( $kind === 'magazines' ? 'Magazines workbench' : 'Docs workbench' ), [ 'display_mode' => 'admin' ] );
	exit;
}

if( !empty( $_REQUEST['load_files'] ) ) {
	// keep going: work for KEEP_GOING_SECONDS, then the page sends the next press by itself while files are left and nothing went wrong
	$keepGoing = !empty( $_REQUEST['keep_going'] );
	$done = FisheyeDocsWorkbench::loadTopic( $topic, $keepGoing ? FisheyeDocsWorkbench::KEEP_GOING_SECONDS : 0 );
	if( $done['loaded'] ) {
		$messages[] = $done['loaded'].' '.KernelTools::tra( 'files loaded' ).( $keepGoing ? ' ('.$done['seconds'].' s).' : '.' );
	}
	$errors = array_merge( $errors, $done['errors'] );
	$gBitSmarty->assign( 'continue', $keepGoing && $done['loaded'] && !$errors );
}
$gBitSmarty->assign( 'keepGoingChecked', !empty( $_REQUEST['keep_going'] ) );
$gBitSmarty->assign( 'keepSeconds', FisheyeDocsWorkbench::KEEP_GOING_SECONDS );

$gBitSmarty->assign( 'st', FisheyeDocsWorkbench::status( $topic ) );
$gBitSmarty->assign( 'uploads', FisheyeDocsWorkbench::uploads( $topic ) );
$gBitSmarty->assign( 'topUrl', FisheyeDocsWorkbench::galleryUrl( FisheyeDocsWorkbench::topContentId() ) );
$gBitSmarty->assign( 'topicUrl', FisheyeDocsWorkbench::galleryUrl( FisheyeDocsWorkbench::topicContentId( $topic ) ) );
$gBitSmarty->assign( 'topTitle', FisheyeDocsWorkbench::topTitle() );
$gBitSmarty->assign( 'batch', FisheyeDocsWorkbench::BATCH );
$gBitSmarty->assign( 'messages', $messages );
$gBitSmarty->assign( 'errors', $errors );
$gBitSystem->display( 'bitpackage:fisheyemedia/docs_workbench.tpl', KernelTools::tra( $kind === 'magazines' ? 'Magazines workbench' : 'Docs workbench' ), [ 'display_mode' => 'edit' ] );

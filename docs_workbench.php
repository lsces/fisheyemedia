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
$gBitSmarty->assign( 'pageTitle', $kind === 'documentation' ? 'Docs workbench' : FisheyeDocsWorkbench::topTitle().' workbench' );

$messages = [];
$errors = [];
// detoxify() HTML-escapes every $_REQUEST value; a topic folder is a raw filesystem name.
$topic = htmlspecialchars_decode( trim( (string)( $_REQUEST['topic'] ?? '' ) ), ENT_NOQUOTES );

if( $topic === '' ) {
	if( !empty( $_REQUEST['make_top'] ) ) {
		FisheyeDocsWorkbench::makeTop() ? $messages[] = KernelTools::tra( 'The top-level gallery was made.' ) : $errors[] = KernelTools::tra( 'The top-level gallery could not be made.' );
	}
	if( $kind === 'magazines' && !empty( $_REQUEST['convert_issues'] ) ) {
		$conv = FisheyeDocsWorkbench::convertLoadedIssues();
		$conv['converted'] ? $messages[] = $conv['converted'].' '.KernelTools::tra( 'loaded issues are now Magazine Issues.' ) : null;
		$errors = array_merge( $errors, $conv['errors'] );
	}
	if( $kind !== 'magazines' && !empty( $_REQUEST['convert_books'] ) ) {
		$conv = FisheyeDocsWorkbench::convertLoadedBooks();
		$conv['converted'] ? $messages[] = $conv['converted'].' '.KernelTools::tra( 'loaded files are now Books.' ) : null;
		$errors = array_merge( $errors, $conv['errors'] );
	}
	if( $kind !== 'magazines' && !empty( $_REQUEST['flatten_singles'] ) ) {
		$flat = FisheyeDocsWorkbench::flattenSingleDocuments();
		$flat['moved'] ? $messages[] = $flat['moved'].' '.KernelTools::tra( 'documents were filed straight into the gallery above.' ) : null;
		$errors = array_merge( $errors, $flat['errors'] );
	}
	$gBitSmarty->assign( 'singleDocuments', $kind !== 'magazines' ? count( FisheyeDocsWorkbench::singleDocumentGalleries() ) : 0 );
	$gBitSmarty->assign( 'plainBooks', $kind !== 'magazines' ? count( FisheyeDocsWorkbench::plainImageBooks() ) : 0 );
	$gBitSmarty->assign( 'plainIssues', $kind === 'magazines' ? count( FisheyeDocsWorkbench::plainImageIssues() ) : 0 );
	$gBitSmarty->assign( 'messages', $messages );
	$gBitSmarty->assign( 'errors', $errors );
	$all = FisheyeDocsWorkbench::overview();
	$showAll = !empty( $_REQUEST['all'] );
	// the front page lists only what has files left to load (or an error to show, or a folder waiting for its Make Book); finished folders and folders with no files of their own
	// (galleries made from uploads) are behind "Show the finished ones"
	$hasWork = fn( $r ) => $r['error'] || $r['todo'] > 0 || !empty( $r['awaitingBook'] );
	$gBitSmarty->assign( 'overview', $showAll ? $all : array_filter( $all, $hasWork ) );
	$gBitSmarty->assign( 'finished', count( array_filter( $all, fn( $r ) => !$hasWork( $r ) ) ) );
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
	// a file that fails does not stop the run (it is retried each press and listed); only a press that loads nothing does, so a bad PDF cannot stall an overnight load
	$gBitSmarty->assign( 'continue', $keepGoing && $done['loaded'] > 0 );
}
if( !empty( $_REQUEST['make_book'] ) ) {
	$made = FisheyeDocsWorkbench::makeProjectBook( htmlspecialchars_decode( trim( (string)$_REQUEST['make_book'] ), ENT_NOQUOTES ) );
	( $made['created'] ?? 0 ) ? $messages[] = KernelTools::tra( 'The Book was made' ).' (content_id '.$made['created'].'), '.$made['rows'].' '.KernelTools::tra( 'ebook rows.' ) : ( $made['rows'] ? $messages[] = $made['rows'].' '.KernelTools::tra( 'ebook rows were added to the existing Book.' ) : null );
	$errors = array_merge( $errors, $made['errors'] );
}
$gBitSmarty->assign( 'projects', FisheyeDocsWorkbench::projectFolders( $topic ) );
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

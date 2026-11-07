<?php
/**
 * Tutorial workbench: the video courses in the Local Tutorials folder one at a time (see FisheyeTutorialWorkbench). The front page lists every
 * course folder and what is left; a course page has the steps in order - register the course, load its seasons, load the files that go with them.
 * Each step is its own button and is recomputed from the database and the disk afterwards, so it can be repeated; nothing is written until a button
 * is pressed.
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
// detoxify() HTML-escapes every $_REQUEST value; a course folder is a raw filesystem name.
$course = htmlspecialchars_decode( trim( (string)( $_REQUEST['course'] ?? '' ) ), ENT_NOQUOTES );

if( $course === '' ) {
	$all = FisheyeTutorialWorkbench::overview();
	$showAll = !empty( $_REQUEST['all'] );
	$gBitSmarty->assign( 'overview', $showAll ? $all : array_filter( $all, fn( $r ) => !$r['done'] ) );
	$gBitSmarty->assign( 'finished', count( array_filter( $all, fn( $r ) => $r['done'] ) ) );
	$gBitSmarty->assign( 'showAll', $showAll );
	$gBitSmarty->assign( 'root', FisheyeTutorialWorkbench::root() );
	$gBitSmarty->assign( 'hasTopGallery', FisheyeTutorialWorkbench::hasTopGallery() );
	$gBitSmarty->assign( 'errors', $errors );
	$gBitSystem->display( 'bitpackage:fisheyemedia/tutorial_index.tpl', KernelTools::tra( 'Tutorial workbench' ), [ 'display_mode' => 'admin' ] );
	exit;
}

if( !empty( $_REQUEST['register_course'] ) ) {
	$done = FisheyeTutorialWorkbench::registerCourse( $course );
	$errors = array_merge( $errors, $done['errors'] );
	if( !$done['errors'] ) {
		$messages[] = KernelTools::tra( 'The course was registered.' );
	}
} elseif( !empty( $_REQUEST['convert_course'] ) ) {
	$done = FisheyeTutorialWorkbench::convertCourse( $course );
	$errors = array_merge( $errors, $done['errors'] );
	if( !$done['errors'] ) {
		$messages[] = KernelTools::tra( 'Converted to a tutorial course:' ).' '.$done['sections'].' '.KernelTools::tra( 'sections.' );
	}
} elseif( !empty( $_REQUEST['load_seasons'] ) ) {
	$done = FisheyeTutorialWorkbench::loadSeasons( $course );
	$messages[] = $done['loaded'].' '.KernelTools::tra( 'seasons loaded,' ).' '.$done['episodes'].' '.KernelTools::tra( 'episodes.' );
	$errors = array_merge( $errors, $done['errors'] );
} elseif( !empty( $_REQUEST['load_files'] ) ) {
	$done = FisheyeTutorialWorkbench::loadFiles( $course );
	if( $done['seasons'] ) {
		$messages[] = $done['seasons'].' '.KernelTools::tra( 'seasons scanned,' ).' '.$done['files'].' '.KernelTools::tra( 'files registered.' );
	}
	$errors = array_merge( $errors, $done['errors'] );
}

$gBitSmarty->assign( 'st', FisheyeTutorialWorkbench::status( $course ) );
$gBitSmarty->assign( 'batch', FisheyeTutorialWorkbench::BATCH );
$gBitSmarty->assign( 'hasTopGallery', FisheyeTutorialWorkbench::hasTopGallery() );
$gBitSmarty->assign( 'messages', $messages );
$gBitSmarty->assign( 'errors', $errors );
$gBitSystem->display( 'bitpackage:fisheyemedia/tutorial_workbench.tpl', KernelTools::tra( 'Tutorial workbench' ), [ 'display_mode' => 'edit' ] );

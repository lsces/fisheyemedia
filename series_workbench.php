<?php
/**
 * Series workbench: the Various Authors series of the Library tree one at a time (see FisheyeSeriesWorkbench). The front page lists the series that
 * still have something to do, each with its next step; a series page has the three steps in order - load the books its SeriesCatalogue.csv names
 * that are not in the database, file the series, load the files of its books. Finished series are put away behind "show finished".
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

$root = FisheyeBookFiles::libraryRoot();
$decode = fn( $pValue ) => htmlspecialchars_decode( trim( (string)$pValue ), ENT_NOQUOTES );
$catalogues = FisheyeVariousAuthors::catalogues();
$series = $decode( $_REQUEST['series'] ?? '' );
if( $series !== '' && !isset( $catalogues[$series] ) ) {
	$series = '';
}
$showAll = !empty( $_REQUEST['all'] );
$messages = [];
$errors = [];

// The three steps. Each is its own button, recomputed from the database afterwards, so a step can be repeated.
if( $series !== '' ) {
	$st = FisheyeSeriesWorkbench::status( $series, $catalogues[$series] );
	if( !empty( $_REQUEST['load_missing'] ) && !$st['error'] ) {
		foreach( FisheyeSeriesWorkbench::loadMissing( $st, array_map( 'intval', (array)( $_REQUEST['book'] ?? [] ) ) ) as $one ) {
			$messages[] = $one['group'].': '.$one['created'].' '.KernelTools::tra( 'books created' ).( $one['already'] ? ', '.$one['already'].' '.KernelTools::tra( 'already there' ) : '' ).'.';
			foreach( $one['errors'] as $error ) {
				$errors[] = $error;
			}
		}
	} elseif( !empty( $_REQUEST['file_series'] ) && !$st['error'] ) {
		$done = FisheyeSeriesWorkbench::fileSeries( $st, array_keys( (array)( $_REQUEST['move'] ?? [] ) ) );
		if( $done['error'] ) {
			$errors[] = $done['error'];
		} else {
			$messages[] = $done['added'].' '.KernelTools::tra( 'books filed' ).', '.$done['made'].' '.KernelTools::tra( 'author series made' ).', '.$done['linked'].' '.KernelTools::tra( 'linked' ).', '
				.$done['moved'].' '.KernelTools::tra( 'books moved into them' ).( $done['missing'] ? ', '.$done['missing'].' '.KernelTools::tra( 'not in the database' ) : '' ).'.';
		}
	} elseif( !empty( $_REQUEST['register_files'] ) && !$st['error'] ) {
		$done = FisheyeSeriesWorkbench::registerFiles( array_map( 'intval', (array)( $_REQUEST['book'] ?? [] ) ) );
		$messages[] = $done['books'].' '.KernelTools::tra( 'books scanned,' ).' '.$done['files'].' '.KernelTools::tra( 'files loaded.' );
		$errors = array_merge( $errors, $done['errors'] );
	}
	$st = FisheyeSeriesWorkbench::status( $series, $catalogues[$series] );
	$gBitSmarty->assign( 'st', $st );
} else {
	$overview = [];
	$finished = 0;
	foreach( $catalogues as $name => $file ) {
		$one = FisheyeSeriesWorkbench::status( $name, $file );
		if( $one['done'] ) {
			$finished++;
		}
		if( $showAll || !$one['done'] ) {
			$overview[$name] = $one;
		}
	}
	$gBitSmarty->assign( 'overview', $overview );
	$gBitSmarty->assign( 'finished', $finished );
}

// The Various Authors gallery the title line links to, so what already exists can be seen.
$vaUrl = '';
if( ( $vaId = FisheyeBookCredits::libraryGalleryFor( FisheyeMediaGallery::VARIOUS_AUTHORS ) ) && ( $vaGallery = \Bitweaver\Liberty\LibertyContent::getLibertyObject( (int)$vaId ) ) && $vaGallery->isValid() ) {
	$vaUrl = (string)$vaGallery->getDisplayUrl();
}
$gBitSmarty->assign( 'vaUrl', $vaUrl );
$gBitSmarty->assign( 'series', $series );
$gBitSmarty->assign( 'showAll', $showAll );
$gBitSmarty->assign( 'catalogues', $catalogues );
$gBitSmarty->assign( 'libraryRoot', $root );
$gBitSmarty->assign( 'messages', $messages );
$gBitSmarty->assign( 'errors', $errors );
$gBitSmarty->assign( 'batch', FisheyeSeriesWorkbench::BATCH );
$gBitSmarty->assign( 'filesBatch', FisheyeSeriesWorkbench::FILES_BATCH );
$gBitSystem->display( 'bitpackage:fisheyemedia/series_workbench.tpl', KernelTools::tra( 'Series workbench' ), [ 'display_mode' => 'admin' ] );

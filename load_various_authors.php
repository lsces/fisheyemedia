<?php
/**
 * Various Authors series: reads each Library/Various Authors/<series>/SeriesCatalogue.csv and files the series (FisheyeVariousAuthors) - the
 * Various Authors gallery and the series under it made when missing, the catalogue's books found in the database added in catalogue order, and
 * each author's own gallery of the series linked under it. Shows what would happen first; only the ticked series are written, and a run can be
 * repeated. Books the catalogue names that are not in the database are listed, not made - they come in through Add Books.
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
$results = [];
$errors = [];

if( !empty( $_REQUEST['file_series'] ) ) {
	foreach( (array)( $_REQUEST['series'] ?? [] ) as $name ) {
		$name = $decode( $name );
		if( !isset( $catalogues[$name] ) ) {
			continue;
		}
		$done = FisheyeVariousAuthors::apply( FisheyeVariousAuthors::plan( $name, $catalogues[$name] ), array_keys( (array)( ( $_REQUEST['move'] ?? [] )[$name] ?? [] ) ) );
		if( $done['error'] ) {
			$errors[] = $name.' - '.$done['error'];
		} else {
			$results[$name] = $done;
		}
	}
}

$plans = [];
foreach( $catalogues as $name => $file ) {
	$plans[$name] = FisheyeVariousAuthors::plan( $name, $file );
	$plans[$name]['found'] = count( array_filter( array_column( $plans[$name]['rows'], 'book' ) ) );
}

$gBitSmarty->assign( 'libraryRoot', $root );
$gBitSmarty->assign( 'plans', $plans );
$gBitSmarty->assign( 'results', $results );
$gBitSmarty->assign( 'errors', $errors );
$gBitSystem->display( 'bitpackage:fisheyemedia/load_various_authors.tpl', KernelTools::tra( 'Various Authors series' ), [ 'display_mode' => 'admin' ] );

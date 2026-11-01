<?php
/**
 * Descriptions to HTML: finds the film, programme, season and album descriptions still held as plain text and stores each as HTML
 * (FisheyeDescriptions::convert(), through the item's own class, so history and the modified date are written). Safe to run again - a
 * row already HTML is not touched. A press converts as many rows as fit in the time budget, then the page sends itself again until
 * none are left (a row that cannot be stored is reported and passed over). 'Only look' counts and stores nothing.
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

// Seconds a press may run: the web server gives up on a page at 150 s.
const CONVERT_DESCRIPTIONS_BUDGET = 40;

$after = max( 0, (int)( $_REQUEST['after'] ?? 0 ) );
$converted = (int)( $_REQUEST['converted'] ?? 0 );
$failed = [];
$more = false;
$run = !empty( $_REQUEST['convert'] );

if( $run ) {
	session_write_close();
	$started = microtime( true );
	foreach( FisheyeDescriptions::plainRows( $after ) as $row ) {
		if( microtime( true ) - $started > CONVERT_DESCRIPTIONS_BUDGET ) {
			$more = true;
			break;
		}
		$after = $row['content_id'];
		if( $error = FisheyeDescriptions::convert( $row ) ) {
			$failed[] = [ 'content_id' => $row['content_id'], 'title' => $row['title'], 'error' => $error ];
		} else {
			$converted++;
		}
	}
}

$left = [];
foreach( FisheyeDescriptions::plainRows( $run ? $after : 0 ) as $row ) {
	$left[$row['content_type_guid']] = ( $left[$row['content_type_guid']] ?? 0 ) + 1;
}
$gBitSmarty->assign( 'left', $left );
$gBitSmarty->assign( 'converted', $converted );
$gBitSmarty->assign( 'failed', $failed );
$gBitSmarty->assign( 'more', $more );
$gBitSmarty->assign( 'after', $after );
$gBitSmarty->assign( 'ran', $run );
$gBitSystem->display( 'bitpackage:fisheyemedia/convert_descriptions.tpl', KernelTools::tra( 'Descriptions to HTML' ), [ 'display_mode' => 'edit' ] );

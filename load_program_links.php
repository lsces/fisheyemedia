<?php
/**
 * Give each BBC program its programme id. The pid comes from the PLAY/INFO lines already in its episodes' summaries: one episode pid per program is
 * taken from there and the BBC's own programme record walked up (episode, series, brand) to the id of the whole programme, which is what the
 * program's 'iplayer' external link holds (bbc.co.uk/programmes/<pid>, a page that stays up after the episodes leave iPlayer).
 *
 * 'Look up' asks the BBC about the next twenty programs and lists what came back with nothing ticked - the BBC title is shown beside yours, and a
 * program that is only a folder of your own (BBC Documentaries) will find nothing sensible, so each line is ticked by hand and the pid can be
 * corrected in its box. 'Add the ticked' saves them through the program's own xref item (the scheme item 'iplayer' must have been applied).
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeImage;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

const LOAD_PROGRAM_LINKS_BATCH = 20;
const LOAD_PROGRAM_LINKS_SECONDS = 100;

$message = '';
$errors = [];
$rows = [];
$after = (int)( $_REQUEST['after'] ?? 0 );

if( !empty( $_REQUEST['add'] ) ) {
	$saved = 0;
	foreach( array_slice( array_map( 'intval', (array)( $_REQUEST['program'] ?? [] ) ), 0, LOAD_PROGRAM_LINKS_BATCH ) as $id ) {
		$pid = strtolower( trim( (string)( $_REQUEST['pid'][$id] ?? '' ) ) );
		$program = FisheyeImage::lookup( [ 'content_id' => $id ] );
		if( !$program || !$program->isValid() || !( $program instanceof FisheyeProgram ) ) {
			$errors[] = $id.' - '.KernelTools::tra( 'that is not a program.' );
		} elseif( !preg_match( '/^[0-9a-z]{8}$/', $pid ) ) {
			$errors[] = $program->getTitle().' - '.KernelTools::tra( 'a BBC programme id is 8 letters and digits.' );
		} elseif( $program->upsertXref( $id, 'iplayer', [ 'xkey' => $pid ] ) ) {
			$saved++;
		} else {
			$errors[] = $program->getTitle().' - '.( implode( '; ', array_map( 'strval', array_values( $program->mErrors ) ) ) ?: KernelTools::tra( 'could not be saved (has the iplayer item been applied from the Local scheme page?).' ) );
		}
	}
	$message = $saved.' '.KernelTools::tra( 'saved.' );
} elseif( !empty( $_REQUEST['lookup'] ) ) {
	$started = time();
	foreach( FisheyeBbc::programsToLink( $after, LOAD_PROGRAM_LINKS_BATCH ) as $p ) {
		if( time() - $started > LOAD_PROGRAM_LINKS_SECONDS ) {
			break;
		}
		$after = $p['content_id'];
		$top = FisheyeBbc::programmeTop( $p['episode_pid'] );
		$program = FisheyeImage::lookup( [ 'content_id' => $p['content_id'] ] );
		$rows[] = $p + [
			'url' => $program && $program->isValid() ? $program->getDisplayUrl() : '',
			'pid' => $top['pid'] ?? '', 'bbc_title' => $top['title'] ?? '', 'bbc_type' => $top['type'] ?? '',
			'match' => $top && FisheyeBbc::sameTitle( $p['title'], $top['title'] ),
			'failed' => !$top,
		];
	}
	if( !$rows ) {
		$message = KernelTools::tra( 'No more programs to look up.' );
	}
}

$gBitSmarty->assign( 'rows', $rows );
$gBitSmarty->assign( 'after', $after );
$gBitSmarty->assign( 'remaining', FisheyeBbc::countToLink() );
$gBitSmarty->assign( 'batch', LOAD_PROGRAM_LINKS_BATCH );
$gBitSmarty->assign( 'message', $message );
$gBitSmarty->assign( 'errors', $errors );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_program_links.tpl', KernelTools::tra( 'BBC program links' ), [ 'display_mode' => 'edit' ] );

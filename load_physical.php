<?php
/**
 * Records the physical copies of media - LPs, singles, CDs, DVD sets - against the albums, films and shows
 * they are copies of, from an uploaded physical-catalogue spreadsheet (FisheyePhysicalCatalogue). Each copy is
 * stored as a 'physical' xref row (location, box, format, owner, note) on the matching item, so a record's
 * place on the shelf shows on its own page.
 *
 * The page lists what is still to do, twenty rows at a time, with what each row would do: add a copy to an
 * existing album, create a stub album (and its artist's gallery) for a record that is not in the library yet,
 * or - for a film or show - add it to the one already there. Nothing is written until the ticked rows are
 * applied. A film or show with no match offers the closest titles in the library to choose from, or a content
 * id to type in; an album whose folder is on disk but not loaded yet is listed but cannot be ticked. A row already stored is not listed again, so the page can be worked through over
 * several visits. Rows that cannot be matched yet, and rows left unticked in the To do list, go to an Unmatched
 * view of their own; Put back returns them. A link made by hand is remembered. Rows with
 * a Box but no Location take the default location typed above the list.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';
require_once dirname( __DIR__ ).'/liberty/plugins/mime.film.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

// Rows listed, and applied, per press.
const LOAD_PHYSICAL_BATCH = 20;
const LOAD_PHYSICAL_LOCATION_CONFIG = 'fisheyemedia_physical_location';

// detoxify() HTML-escapes every $_REQUEST value - undone for free text, as the other loaders do.
$decode = fn( $pValue ) => htmlspecialchars_decode( trim( (string)$pValue ), ENT_NOQUOTES );

$import = null;
if( !empty( $_REQUEST['upload'] ) ) {
	if( !is_uploaded_file( $_FILES['catalogue_file']['tmp_name'] ?? '' ) || strtolower( pathinfo( (string)$_FILES['catalogue_file']['name'], PATHINFO_EXTENSION ) ) !== 'ods' ) {
		$import = [ 'rows' => 0, 'error' => KernelTools::tra( 'Please choose an .ods spreadsheet.' ) ];
	} else {
		$import = FisheyePhysicalCatalogue::importFile( $_FILES['catalogue_file']['tmp_name'] );
	}
}

$catalogue = FisheyePhysicalCatalogue::cached();

$defaultLocation = $gBitSystem->getConfig( LOAD_PHYSICAL_LOCATION_CONFIG, 'Bookcase' );
if( isset( $_REQUEST['location'] ) && ( !empty( $_REQUEST['apply'] ) || !empty( $_REQUEST['show'] ) ) ) {
	$defaultLocation = $decode( $_REQUEST['location'] );
	$gBitSystem->storeConfig( LOAD_PHYSICAL_LOCATION_CONFIG, $defaultLocation, FISHEYEMEDIA_PKG_NAME );
}

$kind = in_array( $_REQUEST['kind'] ?? '', FisheyePhysicalCatalogue::KINDS, true ) ? $_REQUEST['kind'] : '';
// 'todo' - rows that can be stored now; 'unmatched' - the films, shows and albums that cannot be matched yet, and
// any row set aside, kept apart so they can be sorted out later; 'all' - everything not yet stored.
$view = in_array( $_REQUEST['view'] ?? '', [ 'todo', 'unmatched', 'all' ], true ) ? $_REQUEST['view'] : 'todo';
const LOAD_PHYSICAL_UNMATCHED = [ 'none', 'ondisk' ];
$start = max( 0, (int)( $_REQUEST['start'] ?? 0 ) );

$applied = null;
$rows = [];
$counts = [ 'applied' => 0, 'matched' => 0, 'stub' => 0, 'newartist' => 0, 'ondisk' => 0, 'none' => 0 ];
$pending = [];
if( $catalogue['rows'] ) {
	$index = FisheyePhysical::loadIndex();

	$byKey = [];
	foreach( $catalogue['rows'] as $row ) {
		$byKey[$row['key']] = $row;
	}
	$stateChanged = false;
	$picked = array_map( $decode, (array)( $_REQUEST['pick'] ?? [] ) );

	// Rows shown in the To do list and left unticked are set aside, so they stop heading every batch; Put back
	// returns the ticked rows from the other views.
	if( !empty( $_REQUEST['apply'] ) && $view === 'todo' ) {
		foreach( array_diff( array_map( $decode, (array)( $_REQUEST['shown'] ?? [] ) ), $picked ) as $key ) {
			if( isset( $byKey[$key] ) ) {
				$index['state']['skipped'][FisheyePhysicalCatalogue::signature( $byKey[$key] )] = true;
				$stateChanged = true;
			}
		}
	}
	if( !empty( $_REQUEST['restore'] ) ) {
		foreach( $picked as $key ) {
			if( isset( $byKey[$key] ) ) {
				unset( $index['state']['skipped'][FisheyePhysicalCatalogue::signature( $byKey[$key] )] );
				$stateChanged = true;
			}
		}
	}

	if( !empty( $_REQUEST['apply'] ) && $picked ) {
		$applied = [];
		foreach( array_slice( $picked, 0, LOAD_PHYSICAL_BATCH ) as $key ) {
			$row = $byKey[$key] ?? null;
			if( !$row ) {
				continue;
			}
			// A film or show linked by hand: a content id typed in, else the one chosen from the candidates.
			$manual = (int)$decode( $_REQUEST['linkid'][$key] ?? '' ) ?: (int)( $_REQUEST['link'][$key] ?? 0 );
			if( $manual ) {
				$row['manual_target'] = $manual;
			}
			$done = FisheyePhysical::apply( $row, $index, $defaultLocation );
			if( $done['ok'] && !empty( $row['manual_target'] ) ) {
				$index['state']['linked'][FisheyePhysicalCatalogue::signature( $row )] = (int)$row['manual_target'];
			}
			$applied[] = [ 'ok' => $done['ok'], 'message' => $done['message'], 'text' => trim( $row['artist'].' - '.$row['title'], ' -' ), 'format' => $row['format'] ];
			// A stub or a gallery just made has to be in the index for the next row of the same album or artist.
			if( str_starts_with( $done['message'], 'created' ) ) {
				$index = FisheyePhysical::loadIndex();
			} elseif( $done['ok'] && $done['content_id'] ) {
				$index['copies'][$done['content_id']][mb_strtolower( ( $row['box'] ).'|'.$row['format'].'|'.$row['owner'] )] = true;
			}
		}
	}

	if( $stateChanged || ( $applied && $index['state'] !== FisheyePhysicalCatalogue::loadState() ) ) {
		FisheyePhysicalCatalogue::saveState( $index['state'] );
	}

	foreach( $catalogue['rows'] as $row ) {
		$planned = FisheyePhysical::plan( $row, $index, $defaultLocation );
		$counts[$planned['status']]++;
		// Set-aside rows are kept with the unmatched ones, out of the way of the To do list.
		$unmatched = $planned['skipped'] || in_array( $planned['status'], LOAD_PHYSICAL_UNMATCHED, true );
		if( $planned['status'] !== 'applied' && ( $kind === '' || $planned['kind'] === $kind )
			&& ( $view === 'all' || ( $view === 'unmatched' ) === $unmatched ) ) {
			$pending[] = $planned;
		}
	}
	$rows = array_slice( $pending, $start, LOAD_PHYSICAL_BATCH );
}

$gBitSmarty->assign( 'import', $import );
$gBitSmarty->assign( 'catalogueFile', $catalogue['file'] );
$gBitSmarty->assign( 'counts', $counts );
$gBitSmarty->assign( 'rows', $rows );
$gBitSmarty->assign( 'pendingCount', count( $pending ) );
$gBitSmarty->assign( 'start', $start );
$gBitSmarty->assign( 'nextStart', $start + LOAD_PHYSICAL_BATCH < count( $pending ) ? $start + LOAD_PHYSICAL_BATCH : null );
$gBitSmarty->assign( 'prevStart', $start > 0 ? max( 0, $start - LOAD_PHYSICAL_BATCH ) : null );
$gBitSmarty->assign( 'batchSize', LOAD_PHYSICAL_BATCH );
$gBitSmarty->assign( 'kind', $kind );
$gBitSmarty->assign( 'view', $view );
$gBitSmarty->assign( 'defaultLocation', $defaultLocation );
$gBitSmarty->assign( 'applied', $applied );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_physical.tpl', KernelTools::tra( 'Physical Copies' ), [ 'display_mode' => 'edit' ] );

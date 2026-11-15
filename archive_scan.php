<?php
/**
 * Archive scan: reads a folder archive from its raw file structure (no catalogue) and reports what the archive loader would make from it - see
 * FisheyeArchiveScan. Any folder in the media storage root can be picked; nothing is written.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

// The folder that holds the media trees: the one that holds Library/.
$library = FisheyeBookFiles::libraryRoot();
$parent = $library !== '' ? dirname( rtrim( $library, '/' ) ) : '';
$candidates = $parent !== '' && is_dir( $parent ) ? FisheyeArchiveScan::candidates( $parent ) : [];
// detoxify() HTML-escapes every $_REQUEST value; a folder name is a raw filesystem name.
$base = htmlspecialchars_decode( trim( (string)( $_REQUEST['base'] ?? '' ) ), ENT_NOQUOTES );
$known = array_column( $candidates, 'name' );
$result = null;
$errors = [];
$messages = [];
// Registering the folder as an archive: the Docs workbench then loads it, one top-level folder at a time (see FisheyeArchives).
$kindMade = null;
if( !empty( $_REQUEST['register'] ) && $base !== '' ) {
	$error = FisheyeArchives::register( (string)( $_REQUEST['kind'] ?? '' ), htmlspecialchars_decode( trim( (string)( $_REQUEST['top'] ?? '' ) ), ENT_NOQUOTES ), $base, (string)( $_REQUEST['noun'] ?? 'topic' ) );
	if( $error !== null ) {
		$errors[] = KernelTools::tra( $error );
	} else {
		$kindMade = strtolower( trim( (string)$_REQUEST['kind'] ) );
		$messages[] = KernelTools::tra( 'Registered. Open its workbench to make the top gallery and load a folder at a time.' );
	}
}
if( $base !== '' ) {
	if( !in_array( $base, $known, true ) ) {
		$errors[] = KernelTools::tra( 'That folder is not in the media storage folder.' );
	} else {
		$result = FisheyeArchiveScan::scan( $parent.'/'.$base );
	}
}
$gBitSmarty->assign( 'parent', $parent );
$gBitSmarty->assign( 'candidates', $candidates );
$gBitSmarty->assign( 'base', $base );
$gBitSmarty->assign( 'result', $result );
$gBitSmarty->assign( 'errors', $errors );
$gBitSmarty->assign( 'messages', $messages );
$gBitSmarty->assign( 'kindMade', $kindMade );
$gBitSmarty->assign( 'archives', FisheyeArchives::all() );
$gBitSmarty->assign( 'folderArchive', array_values( array_filter( FisheyeArchives::all(), fn( $a ) => strcasecmp( $a['folder'], $base ) === 0 ) )[0] ?? null );
$gBitSmarty->assign( 'suggestKind', $base !== '' ? FisheyeArchives::suggestKind( $base ) : '' );
$gBitSystem->display( 'bitpackage:fisheyemedia/archive_scan.tpl', KernelTools::tra( 'Archive scan' ), [ 'display_mode' => 'admin' ] );

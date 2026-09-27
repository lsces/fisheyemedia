<?php
/**
 * Admin page for registering an already-on-disk film via mime.film.php's no-copy attachment path
 * - the real, permanent replacement for a one-off CLI smoke-test script hand-editing an array of
 * files to register. Runs as a genuine authenticated admin request (php-fpm as the web server
 * user), so thumbnail generation under storage/attachments/ works correctly with no permission
 * workarounds needed.
 *
 * Plex metadata backfill itself lives in FisheyeFilm::reloadPlexMetadata() (moved there
 * 2026-09-02) so edit_film.php's 'Reload Metadata' action can reuse it for a film imported
 * before the backfill existed - see that method's docblock for the Plex-matching detail.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb, $gLibertySystem;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

require_once dirname( __DIR__ ).'/../liberty/plugins/mime.film.php';
if( !$gLibertySystem->isPluginActive( 'mimefilm' ) ) {
	$gLibertySystem->setActivePlugin( 'mimefilm' );
}

$root = \Bitweaver\Liberty\mime_film_get_storage_root();
$result = null;

if( !empty( $_REQUEST['fImport'] ) ) {
	$relativePath = trim( $_REQUEST['relative_path'] ?? '' );
	$title = trim( $_REQUEST['title'] ?? '' );
	if( empty( $title ) ) {
		// Sensible default only - admin can always override before submitting.
		$title = pathinfo( $relativePath, PATHINFO_FILENAME );
	}

	if( empty( $root ) ) {
		$result = [ 'error' => KernelTools::tra( 'fisheye_disk_storage_root is not configured - set it on the General Settings tab first.' ) ];
	} elseif( empty( $relativePath ) || !is_file( $root.$relativePath ) ) {
		$result = [ 'error' => KernelTools::tra( 'File not found under the configured storage root: ' ).$relativePath ];
	} else {
		// Idempotent - FisheyeFilm::registerFromDisk() re-checks whether this exact file is
		// already registered before creating a duplicate (a real gap in the earlier one-off
		// scripts, found the hard way 2026-09-02). Shared with load_film.php's bulk import.
		$result = FisheyeFilm::registerFromDisk( $relativePath, $title );
	}
}

$gBitSmarty->assign( 'storageRoot', $root );
$gBitSmarty->assign( 'result', $result );

$gBitSystem->display( 'bitpackage:fisheyemedia/admin_import_film.tpl', KernelTools::tra( 'Import Film' ), [ 'display_mode' => 'admin' ] );

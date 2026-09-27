<?php
/**
 * General settings for fisheyemedia's own external media library (storage paths + Plex lookup) -
 * moved out of fisheye's own General Settings tab (fisheye/admin/admin_fisheye_inc.php), where
 * they'd been left only because this page didn't exist yet. Same standalone-script pattern as
 * admin_import_film.php - its own verifyPermission() call, not routed through kernel/admin/
 * index.php's generic ?page= dispatcher (which would need a dedicated p_fisheyemedia_admin
 * permission registered and re-synced via the installer for an already-active package; reusing
 * fisheye's own p_fisheye_admin avoids that entirely, and is the same permission every other
 * fisheyemedia admin action already checks).
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

$formFisheyeMediaGeneral = [
	"fisheye_disk_storage_root" => [
		'label' => 'External Disk Storage Path',
		'note' => 'Filesystem path an external media library (e.g. a film collection) lives under. Used by mime plugins that register files already on disk without copying them into storage/attachments/. Include a trailing slash.',
	],
	"fisheye_tvshow_storage_root_am" => [
		'label' => 'TV Show Storage Path (A-M)',
		'note' => 'Filesystem path for TV shows whose title starts A-M, if the library is split across two roots. Leave blank if TV shows live under the single External Disk Storage Path above instead. Include a trailing slash.',
	],
	"fisheye_tvshow_storage_root_nz" => [
		'label' => 'TV Show Storage Path (N-Z)',
		'note' => 'Filesystem path for TV shows whose title starts N-Z - see the A-M path above.',
	],
	"fisheye_plex_db_path" => [
		'label' => 'Plex Library Database File',
		'note' => 'Unlike the storage paths above, this is the actual database FILE itself, not its containing folder - the full path ending in com.plexapp.plugins.library.db (e.g. /var/lib/plexmediaserver/Library/Application Support/Plex Media Server/Plug-in Support/Databases/com.plexapp.plugins.library.db). Read-only lookup, for backfilling genre/director/writer/star/rating/duration when importing a film already known to Plex. Leave blank to skip Plex metadata lookup entirely.',
	],
	"fisheye_plex_token" => [
		'label' => 'Plex API Token',
		'note' => 'From Plex\'s own Preferences.xml (PlexOnlineToken) - only needed for external ID lookups (IMDB/TMDB/TheTVDB/MusicBrainz) via Plex\'s local API, since those aren\'t stored in the database itself. Leave blank to skip external-id lookup only (genre/director/etc still works without it).',
	],
	"fisheye_musicbrainz_contact" => [
		'label' => 'MusicBrainz Contact (User-Agent)',
		'note' => 'MusicBrainz\'s API etiquette policy asks every client to identify itself with real contact info in its User-Agent string - used by FisheyeAlbum\'s Discogs-link lookup. Leave blank to send the request with no contact info (MusicBrainz may rate-limit or block an unidentified client).',
	],
];
$gBitSmarty->assign( 'formFisheyeMediaGeneral', $formFisheyeMediaGeneral );

if( !empty( $_REQUEST['fisheyemediaGeneralSubmit'] ) ) {
	foreach( $formFisheyeMediaGeneral as $item => $data ) {
		$gBitSystem->storeConfig( $item, trim( (string)( $_REQUEST[$item] ?? '' ) ), FISHEYEMEDIA_PKG_NAME );
	}
}

$gBitSystem->display( 'bitpackage:fisheyemedia/admin_fisheyemedia_settings.tpl', KernelTools::tra( 'Media Library Settings' ), [ 'display_mode' => 'admin' ] );

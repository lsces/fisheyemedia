<?php

namespace Bitweaver\Liberty;

/**
 * The plain-file handler of the mimeext* family (shared code in mime.extdoc.php): a file that is not a document or a picture (a zip of code, an archive of
 * maps) kept where it is under a named root, linked into storage/ instead of being copied. Nothing is derived from it: no text, no thumbnail; the item
 * is served and listed beside the book it belongs to, as any other item in the folder's gallery.
 *
 * Asked for by name (no mimetypes): $image->store( [ 'title' => ..., 'mimeplugin' => [ 'mimeextfile' => [ 'root' => 'documentation', 'path' => 'Computing/X/Code.zip' ] ] ] );
 *
 * @package fisheyemedia
 * @subpackage liberty_mime_handler
 */

global $gLibertySystem;

define( 'PLUGIN_MIME_GUID_EXTFILE', 'mimeextfile' );

$pluginParams = [
	'verify_function'     => 'mime_extfile_verify',
	'store_function'      => 'mime_extdoc_store',
	'update_function'     => 'mime_extdoc_update',
	'load_function'       => 'mime_extdoc_load',
	'download_function'   => 'mime_default_download',
	'expunge_function'    => 'mime_extdoc_expunge',
	'help_function'       => 'mime_extdoc_help',
	'title'               => 'Files kept in place',
	'description'         => 'Files that are neither documents nor pictures (a zip, a rar) in a named root, served through a link in storage/ instead of being copied.',
	'view_tpl'            => 'bitpackage:liberty/mime/default/view.tpl',
	'attachment_tpl'      => 'bitpackage:liberty/mime/default/attachment.tpl',
	'plugin_type'         => MIME_PLUGIN,
	'auto_activate'       => true,
	// no mimetypes: only a loader that names the plugin gets it
];
$gLibertySystem->registerPlugin( PLUGIN_MIME_GUID_EXTFILE, $pluginParams );

function mime_extfile_verify( &$pStoreRow ) {
	$pStoreRow['ext_guid'] = PLUGIN_MIME_GUID_EXTFILE;
	return mime_extdoc_verify( $pStoreRow );
}

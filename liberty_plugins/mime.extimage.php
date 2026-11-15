<?php

namespace Bitweaver\Liberty;

/**
 * The image handler of the mimeext* family (shared code in mime.extdoc.php): a picture that stays where it is under a named root, linked into
 * storage/ instead of being copied. The thumbnails are made by the same code as an uploaded picture's, from the link, and sit beside it, so a gallery
 * shows these exactly as it shows any other image; the file itself is never touched.
 *
 * Asked for by name (no mimetypes): $image->store( [ 'title' => ..., 'mimeplugin' => [ 'mimeextimage' => [ 'root' => 'dnd', 'path' => 'Maps/Keep.jpg' ] ] ] );
 * The root key is a kernel_config entry mimeext_root_<key>.
 *
 * @package fisheyemedia
 * @subpackage liberty_mime_handler
 */

global $gLibertySystem;

define( 'PLUGIN_MIME_GUID_EXTIMAGE', 'mimeextimage' );

$pluginParams = [
	'verify_function'     => 'mime_extimage_verify',
	'store_function'      => 'mime_extdoc_store',
	'update_function'     => 'mime_extdoc_update',
	'load_function'       => 'mime_extdoc_load',
	'download_function'   => 'mime_default_download',
	'expunge_function'    => 'mime_extdoc_expunge',
	'help_function'       => 'mime_extdoc_help',
	'title'               => 'Pictures kept in place',
	'description'         => 'Pictures in a named root, served through a link in storage/ instead of being copied. The thumbnails are made as for an uploaded picture.',
	'view_tpl'            => 'bitpackage:liberty/mime/image/view.tpl',
	'attachment_tpl'      => 'bitpackage:liberty/mime/image/attachment.tpl',
	'plugin_type'         => MIME_PLUGIN,
	'auto_activate'       => true,
	// no mimetypes: only a loader that names the plugin gets it
];
$gLibertySystem->registerPlugin( PLUGIN_MIME_GUID_EXTIMAGE, $pluginParams );

function mime_extimage_verify( &$pStoreRow ) {
	$pStoreRow['ext_guid'] = PLUGIN_MIME_GUID_EXTIMAGE;
	if( !mime_extdoc_verify( $pStoreRow ) ) {
		return false;
	}
	if( !preg_match( '#^image/#i', (string)$pStoreRow['mime_type'] ) ) {
		$pStoreRow['errors']['extimage'] = \Bitweaver\KernelTools::tra( 'The file is not a picture.' );
		return false;
	}
	return true;
}

/**
 * The thumbnails, made from the picture where it is; they are written beside the link. A picture over ImageMagick's size policy (8000 px or 32 megapixels:
 * the read is refused outright) is first reduced to a temporary copy, which is removed again - see FisheyePictureShrink.
 */
function mime_extimage_derive( &$pStoreRow, string $pLink ) {
	$source = $pLink;
	$type = $pStoreRow['mime_type'];
	$temp = null;
	if( \Bitweaver\Fisheyemedia\FisheyePictureShrink::tooBig( $pStoreRow['real_file'] ) ) {
		$temp = \Bitweaver\Fisheyemedia\FisheyePictureShrink::make( $pStoreRow['real_file'], dirname( $pLink ).'/shrunk-source.jpg' );
		if( $temp ) {
			$source = $temp;
			$type = 'image/jpeg';
		}
	}
	liberty_generate_thumbnails( [
		'attachment_id' => $pStoreRow['attachment_id'],
		'dest_branch'   => rtrim( str_replace( STORAGE_PKG_PATH, '', dirname( $pLink ) ), '/' ).'/',
		'source_file'   => $source,
		'type'          => $type,
	] );
	if( $temp ) {
		@unlink( $temp );
	}
}

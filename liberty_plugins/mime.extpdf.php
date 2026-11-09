<?php

namespace Bitweaver\Liberty;

/**
 * The PDF handler of the mimeext* family (shared code in mime.extdoc.php): its text from pdftotext, its thumbnails from the first page.
 *
 * @package fisheyemedia
 * @subpackage liberty_mime_handler
 */

global $gLibertySystem;

define( 'PLUGIN_MIME_GUID_EXTPDF', 'mimeextpdf' );

$pluginParams = [
	'verify_function'     => 'mime_extpdf_verify',
	'store_function'      => 'mime_extdoc_store',
	'update_function'     => 'mime_extdoc_update',
	'load_function'       => 'mime_extdoc_load',
	'download_function'   => 'mime_default_download',
	'expunge_function'    => 'mime_extdoc_expunge',
	'help_function'       => 'mime_extdoc_help',
	'title'               => 'PDFs kept in place',
	'description'         => 'PDFs in a named root such as Documentation, served through a link in storage/ instead of being copied. Text goes to liberty_content_data.',
	'view_tpl'            => 'bitpackage:liberty/mime/pdf/view.tpl',
	'plugin_type'         => MIME_PLUGIN,
	'auto_activate'       => true,
	// no mimetypes: only a loader that names the plugin gets it
];
$gLibertySystem->registerPlugin( PLUGIN_MIME_GUID_EXTPDF, $pluginParams );

function mime_extpdf_verify( &$pStoreRow ) {
	$pStoreRow['ext_guid'] = PLUGIN_MIME_GUID_EXTPDF;
	return mime_extdoc_verify( $pStoreRow );
}

/**
 * The text of a PDF, for search: a liberty_content_data row of its own, the description (liberty_content.data) only a short summary of it; 'text'
 * and 'data' on the row are also what tells LibertyMime::store() to re-index the content afterwards. Then the thumbnails, from the function a stored
 * PDF uses; it writes beside the link.
 */
function mime_extpdf_derive( &$pStoreRow, string $pLink ) {
	global $gBitSystem;
	if( !empty( $pStoreRow['this'] ) ) {
		$pdftotext = trim( $gBitSystem->getConfig( 'pdftotext_path', trim( (string)shell_exec( 'which pdftotext' ) ) ?: '/usr/bin/pdftotext' ) );
		if( is_executable( $pdftotext ) ) {
			$text = (string)shell_exec( escapeshellarg( $pdftotext ).' -enc UTF-8 '.escapeshellarg( $pStoreRow['real_file'] ).' -' );
			mime_extdoc_store_text( $pStoreRow, $text, null );
		}
	}
	if( function_exists( '\Bitweaver\Liberty\mime_pdf_thumbnail' ) ) {
		$thumbRow = [ 'attachment_id' => $pStoreRow['attachment_id'], 'source_file' => $pLink, 'log' => [] ];
		mime_pdf_thumbnail( $thumbRow );
	}
}

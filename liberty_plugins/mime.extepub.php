<?php

namespace Bitweaver\Liberty;

/**
 * The epub handler of the mimeext* family (shared code in mime.extdoc.php): the text of its XHTML files for search, the description from the
 * package file's dc:description, the cover image as the thumbnail - read with mime_epub_read() from liberty's mime.epub.php. The epub is read where
 * it is and never unpacked to disk.
 *
 * @package fisheyemedia
 * @subpackage liberty_mime_handler
 */

global $gLibertySystem;

define( 'PLUGIN_MIME_GUID_EXTEPUB', 'mimeextepub' );

$pluginParams = [
	'verify_function'     => 'mime_extepub_verify',
	'store_function'      => 'mime_extdoc_store',
	'update_function'     => 'mime_extdoc_update',
	'load_function'       => 'mime_extdoc_load',
	'download_function'   => 'mime_default_download',
	'expunge_function'    => 'mime_extdoc_expunge',
	'help_function'       => 'mime_extdoc_help',
	'title'               => 'Epubs kept in place',
	'description'         => 'Epubs in a named root such as Documentation, served through a link in storage/ instead of being copied. Text goes to liberty_content_data, the cover is the thumbnail.',
	'view_tpl'            => 'bitpackage:liberty/mime/default/view.tpl',
	'plugin_type'         => MIME_PLUGIN,
	'auto_activate'       => true,
	// no mimetypes: only a loader that names the plugin gets it
];
$gLibertySystem->registerPlugin( PLUGIN_MIME_GUID_EXTEPUB, $pluginParams );

function mime_extepub_verify( &$pStoreRow ) {
	$pStoreRow['ext_guid'] = PLUGIN_MIME_GUID_EXTEPUB;
	// the file-type check calls an epub a plain zip
	$pStoreRow['mime_type'] = 'application/epub+zip';
	return mime_extdoc_verify( $pStoreRow );
}

/**
 * Read the epub where it is (mime_epub_read(), in liberty's mime.epub.php): its text, description and cover go to the row and the thumbnails.
 * Anything the epub does not have - no cover, no description - is left out, not an error.
 */
function mime_extepub_derive( &$pStoreRow, string $pLink ) {
	if( !function_exists( '\Bitweaver\Liberty\mime_epub_read' ) ) {
		return;
	}
	$book = mime_epub_read( $pStoreRow['real_file'] );
	mime_extdoc_store_text( $pStoreRow, $book['text'], $book['summary'] );
	if( $book['cover'] !== null ) {
		mime_epub_thumbnails( $book['cover'], dirname( $pLink ).'/', (int)$pStoreRow['attachment_id'] );
	}
}

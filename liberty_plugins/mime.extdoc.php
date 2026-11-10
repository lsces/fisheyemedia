<?php

namespace Bitweaver\Liberty;

use Bitweaver\BitBase;
use Bitweaver\KernelTools;

/**
 * Shared code of the mimeext* mime handlers - a document that stays where it is: a file under a named root such as Documentation (kernel_config
 * mimeext_root_<key>) becomes an ordinary attachment without being copied into storage/. This file registers no plugin; mime.extpdf.php and
 * mime.extepub.php (one file per file type) each register theirs, name the plugin for this code in their verify wrapper (ext_guid) and supply
 * mime_<type>_derive(), the one step that differs: the text for search and the thumbnails.
 *
 * The attachment folder under storage/ holds a symlink to the real file, so everything that finds a file through the attachment
 * (getSourceFile, thumbnails, download_file.php, the X-Accel-Redirect path) works unchanged, and the generated thumbnails sit
 * beside the link. The path under the root is kept once, in the attachment preference extdoc_path, and the link is made again from
 * it when it is missing (a restored database, a machine whose storage/ was never copied). Expunge removes the link and the
 * thumbnails and never the file the link points at.
 *
 * The plugins have no mimetypes, so lookupMimeHandler() never picks them. A loader asks for one by name:
 *   $image->store( [ 'title' => ..., 'mimeplugin' => [ 'mimeextpdf' => [ 'root' => 'documentation', 'path' => 'Architecture/Some Book.pdf' ] ] ] );
 * The text goes into liberty_content_data (data_type 'text'); liberty_content.data stays for the description.
 *
 * @package fisheyemedia
 * @subpackage liberty_mime_handler
 */

/**
 * The real file for "<root key>|<path under that root>" (a bare path means the Documentation root), or null. The root comes from the
 * kernel_config entry mimeext_root_<key> (the Documentation Storage Path setting for 'documentation', which falls back to Documentation/ under the External Disk Storage Path); it must exist,
 * '..' is refused, and the resolved file has to lie under the root (a link pointing out of it is refused too).
 */
function mime_extdoc_resolve( string $pSpec ): ?string {
	global $gBitSystem;
	[ $key, $rel ] = str_contains( $pSpec, '|' ) ? explode( '|', $pSpec, 2 ) : [ 'documentation', $pSpec ];
	if( !preg_match( '/^[a-z]+$/', $key ) ) {
		return null;
	}
	// the Documentation root falls back to the folder under the External Disk Storage Path (FisheyeDocsWorkbench::root()); another key has only its setting
	$root = $key === 'documentation' ? realpath( \Bitweaver\Fisheyemedia\FisheyeDocsWorkbench::root() ?: '/nonexistent' ) : realpath( (string)$gBitSystem->getConfig( 'mimeext_root_'.$key, '' ) );
	if( !$root || $rel === '' || str_contains( $rel, '..' ) ) {
		return null;
	}
	$real = realpath( $root.'/'.ltrim( $rel, '/' ) );
	return $real && is_file( $real ) && str_starts_with( $real, $root.'/' ) ? $real : null;
}

/**
 * Where the link lives: the folder an ordinary upload's file is in, attachments/<id mod 1000>/<id>/ (the same call the upload path uses).
 * LibertyMime::getSourceFile() also tries one level deeper, FisheyeImage::getStoragePath(), but only uses it when that folder exists - so it
 * must never be made, or the file is looked for there and the gallery's thumbnail lookup (the folder above) misses it. Absolute, with a
 * trailing slash; the folder is made.
 */
function mime_extdoc_dir( int $pAttachmentId ): string {
	return STORAGE_PKG_PATH.liberty_mime_get_storage_branch( [ 'attachment_id' => $pAttachmentId ] );
}

function mime_extdoc_verify( &$pStoreRow ) {
	global $gBitSystem, $gBitUser;
	$pStoreRow['spec'] = ( $pStoreRow['root'] ?? 'documentation' ).'|'.( $pStoreRow['path'] ?? '' );
	$real = mime_extdoc_resolve( $pStoreRow['spec'] );
	if( !$real ) {
		$pStoreRow['errors']['extdoc'] = KernelTools::tra( 'The file was not found under the Documentation root.' );
		return false;
	}
	$pStoreRow['user_id'] = BitBase::verifyId( $gBitUser->mUserId ) ? $gBitUser->mUserId : ROOT_USER_ID;
	// attachment_id: the content's own id, as protector's store has it for an attached file (a number no other attachment has, and the folder
	// attachments/<id mod 1000>/<id>/ is where an ordinary file of that content sits); the next number of the content sequence if taken
	if( empty( $pStoreRow['attachment_id'] ) ) {
		$pStoreRow['attachment_id'] = (int)$pStoreRow['content_id'];
		for( $tries = 0; $gBitSystem->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_attachments` WHERE `attachment_id` = ?", [ $pStoreRow['attachment_id'] ] ) && $tries < 100; $tries++ ) {
			$pStoreRow['attachment_id'] = (int)$gBitSystem->mDb->GenID( 'liberty_content_id_seq' );
		}
	}
	$pStoreRow['real_file'] = $real;
	$pStoreRow['file_name'] = basename( $real );
	// a type's own verify wrapper may have set the mime type (an epub is a zip to the file-type check)
	$pStoreRow['mime_type'] = $pStoreRow['mime_type'] ?? $gBitSystem->verifyMimeType( $real );
	return true;
}

function mime_extdoc_store( &$pStoreRow ) {
	global $gBitSystem;
	$pStoreRow['attachment_plugin_guid'] = $pStoreRow['ext_guid'];
	$name = $pStoreRow['file_name'];
	$link = mime_extdoc_dir( (int)$pStoreRow['attachment_id'] ).$name;
	if( is_link( $link ) ) {
		unlink( $link );
	}
	if( !symlink( $pStoreRow['real_file'], $link ) ) {
		$pStoreRow['errors']['extdoc'] = KernelTools::tra( 'The link to the file could not be made.' );
		return false;
	}

	// liberty_files.file_id: the attachment id, as protector's store has it (the sites that have protector hold file_id = attachment_id, and a
	// later upload takes its own attachment id), unless that is already a file's; then the next free number of the files sequence
	$fileId = (int)$pStoreRow['attachment_id'];
	for( $tries = 0; $gBitSystem->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_files` WHERE `file_id` = ?", [ $fileId ] ) && $tries < 100; $tries++ ) {
		$fileId = (int)$gBitSystem->mDb->GenID( 'liberty_files_id_seq' );
	}
	$gBitSystem->mDb->associateInsert( BIT_DB_PREFIX.'liberty_files', [
		'file_id' => $fileId, 'file_name' => $name, 'mime_type' => $pStoreRow['mime_type'],
		'file_size' => (int)filesize( $pStoreRow['real_file'] ), 'user_id' => $pStoreRow['user_id'],
	] );
	$gBitSystem->mDb->associateInsert( BIT_DB_PREFIX.'liberty_attachments', [
		'attachment_plugin_guid' => $pStoreRow['ext_guid'], 'attachment_id' => $pStoreRow['attachment_id'],
		'content_id' => $pStoreRow['content_id'], 'foreign_id' => $fileId, 'user_id' => $pStoreRow['user_id'],
	] );
	$gBitSystem->mDb->associateInsert( BIT_DB_PREFIX.'liberty_attachment_prefs', [
		'attachment_id' => $pStoreRow['attachment_id'], 'pref_name' => 'extdoc_path', 'pref_value' => $pStoreRow['spec'],
	] );

	// what each type derives from the file - the text for search, the thumbnails - is its own function, mime_<ext type>_derive()
	// (mime_extpdf_derive, mime_extepub_derive), called with the row and the link; it writes beside the link
	$derive = '\\Bitweaver\\Liberty\\mime_'.substr( $pStoreRow['ext_guid'], 4 ).'_derive';
	if( function_exists( $derive ) ) {
		$derive( $pStoreRow, $link );
	}
	return true;
}

/**
 * Store a document's text: the whole of it in liberty_content_data, a short summary in the description when that is still empty ($pSummary null
 * = a summary of the text), and the keys LibertyMime::store() looks at to re-index the content afterwards.
 */
function mime_extdoc_store_text( array &$pStoreRow, string $pText, ?string $pSummary ): void {
	global $gBitSystem;
	if( trim( $pText ) === '' || empty( $pStoreRow['this'] ) ) {
		return;
	}
	$pStoreRow['text'] = $pText;
	$pStoreRow['this']->storeData( $pText, 'text' );
	$summary = $pSummary ?? ( function_exists( '\\Bitweaver\\Liberty\\mime_pdf_summary' ) ? mime_pdf_summary( $pText ) : '' );
	if( $summary !== '' ) {
		$pStoreRow['data'] = $summary;
		$gBitSystem->mDb->query( "UPDATE `".BIT_DB_PREFIX."liberty_content` SET `data` = ? WHERE `content_id` = ? AND (`data` IS NULL OR `data` = '')", [ $summary, $pStoreRow['content_id'] ] );
	}
}

function mime_extdoc_update( &$pStoreRow ) {
	// nothing is uploaded for this kind of file; a changed path is a new attachment
	return true;
}

function mime_extdoc_load( &$pFileHash, &$pPrefs, $pParams = null ) {
	global $gBitSystem;
	static $busy = false;
	$ret = mime_default_load( $pFileHash, $pPrefs );
	if( $busy ) {
		// the owner object built below loads its attachments through this function again
		return $ret;
	}
	// mime_default_load() looks for the file where a plain upload would be; an owner such as FisheyeImage finds it elsewhere, so the
	// link is checked at the owner's place (and made again there when missing)
	$busy = true;
	try {
		$owner = !empty( $ret['content_id'] ) ? LibertyBase::getLibertyObject( $ret['content_id'] ) : null;
	} finally {
		$busy = false;
	}
	if( $ret && $owner && !empty( $ret['attachment_id'] ) && !is_file( $owner->getSourceFile( $ret ) ?? '' ) ) {
		// the link is missing or dangling: make it again from the stored path
		$path = $gBitSystem->mDb->getOne( "SELECT `pref_value` FROM `".BIT_DB_PREFIX."liberty_attachment_prefs` WHERE `attachment_id` = ? AND `pref_name` = 'extdoc_path'", [ $ret['attachment_id'] ] );
		$real = $path ? mime_extdoc_resolve( $path ) : null;
		if( $real ) {
			$link = mime_extdoc_dir( (int)$ret['attachment_id'] ).$ret['file_name'];
			if( is_link( $link ) ) {
				unlink( $link );
			}
			@symlink( $real, $link );
		}
	}
	return $ret;
}

/**
 * Removes the rows, the link and the generated thumbnails. The file under the Documentation root is never touched: the link goes first
 * (unlink on a link removes the link only), then the folder is cleared.
 */
function mime_extdoc_expunge( $pAttachmentId ) {
	global $gBitSystem, $gBitUser;
	$ret = false;
	if( BitBase::verifyId( $pAttachmentId ) && ( $fileHash = LibertyMime::loadAttachment( $pAttachmentId ) ) ) {
		if( $gBitUser->isAdmin() || $gBitUser->mUserId == $fileHash['user_id'] ) {
			$dir = mime_extdoc_dir( (int)$pAttachmentId );
			if( $dir === '' ) {
				return false;
			}
			if( is_link( $dir.$fileHash['file_name'] ) ) {
				unlink( $dir.$fileHash['file_name'] );
			}
			// only a folder that now holds no link and lies inside storage/attachments is cleared
			if( is_dir( $dir ) && !array_filter( glob( $dir.'*' ) ?: [], 'is_link' ) && str_starts_with( realpath( $dir ), realpath( STORAGE_PKG_PATH ).'/' ) ) {
				KernelTools::unlink_r( $dir );
			}
			$gBitSystem->mDb->query( "DELETE FROM `".BIT_DB_PREFIX."liberty_attachment_prefs` WHERE `attachment_id` = ? AND `pref_name` = 'extdoc_path'", [ $pAttachmentId ] );
			$gBitSystem->mDb->query( "DELETE FROM `".BIT_DB_PREFIX."liberty_files` WHERE `file_id` = ?", [ $fileHash['foreign_id'] ] );
			$ret = true;
		}
	}
	return $ret;
}

function mime_extdoc_help() {
	return '';
}

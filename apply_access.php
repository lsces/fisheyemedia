<?php
/**
 * Apply a gallery's access to everything inside it. Protector keeps access per content item, so setting a role on a gallery (its Edit page) hides only
 * the gallery's own page; the galleries and items inside stay open, and so do their pages and the search results. This lists the top-level galleries
 * with the roles they are restricted to and how many of the things inside have different roles, and gives each the gallery's roles on request
 * (see FisheyeAccess). Nothing is written until a button is pressed, and the page can be repeated.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPackage( 'fisheyemedia' );
$gBitSystem->verifyPermission( 'p_fisheye_admin' );

$prefix = BIT_DB_PREFIX;
$roleNames = $gBitDb->getAssoc( "SELECT `role_id`, `role_name` FROM `{$prefix}users_roles`" ) ?: [];
$roleNames[-1] = 'Anonymous';

// the top-level galleries: those that are not inside another gallery
$galleries = $gBitDb->getAll(
	"SELECT g.`gallery_id`, g.`content_id`, lc.`title` FROM `{$prefix}fisheye_gallery` g
	 JOIN `{$prefix}liberty_content` lc ON lc.`content_id` = g.`content_id`
	 WHERE NOT EXISTS ( SELECT 1 FROM `{$prefix}fisheye_gallery_image_map` m WHERE m.`item_content_id` = g.`content_id` )
	 ORDER BY lc.`title`" ) ?: [];
$allowed = array_map( 'intval', array_column( $galleries, 'content_id' ) );

$messages = [];
$errors = [];
if( !empty( $_REQUEST['apply_access'] ) && is_array( $_REQUEST['apply_access'] ) ) {
	foreach( array_keys( $_REQUEST['apply_access'] ) as $contentId ) {
		$contentId = (int)$contentId;
		if( !in_array( $contentId, $allowed, true ) ) {
			continue;
		}
		$done = FisheyeAccess::applyInside( $contentId );
		$title = $gBitDb->getOne( "SELECT `title` FROM `{$prefix}liberty_content` WHERE `content_id` = ?", [ $contentId ] );
		$messages[] = $title.': '.$done['changed'].' '.KernelTools::tra( 'items and galleries now have the gallery\'s access.' );
		$errors = array_merge( $errors, $done['errors'] );
	}
}

$rows = [];
foreach( $galleries as $g ) {
	$cid = (int)$g['content_id'];
	$roles = FisheyeAccess::rolesOf( $cid );
	$rows[] = [
		'contentId' => $cid,
		'title'     => (string)$g['title'],
		'roles'     => $roles ? implode( ', ', array_map( fn( $r ) => $roleNames[$r] ?? (string)$r, $roles ) ) : '',
		'inside'    => count( FisheyeAccess::contentsInside( $cid ) ),
		'differ'    => count( FisheyeAccess::differing( $cid ) ),
	];
}

$gBitSmarty->assign( 'rows', $rows );
$gBitSmarty->assign( 'messages', $messages );
$gBitSmarty->assign( 'errors', $errors );
$gBitSystem->display( 'bitpackage:fisheyemedia/apply_access.tpl', KernelTools::tra( 'Gallery access' ), [ 'display_mode' => 'admin' ] );

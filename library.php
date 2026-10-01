<?php
/**
 * Menu jump to one of the media library's top-level galleries (Films, Music, TV Shows, Library) -
 * linked from fisheye_menu_inc.tpl. Resolved by title here rather than in the menu, since gallery
 * ids differ per site; only a top-level gallery matches (one not itself held in another), so an
 * album or folder that happens to share the title is never picked.
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeGallery;
use Bitweaver\KernelTools;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitDb;

$gBitSystem->verifyPackage( 'fisheyemedia' );
$gBitSystem->verifyPermission( 'p_fisheye_view' );

$root = trim( (string)( $_REQUEST['root'] ?? '' ) );
$galleryId = $root === '' ? null : $gBitDb->getOne(
	"SELECT fg.gallery_id FROM `".BIT_DB_PREFIX."fisheye_gallery` fg
	 INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.content_id = fg.content_id
	 WHERE lc.title = ? AND NOT EXISTS (
		SELECT 1 FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m WHERE m.item_content_id = lc.content_id )",
	[ $root ]
);

if( !$galleryId ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No top-level gallery called' ).' "'.htmlspecialchars( $root ).'"', null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

$urlHash = [ 'gallery_id' => $galleryId ];
KernelTools::bit_redirect( FisheyeGallery::getDisplayUrlFromHash( $urlHash ) );

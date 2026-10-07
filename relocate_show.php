<?php
/**
 * Follow a TV show's folder after it has been renamed on disk - see FisheyeProgram::relocateFolder(). Shows what would change first
 * (episode rows, how many of the new files exist, the titles), and only an explicit Apply rewrites anything. The old folder is read off
 * the show's own episode rows; the new one is suggested from the TV storage root (a folder starting with the old name plus " (year)").
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';
require_once dirname( __DIR__ ).'/liberty/plugins/mime.film.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPackage( 'fisheye' );
$gBitSystem->verifyPermission( 'p_fisheye_admin' );

$gContent = FisheyeGallery::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() || !( $gContent instanceof FisheyeProgram ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No show exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

// The folder the show's rows currently use: the commonest first folder under "TV Shows/" among its episode/featurette rows.
$oldFolder = trim( (string)( $_REQUEST['old_folder'] ?? '' ) );
if( $oldFolder === '' ) {
	$ids = array_merge( [ (int)$gContent->mContentId ], FisheyeCredits::seasonIdsForProgram( (int)$gContent->mContentId ) );
	$counts = [];
	foreach( $gBitDb->getCol(
		"SELECT x.`xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` x WHERE x.`content_id` IN ( ".implode( ',', array_fill( 0, count( $ids ), '?' ) )." )
		 AND x.`item` IN ( 'episode', 'featurette' ) AND x.`end_date` IS NULL AND x.`xkey_ext` LIKE 'TV Shows/%'", $ids ) ?: [] as $path ) {
		$folder = explode( '/', $path )[1] ?? '';
		$counts[$folder] = ( $counts[$folder] ?? 0 ) + 1;
	}
	arsort( $counts );
	$oldFolder = (string)array_key_first( $counts );
}

// Suggest the new folder: one on disk that starts with the old name and a " (" - e.g. "Doctor Who (1963)" for "Doctor Who".
$newFolder = trim( (string)( $_REQUEST['new_folder'] ?? '' ) );
$suggestions = [];
if( $oldFolder !== '' ) {
	$root = \Bitweaver\Liberty\mime_film_get_tvshow_storage_root( $oldFolder );
	foreach( is_dir( $root.'TV Shows' ) ? scandir( $root.'TV Shows' ) : [] as $entry ) {
		if( $entry !== $oldFolder && str_starts_with( $entry, $oldFolder.' (' ) && is_dir( $root.'TV Shows/'.$entry ) ) {
			$suggestions[] = $entry;
		}
	}
	if( $newFolder === '' && count( $suggestions ) >= 1 ) {
		// Prefer a suggestion no other show already uses as its title.
		foreach( $suggestions as $candidate ) {
			if( !$gBitDb->getOne( "SELECT `content_id` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_type_guid` = 'fisheyeprogram' AND `title` = ? AND `content_id` <> ?", [ $candidate, (int)$gContent->mContentId ] ) ) {
				$newFolder = $candidate;
				break;
			}
		}
	}
}

$plan = $newFolder !== '' ? $gContent->relocateFolder( $oldFolder, $newFolder, !empty( $_REQUEST['fApply'] ) ) : null;

$gBitSmarty->assign( 'gContent', $gContent );
$gBitSmarty->assign( 'oldFolder', $oldFolder );
$gBitSmarty->assign( 'newFolder', $newFolder );
$gBitSmarty->assign( 'suggestions', $suggestions );
$gBitSmarty->assign( 'plan', $plan );
$gBitSmarty->assign( 'applied', !empty( $_REQUEST['fApply'] ) && $plan && $plan['ok'] );
$gBitSystem->display( 'bitpackage:fisheyemedia/relocate_show.tpl', KernelTools::tra( 'Relocate a show\'s folder' ).': '.$gContent->getTitle(), [ 'display_mode' => 'edit' ] );

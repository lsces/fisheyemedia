<?php
/**
 * Available information for one Book: what the sources hold (the book as stored, its own file, Open Library, Google Books) side by side, a tick for the value to
 * keep in each row, and nothing written until Apply. See FisheyeBookSources. Reached from the Book's Look up details page. The sources are asked every time the page opens; the form carries what was found, so Apply writes what was shown. Apply goes back to the Book's edit page. Wikidata is a column only for a book that has its Wikidata item, which is found by hand on the Look up details page.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeImage;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'fisheye' );

$gContent = FisheyeImage::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() || !$gContent instanceof FisheyeBook ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No book exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->verifyUpdatePermission();

$result = null;
$applied = null;
if( !empty( $_REQUEST['apply'] ) ) {
	$gContent->verifyUpdatePermission();
	$pick = [ 'fields' => (array)( $_REQUEST['pick_field'] ?? [] ) ];
	foreach( [ 'description', 'genres', 'ids', 'cover' ] as $key ) {
		$pick[$key] = (string)( $_REQUEST['pick_'.$key] ?? '' );
	}
	$applied = FisheyeBookSources::apply( $gContent, $pick, (string)htmlspecialchars_decode( (string)( $_REQUEST['payload'] ?? '' ), ENT_QUOTES ), array_map( fn( $u ) => htmlspecialchars_decode( (string)$u, ENT_QUOTES ), (array)( $_REQUEST['cover_uri'] ?? [] ) ) );
	if( empty( $applied['error'] ) ) {
		KernelTools::bit_redirect( $gContent->getEditUrl() );   // saved: back to the edit page
	}
}
$result = FisheyeBookSources::gather( $gContent );   // the sources are asked each time the page opens

if( $result ) {
	// what is ticked to start with: what the book has; where it has nothing, the first source that does
	$default = function( array $pValues, array $pOrder ): string {
		foreach( $pOrder as $source ) {
			if( isset( $pValues[$source] ) ) {
				return $source;
			}
		}
		return 'now';
	};
	foreach( $result['fields'] as $item => &$row ) {
		$row['default'] = $default( $row['values'], [ 'now', 'wd', 'ol' ] );
	}
	unset( $row );
	$result['descriptionDefault'] = 'now';   // a description is taken only when asked for: what a source holds is often little use
	$result['genresDefault'] = $default( $result['genres'], [ 'now', 'ol' ] );
	$result['idsDefault'] = $default( $result['ids'], [ 'now', 'ol' ] );
	$result['coverDefault'] = $default( $result['covers'], [ 'now', 'file', 'wd', 'ol', 'google' ] );
}

$gBitSmarty->assign( 'gContent', $gContent );
$gBitSmarty->assign( 'result', $result );
$gBitSmarty->assign( 'columns', $result && $result['wdFound'] ? [ 'now', 'wd', 'ol' ] : [ 'now', 'ol' ] );
$gBitSmarty->assign( 'applied', $applied );
$gBitSmarty->assign( 'sourceLabels', FisheyeBookSources::SOURCES );
$gBitSystem->setBrowserTitle( $gContent->getTitle() );
$gBitSystem->display( 'bitpackage:fisheyemedia/available_info.tpl', null, [ 'display_mode' => 'edit' ] );

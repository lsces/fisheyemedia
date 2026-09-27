<?php
/**
 * Dedicated view page for film content (mime.film.php-backed FisheyeImage rows) - deliberately
 * separate from view_image.php rather than folding film-specific rendering into the generic
 * photo view.
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeImage;

require_once '../kernel/includes/setup_inc.php';
global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'fisheye' );

// FisheyeImage::lookup() with a bare content_id resolves whatever content type actually owns
// that content_id, not necessarily a film - isValid() alone only confirms it loaded as ITS OWN
// (possibly unrelated) type. See view_program.php's own identical fix for why this matters.
$gContent = FisheyeImage::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() || !( $gContent instanceof FisheyeFilm ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No film exists with the given ID' ), 'error.tpl' );
}
$gContent->verifyViewPermission();
$gContent->addHit();

// One shaped array instead of a dozen individual assign() calls - same consolidation
// FisheyeSeason::getSeasonViewData() already does for view_season.php/view_program.php.
$filmData = $gContent->getFilmViewData();
foreach( $filmData as $key => $value ) {
	$gBitSmarty->assign( $key === 'firstTab' ? 'firstFilmTab' : $key, $value );
}

$gBitSmarty->assign( 'gContent', $gContent );

$gBitSystem->setCanonicalLink( $gContent->getDisplayUrl() );
$gBitSystem->setBrowserTitle( $gContent->getTitle() );
$gBitSystem->display( 'bitpackage:fisheyemedia/view_film.tpl', null, [ 'display_mode' => 'display' ] );

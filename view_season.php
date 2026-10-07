<?php
/**
 * Dedicated view page for TV season content (FisheyeSeason) - the matching pair to
 * edit_season.php, alongside view_program.php/edit_program.php.
 *
 * No season-level facts panel - Plex itself has none, it's the TV that toggles to display a
 * selected episode's metadata as you select each. Instead this page's main content is the episode list; each
 * episode's own JSON packet (FisheyeSeason::reloadPlexEpisodes(), stored in its xref row's `data`
 * column - title/summary/air_date/director/writer/star/content_rating/duration) is decoded here
 * and rendered into the template already, so selecting an episode client-side just toggles which
 * already-rendered detail block is visible - no per-episode request, mirroring Plex's own
 * highlight-swaps-the-panel interaction.
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;
use Bitweaver\Fisheye\FisheyeImage;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';
global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'fisheye' );

// FisheyeImage::lookup() with a bare content_id resolves whatever content type actually owns
// that content_id, not necessarily a season - isValid() alone only confirms it loaded as ITS OWN
// (possibly unrelated) type. See view_program.php's own identical fix for why this matters.
$gContent = FisheyeImage::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() || !( $gContent instanceof FisheyeSeason ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No season exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->verifyViewPermission();
$gContent->addHit();

$gContent->loadXrefInfo();
$externalLinks = [];
if( $gContent->mXrefInfo ) {
	foreach( $gContent->liveXrefs() as $xref ) {
		if( !empty( $xref['cross_ref_href'] ) && !empty( $xref['xkey'] )) {
			$externalLinks[] = [
				'title' => $xref['xref_title'] ?? strtoupper( $xref['item'] ),
				'url'   => $xref['cross_ref_href'].$xref['xkey'],
			];
		}
	}
}
$gBitSmarty->assign( 'externalLinks', $externalLinks );

$viewData = $gContent->getSeasonViewData();
$gBitSmarty->assign( 'seasonImages', $viewData['images'] );
$gBitSmarty->assign( 'episodes', $viewData['episodes'] );
$gBitSmarty->assign( 'creditUrls', $viewData['creditUrls'] );
$gBitSmarty->assign( 'seasonFeaturettes', $viewData['featurettes'] );
$gBitSmarty->assign( 'firstContentTab', $viewData['firstTab'] );

// parent show, for the "back up a level" link - lookup() (not `new FisheyeGallery()`) so this
// resolves to a real FisheyeProgram instance when the parent is a show, not a plain FisheyeGallery -
// needed for its own getDisplayUrl() override to fire - the generic shared breadcrumb's own
// hardcoded 'gallery/<id>' pretty-url route bypasses that override entirely, landing on the plain
// gallery view instead of view_program.php; `new FisheyeGallery()` here would have silently
// reproduced the same bug even after dropping that shared breadcrumb.
$gGallery = null;
if( !empty( $_REQUEST['gallery_id'] ) && is_numeric( $_REQUEST['gallery_id'] )) {
	$gGallery = FisheyeGallery::lookup( $_REQUEST );
} elseif( $parents = $gContent->getParentGalleries() ) {
	$gal = current( $parents );
	$gGallery = FisheyeGallery::lookup( [ 'gallery_id' => $gal['gallery_id'] ] );
}
$gBitSmarty->assign( 'gGallery', $gGallery );

// this season's title is conventionally "<show title> - <season name>" (e.g. "Example Show -
// Series 1") - split off just the show-name portion to link, leaving the season-specific
// remainder as plain text (the link belongs on just the show name, not the whole title).
// Falls back to the full title with no split if it doesn't actually start with the show's own
// title (defensive - naming isn't enforced anywhere, just a convention).
$seasonTitleSuffix = '';
if( $gGallery && str_starts_with( $gContent->getTitle(), $gGallery->getTitle() ) ) {
	$seasonTitleSuffix = substr( $gContent->getTitle(), strlen( $gGallery->getTitle() ) );
}
$gBitSmarty->assign( 'seasonTitleSuffix', $seasonTitleSuffix );
$gBitSmarty->assign( 'gContent', $gContent );

$gBitSystem->setCanonicalLink( $gContent->getDisplayUrl() );
$gBitSystem->setBrowserTitle( $gContent->getTitle() );
$gBitSystem->display( 'bitpackage:fisheyemedia/view_season.tpl', null, [ 'display_mode' => 'display' ] );

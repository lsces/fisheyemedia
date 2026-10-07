<?php
/**
 * Dedicated view page for a TV show ("program" - FisheyeProgram, extends FisheyeGallery) -
 * show-level facts (genre/cast/rating/external links, same liveXrefs() pattern view_film.php
 * uses) plus a grid of this show's own season members. Deliberately separate from the generic
 * gallery view.php, same reasoning as view_film.php being separate from view_image.php.
 *
 * Named view_program.php (not its original list_program.php) to match the view_X.php convention
 * every other per-item content type uses (view_film.php, view_image.php) - the old name made a
 * TV Show gallery's member links look like they routed to a listing page rather than a single
 * show's own detail view, unlike the Films gallery's view_film.php links.
 *
 * Pure display, no update actions. The Plex
 * 'Reload Metadata'/'Reload Images' actions live on edit_program.php instead, same split as
 * edit_film.php/view_film.php.
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';
global $gBitSystem, $gBitSmarty, $gLibertySystem, $gBitUser;

$gBitSystem->verifyPackage( 'fisheye' );

// FisheyeGallery::lookup() with a bare content_id (no gallery_id) resolves whatever content
// type actually owns that content_id, gallery-family or not - a content_id belonging to some
// unrelated package (found live crashing on a Food record) loads validly as ITS OWN type, so
// isValid() alone doesn't catch it. The instanceof check below is what actually confirms this
// page got a show, not just any successfully-loaded content.
$gContent = FisheyeGallery::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() || !( $gContent instanceof FisheyeProgram ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No show exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->verifyViewPermission();
$gContent->addHit();

// Tools other packages offer on a show's page (the 'program_tools' service - e.g. contactwiki's people loader). A
// tool that asks for 'credit_status' gets a badge from this show's own credit rows: a tick when every credit is
// linked to a contact, otherwise how many are not yet; nothing before the show's credits have been built.
$programTools = [];
foreach( $gLibertySystem->getServiceValues( 'program_tools' ) ?? [] as $serviceTools ) {
	foreach( $serviceTools as $tool ) {
		if( empty( $tool['url'] ) || ( !empty( $tool['perm'] ) && !$gBitUser->hasPermission( $tool['perm'] ) ) ) {
			continue;
		}
		$entry = [ 'title' => KernelTools::tra( $tool['title'] ?? '' ), 'icon' => $tool['icon'] ?? 'view-list',
			'url' => $tool['url'].$gContent->mContentId, 'badge' => null, 'badgeTitle' => null ];
		if( !empty( $tool['credit_status'] ) ) {
			$status = FisheyeCredits::programOverview( (int)$gContent->mContentId )[0] ?? null;
			if( $status && $status['credits'] > 0 ) {
				$linked = $status['credits'] - $status['unlinked'];
				$entry['badge'] = $status['unlinked'] === 0 ? "\u{2713}" : (string)$status['unlinked'];
				$entry['badgeTitle'] = $status['unlinked'] === 0
					? KernelTools::tra( 'All credits linked to contacts' )
					: $linked.' / '.$status['credits'].' '.KernelTools::tra( 'credits linked to contacts' );
			}
		}
		$programTools[] = $entry;
	}
}
$gBitSmarty->assign( 'programTools', $programTools );

// bucket this show's own xref data - same flat liveXrefs() pass as view_film.php, no group names
// hardcoded (see that page's own comment for why that matters).
$gContent->loadXrefInfo();
$genres = $directors = $writers = $stars = [];
$contentRating = $durationMs = null;
$externalLinks = [];
if( $gContent->mXrefInfo ) {
	foreach( $gContent->liveXrefs() as $xref ) {
		switch( $xref['item'] ) {
			case 'genre':          $genres[]     = $xref['xkey_ext']; break;
			case 'director':       $directors[]  = $xref['xkey_ext']; break;
			case 'writer':         $writers[]    = $xref['xkey_ext']; break;
			case 'star':           $stars[]      = $xref['xkey_ext']; break;
			case 'content_rating': $contentRating = $xref['xkey_ext']; break;
			case 'duration':       $durationMs    = (int)$xref['xkey_ext']; break;
		}
		if( !empty( $xref['cross_ref_href'] ) && !empty( $xref['xkey'] )) {
			$externalLinks[] = [
				'title' => $xref['xref_title'] ?? strtoupper( $xref['item'] ),
				'url'   => $xref['cross_ref_href'].$xref['xkey'],
			];
		}
	}
}
$gBitSmarty->assign( 'genres', $genres );
$gBitSmarty->assign( 'directors', $directors );
$gBitSmarty->assign( 'writers', $writers );
$gBitSmarty->assign( 'stars', $stars );
// The show-level names linked to their contacts (a single-season show adds its episodes' names below).
$creditUrls = FisheyeCredits::urlsForNames( array_merge( $directors, $writers, $stars ) );
$gBitSmarty->assign( 'creditUrls', $creditUrls );
// The whole show's credits rolled up from its seasons (empty until the seasons' credit directories exist - then the Plex lines are used).
$gBitSmarty->assign( 'rollup', FisheyeCredits::programRollup( (int)$gContent->mContentId ) );
$gBitSmarty->assign( 'creators', FisheyeCredits::programCreators( (int)$gContent->mContentId ) );
$gBitSmarty->assign( 'contentRating', $contentRating );
$gBitSmarty->assign( 'durationMs', $durationMs );
$gBitSmarty->assign( 'externalLinks', $externalLinks );

// this show's own season members - real gallery membership, unchanged from plain FisheyeGallery.
$listHash = [ 'max_records' => -1 ];
$gContent->loadImages( $listHash );

// season card titles drop the "<show> - " prefix, leaving just "Season 1" etc - same split as
// view_season.php's own title-link suffix, keyed by content_id since this is a whole grid of
// seasons rather than a single one. Falls back
// to the season's own full title if it doesn't actually start with the show's title.
$showTitle = $gContent->getTitle();
$seasonTitles = [];
foreach( (array)$gContent->mItems as $season ) {
	$seasonTitle = $season->mInfo['title'] ?? '';
	$seasonTitles[$season->mContentId] = str_starts_with( $seasonTitle, $showTitle )
		? ltrim( substr( $seasonTitle, strlen( $showTitle ) ), ' -' )
		: $seasonTitle;
}
$gBitSmarty->assign( 'seasonTitles', $seasonTitles );

// Single-season shows skip the dummy "Season 1" click-through - view_program_single_season.tpl
// merges the show's own facts (above) with that one season's episode grid/detail panel directly,
// same data shape view_season.php itself loads. Real FisheyeSeason object still underneath, just
// not a separate page - a different tpl for the single-season state rather than special-casing
// the multi-season template.
$template = 'bitpackage:fisheyemedia/view_program.tpl';
if( count( (array)$gContent->mItems ) === 1 ) {
	$season = current( $gContent->mItems );
	$viewData = $season->getSeasonViewData();
	$gBitSmarty->assign( 'seasonImages', $viewData['images'] );
	$gBitSmarty->assign( 'episodes', $viewData['episodes'] );
	$gBitSmarty->assign( 'creditUrls', $creditUrls + $viewData['creditUrls'] );
	$gBitSmarty->assign( 'seasonFeaturettes', $viewData['featurettes'] );
	$gBitSmarty->assign( 'firstContentTab', $viewData['firstTab'] );
	// The show's own $gContent is what this template otherwise renders against - the tab edit
	// actions (Reload Episodes/Featurettes, Add Image) need the *season's* content_id instead,
	// since that's what actually owns these xrefs.
	$gBitSmarty->assign( 'seasonContentId', $season->mContentId );
	$template = 'bitpackage:fisheyemedia/view_program_single_season.tpl';
}

$gBitSmarty->assign( 'gContent', $gContent );

$gBitSystem->setCanonicalLink( $gContent->getDisplayUrl() );
$gBitSystem->setBrowserTitle( $gContent->getTitle() );
$gBitSystem->display( $template, null, [ 'display_mode' => 'display' ] );

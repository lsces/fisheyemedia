<?php
/**
 * Dedicated view page for a music album (FisheyeAlbum) - same shape as view_film.php, bucketing
 * this album's own xref data into template-friendly arrays rather than hardcoding which x_group
 * an item lives under (see that file's own docblock for why that matters).
 *
 * 'track' xrefs (raw file paths, not real LibertyMime attachments - same reasoning as an
 * episode's own xref, see FisheyeAlbum.php's docblock) are the track listing; each one plays via
 * play_track.php (xref_id only, mirrors play_episode.php).
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

$gContent = FisheyeImage::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No album exists with the given ID' ), 'error.tpl' );
}
$gContent->verifyViewPermission();
$gContent->addHit();

$gContent->loadXrefInfo();
$tracks = [];
$credits = [];
$trackArtists = [];
$externalLinks = [];
if( $gContent->mXrefInfo ) {
	foreach( $gContent->liveXrefs() as $xref ) {
		// External links (mbid/discogs/...) - same generic cross_ref_href convention FisheyeFilm's
		// own imdb/tmdb links use, identified by having one rather than by item name, so any future
		// liberty_xref_item source added for fisheyealbum picks this up for free. Unlike imdb/tmdb
		// (which only ever store their id in xkey), 'mbid' predates this convention and keeps its
		// value in xkey_ext (extractCommonTags()'s own common-tag storage, shared with every other
		// FISHEYEALBUM_COMMON_TAG_MAP entry) - falling back to xkey_ext rather than moving where
		// mbid is stored, since fetchDiscogsLink() and reloadTracks()/registerFromDisk() all already
		// read/write it there.
		$linkValue = $xref['xkey'] ?: ( $xref['xkey_ext'] ?? null );
		if( !empty( $xref['cross_ref_href'] ) && !empty( $linkValue ) ) {
			$externalLinks[] = [
				'title' => $xref['xref_title'] ?? strtoupper( $xref['item'] ),
				'url'   => $xref['cross_ref_href'].$linkValue,
			];
		}
		switch( $xref['item'] ) {
			case 'track':
				$data = !empty( $xref['data'] ) ? json_decode( $xref['data'], true ) : [];
				$tracks[] = [
					'xref_id'    => $xref['xref_id'],
					'title'      => $data['title'] ?? $xref['xkey_ext'],
					'disc'       => $data['disc'] ?? 1,
					// The track's real number from its own tags/filename, not its position in the
					// list - a missing track then shows as a visible gap (1, 4, 5...) the way the
					// edit page's Tracks tab already shows it, rather than being silently renumbered.
					'trackNum'   => isset( $data['track'] ) ? (int)$data['track'] : null,
					// The track's own per-credit performer (a various-artists/composers
					// compilation's real value-add over the album-level 'artist' xref already
					// shown above the list) - null on a normal single-artist album, where it'd
					// just repeat that same value. ARTISTS (MusicBrainz's own raw multi-artist
					// credit) is the fallback for a release with no plain ARTIST tag at all - seen
					// on Classic Composers, which only carries ARTISTS/ARTISTSORT per track.
					// ARTISTS is a list once a track credits several artists (see
					// FisheyeAlbum::readTrackTags()), shown joined. PERFORMERS (an older rip's own
					// "Performers" comment - orchestra, soloists, "X, conductor" one per line) is the
					// last fallback, for a single-composer classical album whose ARTIST/ARTISTS got
					// promoted to album level, leaving the performing credit as the only per-track one.
					'artist'     => is_array( $x = $data['ARTIST'] ?? $data['ARTISTS'] ?? null ) ? implode( ', ', $x ) : ( $x ?? ( isset( $data['PERFORMERS'] ) ? preg_replace( '/\s*\R\s*/', ', ', trim( $data['PERFORMERS'] ) ) : null ) ),
					// Same TSST (ID3v2) / DISCSUBTITLE (Vorbis) precedence as getDiscTitle() uses
					// for a box set's own per-disc title - here it's just extra context after the
					// "Disc X" heading on a single flattened multi-disc album, not the title itself.
					'discSubtitle' => is_array( $x = $data['TSST'] ?? $data['DISCSUBTITLE'] ?? null ) ? implode( '; ', $x ) : $x,
					'durationMs' => $data['duration'] ?? null,
					// A single-artist track linked to its contact (FisheyeAlbum::reconcileAlbumXrefs()) -
					// the root index.php?content_id= dispatcher routes to whatever the contact's own
					// display page is, so nothing here needs to know which package it belongs to.
					'artistUrl'  => !empty( $xref['xref'] ) ? BIT_ROOT_URL.'index.php?content_id='.(int)$xref['xref'] : null,
					// First name of a several-artist ARTISTS list - its own link above; the rest
					// come from track_artist rows.
					'firstArtist' => is_array( $data['ARTISTS'] ?? null ) ? ( $data['ARTISTS'][0] ?? null ) : null,
					'xorder'     => (int)$xref['xorder'],
				];
				break;
			case 'track_artist':
				// A further credited artist of a several-artist track (see
				// FisheyeAlbum::reconcileAlbumXrefs()) - added to that track's line below by xorder.
				$data = !empty( $xref['data'] ) ? json_decode( $xref['data'], true ) : [];
				$trackArtists[(int)$xref['xorder']][] = [
					'name' => $data['name'] ?? '',
					'url'  => !empty( $xref['xref'] ) ? BIT_ROOT_URL.'index.php?content_id='.(int)$xref['xref'] : null,
				];
				break;
			default:
				// Album credits - one row per person under their job (artist/composer/conductor/...),
				// see FISHEYEALBUM_CREDIT_ITEMS. Linked to the person's contact when xref is set,
				// otherwise to their MusicBrainz artist page when the id is known.
				if( in_array( $xref['item'], FISHEYEALBUM_CREDIT_ITEMS, true ) ) {
					$data = !empty( $xref['data'] ) ? json_decode( $xref['data'], true ) : [];
					$credits[$xref['item']][] = [
						'name'   => $xref['xkey_ext'],
						'xorder' => (int)$xref['xorder'],
						'url'    => !empty( $xref['xref'] ) ? BIT_ROOT_URL.'index.php?content_id='.(int)$xref['xref']
							: ( !empty( $data['mbid'] ) ? 'https://musicbrainz.org/artist/'.$data['mbid'] : null ),
						'local'  => !empty( $xref['xref'] ),
					];
				}
				break;
		}
	}
}
usort( $tracks, fn( $a, $b ) => $a['xorder'] <=> $b['xorder'] );
// The release's own cover-art page on MusicBrainz (the Cover Art Archive images Reload Images can
// fetch), next to the album's other external links.
if( $releaseMbid = $gContent->getReleaseMbid() ) {
	$externalLinks[] = [ 'title' => KernelTools::tra( 'MusicBrainz cover art' ), 'url' => 'https://musicbrainz.org/release/'.rawurlencode( $releaseMbid ).'/cover-art' ];
}
// A several-artist track: each artist named and linked separately - the first from the track row
// itself (its own contact link), the rest from their track_artist rows.
foreach( $tracks as &$track ) {
	if( !empty( $trackArtists[$track['xorder']] ) ) {
		$track['artists'] = array_merge(
			[ [ 'name' => $track['firstArtist'] ?? $track['artist'], 'url' => $track['artistUrl'] ] ],
			$trackArtists[$track['xorder']]
		);
	}
}
unset( $track );
// Credits in job order (FISHEYEALBUM_CREDIT_ITEMS), each job's people in credit order.
$creditGroups = [];
foreach( FISHEYEALBUM_CREDIT_ITEMS as $role ) {
	if( !empty( $credits[$role] ) ) {
		usort( $credits[$role], fn( $a, $b ) => $a['xorder'] <=> $b['xorder'] );
		$creditGroups[] = [ 'role' => $role, 'people' => $credits[$role] ];
	}
}

// Grouped by disc here, not detected via a boundary-change check in the template - a single-disc
// album (the common case) just gets one group and no "Disc 1" heading at all.
$discs = [];
$discSubtitles = [];
foreach( $tracks as $track ) {
	$discs[$track['disc']][] = $track;
	if( !empty( $track['discSubtitle'] ) && empty( $discSubtitles[$track['disc']] ) ) {
		$discSubtitles[$track['disc']] = $track['discSubtitle'];
	}
}

$gBitSmarty->assign( 'discs', $discs );
$gBitSmarty->assign( 'discSubtitles', $discSubtitles );
$gBitSmarty->assign( 'multiDisc', count( $discs ) > 1 );
$gBitSmarty->assign( 'creditGroups', $creditGroups );
$gBitSmarty->assign( 'externalLinks', $externalLinks );
$gBitSmarty->assign( 'gContent', $gContent );

$gBitSystem->setCanonicalLink( $gContent->getDisplayUrl() );
$gBitSystem->setBrowserTitle( $gContent->getTitle() );
$gBitSystem->display( 'bitpackage:fisheyemedia/view_album.tpl', null, [ 'display_mode' => 'display' ] );

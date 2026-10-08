{* Swappable per-episode detail blocks - one per $episodes entry, all but the first hidden.
   fisheyeShowGridItem('episode', idx) (episode_grid_inc.tpl, content_tabs_js_inc.tpl) toggles
   which is visible by index. Shared between view_season.tpl and view_program_single_season.tpl -
   factored out rather than duplicated, no wrapping column div here so each caller supplies its
   own. *}
{foreach from=$episodes item=episode name=episodeDetails}
	<div class="episode-detail" id="episode-detail-{$smarty.foreach.episodeDetails.index}"{if !$smarty.foreach.episodeDetails.first} style="display:none;"{/if}>
		<h3>{$episode.title|escape}</h3>
		{if $episode.air_date}<p class="episode-air-date"><small>{$episode.air_date|escape}</small></p>{/if}
		{if $episode.summary}<p>{$episode.summary|escape}</p>{/if}
		<dl>
			{if $episode.directors|@count}<dt>{tr}Director{/tr}{if $episode.directors|@count > 1}s{/if}</dt><dd>{include file="bitpackage:fisheyemedia/credit_names_inc.tpl" names=$episode.directors limit=null}</dd>{/if}
			{if $episode.writers|@count}<dt>{tr}Writer{/tr}{if $episode.writers|@count > 1}s{/if}</dt><dd>{include file="bitpackage:fisheyemedia/credit_names_inc.tpl" names=$episode.writers limit=null}</dd>{/if}
			{if $episode.stars|@count}<dt>{tr}Starring{/tr}</dt><dd>{include file="bitpackage:fisheyemedia/credit_names_inc.tpl" names=$episode.stars creditRoles=$episode.roles limit=null}</dd>{/if}
			{if $episode.content_rating}<dt>{tr}Rating{/tr}</dt><dd>{$episode.content_rating|escape}</dd>{/if}
			{if $episode.durationMs}<dt>{tr}Duration{/tr}</dt><dd>{($episode.durationMs/1000)|display_duration}</dd>{/if}
			{if $episode.resolution}<dt>{tr}Video{/tr}</dt><dd>{$episode.resolution|escape}</dd>{/if}
			{if $episode.audio}<dt>{tr}Audio{/tr}</dt><dd>{$episode.audio|escape}</dd>{/if}
		</dl>
		<p class="episode-play-action">
			<a class="btn btn-primary" id="episode-play-btn-{$smarty.foreach.episodeDetails.index}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}play_episode.php?xref_id={$episode.xref_id}" target="_blank" rel="noopener" onclick="return fisheyeToggleEpisodePlayback(this, this.href);">&#9658; {tr}Play Episode{/tr}</a>
			{* `download` asks the browser to save rather than play inline (play_episode.php always
			   serves Content-Disposition: inline, needed for the Play button's own use above) -
			   the only reliable way to get full multichannel audio out of an episode whose audio
			   track is 5.1+: the in-page <video> element commonly downmixes or goes silent on
			   it, even though a real media player handles the exact same file's surround track
			   correctly once it's saved locally. *}
			<a class="btn btn-default" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}play_episode.php?xref_id={$episode.xref_id}" download>{tr}Download original file{/tr}</a>
		</p>
	</div>
{/foreach}
<script>
	// Swaps the poster image (fisheye-episode-poster) for the hidden player
	// (fisheye-episode-player, view_season.tpl/view_program_single_season.tpl's own left-hand
	// poster column), in the left half of the top area, rather than navigating away. Falls
	// through to the real play_episode.php page (target="_blank") if JS doesn't run or either
	// element isn't on this page for some reason.
	//
	// view_program_single_season.tpl's left side is two col-md-3 columns (poster + show facts) -
	// fisheye-episode-poster-col/-facts-col, both hidden so the player (col-md-6) gets their
	// combined width instead of being squeezed into just the poster's own column. Neither id
	// exists on view_season.tpl (a single col-md-6 poster column already), so those two lookups
	// just no-op there.
	//
	// The button itself doubles as the back control - clicking the same (or any other) episode's
	// button while one is playing stops it and restores the poster/facts columns; only one button
	// is ever in "Stop" state at a time, tracked via fisheyePlayingBtn so switching to a different
	// episode's button resets whichever one was previously showing "Stop" back to "Play Episode".
	var fisheyePlayingBtn = null;
	var FISHEYE_PLAY_LABEL = '&#9658; {tr}Play Episode{/tr}';
	var FISHEYE_STOP_LABEL = '&#9632; {tr}Stop{/tr}';

	function fisheyeResetEpisodePlayer() {
		var poster = document.getElementById( 'fisheye-episode-poster' );
		var player = document.getElementById( 'fisheye-episode-player' );
		if( player ) {
			player.pause();
			player.style.display = 'none';
		}
		if( poster ) {
			poster.style.display = '';
		}
		[ 'fisheye-episode-poster-col', 'fisheye-episode-facts-col' ].forEach( function( id ) {
			var col = document.getElementById( id );
			if( col ) {
				col.style.display = '';
			}
		} );
		if( fisheyePlayingBtn ) {
			// Featurette buttons (featurette_detail_panels_inc.tpl) set their own dataset.title so their
			// own label is restored here, rather than the generic episode "Play Episode" text -
			// an episode button never sets this, so its own behaviour is unchanged.
			fisheyePlayingBtn.innerHTML = fisheyePlayingBtn.dataset.title ? ( '&#9658; ' + fisheyePlayingBtn.dataset.title ) : FISHEYE_PLAY_LABEL;
			fisheyePlayingBtn = null;
		}
	}

	function fisheyeToggleEpisodePlayback( btn, url ) {
		var poster = document.getElementById( 'fisheye-episode-poster' );
		var player = document.getElementById( 'fisheye-episode-player' );
		var source = player ? player.querySelector( 'source' ) : null;
		if( !poster || !player || !source ) {
			return true;
		}
		if( btn === fisheyePlayingBtn ) {
			fisheyeResetEpisodePlayer();
			return false;
		}
		fisheyeResetEpisodePlayer();
		source.src = url;
		player.load();
		player.play();
		poster.style.display = 'none';
		player.style.display = '';
		[ 'fisheye-episode-poster-col', 'fisheye-episode-facts-col' ].forEach( function( id ) {
			var col = document.getElementById( id );
			if( col ) {
				col.style.display = 'none';
			}
		} );
		btn.innerHTML = FISHEYE_STOP_LABEL;
		fisheyePlayingBtn = btn;
		return false;
	}
</script>

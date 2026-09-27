{* Swappable per-featurette detail blocks - same shape as episode_detail_panels_inc.tpl, one per
   $featurettes entry, all but the first hidden. fisheyeShowGridItem('featurette', idx)
   (featurette_grid_inc.tpl) toggles which is visible.

   Params: $featurettes (array), $playToggleFn (JS function name to call on Play, defaults to
   fisheyeToggleEpisodePlayback - Season/Program's shared #fisheye-episode-player/poster toggle).
   view_film.tpl passes its own fisheyeToggleFeaturette() instead, since a film's page has a
   different player (#liberty-video-player, always showing the film itself rather than a
   hidden-until-clicked poster) - both toggle functions accept the same (btn, url) shape and
   read/restore the button's own dataset.title, so this template doesn't need to know which one
   it's calling. *}
{foreach from=$featurettes item=featurette name=featuretteDetails}
	<div class="featurette-detail" id="featurette-detail-{$smarty.foreach.featuretteDetails.index}"{if !$smarty.foreach.featuretteDetails.first} style="display:none;"{/if}>
		<h3>{$featurette.title|escape}</h3>
		{if $featurette.summary}<p>{$featurette.summary|escape}</p>{/if}
		{if $featurette.durationMs || $featurette.resolution || $featurette.audio}
			<dl>
				{if $featurette.durationMs}<dt>{tr}Duration{/tr}</dt><dd>{($featurette.durationMs/1000)|display_duration}</dd>{/if}
				{if $featurette.resolution}<dt>{tr}Video{/tr}</dt><dd>{$featurette.resolution|escape}</dd>{/if}
				{if $featurette.audio}<dt>{tr}Audio{/tr}</dt><dd>{$featurette.audio|escape}</dd>{/if}
			</dl>
		{/if}
		<p class="episode-play-action">
			<a class="btn btn-primary" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}play_episode.php?xref_id={$featurette.xref_id}" data-title="{$featurette.title|escape}" onclick="return {$playToggleFn|default:'fisheyeToggleEpisodePlayback'}(this, this.href);">&#9658; {tr}Play{/tr}</a>
			{* Same reasoning as episode_detail_panels_inc.tpl's own download link - `download`
			   saves rather than plays inline, the only reliable way to get 5.1+ audio out of a
			   featurette whose in-page playback commonly downmixes or goes silent on it. *}
			<a class="btn btn-default" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}play_episode.php?xref_id={$featurette.xref_id}" download>{tr}Download original file{/tr}</a>
		</p>
	</div>
{/foreach}

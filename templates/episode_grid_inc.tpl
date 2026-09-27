{* Episode picker grid - purely a picker, clicking a card swaps which detail block (see
   episode_detail_panels_inc.tpl) is shown, no per-episode request. 6-across at md+ (col-md-2).
   Shared between view_season.tpl and view_program_single_season.tpl - factored out rather than
   duplicated. Card-switching itself is fisheyeShowGridItem() (content_tabs_js_inc.tpl), shared
   with featurette_grid_inc.tpl's identical own picker. *}
{if $episodes|@count}
	<section class="season-episodes">
		<div class="row">
			{foreach from=$episodes item=episode name=episodes}
				<div class="col-md-2 col-sm-4 col-xs-6">
					<div class="gallery-box episode-item{if $smarty.foreach.episodes.first} active{/if}" onclick="fisheyeShowGridItem('episode', {$smarty.foreach.episodes.index})" style="cursor:pointer;">
						<div class="gallery-img">
							{if $episode.thumb}
								<img class="img-responsive thumb" src="{$smarty.const.FISHEYE_PKG_URL}view_extra_image.php?xref_id={$episode.xref_id}" alt="{$episode.title|escape}" />
							{/if}
						</div>
						<div class="gallery-img-title center">
							<small>{$episode.xorder}. {$episode.title|escape}{if $episode.air_date} &middot; {$episode.air_date|escape}{/if}</small>
						</div>
					</div>
				</div>
				{if $smarty.foreach.episodes.iteration % 2 == 0}<div class="clearfix visible-xs-block"></div>{/if}
				{if $smarty.foreach.episodes.iteration % 3 == 0}<div class="clearfix visible-sm-block"></div>{/if}
				{if $smarty.foreach.episodes.iteration % 6 == 0}<div class="clearfix visible-md-block visible-lg-block"></div>{/if}
			{/foreach}
		</div>
	</section>
{/if}

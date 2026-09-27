{* Featurette picker grid - same shape and card-switching mechanism as episode_grid_inc.tpl
   (fisheyeShowGridItem(), content_tabs_js_inc.tpl), clicking a card swaps which detail block
   (featurette_detail_panels_inc.tpl) is shown - in Season/Program's shared top-right facts
   column, or directly below this grid on Film's page, wherever the caller places it. *}
{if $featurettes|@count}
	<div class="row">
		{foreach from=$featurettes item=featurette name=featurettes}
			<div class="col-md-2 col-sm-4 col-xs-6">
				<div class="gallery-box featurette-item{if $smarty.foreach.featurettes.first} active{/if}" onclick="fisheyeShowGridItem('featurette', {$smarty.foreach.featurettes.index})" style="cursor:pointer;">
					<div class="gallery-img">
						{if $featurette.thumb}
							<img class="img-responsive thumb" src="{$smarty.const.FISHEYE_PKG_URL}view_extra_image.php?xref_id={$featurette.xref_id}" alt="{$featurette.title|escape}" />
						{/if}
					</div>
					<div class="gallery-img-title center">
						<small>{$featurette.title|escape}</small>
					</div>
				</div>
			</div>
			{if $smarty.foreach.featurettes.iteration % 2 == 0}<div class="clearfix visible-xs-block"></div>{/if}
			{if $smarty.foreach.featurettes.iteration % 3 == 0}<div class="clearfix visible-sm-block"></div>{/if}
			{if $smarty.foreach.featurettes.iteration % 6 == 0}<div class="clearfix visible-md-block visible-lg-block"></div>{/if}
		{/foreach}
	</div>
{/if}

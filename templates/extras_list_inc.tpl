{* The Extras list ($extrasGroups, each {id, kind, items[], links[{title,url,icon}]}): a tab per kind of thing when there is
   more than one, each tab carrying its own icons, laid out with the shared media grid styles. A featurettes group renders
   with the featurette cards and detail panels. Used for the popup's contents and the page of its own; extras_inc.tpl's script
   switches the tabs. *}
{include file="bitpackage:fisheyemedia/media_grid_css_inc.tpl"}
{assign var=extrasTabbed value=($extrasGroups|@count > 1)}
{if $extrasTabbed}
	<ul class="nav nav-tabs">
		{foreach from=$extrasGroups item=group name=extrasTabs}
			<li class="{if $smarty.foreach.extrasTabs.first}active{/if}"><a href="#" class="extras-tab-link" data-tab="{$group.id}">{tr}{$group.kind}{/tr} ({$group.items|@count})</a></li>
		{/foreach}
	</ul>
{/if}
{foreach from=$extrasGroups item=group name=extrasPanels}
	<div class="extras-tab-panel" id="extras-tab-{$group.id}"{if $extrasTabbed && !$smarty.foreach.extrasPanels.first} hidden{/if}>
		{if $group.links}
			<div class="extras-bar">
				{foreach from=$group.links item=link}<a title="{tr}{$link.title}{/tr}" href="{$link.url|escape}">{biticon ipackage="icons" iname=$link.icon iexplain=$link.title}</a>{/foreach}
			</div>
		{/if}
		{if $group.template|default:'' eq 'featurettes'}
			{include file="bitpackage:fisheyemedia/featurette_grid_inc.tpl" featurettes=$group.featurettes}
			{include file="bitpackage:fisheyemedia/featurette_detail_panels_inc.tpl" featurettes=$group.featurettes playToggleFn=$group.playFn|default:'fisheyeToggleFeaturette'}
		{else}
			<div class="media-strip">
				{foreach from=$group.items item=extra}
					<div class="media-grid-item">
						{box class="box"}
							<a href="{$extra.url|escape}" target="_blank" rel="noopener" title="{$extra.title|escape}">
								<img class="thumb img-responsive center-block media-cover" src="{$extra.thumb|escape}" alt="{$extra.title|escape}" />
							</a>
							{if $extra.label|default:''}<h4>{$extra.label|escape}</h4>{elseif $gBitSystem->isFeatureActive('fisheye_gallery_list_image_titles')}<h4>{$extra.title|escape}</h4>{/if}
						{/box}
					</div>
				{foreachelse}
					<p class="norecords">{tr}Nothing here yet.{/tr}</p>
				{/foreach}
			</div>
		{/if}
	</div>
{foreachelse}
	<p class="norecords">{tr}Nothing here yet.{/tr}</p>
{/foreach}

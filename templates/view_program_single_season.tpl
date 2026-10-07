{* Single-season show: skips the dummy "Season 1" click-through entirely - view_program.php
   dispatches here instead of view_program.tpl when a show has exactly one season, loading that
   season's own episode/image data itself (same shape view_season.php uses). Real FisheyeSeason
   object still underneath, just not a separate page view - a different tpl for the single-season
   state rather than special-casing view_program.tpl itself.

   Layout: left side 50/50 (series thumbnail | show summary), episode detail panel
   to the right (col-md-6, swaps per-episode same as view_season.tpl), episodes along the bottom. *}
{strip}
<div class="display fisheye view-program view-program-single-season">
	<header>
		<div class="floaticon">
			{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='icon' serviceHash=$gContent->mInfo}
			{if $gContent->hasUpdatePermission()}
				<a title="{tr}Edit{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_program.php?content_id={$gContent->mContentId}">{biticon ipackage="icons" iname="edit" iexplain="Edit"}</a>
				{* view_program.tpl (the multi-season case) calls this "Load More Seasons" - wrong
				   label here since a single-season show never has another season to load, but the
				   same destination also now surfaces a "Reload Episodes" action (load_program.php's
				   $reloadCandidates - see FisheyeSeason::getEpisodeFileCountOnDisk()) for exactly
				   this show's one season whenever its folder has more files than are registered -
				   the common case worth a direct icon here, unlike the multi-season page. *}
				<a title="{tr}Reload Episodes{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_program.php?gallery_id={$gContent->mGalleryId}&amp;show={$gContent->getTitle()|escape:"url"}">{biticon ipackage="icons" iname="view-refresh" iexplain="Reload Episodes"}</a>
			{/if}
		</div>
		<h1>{foreach from=$gContent->getBreadcrumbTrail() item=crumb}<a href="{$crumb.url|escape}">{$crumb.title|escape}</a> - {/foreach}{$gContent->getTitle()|escape}</h1>
	</header>

	<section class="body">
		<div class="row">
			{* Player hidden until "Play Episode" (episode_detail_panels_inc.tpl) shows it, in the
			   left half of the top area. Spans both col-md-3s below (col-md-6 combined) rather
			   than being squeezed into just the poster's own column, hiding both when shown so
			   the row's own column math still adds up to 12. *}
			<video id="fisheye-episode-player" class="col-md-6" controls preload="metadata" style="display:none; max-height:600px;">
				<source src="" type="video/mp4">
			</video>
			{if $gContent->getThumbnailUri('medium')}
				<div class="col-md-3 film-poster" id="fisheye-episode-poster-col">
					<img id="fisheye-episode-poster" class="img-responsive" src="{$gContent->getThumbnailUri('medium')}" alt="{$gContent->getTitle()|escape}" />
				</div>
			{/if}
			<div class="col-md-3 film-facts" id="fisheye-episode-facts-col">
				{if $gContent->mInfo.data}
					<p class="film-summary">{$gContent->mInfo.data|escape}</p>
				{/if}
				{if $rollup.director || $rollup.star}
					<div class="film-credits">
						{if $rollup.director}<strong>{tr}Director{/tr}{if $rollup.director|@count > 1}s{/if}:</strong> {include file="bitpackage:fisheyemedia/credit_rollup_inc.tpl" people=$rollup.director limit=10}<br />{/if}
						{if $rollup.star}<strong>{tr}Starring{/tr}:</strong> {include file="bitpackage:fisheyemedia/credit_rollup_inc.tpl" people=$rollup.star limit=12}{/if}
					</div>
				{else}
				{if $directors|@count || $stars|@count}
					<p class="film-credits">
						{if $directors|@count}<strong>{tr}Director{/tr}{if $directors|@count > 1}s{/if}:</strong> {include file="bitpackage:fisheyemedia/credit_names_inc.tpl" names=$directors limit=null}<br />{/if}
						{if $stars|@count}<strong>{tr}Starring{/tr}:</strong> {include file="bitpackage:fisheyemedia/credit_names_inc.tpl" names=$stars limit=null}{/if}
					</p>
				{/if}
				{/if}
				{if $genres|@count}
					<p class="film-genres">
						{foreach from=$genres item=genre}<span class="label label-default">{$genre|escape}</span> {/foreach}
					</p>
				{/if}
				<dl class="film-info">
					{if $contentRating}<dt>{tr}Rating{/tr}</dt><dd>{$contentRating|escape}</dd>{/if}
					{if $durationMs}<dt>{tr}Duration{/tr}</dt><dd>{($durationMs/1000)|display_duration}</dd>{/if}
					{if $rollup.writer}<dt>{tr}Writer{/tr}{if $rollup.writer|@count > 1}s{/if}</dt><dd>{include file="bitpackage:fisheyemedia/credit_rollup_inc.tpl" people=$rollup.writer limit=10}</dd>{elseif $writers|@count}<dt>{tr}Writer{/tr}{if $writers|@count > 1}s{/if}</dt><dd>{include file="bitpackage:fisheyemedia/credit_names_inc.tpl" names=$writers limit=null}</dd>{/if}
				</dl>
				{if $externalLinks|@count}
					<p class="film-external-links">
						{foreach from=$externalLinks item=link name=externalLinks}
							<a href="{$link.url|escape}" target="_blank" rel="noopener">{$link.title|escape}</a>{if !$smarty.foreach.externalLinks.last} &middot; {/if}
						{/foreach}
					</p>
				{/if}
			</div>
			<div class="col-md-6">
				{* See view_season.tpl's own identical comment - fisheyeShowContentTab() toggles
				   which group is visible to match the active tab. *}
				<div id="episode-details-group"{if $firstContentTab != 'episodes'} style="display:none;"{/if}>
					{include file="bitpackage:fisheyemedia/episode_detail_panels_inc.tpl"}
				</div>
				<div id="featurette-details-group"{if $firstContentTab != 'featurettes'} style="display:none;"{/if}>
					{include file="bitpackage:fisheyemedia/featurette_detail_panels_inc.tpl" featurettes=$seasonFeaturettes}
				</div>
			</div>
		</div>
	</section>

	{* Episodes/Featurettes/Images tabs - see view_season.tpl's own identical structure. Edit
	   links use $seasonContentId (the season's own content_id, see view_program.php), not
	   $gContent (the show), since the season is what actually owns these xrefs. *}
	<div class="fisheye-content-tabs">
		<ul class="nav nav-tabs">
			{if $episodes|@count}<li class="fisheye-tab-item{if $firstContentTab == 'episodes'} active{/if}"><a class="fisheye-tab-link" href="#" onclick="return fisheyeShowContentTab('episodes', this);">{tr}Episodes{/tr}</a></li>{/if}
			{if $seasonFeaturettes|@count}<li class="fisheye-tab-item{if $firstContentTab == 'featurettes'} active{/if}"><a class="fisheye-tab-link" href="#" onclick="return fisheyeShowContentTab('featurettes', this);">{tr}Featurettes{/tr}</a></li>{/if}
			{if $seasonImages|@count}<li class="fisheye-tab-item{if $firstContentTab == 'images'} active{/if}"><a class="fisheye-tab-link" href="#" onclick="return fisheyeShowContentTab('images', this);">{tr}Images{/tr}</a></li>{/if}
		</ul>

		{if $episodes|@count}
			<div class="fisheye-tab-panel" id="fisheye-tab-episodes"{if $firstContentTab != 'episodes'} style="display:none;"{/if}>
				{if $gContent->hasUpdatePermission()}
					<div class="fisheye-tab-header"><a title="{tr}Reload Episodes{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_season.php?content_id={$seasonContentId}&amp;fReloadEpisodes=1">{biticon ipackage="icons" iname="view-refresh" iexplain="Reload Episodes"}</a></div>
				{/if}
				{include file="bitpackage:fisheyemedia/episode_grid_inc.tpl"}
			</div>
		{/if}
		{if $seasonFeaturettes|@count}
			<div class="fisheye-tab-panel" id="fisheye-tab-featurettes"{if $firstContentTab != 'featurettes'} style="display:none;"{/if}>
				{if $gContent->hasUpdatePermission()}
					<div class="fisheye-tab-header"><a title="{tr}Reload Featurettes{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_season.php?content_id={$seasonContentId}&amp;fReloadFeaturettes=1">{biticon ipackage="icons" iname="view-refresh" iexplain="Reload Featurettes"}</a></div>
				{/if}
				{include file="bitpackage:fisheyemedia/featurette_grid_inc.tpl" featurettes=$seasonFeaturettes}
			</div>
		{/if}
		{if $seasonImages|@count}
			<div class="fisheye-tab-panel" id="fisheye-tab-images"{if $firstContentTab != 'images'} style="display:none;"{/if}>
				{if $gContent->hasUpdatePermission()}
					<div class="fisheye-tab-header"><a title="{tr}Add Image{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}add_image_xref.php?content_id={$seasonContentId}">{biticon ipackage="icons" iname="go-up" iexplain="Add Image"}</a></div>
				{/if}
				{include file="bitpackage:fisheyemedia/images_grid_inc.tpl" images=$seasonImages imagesAltText="Images"}
			</div>
		{/if}
	</div>
	{include file="bitpackage:fisheyemedia/content_tabs_js_inc.tpl"}
</div>
{/strip}

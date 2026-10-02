{strip}
<div class="display fisheye view-season">
	<header>
		<div class="floaticon">
			{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='icon' serviceHash=$gContent->mInfo}
			{if $gContent->hasUpdatePermission()}
				<a title="{tr}Edit{/tr}" href="{$gContent->getEditUrl()|escape}">{biticon ipackage="icons" iname="edit" iexplain="Edit"}</a>
			{/if}
		</div>
		{* The shared gallery_breadcrumb_inc.tpl hardcodes a pretty-url 'gallery/<id>' link for
		   every ancestor, which always lands on the generic gallery view regardless of type -
		   wrong for a show (FisheyeProgram has its own view_program.php). A season is only ever
		   one level below its show, so the title itself just links back up to the parent's own
		   getDisplayUrl() - the link belongs on the title text itself - rather than a separate
		   breadcrumb line, and rather than trying to make the shared, type-agnostic breadcrumb
		   component type-aware. *}
		{if $gGallery && $seasonTitleSuffix}
			<h1><a href="{$gGallery->getDisplayUrl()|escape}">{$gGallery->getTitle()|escape}</a>{$seasonTitleSuffix|escape}</h1>
		{elseif $gGallery}
			<h1><a href="{$gGallery->getDisplayUrl()|escape}">{$gContent->getTitle()|escape}</a></h1>
		{else}
			<h1>{$gContent->getTitle()|escape}</h1>
		{/if}
	</header>

	<section class="body">
		<div class="row">
			{if $gContent->getThumbnailUri('medium')}
				<div class="col-md-6 film-poster">
					{* Hidden until a "Play Episode" button (episode_detail_panels_inc.tpl) shows
					   it in place of the poster, in the left half of the top area. *}
					<img id="fisheye-episode-poster" class="img-responsive" src="{$gContent->getThumbnailUri('medium')}" alt="{$gContent->getTitle()|escape}" />
					<video id="fisheye-episode-player" class="img-responsive" controls preload="metadata" style="display:none; width:100%; max-height:600px;">
						<source src="" type="video/mp4">
					</video>
				</div>
			{/if}
			<div class="col-md-6">
				{* No season-level facts panel - Plex has none of its own either, it's the TV that
				   toggles to display a selected episode's metadata as you select each. This is
				   that panel: the highlighted episode's own already-rendered detail block, swapped
				   by the grid below with no per-episode request - matching that highlight-swaps-
				   the-panel interaction, positioned beside the poster (top right) the way
				   view_film.tpl's own facts panel is. Featurettes get the exact same treatment,
				   in their own group - fisheyeShowContentTab() (content_tabs_js_inc.tpl) toggles
				   which group is visible to match the active tab, so this one area serves
				   whichever of Episodes/Featurettes is currently selected. *}
				<div id="episode-details-group"{if $firstContentTab != 'episodes'} style="display:none;"{/if}>
					{include file="bitpackage:fisheyemedia/episode_detail_panels_inc.tpl"}
				</div>
				<div id="featurette-details-group"{if $firstContentTab != 'featurettes'} style="display:none;"{/if}>
					{include file="bitpackage:fisheyemedia/featurette_detail_panels_inc.tpl" featurettes=$seasonFeaturettes}
				</div>
				{if !$episodes|@count}
					{if $externalLinks|@count}
						<p class="film-external-links">
							{foreach from=$externalLinks item=link name=externalLinks}
								<a href="{$link.url|escape}" target="_blank" rel="noopener">{$link.title|escape}</a>{if !$smarty.foreach.externalLinks.last} &middot; {/if}
							{/foreach}
						</p>
					{/if}
					{if $gContent->mInfo.data}
						<p>{$gContent->mInfo.data|escape}</p>
					{/if}
				{/if}
			</div>
		</div>
	</section>

	{* Episodes/Featurettes/Images tabs - each tab's own top-right edit action keeps
	   section-specific admin actions out of the page's shared icon bar. *}
	<div class="fisheye-content-tabs">
		<ul class="nav nav-tabs">
			{if $episodes|@count}<li class="fisheye-tab-item{if $firstContentTab == 'episodes'} active{/if}"><a class="fisheye-tab-link" href="#" onclick="return fisheyeShowContentTab('episodes', this);">{tr}Episodes{/tr}</a></li>{/if}
			{if $seasonFeaturettes|@count}<li class="fisheye-tab-item{if $firstContentTab == 'featurettes'} active{/if}"><a class="fisheye-tab-link" href="#" onclick="return fisheyeShowContentTab('featurettes', this);">{tr}Featurettes{/tr}</a></li>{/if}
			{if $seasonImages|@count}<li class="fisheye-tab-item{if $firstContentTab == 'images'} active{/if}"><a class="fisheye-tab-link" href="#" onclick="return fisheyeShowContentTab('images', this);">{tr}Images{/tr}</a></li>{/if}
		</ul>

		{if $episodes|@count}
			<div class="fisheye-tab-panel" id="fisheye-tab-episodes"{if $firstContentTab != 'episodes'} style="display:none;"{/if}>
				{if $gContent->hasUpdatePermission()}
					<div class="fisheye-tab-header"><a title="{tr}Reload Episodes{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_season.php?content_id={$gContent->mContentId}&amp;fReloadEpisodes=1">{biticon ipackage="icons" iname="view-refresh" iexplain="Reload Episodes"}</a></div>
				{/if}
				{include file="bitpackage:fisheyemedia/episode_grid_inc.tpl"}
			</div>
		{/if}
		{if $seasonFeaturettes|@count}
			<div class="fisheye-tab-panel" id="fisheye-tab-featurettes"{if $firstContentTab != 'featurettes'} style="display:none;"{/if}>
				{if $gContent->hasUpdatePermission()}
					<div class="fisheye-tab-header"><a title="{tr}Reload Featurettes{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_season.php?content_id={$gContent->mContentId}&amp;fReloadFeaturettes=1">{biticon ipackage="icons" iname="view-refresh" iexplain="Reload Featurettes"}</a></div>
				{/if}
				{include file="bitpackage:fisheyemedia/featurette_grid_inc.tpl" featurettes=$seasonFeaturettes}
			</div>
		{/if}
		{if $seasonImages|@count}
			<div class="fisheye-tab-panel" id="fisheye-tab-images"{if $firstContentTab != 'images'} style="display:none;"{/if}>
				{if $gContent->hasUpdatePermission()}
					<div class="fisheye-tab-header"><a title="{tr}Add Image{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}add_image_xref.php?content_id={$gContent->mContentId}">{biticon ipackage="icons" iname="go-up" iexplain="Add Image"}</a></div>
				{/if}
				{include file="bitpackage:fisheyemedia/images_grid_inc.tpl" images=$seasonImages imagesAltText="Images"}
			</div>
		{/if}
	</div>
	{include file="bitpackage:fisheyemedia/content_tabs_js_inc.tpl"}
</div>
{/strip}

{strip}
<div class="display fisheye view-program">
	<header>
		<div class="floaticon">
			{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='icon' serviceHash=$gContent->mInfo}
			{if $gContent->hasUpdatePermission()}
				<a title="{tr}Edit{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_program.php?content_id={$gContent->mContentId}">{biticon ipackage="icons" iname="edit" iexplain="Edit"}</a>
				<a title="{tr}Load More Seasons{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_program.php?gallery_id={$gContent->mGalleryId}&amp;show={$gContent->getTitle()|escape:"url"}">{biticon ipackage="icons" iname="folder-open" iexplain="Load More Seasons"}</a>
				<a title="{tr}Season Order{/tr}" href="{$smarty.const.FISHEYE_PKG_URL}image_order.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="view-sort-ascending" iexplain="Season Order"}</a>
			{/if}
			{foreach from=$programTools item=tool}
				<a title="{$tool.title|escape}" href="{$tool.url|escape}">{biticon ipackage="icons" iname=$tool.icon iexplain=$tool.title}{if $tool.badge}<span class="badge" title="{$tool.badgeTitle|escape}">{$tool.badge|escape}</span>{/if}</a>
			{/foreach}
		</div>
		{* Breadcrumb - same getBreadcrumbTrail() mechanism as view_film.tpl/fisheye_film_grid_inc.tpl/
		   fisheye_program_grid_inc.tpl, previously just missing here entirely. Walks the real
		   ancestor chain via fisheye_gallery_image_map - "TV Shows" leads for any registered show. *}
		<h1>{foreach from=$gContent->getBreadcrumbTrail() item=crumb}<a href="{$crumb.url|escape}">{$crumb.title|escape}</a> - {/foreach}{$gContent->getTitle()|escape}</h1>
	</header>

	<section class="body">
		<div class="row">
			{if $gContent->getThumbnailUri('medium')}
				<div class="col-md-6 film-poster">
					<img class="img-responsive" src="{$gContent->getThumbnailUri('medium')}" alt="{$gContent->getTitle()|escape}" />
				</div>
			{/if}
			<div class="col-md-6 film-facts">
				{if $gContent->mInfo.data}
					<p class="film-summary">{$gContent->mInfo.data|escape}</p>
				{/if}
				{if $directors|@count || $stars|@count}
					<p class="film-credits">
						{if $directors|@count}<strong>{tr}Director{/tr}{if $directors|@count > 1}s{/if}:</strong> {$directors|@implode:", "|escape}<br />{/if}
						{if $stars|@count}<strong>{tr}Starring{/tr}:</strong> {$stars|@implode:", "|escape}{/if}
					</p>
				{/if}
				{if $genres|@count}
					<p class="film-genres">
						{foreach from=$genres item=genre}<span class="label label-default">{$genre|escape}</span> {/foreach}
					</p>
				{/if}
				<dl class="film-info">
					{if $contentRating}<dt>{tr}Rating{/tr}</dt><dd>{$contentRating|escape}</dd>{/if}
					{if $durationMs}<dt>{tr}Duration{/tr}</dt><dd>{($durationMs/1000)|display_duration}</dd>{/if}
					{if $writers|@count}<dt>{tr}Writer{/tr}{if $writers|@count > 1}s{/if}</dt><dd>{$writers|@implode:", "|escape}</dd>{/if}
				</dl>
				{if $externalLinks|@count}
					<p class="film-external-links">
						{foreach from=$externalLinks item=link name=externalLinks}
							<a href="{$link.url|escape}" target="_blank" rel="noopener">{$link.title|escape}</a>{if !$smarty.foreach.externalLinks.last} &middot; {/if}
						{/foreach}
					</p>
				{/if}
			</div>
		</div>
	</section>

	{if $gContent->mItems|@count}
		<section class="film-seasons">
			<h2>{tr}Seasons{/tr}</h2>
			{* Same responsive flex grid as fisheye_film_grid_inc.tpl/fisheye_program_grid_inc.tpl -
			   8 across on a wide monitor, folding to 4 then 2 - not Bootstrap-col driven. *}
			<style>
				.film-grid { display: flex; flex-wrap: wrap; margin: 0 -5px; }
				.film-grid-item { box-sizing: border-box; padding: 5px; text-align: center; width: 12.5%; }
				@media (max-width: 1199px) { .film-grid-item { width: 25%; } }
				@media (max-width: 767px) { .film-grid-item { width: 50%; } }
			</style>
			<div class="film-grid">
				{foreach from=$gContent->mItems item=season}
					<div class="film-grid-item">
						<div class="gallery-box">
							<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_season.php?content_id={$season->mContentId}">
								{if $season->getThumbnailUri()}
									<div class="gallery-img">
										<img class="img-responsive thumb" src="{$season->getThumbnailUri()}" alt="{$season->mInfo.title|escape}" />
									</div>
								{/if}
								<div class="gallery-img-title center">
									{$seasonTitles[$season->mContentId]|default:$season->mInfo.title|escape}
								</div>
							</a>
						</div>
					</div>
				{/foreach}
			</div>
		</section>
	{/if}
</div>
{/strip}

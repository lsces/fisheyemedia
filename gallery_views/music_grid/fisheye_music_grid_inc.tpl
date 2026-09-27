{* Plex-style artist page: one flowing strip per discography category (FisheyeGallery::
   getCategorizedItems(), grouped via each album's own 'category' xref) instead of a single
   paginated grid - every album registered through the current flow always has a category, and a
   box set/Videos subgallery item lands in its own trailing "Collections" strip, so there's no
   pagination widget here at all, just strips flowing down the page. Own floaticon set
   (music_gallery_icons_inc.tpl - Edit/Image Order/Public/Delete/Load Album/Load Videos, same
   shape as program_gallery_icons_inc.tpl) - the generic gallery_icons_inc.tpl's own Download
   Gallery/Add Image actions don't apply to an artist gallery. *}
{strip}
<div class="display fisheye">
	<header>
		{include file="bitpackage:fisheyemedia/music_gallery_icons_inc.tpl"}
		<h1>{foreach from=$gContent->getBreadcrumbTrail() item=crumb}<a href="{$crumb.url|escape}">{$crumb.title|escape}</a> - {/foreach}{$gContent->getTitle()|escape}</h1>
	</header>

	{if $gContent->mInfo.data && $gContent->getPreference('show_description') ne 'n'}
	<section class="body">
		<p>{$gContent->mInfo.data|escape}</p>
	</section>
	{/if}

	<div class="body">
		{formfeedback success=$fisheyeSuccess error=$fisheyeErrors warning=$fisheyeWarnings}

		{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='body' serviceHash=$gContent->mInfo}

		<style>
			.music-strip-title { margin: 20px 0 8px; }
			.music-strip-title:first-child { margin-top: 0; }
			.music-strip { display: flex; flex-wrap: wrap; margin: 0 -5px; }
			.music-grid-item { box-sizing: border-box; padding: 5px; text-align: center; width: 12.5%; }
			@media (max-width: 1199px) { .music-grid-item { width: 25%; } }
			@media (max-width: 767px) { .music-grid-item { width: 50%; } }
		</style>

		{foreach from=$gContent->getCategorizedItems() item=stripItems key=stripKey}
			<h3 class="music-strip-title">{$stripKey|capitalize}</h3>
			<div class="music-strip">
			{foreach from=$stripItems item=galItem key=itemContentId}
				<div class="music-grid-item">
					{box class="box `$galItem->mInfo.content_type_guid`" style="margin-left:0;"}
						<a href="{$galItem->getDisplayUrl()|escape}">
							<img class="thumb img-responsive center-block" src="{$galItem->getThumbnailUri($gContent->getField('thumbnail_size'))}" alt="{$galItem->mInfo.title|escape|default:'image'}" />
						</a>
						{if $gBitSystem->isFeatureActive('fisheye_gallery_list_image_titles')}
							<h4>{$galItem->mInfo.title|escape}</h4>
						{/if}
						{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='body' serviceHash=$galItem->mInfo type=mini}
						{if $gBitSystem->isFeatureActive('fisheye_gallery_list_image_descriptions')}
							<p>{$galItem->mInfo.data|truncate:200:"..."|escape}</p>
						{/if}
					{/box}
				</div>
			{/foreach}
			</div>
		{foreachelse}
			<p class="norecords">{tr}This gallery is empty{/tr}. <a href="{$smarty.const.FISHEYE_PKG_URL}upload.php?gallery_id={$gContent->mGalleryId ?? 0}">Upload pictures!</a></p>
		{/foreach}
	</div><!-- end .body -->

	{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='view' serviceHash=$gContent->mInfo}

	{if $gContent->getPreference('allow_comments') eq 'y'}
		{include file="bitpackage:liberty/comments.tpl"}
	{/if}
</div><!-- end .fisheye -->
{/strip}

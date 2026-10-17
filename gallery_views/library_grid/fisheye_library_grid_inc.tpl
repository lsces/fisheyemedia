{* Two things share this one layout value, as music_grid does: the top-level "Library" pool (many
   author and category galleries - a plain grid of tiles) and one author or category gallery (its own
   books, one flowing strip per category via FisheyeMediaGallery::getCategorizedItems(), a nested
   series gallery landing in a trailing "Collections" strip). getCategorizedItems() only exists on
   FisheyeMediaGallery and only makes sense for one gallery's own items, so the pool - a plain
   FisheyeGallery - takes the first branch, picked by its title the way music_grid picks 'Music'.
   Own floaticon set either way (library_gallery_icons_inc.tpl); the generic gallery_icons_inc.tpl's
   Download Gallery/Add Image actions don't apply to a shelf of books. *}
{strip}
<div class="display fisheye">
	<header>
		{include file="bitpackage:fisheyemedia/library_gallery_icons_inc.tpl"}
		{* An author's gallery: the name goes on to the author's own page once the authors pass has linked their books. *}
		{if $gContent->getTitle() ne 'Library'}{assign var=authorUrl value=$gContent->getAuthorContactUrl()}{/if}
		<h1>{foreach from=$gContent->getBreadcrumbTrail() item=crumb}<a href="{$crumb.url|escape}">{$crumb.title|escape}</a> - {/foreach}{if $authorUrl}<a href="{$authorUrl|escape}">{$gContent->getTitle()|escape}</a>{else}{$gContent->getTitle()|escape}{/if}</h1>
	</header>

	{if $gContent->mInfo.data && $gContent->getPreference('show_description') ne 'n'}
	<section class="body">
		<p>{$gContent->mInfo.data|escape}</p>
	</section>
	{/if}

	<div class="body">
		{formfeedback success=$fisheyeSuccess error=$fisheyeErrors warning=$fisheyeWarnings}

		{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='body' serviceHash=$gContent->mInfo}

		{include file="bitpackage:fisheyemedia/media_grid_css_inc.tpl"}

		{if $gContent->getTitle() eq 'Library'}
			{* Top-level pool: a plain paginated grid of author and category galleries. *}
			<div class="media-strip">
			{foreach from=$gContent->mItems item=galItem key=itemContentId}
				<div class="media-grid-item">
					{box class="box `$galItem->mInfo.content_type_guid`"}
						{assign var="tileThumb" value=$galItem->getThumbnailUri($gContent->getField('thumbnail_size'))}
						<a href="{$galItem->getDisplayUrl()|escape}">
							{if $tileThumb && $tileThumb neq $smarty.const.BIT_ROOT_URI}
								<img class="thumb img-responsive center-block media-cover" src="{$tileThumb}" alt="{$galItem->mInfo.title|escape|default:'image'}" />
							{else}
								{* No cover anywhere in it - a name tile rather than a broken image *}
								<div class="media-name-tile"><span>{$galItem->mInfo.title|escape}</span></div>
							{/if}
						</a>
						{if $gBitSystem->isFeatureActive('fisheye_gallery_list_image_titles')}
							<h4>{$galItem->mInfo.title|escape}</h4>
						{/if}
						{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='body' serviceHash=$galItem->mInfo type=mini}
					{/box}
				</div>
			{foreachelse}
				<p class="norecords">{tr}This gallery is empty{/tr}.</p>
			{/foreach}
			</div>
			{pagination gallery_id=$gContent->mGalleryId ?? 0}
		{else}
			{* One author's (or category's) own books - the categorized strip layout. *}
			{foreach from=$gContent->getCategorizedItems() item=stripItems key=stripKey}
				{* '' = items with no category (unlabelled); otherwise the category as written *}
				{if $stripKey neq ''}<h3 class="media-strip-title">{$stripKey|escape}</h3>{/if}
				<div class="media-strip">
				{foreach from=$stripItems item=galItem key=itemContentId}
					<div class="media-grid-item">
						{box class="box `$galItem->mInfo.content_type_guid`"}
							{assign var="tileThumb" value=$galItem->getThumbnailUri($gContent->getField('thumbnail_size'))}
							<a href="{$galItem->getDisplayUrl()|escape}">
								{if $tileThumb && $tileThumb neq $smarty.const.BIT_ROOT_URI}
									<img class="thumb img-responsive center-block media-cover" src="{$tileThumb}" alt="{$galItem->mInfo.title|escape|default:'image'}" />
								{else}
									<div class="media-name-tile"><span>{$galItem->mInfo.title|escape}</span></div>
								{/if}
							</a>
							{if $gBitSystem->isFeatureActive('fisheye_gallery_list_image_titles')}
								<h4>{$galItem->mInfo.title|escape}</h4>
							{/if}
							{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='body' serviceHash=$galItem->mInfo type=mini}
						{/box}
					</div>
				{/foreach}
				</div>
			{foreachelse}
				<p class="norecords">{tr}This gallery is empty{/tr}.</p>
			{/foreach}
		{/if}
	</div><!-- end .body -->

	{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='view' serviceHash=$gContent->mInfo}

	{if $gContent->getPreference('allow_comments') eq 'y'}
		{include file="bitpackage:liberty/comments.tpl"}
	{/if}
</div><!-- end .fisheye -->
{/strip}

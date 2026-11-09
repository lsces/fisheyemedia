{* The Documentation gallery and its topic galleries: a plain grid of tiles (a topic gallery, or a document's first page) with the title under each, the
   gallery's own floaticon set (docs_gallery_icons_inc.tpl - the Docs workbench) and the breadcrumb trail as the heading, as library_grid does for the Library
   pool. A document tile opens the document's own page. *}
{strip}
<div class="display fisheye">
	<header>
		{include file="bitpackage:fisheyemedia/docs_gallery_icons_inc.tpl"}
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

		{include file="bitpackage:fisheyemedia/media_grid_css_inc.tpl"}

		<div class="media-strip">
		{foreach from=$gContent->mItems item=galItem key=itemContentId}
			<div class="media-grid-item">
				{box class="box `$galItem->mInfo.content_type_guid`"}
					{assign var="tileThumb" value=$galItem->getThumbnailUri($gContent->getField('thumbnail_size'))}
					{* a PDF opens in the site's pdf.js viewer and an epub in its epub.js reader (view_extra_file.php), not the generic file page *}
					<a href="{if $galItem->mInfo.mime_type eq 'application/pdf' || $galItem->mInfo.mime_type eq 'application/epub+zip'}{$smarty.const.FISHEYEMEDIA_PKG_URL}view_extra_file.php?content_id={$itemContentId}{else}{$galItem->getDisplayUrl()|escape}{/if}">
						{if $tileThumb && $tileThumb neq $smarty.const.BIT_ROOT_URI}
							<img class="thumb img-responsive center-block media-cover" src="{$tileThumb}" alt="{$galItem->mInfo.title|escape|default:'document'}" />
						{else}
							<div class="media-name-tile"><span>{$galItem->mInfo.title|escape}</span></div>
						{/if}
					</a>
					<h4>{$galItem->mInfo.title|escape}</h4>
					{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='body' serviceHash=$galItem->mInfo type=mini}
				{/box}
			</div>
		{foreachelse}
			<p class="norecords">{tr}This gallery is empty{/tr}.</p>
		{/foreach}
		</div>
		{pagination gallery_id=$gContent->mGalleryId ?? 0}
	</div>
</div>
{/strip}

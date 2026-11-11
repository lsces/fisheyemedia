{* Floaticon set for the docs_grid layout (the Documentation gallery and its topic galleries): Edit, Image Order and Upload documents, and for an admin the Docs workbench
   (the front page from the top gallery, that topic's page from a topic gallery - the topic is the gallery's title). Loading files from the Documentation folder is the workbench's job; the Upload icon
   is for adding documents by hand. *}
<div class="floaticon">
	{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='icon' serviceHash=$gContent->mInfo}
	{if $gContent->hasUpdatePermission()}
		{if $gBitUser->hasPermission( 'p_fisheye_admin' )}
			{* the collection is the top gallery of the trail (or this gallery when it is the top); the folder is the gallery just under it *}
			{assign var=docsTrail value=$gContent->getBreadcrumbTrail()}
			{assign var=docsTop value=$gContent->getTitle()}
			{if $docsTrail|@count}{assign var=docsTop value=$docsTrail[0].title}{/if}
			{assign var=docsKind value=$docsTop|lower}
			{if $docsTrail|@count > 1}{assign var=docsFolder value=$docsTrail[1].title}{elseif $docsTrail|@count}{assign var=docsFolder value=$gContent->getTitle()}{else}{assign var=docsFolder value=''}{/if}
			{if $docsFolder eq ''}
				<a title="{tr}Workbench: every folder and what is left to load{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}docs_workbench.php?kind={$docsKind|escape:'url'}">{biticon ipackage="icons" iname="document-properties" iexplain="Docs workbench"}</a>
			{else}
				<a title="{tr}Workbench: load the files of this folder{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}docs_workbench.php?kind={$docsKind|escape:'url'}&amp;topic={$docsFolder|escape:'url'}">{biticon ipackage="icons" iname="document-properties" iexplain="Docs workbench"}</a>
			{/if}
		{/if}
		<a title="{tr}Edit{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_gallery.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="edit" iexplain="Edit"}</a>
		<a title="{tr}Image Order{/tr}" href="{$smarty.const.FISHEYE_PKG_URL}image_order.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="view-sort-ascending" iexplain="Image Order"}</a>
		{* upload documents (PDFs, epubs, or a zip of them) straight into this gallery, for one-offs and later additions; loading in place is the workbench's job *}
		<a title="{tr}Upload documents: PDFs, epubs, or a zip of them{/tr}" href="{$smarty.const.FISHEYE_PKG_URL}upload.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="go-up" iexplain="Upload documents"}</a>
	{/if}
	{if $gContent->getPreference('is_public')}
		{biticon ipackage="icons" iname="emblem-important" iexplain="Public"}
	{/if}
</div>

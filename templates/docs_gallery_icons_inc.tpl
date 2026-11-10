{* Floaticon set for the docs_grid layout (the Documentation gallery and its topic galleries): Edit, Image Order and Upload documents, and for an admin the Docs workbench
   (the front page from the top gallery, that topic's page from a topic gallery - the topic is the gallery's title). Loading files from the Documentation folder is the workbench's job; the Upload icon
   is for adding documents by hand. *}
<div class="floaticon">
	{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='icon' serviceHash=$gContent->mInfo}
	{if $gContent->hasUpdatePermission()}
		{if $gBitUser->hasPermission( 'p_fisheye_admin' )}
			{if $gContent->getTitle() eq 'Documentation'}
				<a title="{tr}Docs workbench: every topic folder and what is left to load{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}docs_workbench.php">{biticon ipackage="icons" iname="document-properties" iexplain="Docs workbench"}</a>
			{else}
				<a title="{tr}Docs workbench: load the files of this topic{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}docs_workbench.php?topic={$gContent->getTitle()|escape:'url'}">{biticon ipackage="icons" iname="document-properties" iexplain="Docs workbench"}</a>
			{/if}
		{/if}
		<a title="{tr}Add a gallery inside this one{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}add_gallery.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="folder-new" iexplain="Add gallery"}</a>
		<a title="{tr}Edit{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_gallery.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="edit" iexplain="Edit"}</a>
		<a title="{tr}Image Order{/tr}" href="{$smarty.const.FISHEYE_PKG_URL}image_order.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="view-sort-ascending" iexplain="Image Order"}</a>
		{* upload documents (PDFs, epubs, or a zip of them) straight into this gallery, for one-offs and later additions; loading in place is the workbench's job *}
		<a title="{tr}Upload documents: PDFs, epubs, or a zip of them{/tr}" href="{$smarty.const.FISHEYE_PKG_URL}upload.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="go-up" iexplain="Upload documents"}</a>
	{/if}
	{if $gContent->getPreference('is_public')}
		{biticon ipackage="icons" iname="emblem-important" iexplain="Public"}
	{/if}
</div>

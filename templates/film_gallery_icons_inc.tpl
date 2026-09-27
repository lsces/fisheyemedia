<div class="floaticon">
	{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='icon' serviceHash=$gContent->mInfo}
	{if $gContent->hasUpdatePermission()}
		{if $gContent->getTitle() eq 'Films'}
			{* Collections aren't nested (a collection is just another gallery under this one) -
			   only offered here on the top "Films" gallery itself, not on a collection gallery. *}
			<a title="{tr}Load Collections{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_collection.php">{biticon ipackage="icons" iname="folder-new" iexplain="Load Collections"}</a>
		{/if}
		<a title="{tr}Load Films{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_film.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="folder-open" iexplain="Load Films"}</a>
		<a title="{tr}Edit{/tr}" href="{$smarty.const.FISHEYE_PKG_URL}edit.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="edit"  iexplain="Edit"}</a>
		<a title="{tr}Image Order{/tr}" href="{$smarty.const.FISHEYE_PKG_URL}image_order.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="view-sort-ascending" iexplain="Image Order"}</a>
	{/if}
	{if $gContent->getPreference('is_public')}
		{biticon ipackage="icons" iname="emblem-important"  iexplain="Public"}
	{/if}
	{if $gContent->hasAdminPermission()}
		<a title="{tr}User Permissions{/tr}" href="{$smarty.const.FISHEYE_PKG_URL}edit.php?gallery_id={$gContent->mGalleryId}&amp;delete=1">{biticon ipackage="icons" iname="user-trash" iexplain="Delete Gallery"}</a>
	{/if}
</div>

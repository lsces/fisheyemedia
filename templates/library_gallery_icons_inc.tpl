{* Floaticon set for the library_grid layout - Edit/Image Order/Public/Delete as the music and
   program galleries have, plus Add Books, Authors and Book details on the top-level Library pool only (same title gating as
   music_gallery_icons_inc.tpl's own 'Music'). *}
<div class="floaticon">
	{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='icon' serviceHash=$gContent->mInfo}
	{if $gContent->hasUpdatePermission()}
		{if $gContent->getTitle() eq 'Library'}
			<a title="{tr}Add Books{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_books.php">{biticon ipackage="icons" iname="folder-new" iexplain="Add Books"}</a>
			<a title="{tr}Authors from Open Library{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_authors.php">{biticon ipackage="icons" iname="system-users" iexplain="Authors from Open Library"}</a>
			<a title="{tr}Book details from Open Library{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_details.php">{biticon ipackage="icons" iname="view-refresh" iexplain="Book details from Open Library"}</a>
		{else}
			<a title="{tr}Author's contact from Open Library{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_authors.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="system-users" iexplain="Author's contact from Open Library"}</a>
			<a title="{tr}Series from folders{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_series.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="folder-open" iexplain="Series from folders"}</a>
		{/if}
		<a title="{tr}Edit{/tr}" href="{$smarty.const.FISHEYE_PKG_URL}edit.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="edit" iexplain="Edit"}</a>
		<a title="{tr}Image Order{/tr}" href="{$smarty.const.FISHEYE_PKG_URL}image_order.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="view-sort-ascending" iexplain="Image Order"}</a>
	{/if}
	{if $gContent->getPreference('is_public')}
		{biticon ipackage="icons" iname="emblem-important" iexplain="Public"}
	{/if}
	{if $gContent->hasAdminPermission()}
		<a title="{tr}User Permissions{/tr}" href="{$smarty.const.FISHEYE_PKG_URL}edit.php?gallery_id={$gContent->mGalleryId}&amp;delete=1">{biticon ipackage="icons" iname="user-trash" iexplain="Delete Gallery"}</a>
	{/if}
</div>

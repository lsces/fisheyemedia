{* Floaticon set for the library_grid layout - Edit/Image Order/Public/Delete as the music and
   program galleries have, plus Add Books from ISBNs and, for an admin, the Author workbench front page on the top-level Library pool only (same title gating as
   music_gallery_icons_inc.tpl's own 'Music') - the bulk loaders (authors, the catalogue, book details, covers) are links in the Fisheyemedia
   admin menu, not page icons; an author's gallery gets the Author workbench (books, series and files for that author, one page), preceded by the author's contact icon (the contact page once linked, the creating page before). Edit is safe here:
   FisheyeMediaGallery::addToGalleries() keeps a library gallery's parents, which edit.php would otherwise drop
   (it detached series from their author) - Edit is for renaming and the description, the load pages do the filing. *}
<div class="floaticon">
	{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='icon' serviceHash=$gContent->mInfo}
	{if $gContent->hasUpdatePermission()}
		{if $gContent->getTitle() eq 'Library'}
			<a title="{tr}Add Books from ISBNs{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}add_books.php">{biticon ipackage="icons" iname="bwfm-book-add" iexplain="Add Books from ISBNs"}</a>
			{if $gBitUser->hasPermission( 'p_fisheye_admin' )}
				<a title="{tr}Author workbench: every author folder in the Library and what is left to do{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}author_workbench.php">{biticon ipackage="icons" iname="bwfm-author" iexplain="Author workbench"}</a>
			{/if}
		{else}
			{assign var=seriesWorkbenchUrl value=$libraryGallery->getSeriesWorkbenchUrl()}
			{if $seriesWorkbenchUrl}
				<a title="{tr}Series workbench: load books, file the series, load files{/tr}" href="{$seriesWorkbenchUrl|escape}">{biticon ipackage="icons" iname="bwfm-series" iexplain="Series workbench"}</a>
			{else}
				{* The author's contact: the contact page itself once one is linked, else the page that creates it. *}
				{assign var=authorContactUrl value=$libraryGallery->getAuthorContactUrl()}
				{if $authorContactUrl}
					<a title="{tr}Author's contact page{/tr}" href="{$authorContactUrl|escape}">{biticon ipackage="icons" iname="system-users" iexplain="Author's contact page"}</a>
				{else}
					<a title="{tr}Author's contact from Open Library{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_authors.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="system-users" iexplain="Author's contact from Open Library"}</a>
				{/if}
				<a title="{tr}Author workbench: load books, sort series, load files{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}author_workbench.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="bwfm-author" iexplain="Author workbench"}</a>
			{/if}
		{/if}
		<a title="{tr}Edit{/tr}" href="{$smarty.const.FISHEYE_PKG_URL}edit.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="edit" iexplain="Edit"}</a>
		<a title="{tr}Image Order{/tr}" href="{$smarty.const.FISHEYE_PKG_URL}image_order.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="view-sort-ascending" iexplain="Image Order"}</a>
	{/if}
	{if $gContent->getPreference('is_public')}
		{biticon ipackage="icons" iname="emblem-important" iexplain="Public"}
	{/if}
</div>

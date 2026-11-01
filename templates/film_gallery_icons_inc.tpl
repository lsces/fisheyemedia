<div class="floaticon">
	{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='icon' serviceHash=$gContent->mInfo}
	{if $gContent->hasUpdatePermission()}
		{if $gContent->getTitle() eq 'Films'}
			{* Collections aren't nested (a collection is just another gallery under this one) -
			   only offered here on the top "Films" gallery itself, not on a collection gallery. *}
			<a title="{tr}Load Collections{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_collection.php">{biticon ipackage="icons" iname="folder-new" iexplain="Load Collections"}</a>
		{/if}
		{if $gContent->getTitle() eq 'Local Videos'}
			<a title="{tr}Load Local Videos{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_local.php">{biticon ipackage="icons" iname="folder-new" iexplain="Load Local Videos"}</a>
			<a title="{tr}Load Local Shows{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_program.php?local=1">{biticon ipackage="icons" iname="video-x-generic" iexplain="Load Local Shows"}</a>
		{/if}
		{* An artist's nested "Videos" gallery holds music videos: they are loaded from the artist's Videos/ folder
		   (load_video.php, given the artist's gallery), not from the film library. An album's "<album> - Videos"
		   gallery has no film loader either - Load Videos is on the album page. *}
		{assign var=videosTitle value=$smarty.const.FISHEYEMEDIA_VIDEOS_GALLERY_TITLE}
		{if $gContent->getTitle() eq $videosTitle}
			{* Offered only while the artist's Videos/ folder has videos to load, as on the artist page *}
			{if is_a( $gContent, '\Bitweaver\Fisheyemedia\FisheyeMediaGallery' )}
				{assign var=videosOwnerId value=$gContent->getUnloadedVideosOwnerId()}
				{if $videosOwnerId}
					<a title="{tr}Load Videos{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_video.php?gallery_id={$videosOwnerId}">{biticon ipackage="icons" iname="video-x-generic" iexplain="Load Videos"}</a>
				{/if}
			{/if}
		{elseif $gContent->getTitle() ne 'Local Videos' and $gContent->getTitle()|substr:-9 ne ' - Videos'}
		<a title="{tr}Load Films{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_film.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="folder-open" iexplain="Load Films"}</a>
		{/if}
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

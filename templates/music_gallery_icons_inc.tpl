<div class="floaticon">
	{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='icon' serviceHash=$gContent->mInfo}
	{if $gContent->hasUpdatePermission()}
		{if $gContent->getTitle() eq 'Music'}
			{* Top-level only, same gating as film_gallery_icons_inc.tpl's own "Load Collections". *}
			<a title="{tr}Add Music Collection{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_music.php">{biticon ipackage="icons" iname="folder-new" iexplain="Add Music Collection"}</a>
		{else}
			{* Any collection gallery below the top level - registers one album (a single
			   FisheyeAlbum::registerFromDisk() folder) into this gallery, as opposed to the
			   top-level button above which will bulk-scan for whole collections at once.
			   Only shown when there's actually something left to load (Lester, 2026-09-24) -
			   hasUnloadedAlbumCandidates()/hasUnloadedVideoCandidates() are cheap short-circuit
			   scans, same folder shape load_album.php/load_video.php themselves use. *}
			{if $gContent->hasUnloadedAlbumCandidates()}
				<a title="{tr}Load Album{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_album.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="folder-open" iexplain="Load Album"}</a>
			{/if}
			{if $gContent->hasUnloadedVideoCandidates()}
				<a title="{tr}Load Videos{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_video.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="video-x-generic" iexplain="Load Videos"}</a>
			{/if}
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

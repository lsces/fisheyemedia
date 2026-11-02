<div class="floaticon">
	{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='icon' serviceHash=$gContent->mInfo}
	{if $gContent->hasUpdatePermission()}
		{if $gContent->getTitle() eq 'Music'}
			{* Top-level only, same gating as film_gallery_icons_inc.tpl's own "Load Collections". *}
			<a title="{tr}Add Music Collection{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_music.php">{biticon ipackage="icons" iname="bwfm-music-add" iexplain="Add Music Collection"}</a>
			<a title="{tr}Physical copies - records, CDs and DVDs{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_physical.php">{biticon ipackage="icons" iname="media-optical" iexplain="Physical copies"}</a>
		{elseif $gContent->mContentId eq $gBitSystem->getConfig('fisheyemedia_audio_books_gallery', 0)}
			{* The audio books gallery has no folder behind it: albums are made from a book's Audio folder (Load Audio Book on the book). *}
		{else}
			{* Any collection gallery below the top level - registers one album (a single
			   FisheyeAlbum::registerFromDisk() folder) into this gallery, as opposed to the
			   top-level button above which will bulk-scan for whole collections at once.
			   Only shown when there's actually something left to load -
			   hasUnloadedAlbumCandidates()/hasUnloadedVideoCandidates() are cheap short-circuit
			   scans, same folder shape load_album.php/load_video.php themselves use. *}
			{if $gContent->hasUnloadedAlbumCandidates()}
				<a title="{tr}Load Album{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_album.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="bwfm-music-load" iexplain="Load Album"}</a>
			{/if}
			{if $gContent->hasUnloadedVideoCandidates()}
				<a title="{tr}Load Videos{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_video.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="bwfm-film-load" iexplain="Load Videos"}</a>
			{/if}
		{/if}
		<a title="{tr}Edit{/tr}" href="{$smarty.const.FISHEYE_PKG_URL}edit.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="edit"  iexplain="Edit"}</a>
		<a title="{tr}Image Order{/tr}" href="{$smarty.const.FISHEYE_PKG_URL}image_order.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="view-sort-ascending" iexplain="Image Order"}</a>
	{/if}
	{if $gContent->getPreference('is_public')}
		{biticon ipackage="icons" iname="emblem-important"  iexplain="Public"}
	{/if}
</div>

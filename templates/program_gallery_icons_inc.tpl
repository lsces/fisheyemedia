<div class="floaticon">
	{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='icon' serviceHash=$gContent->mInfo}
	{if $gContent->hasUpdatePermission()}
		<a title="{tr}Load Seasons{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_program.php?{if $gContent->getTitle() eq 'Local Tutorials'}local=tutorials{else}gallery_id={$gContent->mGalleryId}{/if}">{biticon ipackage="icons" iname="bwfm-tv-load" iexplain="Load Seasons"}</a>
		{if $gContent->getTitle() eq 'Local Tutorials' && $gBitUser->hasPermission( 'p_fisheye_admin' )}
			<a title="{tr}Tutorial workbench: every course folder and what is left to do{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}tutorial_workbench.php">{biticon ipackage="icons" iname="applications-education" iexplain="Tutorial workbench"}</a>
		{/if}
		<a title="{tr}Edit{/tr}" href="{$smarty.const.FISHEYE_PKG_URL}edit.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="edit"  iexplain="Edit"}</a>
		<a title="{tr}Image Order{/tr}" href="{$smarty.const.FISHEYE_PKG_URL}image_order.php?gallery_id={$gContent->mGalleryId}">{biticon ipackage="icons" iname="view-sort-ascending" iexplain="Image Order"}</a>
	{/if}
	{if $gContent->getPreference('is_public')}
		{biticon ipackage="icons" iname="emblem-important"  iexplain="Public"}
	{/if}
</div>

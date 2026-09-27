<div class="floaticon">
	{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='icon' serviceHash=$gContent->mInfo}
	{if $gContent->hasUpdatePermission()}
		<a title="{tr}Edit{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_film.php?content_id={$gContent->mContentId}">{biticon ipackage="icons" iname="edit" iexplain="Edit"}</a>
	{/if}
</div>

{* Group-tab override for the 'images' xref group (fisheyefilm/fisheyeseason/fisheyeprogram/
   fisheyealbum all share this one - liberty_xref_group.template set to 'images' for their group
   rows). Identical to liberty's own generic list_xref.tpl except the "Add" link below, which
   goes through the standard add_xref.php - LibertyContent::getXrefAddTemplate() resolves this
   same group's own add_images_group.tpl there too (real file upload), rather than the plain
   add_xref.tpl (no upload at all). *}
{assign var=xrefAllowEdit value=$allow_edit|default:true}
{assign var=tabTitle value=$xrefGroup->mTitle}
{assign var=isHistory value=($xrefGroup->mXGroup eq 'history')}
{* The item's pictures folder (Images / <Types> / <title>), listed with the item's own image rows - nothing is stored on the item for them. *}
{if $isHistory}{assign var=folderImages value=[]}{else}{assign var=folderImages value=$gContent->getPicturesFolderImages()}{/if}
{jstab title="`$tabTitle` ({$xrefGroup->mXrefs|@count + $folderImages|@count})"}
{legend legend=$tabTitle}
<div class="form-group table-responsive">
	<table class="table table-condensed">
		<thead>
			<tr>
				<th>{tr}Type{/tr}</th>
				<th>{tr}Value{/tr}</th>
				<th>{tr}Notes{/tr}</th>
				{if $xrefAllowEdit}
					{if $isHistory}<th>{tr}Ended{/tr}</th>{else}<th>{tr}Started{/tr}</th>{/if}
					<th>{tr}Updated{/tr}</th>
					<th>{tr}Edit{/tr}</th>
				{/if}
			</tr>
		</thead>
		<tbody>
			{if $xrefGroup->mXrefs || $folderImages}
				{foreach $xrefGroup->mXrefs as $xrefInfo}
					<tr class="{cycle values="even,odd"}">
						{include file=$gContent->getXrefRecordTemplate($xrefInfo.template)}
					</tr>
				{/foreach}
				{foreach $folderImages as $folderImage}
					<tr class="{cycle values="even,odd"}">
						<td>{tr}Pictures folder{/tr}</td>
						<td><a href="{$folderImage.url|escape}"><img src="{$folderImage.thumb|escape}" alt="{$folderImage.title|escape}" style="max-height:90px; max-width:140px;" /></a></td>
						<td>{$folderImage.title|escape}</td>
						{if $xrefAllowEdit}
							<td></td>
							<td></td>
							<td>
								<span class="actionicon">
									{if $gContent->hasUpdatePermission()}
										<a title="{tr}Set as Thumbnail{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}set_thumbnail.php?content_id={$gContent->mContentId}&amp;image_content_id={$folderImage.content_id}">{biticon ipackage="icons" iname="image-x-generic" iexplain="Set as Thumbnail"}</a>
									{/if}
								</span>
							</td>
						{/if}
					</tr>
				{/foreach}
			{else}
				<tr class="norecords">
					<td colspan="{if $xrefAllowEdit}6{else}3{/if}">{tr}No {$tabTitle} records found{/tr}</td>
				</tr>
			{/if}
		</tbody>
	</table>
</div>
{if $allow_add && $gContent->isValid() && $gContent->hasUpdatePermission() && !$isHistory}
	<div>
		{smartlink ititle="Add Image" ipackage="liberty" ifile="add_xref.php" biticon="list-add" content_id=$gContent->mInfo.content_id group=$xrefGroup->mSortOrder}
		{if $gContent->canGrabVideoFrame()}
			&nbsp;
			<a title="{tr}Grab Thumbnail from Video{/tr}" href="{$gContent->getEditUrl()|cat:'&fGrabFrame=1'|escape}">{biticon ipackage="icons" iname="package_multimedia" iexplain="Grab Thumbnail from Video"}</a>
		{/if}
	</div>
{/if}
{/legend}
{/jstab}

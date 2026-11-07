{* Edit page for a section of a Local Tutorials course - see edit_section.php's own docblock. *}
{strip}
<div class="floaticon">
	{if $gContent->hasAdminPermission()}
		<a title="{tr}Delete Section{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_section.php?content_id={$gContent->mContentId}&amp;delete=1">{biticon ipackage="icons" iname="user-trash" iexplain="Delete Section"}</a>
	{/if}
	{bithelp}
</div>

<div class="admin fisheye">
	<div class="header">
		<h1>{tr}Edit Section{/tr}: {$gContent->getTitle()|escape}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}

		{if $result}
			<div class="alert alert-info">
				{if $result.items}
					<p>{$resultLabel|escape}:</p>
					<ul>{foreach from=$result.items item=line}<li>{$line|escape}</li>{/foreach}</ul>
				{else}
					<p>{$resultEmptyLabel|escape}</p>
				{/if}
			</div>
		{/if}

		{form id="editSectionForm"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />

			{jstabs}
				{jstab title="{tr}Section{/tr}"}
					{legend legend="Section"}
						<div class="form-group">
							{formlabel label="Title" for="title"}
							{forminput}
								<input type="text" class="form-control" name="title" id="title" value="{$gContent->getTitle()|escape}" />
							{/forminput}
						</div>
						<div class="form-group">
							{formlabel label="Description" for="edit"}
							{forminput}
								{textarea rows=8 edit=$gContent->mInfo.data formatguid=$gContent->mInfo.format_guid}
							{/forminput}
						</div>
					{/legend}

					{if $gXrefInfo->mGroups}
						{jstabs}
							{foreach $gXrefInfo->mGroups as $xrefGroup}
								{include file=$gContent->getXrefListTemplate($xrefGroup->mTemplate)
									xrefGroup=$xrefGroup
									allow_add=true
									allow_edit=true}
							{/foreach}
						{/jstabs}
					{/if}
				{/jstab}
			{/jstabs}

			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="fCancel" value="{tr}Cancel{/tr}" />
				<input type="submit" class="btn btn-primary" name="fSave" value="{tr}Save{/tr}" />
				<input type="submit" class="btn btn-secondary" name="fReloadEpisodes" value="{tr}Reload Lessons{/tr}" title="{tr}Re-reads the lessons from the video files in this section's folder. Thumbnails already loaded are kept.{/tr}" />
				<input type="submit" class="btn btn-secondary" name="fReloadFiles" value="{tr}Reload Files{/tr}" title="{tr}Re-reads the exercise archives, notes and handouts in this section's folder.{/tr}" />
				<input type="submit" class="btn btn-secondary" name="fRefreshThumbs" value="{tr}Refresh Thumbnails{/tr}" title="{tr}Grabs a new thumbnail for every lesson from its video, replacing the ones stored.{/tr}" />
			</div>
		{/form}
	</div><!-- end .body -->
</div><!-- end .fisheye -->
{/strip}

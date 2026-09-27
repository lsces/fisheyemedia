{strip}
<div class="floaticon">
	{if $gContent->hasAdminPermission()}
		<a title="{tr}Delete Season{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_season.php?content_id={$gContent->mContentId}&amp;delete=1">{biticon ipackage="icons" iname="user-trash" iexplain="Delete Season"}</a>
	{/if}
	{bithelp}
</div>

<div class="admin fisheye">
	<div class="header">
		<h1>{tr}Edit Season{/tr}: {$gContent->getTitle()|escape}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}

		{if $plexResult}
			<div class="alert alert-info">
				{if $plexResult.items}
					<p>{$plexResultLabel|escape}:</p>
					<ul>{foreach from=$plexResult.items item=line}<li>{$line|escape}</li>{/foreach}</ul>
				{else}
					<p>{$plexResultEmptyLabel|escape}</p>
				{/if}
			</div>
		{/if}

		{form id="editSeasonForm"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />

			{jstabs}
				{jstab title="{tr}Season{/tr}"}
					{legend legend="Season Details"}
						<div class="form-group">
							{formlabel label="Title" for="title"}
							{forminput}
								<input type="text" class="form-control" name="title" id="title" value="{$gContent->getTitle()|escape}" />
							{/forminput}
						</div>
						<div class="form-group">
							{formlabel label="Description" for="edit"}
							{forminput}
								<textarea class="form-control" name="edit" id="edit" rows="4">{$gContent->mInfo.data|default:''|escape}</textarea>
								{formhelp note="Plex has no season-level description to auto-fill this - manual only."}
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
				<input type="submit" class="btn btn-secondary" name="fReloadImages" value="{tr}Reload Images{/tr}" />
				<input type="submit" class="btn btn-secondary" name="fReloadEpisodes" value="{tr}Load Episodes{/tr}" />
			</div>
		{/form}
	</div><!-- end .body -->
</div><!-- end .fisheye -->
{/strip}

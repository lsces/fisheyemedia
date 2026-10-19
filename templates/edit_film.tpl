{strip}
<div class="floaticon">
	{if $gContent->hasAdminPermission()}
		<a title="{tr}Delete Film{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_film.php?content_id={$gContent->mContentId}&amp;delete=1">{biticon ipackage="icons" iname="user-trash" iexplain="Delete Film"}</a>
	{/if}
	{bithelp}
</div>

<div class="admin fisheye">
	<div class="header">
		<h1>{tr}Edit Film{/tr}: {$gContent->getTitle()|escape}</h1>
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

		{form id="editFilmForm"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />

			{jstabs}
				{jstab title="{tr}Film{/tr}"}
					{legend legend="Film Details"}
						<div class="form-group">
							{formlabel label="Title" for="title"}
							{forminput}
								<input type="text" class="form-control" name="title" id="title" value="{$gContent->getTitle()|escape}" />
							{/forminput}
						</div>
						<div class="form-group">
							{formlabel label="File" for="file_name"}
							{forminput}
								<input type="text" class="form-control" name="file_name" id="file_name" value="{$storedFilePath|escape}" />
								{if !$storedFileExists}<div class="alert alert-danger">{tr}There is no file at this path on disk.{/tr}</div>{/if}
								{formhelp note="The film's file under the storage root. If the file was renamed or moved on disk, correct the path here and Save - it is checked against the disk and must not belong to another film."}
							{/forminput}
						</div>
						<div class="form-group">
							{formlabel label="Description" for="edit"}
							{forminput}
								<textarea class="form-control" name="edit" id="edit" rows="4">{$gContent->mInfo.data|default:''|escape}</textarea>
								{formhelp note="Auto-filled from Plex's own summary on Reload Metadata, if it finds a match - edit here directly for a film Plex doesn't have (or to override it)."}
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
				{if $gContent->hasAdminPermission()}
				{jstab title="{tr}Collections{/tr}"}
					{legend legend="Galleries this film is shown in"}
						<ul>
							{foreach from=$filmGalleries key=galleryContentId item=galleryTitle}
								<li>{$galleryTitle|escape} <a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_film.php?content_id={$gContent->mContentId}&amp;fRemoveFrom[{$galleryContentId}]=1" onclick="return confirm('{tr}Remove this film from{/tr} {$galleryTitle|escape:'javascript'}?')">{tr}remove{/tr}</a></li>
							{/foreach}
						</ul>
						<div class="form-group">
							{formlabel label="Collection" for="collection"}
							{forminput}
								<select name="collection" id="collection" class="form-control">
									{foreach from=$collectionChoices key=galleryContentId item=galleryTitle}
										<option value="{$galleryContentId}">{$galleryTitle|escape}</option>
									{/foreach}
								</select>
								<input type="submit" class="btn btn-default" name="fMoveTo" value="{tr}Move to this collection{/tr}" />
								<input type="submit" class="btn btn-default" name="fAddTo" value="{tr}Also show in it{/tr}" />
								{formhelp note="Move takes the film out of the other Films galleries, so only this collection shows it. Only which galleries show the film changes - the file on disk is not touched."}
							{/forminput}
						</div>
						<div class="form-group">
							{formlabel label="New collection" for="new_collection"}
							{forminput}
								<input type="text" class="form-control" name="new_collection" id="new_collection" value="" />
								<label><input type="checkbox" name="new_collection_move" value="y" checked="checked" /> {tr}move this film into it{/tr}</label>
								<input type="submit" class="btn btn-default" name="fNewCollection" value="{tr}Create collection{/tr}" />
								{formhelp note="Makes a collection under Films with that name (or uses the one already there), then puts this film in it."}
							{/forminput}
						</div>
					{/legend}
				{/jstab}
				{/if}
			{/jstabs}

			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="fCancel" value="{tr}Cancel{/tr}" />
				<input type="submit" class="btn btn-primary" name="fSave" value="{tr}Save{/tr}" />
				<input type="submit" class="btn btn-secondary" name="fReloadMetadata" value="{tr}Reload Metadata{/tr}" />
				<input type="submit" class="btn btn-secondary" name="fReloadImages" value="{tr}Reload Images{/tr}" />
			</div>
		{/form}
	</div><!-- end .body -->
</div><!-- end .fisheye -->
{/strip}

{strip}
<div class="floaticon">
	{if $gContent->hasAdminPermission()}
		<a title="{tr}Delete Show{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_program.php?gallery_id={$gContent->mGalleryId}&amp;delete=1">{biticon ipackage="icons" iname="user-trash" iexplain="Delete Show"}</a>
	{/if}
	{bithelp}
</div>

<div class="admin fisheye">
	<div class="header">
		<h1>{tr}Edit Show{/tr}: {$gContent->getTitle()|escape}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}

		{if $plexResult}
			<div class="alert alert-info">
				{if $plexResult.items}
					<p>{$plexResultLabel|escape}:</p>
					<ul>{foreach from=$plexResult.items item=line}<li>{$line|escape}</li>{/foreach}</ul>
				{else}
					<p>{tr}No matching Plex entry found for this show.{/tr}</p>
				{/if}
			</div>
		{/if}

		{if !$plexHasMatch}
			<div class="alert alert-warning">
				<p>{tr}No Plex match for this show - metadata and images have not been fetched.{/tr}</p>
			</div>

			{form id="searchPlexForm"}
				<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
				<div class="form-group">
					{formlabel label="Search Plex" for="plex_query"}
					{forminput}
						<input type="text" class="form-control" name="plex_query" id="plex_query" value="{$plexSearchQuery|escape}" style="width:auto;display:inline-block;" />
						<input type="submit" class="btn btn-secondary" name="fSearchPlex" value="{tr}Search{/tr}" />
					{/forminput}
				</div>
			{/form}

			{if $plexSearchResults !== null}
				{if $plexSearchResults}
					<ul>
						{foreach from=$plexSearchResults item=row}
							<li>
								{$row.title|escape}{if $row.year} ({$row.year|escape}){/if}
								- <a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_program.php?content_id={$gContent->mContentId}&amp;fSetPlexMatch=1&amp;plex_metadata_item_id={$row.id}">{tr}Use this{/tr}</a>
							</li>
						{/foreach}
					</ul>
				{else}
					<p>{tr}No Plex shows matched that search.{/tr}</p>
				{/if}
			{/if}
		{/if}

		{form id="editProgramForm"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />

			{jstabs}
				{jstab title="{tr}Show{/tr}"}
					{legend legend="Show Details"}
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
								{formhelp note="Auto-filled from Plex's own summary on Reload Metadata, if it finds a match - edit here directly for a show Plex doesn't have (or to override it)."}
							{/forminput}
						</div>
					{/legend}

					{if $gXrefInfo->mGroups}
						{jstabs}
							{foreach $gXrefInfo->mGroups as $xrefGroup}
								{* The merged-in 'episodes' group (edit_program.php's getSingleSeason() merge, single-
								   season shows only) belongs to the season's own content_id, not this show's -
								   xrefContentId overrides action_icons.tpl/list_xref.tpl's Edit/Add links to target
								   the season correctly rather than this page's own $gContent. *}
								{assign var=xrefContentId value=($xrefGroup->mXGroup eq 'episodes') ? $episodesContentId : null}
								{include file=$gContent->getXrefListTemplate($xrefGroup->mTemplate)
									xrefGroup=$xrefGroup
									allow_add=true
									allow_edit=true
									xrefContentId=$xrefContentId}
							{/foreach}
						{/jstabs}
					{/if}
				{/jstab}
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

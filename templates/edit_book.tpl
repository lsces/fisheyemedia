{strip}
<div class="floaticon">
	{if $gContent->hasAdminPermission()}
		<a title="{tr}Delete Book{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_book.php?content_id={$gContent->mContentId}&amp;delete=1">{biticon ipackage="icons" iname="user-trash" iexplain="Delete Book"}</a>
	{/if}
	{bithelp}
</div>

<div class="admin fisheye">
	<div class="header">
		<h1>{tr}Edit Book{/tr}: {$gContent->getTitle()|escape}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}
		{include file="bitpackage:liberty/xref_scheme_error_inc.tpl" xrefInfo=$gXrefInfo xrefTypeGuid=$gContent->mContentTypeGuid}

		{if $olMessage}
			<div class="alert alert-info">
				<p>{$olMessage|escape}{if $olResult.items}:{/if}</p>
				{if $olResult.items}<ul>{foreach from=$olResult.items item=line}<li>{$line|escape}</li>{/foreach}</ul>{/if}
			</div>
		{/if}

		{if $isbnHint && !$isbnCheck}
			<div class="alert alert-warning">{$isbnHint|escape} {tr}Use Check ISBN to compare the details with the edition it names.{/tr}</div>
		{/if}

		{if $isbnCheck && $isbnCheck.found}
			<div class="alert alert-info">
				<p>{tr}Open Library has an edition for this ISBN. Stored here, and what it holds:{/tr}</p>
				<table class="table data">
					<tr><th></th><th>{tr}Stored{/tr}</th><th>{tr}Open Library{/tr}</th></tr>
					{foreach from=$isbnCheck.rows item=row}
						<tr{if $row.differs} class="warning"{/if}><td>{$row.label|escape}</td><td>{$row.stored|escape}</td><td>{$row.found|escape}</td></tr>
					{/foreach}
				</table>
				<p><strong>{tr}The description Open Library would give{/tr}:</strong></p>
				{if $isbnCheck.description}<blockquote>{$isbnCheck.description|escape|nl2br}</blockquote>{else}<p><em>{tr}Open Library holds none for this edition.{/tr}</em></p>{/if}
				{if $isbnCheck.cover}<p><img src="{$isbnCheck.cover|escape}" alt="" style="max-height:160px" /></p>{/if}
				<p>{tr}Replace changes the edition details and the cover; the title, series, authors and genres are kept, and the old values stay in the History tab.{/tr}</p>
				<label><input type="checkbox" name="replace_description" value="1" form="editBookForm" /> {tr}Also replace the description{/tr}</label>
				<div><input type="submit" class="btn btn-warning" name="fReplaceIsbn" value="{tr}Replace details from this ISBN{/tr}" form="editBookForm" /></div>
			</div>
		{/if}

		{form id="editBookForm"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />

			{jstabs}
				{jstab title="{tr}Book{/tr}"}
					{legend legend="Book Details"}
						<div class="form-group">
							{formlabel label="Title" for="title"}
							{forminput}
								<input type="text" class="form-control" name="title" id="title" value="{$gContent->getTitle()|escape}" />
							{/forminput}
						</div>
						{* The same editor a contact's description uses; the text is HTML (paragraphs), a description still plain is shown converted. *}
						{textarea rows=12 edit=$descriptionHtml formatguid=$gContent->mInfo.format_guid}
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
				<input type="submit" class="btn btn-secondary" name="fReloadOpenLibrary" value="{tr}Reload from Open Library{/tr}" />
				<input type="submit" class="btn btn-secondary" name="fCheckIsbn" value="{tr}Check ISBN{/tr}" />
				<input type="submit" class="btn btn-secondary" name="fReloadWikidata" value="{tr}Reload from Wikidata{/tr}" />
				<a class="btn btn-secondary" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}pick_cover.php?content_id={$gContent->mContentId}">{tr}Find a Cover{/tr}</a>
			</div>
		{/form}
	</div><!-- end .body -->
</div><!-- end .fisheye -->
{/strip}

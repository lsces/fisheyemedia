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
				<a class="btn btn-secondary" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}look_up_details.php?content_id={$gContent->mContentId}">{tr}Look up details{/tr}</a>
			</div>
		{/form}
	</div><!-- end .body -->
</div><!-- end .fisheye -->
{/strip}

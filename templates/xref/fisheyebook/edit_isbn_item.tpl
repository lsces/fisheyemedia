{* Edit a book's ISBN: type either number, with or without hyphens - FisheyeBook::storeXref() checks it and stores the ISBN-13 and the ISBN-10. *}
{strip}
<div class="edit liberty">
	<div class="header">
		<h1>{tr}Edit{/tr}: {$gContent->getTitle()|escape}</h1>
	</div>
	<div class="body">
		{formfeedback error=$errors}
		{form id="editXrefForm"}
			<input type="hidden" name="content_id" value="{$xrefInfo.content_id|escape}" />
			<input type="hidden" name="xref_id"    value="{$xrefInfo.xref_id|escape}" />
			<input type="hidden" name="item"       value="{$xrefInfo.item|escape}" />

			<div class="form-group">
				{formlabel label="ISBN" for="isbn"}
				{forminput}
					<input type="text" class="form-control input-small" name="isbn" id="isbn" value="{if $xrefInfo.xkey ne ''}{$xrefInfo.xkey|escape}{else}{$xrefInfo.xkey_ext|escape}{/if}" />
					{formhelp note="An ISBN-13 or an ISBN-10, hyphens allowed. The other form is worked out and stored beside it. Then use Reload from Open Library on the book's page to fill in its details."}
				{/forminput}
			</div>

			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="fCancel"   value="{tr}Cancel{/tr}" />
				<input type="submit" class="btn btn-primary" name="fSaveXref" value="{tr}Save{/tr}" />
			</div>
		{/form}
	</div>
</div>
{/strip}

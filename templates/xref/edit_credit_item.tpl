{* Edit one credit row - see view_credit_item.tpl. Same form as liberty's edit_text_item.tpl, with the fields
   named for what a credit holds: the credited name, the contact it is linked to, its Wikidata id. *}
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
				{formlabel label="Type"}
				{forminput}
					<p class="form-control-static">{$xrefInfo.template_title|escape}</p>
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Credited as" for="xkey_ext"}
				{forminput}
					<input type="text" class="form-control input-small" name="xkey_ext" id="xkey_ext" value="{$xrefInfo.xkey_ext|escape}" />
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Contact (content id)" for="xref"}
				{forminput}
					<input type="number" min="0" class="form-control input-small" name="xref" id="xref" value="{if $xrefInfo.xref > 0}{$xrefInfo.xref|escape}{/if}" />
					{formhelp note="The contact this person is linked to - leave empty for none."}
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Wikidata id" for="xkey"}
				{forminput}
					<input type="text" class="form-control input-small" name="xkey" id="xkey" maxlength="32" value="{$xrefInfo.xkey|escape}" />
					{formhelp note="e.g. Q42 - the contact's Wikidata item."}
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Detail" for="edit"}
				{forminput}
					<textarea class="form-control" name="edit" id="edit" rows="3">{$xrefInfo.data|escape}</textarea>
					<p class="help-block">{tr}For a season: the episode numbers this person appears in, as{/tr} <code>{literal}{"episodes":[1,2,3]}{/literal}</code></p>
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

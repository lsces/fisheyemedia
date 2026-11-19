{* Fields for adding a book's ISBN (the add page; the edit page has its own form beside this): the number, either form, and a note saying which edition it is. FisheyeBook::storeXref() checks the number and stores the ISBN-13 and the ISBN-10. $xid prefixes the element ids so several item forms can sit on one page. *}
{strip}
{assign var=xid value=$xid|default:''}
<div class="form-group">
	{formlabel label="ISBN" for="`$xid`isbn"}
	{forminput}
		<input type="text" class="form-control input-small" name="isbn" id="{$xid}isbn" value="{if $xrefInfo.xkey ne ''}{$xrefInfo.xkey|escape}{else}{$xrefInfo.xkey_ext|escape}{/if}" />
		{formhelp note="An ISBN-13 or an ISBN-10, hyphens allowed. The other form is worked out and stored beside it."}
	{/forminput}
</div>

<div class="form-group">
	{formlabel label="Note" for="`$xid`edit"}
	{forminput}
		<input type="text" class="form-control input-small" name="edit" id="{$xid}edit" value="{$xrefInfo.data|escape}" />
		{formhelp note="Which edition this is, for example ebook or paperback. Optional."}
	{/forminput}
</div>
{/strip}

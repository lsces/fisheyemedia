{* A book's json-list rows. An ebook or extra row that is a PDF (a chapter held as a PDF) shows its drawn first page and carries Set as Thumbnail, which
   makes that page the book's cover (FisheyeBook::promoteImageToThumbnail()); every other row is liberty's own json-list row, unchanged. *}
{assign var=fileExt value=$xrefInfo.xkey_ext|default:''|lower|regex_replace:'/^.*\./':''}
{if ( $xrefInfo.item eq 'ebook' || $xrefInfo.item eq 'extra' ) && $fileExt eq 'pdf'}
{strip}
<td>{$xrefInfo.xref_title|escape}</td>
<td>
	<img src="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_book_file.php?xref_id={$xrefInfo.xref_id}&amp;thumb=1" alt="{$xrefInfo.xkey_ext|basename|escape}" style="max-height:90px; max-width:70px; float:left; margin-right:.75em;" />
	{assign var="jsonData" value=$xrefInfo.data|default:'null'|json_decode:true}
	{if $jsonData}
		<table class="table-condensed table-borderless" style="margin:0">
			{foreach $jsonData as $jkey => $jval}
				<tr><th style="padding-right:.5em">{$jkey|replace:'_':' '|capitalize}</th><td>{if is_array($jval)}{foreach $jval as $jv}{if is_array($jv)}{$jv|json_encode|escape}{else}{$jv|escape}{/if}{if !$jv@last}, {/if}{/foreach}{else}{$jval|escape}{/if}</td>
			{/foreach}
		</table>
	{/if}
</td>
<td>&nbsp;</td>
{include file="bitpackage:liberty/xref/dates_cell.tpl"}
{if $xrefAllowEdit|default:true}
<td>
	<span class="actionicon">
		{if $gContent->hasUpdatePermission() && !$isHistory}
			{smartlink ititle="Set as Thumbnail" ipackage="liberty" ifile="edit_xref.php" biticon="image-x-generic" content_id=$gContent->mInfo.content_id xref_id=$xrefInfo.xref_id fSetAsThumbnail=1}
		{/if}
		{if $gContent->hasUpdatePermission() && !$isHistory && $xrefInfo.multiple neq -1}
			{smartlink ititle="Edit" ipackage="liberty" ifile="edit_xref.php" biticon="edit" content_id=$gContent->mInfo.content_id xref_id=$xrefInfo.xref_id}
		{/if}
		{if $gContent->hasUpdatePermission() && !$xrefProtected|default:false}
			{if $isHistory}
				{smartlink ititle="Restore" ipackage="liberty" ifile="edit_xref.php" biticon="edit" content_id=$gContent->mInfo.content_id xref_id=$xrefInfo.xref_id expunge=-1}
			{else}
				{smartlink ititle="Archive" ipackage="liberty" ifile="edit_xref.php" biticon="archive-insert" content_id=$gContent->mInfo.content_id xref_id=$xrefInfo.xref_id expunge=1}
			{/if}
		{/if}
		{if $gContent->hasExpungePermission() && !$xrefProtected|default:false}
			{smartlink ititle="Delete" ipackage="liberty" ifile="edit_xref.php" biticon="user-trash" content_id=$gContent->mInfo.content_id xref_id=$xrefInfo.xref_id expunge=3}
		{/if}
	</span>
</td>
{/if}
{/strip}
{else}
{include file="bitpackage:liberty/xref/view_json-list_item.tpl"}
{/if}

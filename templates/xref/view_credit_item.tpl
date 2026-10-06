{* A credit row (director/writer/star on a film, program or season): the credited name linked to its
   contact through xref, the contact's Wikidata item through xkey (a Q-id), and `data` pulled apart
   (a season's episode numbers). Same cells as every xref item template: type, value, notes, dates, actions. *}
{strip}
<td>{$xrefInfo.xref_title|escape}</td>
<td>
	{if isset($xrefInfo.xref) && $xrefInfo.xref > 0}
		<a href="{$smarty.const.BIT_ROOT_URL}index.php?content_id={$xrefInfo.xref|escape}">{$xrefInfo.xkey_ext|escape}</a>
	{else}
		{$xrefInfo.xkey_ext|escape}
	{/if}
	{if $xrefInfo.xkey && $xrefInfo.xkey|truncate:1:'' == 'Q'}
		<a class="small text-muted" href="https://www.wikidata.org/wiki/{$xrefInfo.xkey|escape}" target="_blank" rel="noopener">{$xrefInfo.xkey|escape}</a>
	{/if}
</td>
<td>
	{assign var="jsonData" value=$xrefInfo.data|default:'null'|json_decode:true}
	{if $jsonData}
		{foreach $jsonData as $jkey => $jval}
			{if $jkey == 'episodes'}{tr}Episodes{/tr}: {else}{$jkey|replace:'_':' '|capitalize}: {/if}{if is_array($jval)}{$jval|@implode:', '|escape}{else}{$jval|escape}{/if}{if !$jval@last}; {/if}
		{/foreach}
	{/if}
</td>
{include file="bitpackage:liberty/xref/dates_cell.tpl"}
{include file="bitpackage:liberty/xref/action_icons.tpl"}
{/strip}

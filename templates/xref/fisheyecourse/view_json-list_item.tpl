{* A season's json-list rows (featurettes, extras): an 'extra' row - a file that goes with a Local Tutorials section - shows the file's type icon and
   name as the download link, with its size; every other row is liberty's own json-list row, unchanged. *}
{if $xrefInfo.item eq 'extra'}
{strip}
{assign var=extraData value=$xrefInfo.data|default:'null'|json_decode:true}
{assign var=extraName value=$xrefInfo.xkey_ext|basename}
<td>{$xrefInfo.xref_title|escape}</td>
<td>
	<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_season_file.php?xref_id={$xrefInfo.xref_id}" title="{tr}Download{/tr}">
		<img src="{$gContent->getFileThumbUrl($xrefInfo.xref_id, $extraName)|escape}" alt="{$extraData.format|default:''|escape}" style="height:48px; width:48px; vertical-align:middle;" /> {$extraName|escape}
	</a>
	{if $extraData.size|default:''}<span class="text-muted">({$extraData.size|escape})</span>{/if}
</td>
<td>{$extraData.note|default:''|escape}</td>
{include file="bitpackage:liberty/xref/dates_cell.tpl"}
{include file="bitpackage:liberty/xref/action_icons.tpl"}
{/strip}
{else}
{include file="bitpackage:liberty/xref/view_json-list_item.tpl"}
{/if}

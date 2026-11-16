{* A book's ISBN row: the ISBN-13 (the edition key, xkey) and the ISBN-10 beside it (xkey_ext, empty for a 979- number). *}
{strip}
<td>{$xrefInfo.xref_title|escape}</td>
<td>{if $xrefInfo.xkey ne ''}{$xrefInfo.xkey|escape}{else}{$xrefInfo.xkey_ext|escape}{/if}</td>
<td>{if $xrefInfo.xkey ne '' && $xrefInfo.xkey_ext ne ''}{tr}ISBN-10{/tr} {$xrefInfo.xkey_ext|escape}{/if}</td>
{include file="bitpackage:liberty/xref/dates_cell.tpl"}
{include file="bitpackage:liberty/xref/action_icons.tpl"}
{/strip}

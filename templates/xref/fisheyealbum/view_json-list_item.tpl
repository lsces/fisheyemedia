{* Album-specific override of liberty's own generic view_json-list_item.tpl (see
   FisheyeContent::getXrefRecordTemplate() - a content-type-specific template under this exact
   fisheyealbum/ subfolder wins over the generic one) - first column shows the track's own
   Disc-Track position instead of its generic auto-numbered xref_title, since that's what actually
   identifies a track in a listing. 'disc' is only present in the stored data at all when the album
   is genuinely multi-disc (see FisheyeAlbum::registerFromDisk()/reloadTracks() - a single-disc
   album never stores it, so this falls back to a bare track number with no "1-" prefix). *}
{strip}
{assign var="jsonData" value=$xrefInfo.data|default:'null'|json_decode:true}
<td>
	{if $jsonData.track}
		{if $jsonData.disc}{$jsonData.disc}-{/if}{$jsonData.track|string_format:"%02d"}
	{else}
		{$xrefInfo.xref_title|escape}
	{/if}
</td>
<td>
	{if $jsonData}
		<table class="table-condensed table-borderless" style="margin:0">
			{foreach $jsonData as $jkey => $jval}
				{if $jkey neq 'track' && $jkey neq 'disc'}
					<tr><th style="padding-right:.5em">{$jkey|replace:'_':' '|capitalize}</th><td>{$jval|escape}</td></tr>
				{/if}
			{/foreach}
		</table>
	{else}
		&nbsp;
	{/if}
</td>
<td>&nbsp;</td>
{include file="bitpackage:liberty/xref/dates_cell.tpl"}
{include file="bitpackage:liberty/xref/action_icons.tpl"}
{/strip}

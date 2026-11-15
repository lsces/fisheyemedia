{* One folder of the archive scan, and its children - see archive_scan.tpl. *}
<details{if $level < 1} open="open"{/if}>
	<summary>{$node.name|escape} <span class="text-muted">{$node.kind} - {$node.docs} {tr}documents{/tr}{if $node.pictures}, {$node.pictures} {tr}pictures{/tr}{/if}{if $node.extras}, {$node.extras} {tr}extra files{/tr}{/if}</span></summary>
	<div style="margin-left:1.5em">
		{foreach from=$node.children item=child}
			{include file="bitpackage:fisheyemedia/archive_scan_node.tpl" node=$child level=$level+1}
		{/foreach}
		{if $node.loose}<div class="text-muted">{$node.loose|@count} {tr}loose documents{/tr}: {foreach from=$node.loose item=l name=ll}{$l.name|escape}{if !$smarty.foreach.ll.last}, {/if}{/foreach}</div>{/if}
	</div>
</details>

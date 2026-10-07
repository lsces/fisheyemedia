{* A list of credited names, comma separated, each linked to its contact where one is known ($creditUrls: name => url, see
   FisheyeCredits::urlsForNames()). Pass the names as names=...; an optional limit=N shows the first N then 'and M more'. *}
{strip}
{foreach from=$names item=creditName name=creditNames}{if !$limit || $smarty.foreach.creditNames.iteration <= $limit}{if !$smarty.foreach.creditNames.first}, {/if}{if $creditUrls.$creditName}<a href="{$creditUrls.$creditName|escape}">{$creditName|escape}</a>{else}{$creditName|escape}{/if}{/if}{/foreach}{if $limit && $names|@count > $limit} <span class="more-credits">{tr}and{/tr} {$names|@count - $limit} {tr}more{/tr}</span>{/if}
{/strip}

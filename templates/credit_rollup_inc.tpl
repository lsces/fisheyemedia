{* A show's rolled-up credits for one role: people = list of name/url/episodes/seasons (FisheyeCredits::programRollup()), most episodes first.
   The first `limit` are shown, linked to their contact where there is one (the hover gives episodes and seasons); the rest sit behind an
   "and N more" link that opens them in place. *}
{strip}
{foreach from=$people item=p name=rp}{if $smarty.foreach.rp.iteration <= $limit}{if !$smarty.foreach.rp.first}, {/if}{if $p.url}<a href="{$p.url|escape}" title="{$p.episodes} {if $p.episodes == 1}{tr}episode{/tr}{else}{tr}episodes{/tr}{/if}, {$p.seasons} {if $p.seasons == 1}{tr}season{/tr}{else}{tr}seasons{/tr}{/if}">{$p.name|escape}</a>{else}<span title="{$p.episodes} {if $p.episodes == 1}{tr}episode{/tr}{else}{tr}episodes{/tr}{/if}, {$p.seasons} {if $p.seasons == 1}{tr}season{/tr}{else}{tr}seasons{/tr}{/if}">{$p.name|escape}</span>{/if}{/if}{/foreach}
{if $people|@count > $limit}
 <span class="credit-more"><a href="#" onclick="this.style.display='none';this.nextElementSibling.style.display='inline';return false;">{tr}and{/tr} {$people|@count - $limit} {tr}more{/tr}</a><span style="display:none">{foreach from=$people item=p name=rest}{if $smarty.foreach.rest.iteration > $limit}, {if $p.url}<a href="{$p.url|escape}" title="{$p.episodes} {if $p.episodes == 1}{tr}episode{/tr}{else}{tr}episodes{/tr}{/if}, {$p.seasons} {if $p.seasons == 1}{tr}season{/tr}{else}{tr}seasons{/tr}{/if}">{$p.name|escape}</a>{else}<span title="{$p.episodes} {if $p.episodes == 1}{tr}episode{/tr}{else}{tr}episodes{/tr}{/if}, {$p.seasons} {if $p.seasons == 1}{tr}season{/tr}{else}{tr}seasons{/tr}{/if}">{$p.name|escape}</span>{/if}{/if}{/foreach}</span></span>
{/if}
{/strip}

{strip}
<div class="display fisheye">
	<header>
		<h1><a href="{$gContent->getDisplayUrl()|escape}">{$gContent->getTitle()|escape}</a> - {tr}Extras{/tr}</h1>
	</header>
	<div class="body">
		{include file="bitpackage:fisheyemedia/extras_list_inc.tpl"}
	</div>
</div>
{/strip}

{strip}
{if $packageMenuTitle}<a href="#"> {tr}{$packageMenuTitle|capitalize}{/tr}</a>{/if}
<ul class="{$packageMenuClass}">
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}admin/admin_fisheyemedia_settings.php">{tr}Media Library Settings{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}admin/admin_import_film.php">{tr}Import Film{/tr}</a></li>
</ul>
{/strip}

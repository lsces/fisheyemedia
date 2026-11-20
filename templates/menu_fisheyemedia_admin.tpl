{strip}
{if $packageMenuTitle}<a href="#"> {tr}{$packageMenuTitle|capitalize}{/tr}</a>{/if}
<ul class="{$packageMenuClass}">
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}admin/admin_fisheyemedia_settings.php">{tr}Media Library Settings{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}archive_scan.php">{tr}Archive Workbench{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}apply_access.php">{tr}Gallery access{/tr}</a></li>
	<li class="divider"></li>
	<li class="dropdown-header">{tr}Folder archive workbenches{/tr}</li>
	{foreach ''|archive_entries as $entry}
		<li><a class="item" href="{$entry.url|escape}">{tr}{$entry.title}{/tr}</a></li>
	{/foreach}
	<li class="divider"></li>
	<li class="dropdown-header">{tr}Library books{/tr}</li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}author_workbench.php">{tr}Author workbench{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}series_workbench.php">{tr}Series workbench{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}add_books.php">{tr}Add Books from ISBNs{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}list_no_isbn.php">{tr}Books with no ISBN{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_details.php?nocover=1">{tr}Book covers and details{/tr}</a></li>
	<li class="divider"></li>
	<li class="dropdown-header">{tr}Authors, contacts and sheets{/tr}</li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_authors.php">{tr}Authors and contacts{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}link_book_credits.php">{tr}Link credits to contacts{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_books.php">{tr}Books from a spreadsheet{/tr}</a></li>
	<li class="divider"></li>
	<li class="dropdown-header">{tr}Films, shows and tutorials{/tr}</li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}admin/admin_import_film.php">{tr}Import Film{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_local.php">{tr}Load Local Videos{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}tutorial_workbench.php">{tr}Tutorial workbench{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_program_links.php">{tr}BBC program links{/tr}</a></li>
</ul>
{/strip}

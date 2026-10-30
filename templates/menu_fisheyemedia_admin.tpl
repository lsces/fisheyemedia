{strip}
{if $packageMenuTitle}<a href="#"> {tr}{$packageMenuTitle|capitalize}{/tr}</a>{/if}
<ul class="{$packageMenuClass}">
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}admin/admin_fisheyemedia_settings.php">{tr}Media Library Settings{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}admin/admin_import_film.php">{tr}Import Film{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_local.php">{tr}Load Local Videos{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_authors.php">{tr}Book Authors from OL{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_books.php">{tr}Add books from a sheet{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_details.php">{tr}Book Details from OL{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_covers.php">{tr}Book Covers from a Gallery{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}link_book_credits.php">{tr}Link Books{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}add_books.php">{tr}Add Books from ISBNs{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}list_no_isbn.php">{tr}Books with no ISBN{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_files.php">{tr}Book Files from the Library{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}series_workbench.php">{tr}Series workbench{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_various_authors.php">{tr}Various Authors series{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_program_links.php">{tr}BBC program links{/tr}</a></li>
</ul>
{/strip}

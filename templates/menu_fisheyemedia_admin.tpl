{strip}
{if $packageMenuTitle}<a href="#"> {tr}{$packageMenuTitle|capitalize}{/tr}</a>{/if}
<ul class="{$packageMenuClass}">
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}admin/admin_fisheyemedia_settings.php">{tr}Media Library Settings{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}admin/admin_import_film.php">{tr}Import Film{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_local.php">{tr}Load Local Videos{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_authors.php">{tr}Book Authors from Open Library{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_books.php">{tr}Load Books from a Catalogue{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_details.php">{tr}Book Details from Open Library{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_covers.php">{tr}Book Covers from a Gallery{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}link_book_credits.php">{tr}Link Book Credits to Contacts{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}add_books.php">{tr}Add Books from ISBNs{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}list_no_isbn.php">{tr}Books with no ISBN{/tr}</a></li>
</ul>
{/strip}

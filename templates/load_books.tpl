{* Loads books from an uploaded catalogue spreadsheet - see load_books.php's own docblock for the
   filing rule (an author gallery for two or more books, a category gallery otherwise). *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{if $topGalleryUrl}<a href="{$topGalleryUrl|escape}">{tr}Library{/tr}</a> - {/if}{tr}Add books from a sheet{/tr}</h1>
	</div>

	<div class="body">

		{if !$hasTopGallery}
			<div class="alert alert-danger"><p>{tr}There is no top-level Library gallery yet - apply the media xref scheme so it is created.{/tr}</p></div>
		{/if}

		{foreach from=$results item=result}
			{if $result.errors}
				<div class="alert alert-danger">
					<p>{tr}Failed{/tr}:</p>
					<ul>{foreach from=$result.errors item=error}<li>{$error|escape}</li>{/foreach}</ul>
				</div>
			{/if}
			{if $result.group}
				<div class="alert alert-{if $result.errors || !$result.created}info{else}success{/if}"><p>{$result.group|escape}: {$result.created} {tr}loaded{/tr}{if $result.already}, {$result.already} {tr}already there{/tr}{/if}.{if $result.group_url} <a href="{$result.group_url|escape}">{tr}Open the gallery{/tr}</a>{/if}</p></div>
				{if $result.series}<p>{tr}Filed in series{/tr}: {foreach from=$result.series key=seriesName item=count name=sr}{$seriesName|escape} ({$count}){if !$smarty.foreach.sr.last}, {/if}{/foreach}.</p>{/if}
				{if $result.details}
					<ul class="list-unstyled">{foreach from=$result.details item=detail}<li><a href="{$detail.url|escape}">{$detail.title|escape}</a> - {if $detail.matched}{tr}details from Open Library{/tr}{else}{tr}no Open Library record{/tr}{/if}{if $detail.error}: {$detail.error|escape}{/if}</li>{/foreach}</ul>
				{/if}
			{/if}
		{/foreach}

		{if $reviewKey && !$authorGalleryId}
			<p>{if $reviewUrl}<a href="{$reviewUrl|escape}">&laquo; {$reviewName|escape}</a> | {/if}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_books.php">{tr}Other authors in this sheet{/tr}</a></p>
		{/if}
		{if $authorGalleryId}
			<p><a href="{$authorGalleryUrl|escape}">&laquo; {$authorGalleryTitle|escape}</a></p>
			{if $filedHere}<div class="alert alert-info"><p>{$filedHere} {tr}books already loaded under another author's name were filed in this gallery too.{/tr}</p></div>{/if}
		{else}
			{include file="bitpackage:fisheyemedia/catalogue_upload.tpl"}
		{/if}

		{if $catalogueRows}
			<p>{tr}Catalogue{/tr}: {$catalogueRows} {tr}books in{/tr} {$groupCount} {tr}groups{/tr} ({$catalogueFile|escape}, {tr}uploaded{/tr} {$catalogueImported|bit_short_datetime}). {tr}An author with{/tr} {$minAuthorBooks}+ {tr}books gets a gallery of their own; everyone else is filed under the book's Category.{/tr}</p>

			{if $authorGalleryId}
				{form legend="Series" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_books.php"}
					<input type="hidden" name="gallery_id" value="{$authorGalleryId}" />
					<input type="hidden" name="series_form" value="1" />
					<div class="form-group">
						<label><input type="checkbox" name="use_series" value="1"{if $useSeries} checked="checked"{/if} /> {tr}File books into their series as they are loaded (series from Wikidata){/tr}</label>
					</div>
					{if $seriesPlan}
						{foreach from=$seriesPlan.umbrellas item=umbrella}
							<div class="form-group">
								<label><input type="checkbox" name="split[{$umbrella.key|escape}]" value="1"{if in_array($umbrella.key, $seriesPlan.split)} checked="checked"{/if} /> {tr}Keep the sub-series of{/tr} {$umbrella.name|escape} {tr}separate{/tr}</label>
								<span class="help-block">{foreach from=$umbrella.children item=child name=ch}{$child|escape}{if !$smarty.foreach.ch.last}, {/if}{/foreach}. {tr}Left unticked they are folded into{/tr} {$umbrella.name|escape}.</span>
							</div>
						{/foreach}
					{elseif $seriesWikidataFailed}
						<p class="help-block">{tr}Wikidata could not be asked, or the author's contact has no Wikidata id - books are filed in the author's own list.{/tr}</p>
					{/if}
					<div class="form-group submit">
						<input type="submit" class="btn btn-default" name="series_update" value="{tr}Update{/tr}" />
					</div>
				{/form}
			{/if}
			{if ( $authorGalleryId || $reviewKey ) && $bookList}
				{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_books.php"}
					{if $authorGalleryId}<input type="hidden" name="gallery_id" value="{$authorGalleryId}" />{/if}
					{if $reviewKey}<input type="hidden" name="review_group" value="{$reviewKey|escape}" />{/if}
					<input type="hidden" name="series_form" value="1" />
					<input type="hidden" name="picked_form" value="1" />
					{if $useSeries}<input type="hidden" name="use_series" value="1" />{/if}
					{if $seriesPlan}{foreach from=$seriesPlan.split item=splitKey}<input type="hidden" name="split[{$splitKey|escape}]" value="1" />{/foreach}{/if}
					<h3>{if $reviewKey}{tr}Read from the sheet{/tr}: {$reviewName|escape}{else}{tr}This author's books still to load{/tr}{/if}</h3>
					<p class="help-block">{$booksLoaded}/{$booksTotal} {tr}loaded. Tick the books to load; at most{/tr} {$batchSize} {tr}are loaded per press, and loaded books drop off this list.{/tr}</p>
					<table class="table table-condensed">
						<thead><tr><th></th><th>{tr}Title{/tr}</th><th>{tr}Published{/tr}</th><th>{tr}With{/tr}</th><th>{tr}Series{/tr}</th><th>{tr}Look for it{/tr}</th><th>{tr}ISBN{/tr}</th></tr></thead>
						<tbody>
							{foreach from=$bookList item=book}
								<tr>
									<td><input type="checkbox" name="book[]" value="{$book.index}"{if $book.ticked} checked="checked"{/if} /></td>
									<td>{$book.title|escape}</td>
									<td>{$book.year|escape}</td>
									<td>{foreach from=$book.coauthors item=other name=co}{$other|escape}{if !$smarty.foreach.co.last}, {/if}{/foreach}</td>
									<td>{$book.series|escape}</td>
									<td>
										<a href="https://www.abebooks.co.uk/servlet/SearchResults?tn={$book.titleTerm}{if $book.authorTerm}&amp;an={$book.authorTerm}{/if}" target="_blank" rel="noopener">AbeBooks</a> |
										<a href="https://openlibrary.org/search?q={$book.terms}" target="_blank" rel="noopener">Open Library</a> |
										<a href="https://www.google.com/search?tbm=bks&amp;q={$book.terms}" target="_blank" rel="noopener">Google Books</a> |
											<a href="#" class="isbn-suggest" data-title="{$book.titleTerm}" data-author="{$book.authorTerm}">{tr}Suggest{/tr}</a>
											<div class="isbn-options"></div>
									</td>
									<td><input type="text" class="form-control" name="isbn[{$book.index}]" value="{$book.isbn|escape}" placeholder="{tr}ISBN-10 or ISBN-13{/tr}" size="16" /></td>
								</tr>
							{/foreach}
						</tbody>
					</table>
					<div class="form-group submit">
						<button type="submit" class="btn btn-primary" name="process_group" value="{if $reviewKey}{$reviewKey|escape}{else}{$onlyKey|escape}{/if}"{if !$hasTopGallery} disabled="disabled"{/if}>{tr}Load the ticked books{/tr}</button>
					</div>
				{/form}
			{/if}
			{if $authorGalleryId && $coCredited}
				<h3>{tr}Co-written - filed here from the primary author's page{/tr}</h3>
				<ul class="list-unstyled">{foreach from=$coCredited item=book}<li>{$book.title|escape} ({tr}primary author{/tr}: {$book.primary|escape}) - {if $book.loaded}{tr}loaded{/tr}{else}{tr}not loaded yet{/tr}{/if}</li>{/foreach}</ul>
			{/if}

			{if $reviewKey && !$authorGalleryId}
				{if !$bookList}
					<div class="alert alert-success">
						<p><strong>{tr}Completed{/tr}</strong>: {if $reviewUrl}<a href="{$reviewUrl|escape}">{$reviewName|escape}</a>{else}{$reviewName|escape}{/if} - {$booksLoaded} {tr}of{/tr} {$booksTotal} {tr}books are loaded.{/tr}
						<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}list_no_isbn.php?author={$reviewName|escape:'url'}&amp;newest=1">{tr}Books with no ISBN for this author{/tr}</a></p>
					</div>
				{/if}
				<p><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_books.php">{tr}Back to the list of groups{/tr}</a></p>
			{elseif $authorGalleryId}
				{if !$bookList}
					<p>{if $authorInCatalogue}{tr}Every book on the catalogue for this author is already loaded.{/tr}{else}{tr}This author has no books of their own as primary author - books they co-wrote are filed here when the primary author's books are loaded.{/tr}{/if}</p>
				{/if}
			{elseif $allTotal}
				<form method="get" action="{$smarty.server.SCRIPT_NAME}" class="form-inline">
					<input type="text" class="form-control" name="find" value="{$findText|escape}" placeholder="{tr}Author or title (any part){/tr}" />
					<input type="submit" class="btn btn-default" value="{tr}Show{/tr}" />
					{if $findText}<a href="{$smarty.server.SCRIPT_NAME}">{tr}Show all{/tr}</a>{/if}
				</form>
				<p>{$allLoaded}/{$allTotal} {tr}of the books read from the sheet are loaded. Tick the books to load, one line at a time as you deal with each: look the ISBN up with the links and type it in, then Load. At most{/tr} {$batchSize} {tr}are loaded per press; use the find box to work through one author, and loaded books drop off this list.{/tr}</p>
				{if $allList}
					{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_books.php"}
						<input type="hidden" name="series_form" value="1" />
						<input type="hidden" name="find" value="{$findText|escape}" />
						{if $useSeries}<input type="hidden" name="use_series" value="1" />{/if}
						<table class="table table-condensed">
							<thead><tr><th></th><th>{tr}Title{/tr}</th><th>{tr}Author{/tr}</th><th>{tr}Year{/tr}</th><th>{tr}Look for it{/tr}</th><th>{tr}ISBN{/tr}</th></tr></thead>
							<tbody>
								{foreach from=$allList item=book}
									<tr>
										<td><input type="checkbox" name="book[]" value="{$book.id}" /></td>
										<td>{$book.title|escape}</td>
										<td><a href="{$smarty.server.SCRIPT_NAME}?find={$book.group|escape:'url'}">{$book.authors|escape}</a>{if $book.groupUrl} <a href="{$book.groupUrl|escape}" title="{tr}Open the gallery{/tr}">&raquo;</a>{/if}</td>
										<td>{$book.year|escape}</td>
										<td>
											<a href="https://www.abebooks.co.uk/servlet/SearchResults?tn={$book.titleTerm}{if $book.authorTerm}&amp;an={$book.authorTerm}{/if}" target="_blank" rel="noopener">AbeBooks</a> |
											<a href="https://openlibrary.org/search?q={$book.terms}" target="_blank" rel="noopener">Open Library</a> |
											<a href="https://www.google.com/search?tbm=bks&amp;q={$book.terms}" target="_blank" rel="noopener">Google Books</a> |
											<a href="#" class="isbn-suggest" data-title="{$book.titleTerm}" data-author="{$book.authorTerm}">{tr}Suggest{/tr}</a>
											<div class="isbn-options"></div>
										</td>
										<td><input type="text" class="form-control" name="isbn[{$book.id}]" value="{$book.isbn|escape}" placeholder="{tr}ISBN-10 or ISBN-13{/tr}" size="16" /></td>
									</tr>
								{/foreach}
							</tbody>
						</table>
						<div class="form-group submit">
							<button type="submit" class="btn btn-primary" name="process_all" value="1"{if !$hasTopGallery} disabled="disabled"{/if}>{tr}Load the ticked books{/tr}</button>
						</div>
					{/form}
				{else}
					{if $findText}<p>{tr}Nothing in the sheet matches that - or every matching book is already loaded.{/tr}</p>
					{else}<div class="alert alert-success"><p><strong>{tr}Completed{/tr}</strong>: {$allLoaded} {tr}of{/tr} {$allTotal} {tr}books read from the sheet are loaded.{/tr} <a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}list_no_isbn.php?newest=1">{tr}Books with no ISBN{/tr}</a></p></div>{/if}
				{/if}
			{else}
				<p>{tr}Nothing left to load - every book in the catalogue is already there.{/tr}</p>
			{/if}
		{/if}

	</div>
</div>
{literal}<script>
/* Suggest: the first few Open Library editions for a row's title and author. They are fetched for the first ten rows with no ISBN when the page
   opens, one after another (Open Library asks for gentle use), and by the row's own Suggest link. Picking one fills the ISBN box, and any ISBN in
   the box - picked or typed - ticks the row; an emptied box unticks it. */
(function () {
	var url = '{/literal}{$smarty.const.FISHEYEMEDIA_PKG_URL}{literal}suggest_isbn.php';
	function rowInput(el) { return el.closest('tr').querySelector('input[name^="isbn"]'); }
	function rowTick(el) { return el.closest('tr').querySelector('input[type="checkbox"]'); }
	function tickFor(input) { var box = rowTick(input); if (box) { box.checked = input.value.trim() !== ''; } }
	function suggest(link) {
		var box = link.parentNode.querySelector('.isbn-options');
		box.textContent = 'Asking Open Library...';
		return fetch(url + '?title=' + link.dataset.title + '&author=' + link.dataset.author, { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				box.textContent = '';
				if (!data.matches || !data.matches.length) { box.textContent = 'No matches found.'; return; }
				data.matches.forEach(function (m) {
					var a = document.createElement('a');
					a.href = '#'; a.className = 'isbn-pick'; a.dataset.isbn = m.isbn;
					a.textContent = m.title + (m.authors ? ' - ' + m.authors : '') + (m.year ? ' (' + m.year + ')' : '') + '  ' + m.isbn;
					var d = document.createElement('div'); d.appendChild(a); box.appendChild(d);
				});
			})
			.catch(function () { box.textContent = 'Open Library did not answer.'; });
	}
	document.addEventListener('click', function (e) {
		var link = e.target.closest('.isbn-suggest');
		var pick = e.target.closest('.isbn-pick');
		if (link) {
			e.preventDefault();
			suggest(link);
		} else if (pick) {
			e.preventDefault();
			var input = rowInput(pick);
			if (input) { input.value = pick.dataset.isbn; tickFor(input); }
		}
	});
	document.addEventListener('input', function (e) {
		if (e.target.matches && e.target.matches('input[name^="isbn"]')) { tickFor(e.target); }
	});
	document.addEventListener('DOMContentLoaded', function () {
		var todo = Array.prototype.filter.call(document.querySelectorAll('.isbn-suggest'), function (l) { var i = rowInput(l); return i && i.value.trim() === ''; }).slice(0, 10);
		(function next() {
			var l = todo.shift();
			if (l) { suggest(l).then(function () { setTimeout(next, 400); }); }
		})();
	});
})();
</script>{/literal}
{/strip}

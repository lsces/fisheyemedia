{* The books with no ISBN - see list_no_isbn.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Books with no ISBN{/tr} ({$books|@count})</h1>
	</div>

	<div class="body">
		{if $message}<div class="alert alert-success"><p>{$message|escape}</p></div>{/if}
		{formfeedback error=$errors}

		{if $books}
			<p>{tr}Find the ISBN on a bookseller's page or in a catalogue and paste it in; it is checked and kept as ISBN-13 and ISBN-10, and the book then leaves this list. A book with no ISBN at all (older than the number) can have its cover made or found from Find a Cover.{/tr}</p>
			<table class="table table-condensed">
				<thead><tr><th>{tr}Title{/tr}</th><th>{tr}Author{/tr}</th><th>{tr}Year{/tr}</th><th>{tr}Look for it{/tr}</th><th>{tr}ISBN{/tr}</th><th></th></tr></thead>
				<tbody>
					{foreach from=$books item=book}
						<tr>
							<td><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_book.php?content_id={$book.content_id}">{$book.title|escape}</a></td>
							<td>{foreach from=$book.authors item=author name=au}{$author|escape}{if !$smarty.foreach.au.last}, {/if}{/foreach}</td>
							<td>{$book.year|escape}</td>
							<td>
								<a href="https://www.abebooks.co.uk/servlet/SearchResults?tn={$book.titleTerm}{if $book.authorTerm}&amp;an={$book.authorTerm}{/if}" target="_blank" rel="noopener">AbeBooks</a> |
								<a href="https://openlibrary.org/search?q={$book.terms}" target="_blank" rel="noopener">Open Library</a> |
								<a href="https://www.google.com/search?tbm=bks&amp;q={$book.terms}" target="_blank" rel="noopener">Google Books</a>
							</td>
							<td>
								{form}
									<input type="hidden" name="content_id" value="{$book.content_id}" />
									<div class="input-group">
										<input type="text" class="form-control" name="isbn" value="" placeholder="{tr}ISBN-10 or ISBN-13{/tr}" />
										<span class="input-group-btn"><input type="submit" class="btn btn-primary" name="add_isbn" value="{tr}Add ISBN{/tr}" /></span>
									</div>
								{/form}
							</td>
							<td>
								<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}pick_cover.php?content_id={$book.content_id}">{tr}Find a Cover{/tr}</a>&nbsp;
								<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_book.php?content_id={$book.content_id}">{tr}Edit{/tr}</a>
							</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
		{else}
			<div class="alert alert-success"><p>{tr}Every book has an ISBN.{/tr}</p></div>
		{/if}
	</div>
</div>
{/strip}

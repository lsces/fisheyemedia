{* Book Files from the Library - see load_book_files.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Book Files from the Library{/tr}</h1>
	</div>

	<div class="body">
		{if $message}<div class="alert alert-success"><p>{$message|escape}</p></div>{/if}
		{formfeedback error=$errors}

		{if !$libraryRoot}
			<div class="alert alert-warning"><p>{tr}No Library folder is set: give the Book Library Storage Path in Media Library Settings.{/tr}</p></div>
		{else}
			<p>{tr}Library folder{/tr}: <code>{$libraryRoot|escape}</code></p>
			<form method="get" action="{$smarty.server.SCRIPT_NAME}" class="form-inline">
				<input type="text" class="form-control" name="find" value="{$find|escape}" placeholder="{tr}Author or title (any part){/tr}" />
				<label><input type="checkbox" name="todo" value="1"{if $onlyTodo} checked="checked"{/if} /> {tr}Only books with no files registered yet{/tr}</label>
				<input type="submit" class="btn btn-default" value="{tr}Show{/tr}" />
			</form>
			<p>{tr}Each book's folder is found from its series and author galleries. Books whose folder holds files are ticked; Scan writes their ebook, audio book and other files as Extras (a rebuild of just those, at most{/tr} {$batch} {tr}per press). Pictures and cover files are left alone. Showing at most{/tr} {$listLimit}.</p>
			{if $books}
				{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`load_book_files.php"}
					<input type="hidden" name="find" value="{$find|escape}" />
					{if $onlyTodo}<input type="hidden" name="todo" value="1" />{/if}
					<table class="table table-condensed">
						<thead><tr><th></th><th>{tr}Book{/tr}</th><th>{tr}Author{/tr}</th><th>{tr}Folder{/tr}</th><th>{tr}Ebooks{/tr}</th><th>{tr}Audio{/tr}</th><th>{tr}Other{/tr}</th><th>{tr}Registered{/tr}</th></tr></thead>
						<tbody>
							{foreach from=$books item=book}
								<tr{if !$book.folder} class="warning"{/if}>
									<td>{if $book.folder}<input type="checkbox" name="book[]" value="{$book.id}"{if $book.ebook + $book.audiobook + $book.extra} checked="checked"{/if} />{/if}</td>
									<td><a href="{$book.url|escape}">{$book.title|escape}</a></td>
									<td>{$book.authors|escape}</td>
									<td>{if $book.folder}{$book.folder|escape}{else}{tr}no folder found{/tr}{/if}</td>
									<td>{$book.ebook}</td><td>{$book.audiobook}</td><td>{$book.extra}</td><td>{$book.have}</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
					<input type="submit" class="btn btn-primary" name="scan" value="{tr}Scan the ticked books{/tr}" />
				{/form}
			{else}
				<p class="norecords">{tr}No books to show.{/tr}</p>
			{/if}
			{if $find}
				<h2>{tr}Folders with no book record{/tr} ({$unmatched|@count})</h2>
				{if $unmatched}
					<p>{tr}Under the matching authors, folders that none of the books above resolved to - candidates for the catalogue sheets, or a folder named differently from its book.{/tr}</p>
					<ul>{foreach from=$unmatched item=folder}<li>{$folder|escape}</li>{/foreach}</ul>
				{else}
					<p class="norecords">{tr}None - every folder under the matching authors belongs to a book above.{/tr}</p>
				{/if}
			{/if}
		{/if}
	</div>
</div>
{/strip}

{* Author workbench - see author_workbench.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{if $st.galleryUrl}<a href="{$st.galleryUrl|escape}" title="{tr}Open the author's gallery{/tr}">{$st.name|escape}</a>{else}{$st.name|escape}{/if} - {tr}Author workbench{/tr}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}
		{foreach from=$messages item=message}
			<div class="alert alert-success"><p>{$message|escape}</p></div>
		{/foreach}

		{if $st.error}
			<div class="alert alert-danger"><p>{$st.error|escape}</p></div>
		{else}
			{if $st.folder}
				<p class="help-block">{tr}Library folder{/tr}: <code>{$st.folder|escape}</code>{if $st.done} - <strong>{tr}Finished{/tr}</strong>: {tr}every step is done.{/tr}{/if}</p>
			{else}
				<div class="alert alert-warning"><p>{tr}No Library folder was found for this author, so the series cannot be read from folders and there are no files to load.{/tr}</p></div>
			{/if}

			{* ---- 1. Books ---- *}
			<h3>1. {tr}Books{/tr}{if $st.hasCatalogue} <small>{$st.loaded} {tr}of{/tr} {$st.total} {tr}loaded{/tr}</small>{/if}</h3>
			{include file="bitpackage:fisheyemedia/catalogue_drift_inc.tpl" catalogue=$st.catalogue actionUrl="`$smarty.const.FISHEYEMEDIA_PKG_URL`author_workbench.php" hidden=['gallery_id' => $st.galleryId]}
			{if $st.aliases}
				<p class="help-block">{tr}Another title of a book already loaded (not loaded again){/tr}: {foreach from=$st.aliases item=a name=al}{$a.title|escape}{if $a.isbn} ({$a.isbn|escape}){/if} - {tr}see{/tr} {$a.of|escape}{if !$smarty.foreach.al.last}; {/if}{/foreach}. {tr}Its row can be removed from the catalogue file.{/tr}</p>
			{/if}
			{if !$st.hasCatalogue}
				<p class="text-muted">{tr}There is no AuthorCatalogue.csv in the author's folder, so the books are the ones already loaded.{/tr}</p>
			{elseif $st.missing}
				<p class="help-block">{tr}These books are in the catalogue but not in the database. A book is filed in its first author's Library gallery (and beside any other author that has one), under the ISBN the catalogue gives; a row with no ISBN starts unticked: look its ISBN up here (Suggest fills the box and ticks the row), or tick it to load from its title and author alone. The catalogue's own ISBN always wins over one typed. At most{/tr} {$batch} {tr}are loaded per press. A book already there under the same title and first author counts as loaded; one that looks like a book the author already has under a fuller title is flagged and left unticked.{/tr}</p>
				{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`author_workbench.php"}
					<input type="hidden" name="gallery_id" value="{$st.galleryId}" />
					<table class="table table-condensed">
						<thead><tr><th></th><th>{tr}Title{/tr}</th><th>{tr}Authors{/tr}</th><th>{tr}Look for it{/tr}</th><th>{tr}ISBN{/tr}</th></tr></thead>
						<tbody>
							{foreach from=$st.missing item=index}
								<tr>
									<td><input type="checkbox" name="book[]" value="{$index}"{if !$st.rows[$index].maybe && $st.rows[$index].isbn} checked="checked"{/if} /></td>
									<td>{$st.rows[$index].title|escape}{if $st.rows[$index].maybe} <strong>{tr}Possible duplicate of{/tr}</strong> <a href="{$smarty.const.BIT_ROOT_URL}index.php?content_id={$st.rows[$index].maybe.id}">{$st.rows[$index].maybe.title|escape}</a>{/if}</td>
									<td>{$st.rows[$index].authors|escape}{if $st.rows[$index].filedUnder} <span class="help-block">({tr}filed under{/tr} {$st.rows[$index].filedUnder|escape})</span>{/if}</td>
									<td>
										<a href="https://www.abebooks.co.uk/servlet/SearchResults?tn={$st.rows[$index].titleTerm}{if $st.rows[$index].authorTerm}&amp;an={$st.rows[$index].authorTerm}{/if}" target="_blank" rel="noopener">AbeBooks</a> |
										<a href="https://openlibrary.org/search?q={$st.rows[$index].terms}" target="_blank" rel="noopener">Open Library</a> |
										<a href="https://www.google.com/search?tbm=bks&amp;q={$st.rows[$index].terms}" target="_blank" rel="noopener">Google Books</a> |
										<a href="#" class="isbn-suggest" data-title="{$st.rows[$index].titleTerm}" data-author="{$st.rows[$index].authorTerm}">{tr}Suggest{/tr}</a>
										<div class="isbn-options"></div>
									</td>
									<td><input type="text" class="form-control" name="isbn[{$index}]" value="{$st.rows[$index].isbn|escape}" data-sheet="{$st.rows[$index].isbn|escape}" placeholder="{tr}ISBN-10 or ISBN-13{/tr}" size="16" /></td>
								</tr>
							{/foreach}
						</tbody>
					</table>
					<div class="form-group submit">
						<input type="submit" class="btn btn-primary" name="load_missing" value="{tr}Load the ticked books{/tr}" />
					</div>
				{/form}
				{include file="bitpackage:fisheyemedia/isbn_suggest_inc.tpl"}
			{else}
				<p class="text-muted">{tr}All the catalogue's books are loaded.{/tr}{if $st.noIsbn} {$st.noIsbn} {tr}of its rows have no ISBN; the Book Details and no-ISBN pages find them.{/tr}{/if}</p>
			{/if}

			{* ---- 2. Series ---- *}
			<h3>2. {tr}Series{/tr}</h3>
			{if $st.children}
				<p>{tr}Series already under this author{/tr}: {foreach from=$st.children item=child name=existing}<strong>{$child.title|escape}</strong> ({$child.count}){if !$smarty.foreach.existing.last}, {/if}{/foreach}. {$st.direct|@count} {tr}books are still in the author's own list.{/tr}</p>
			{else}
				<p>{$st.direct|@count} {tr}books are in the author's own list; there are no series yet.{/tr}</p>
			{/if}

			{if $st.proposal.series}
				<h4>{tr}Proposed from the folders{/tr}</h4>
				{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`author_workbench.php"}
					<input type="hidden" name="gallery_id" value="{$st.galleryId}" />
					<p class="help-block">{tr}Each series folder is matched to this author's books by title. Tick the ones to create and rename any you like - give two folders the same name to put them in one series. Books go in publication order and leave the author's own list. Folders that only repeat other series' books (an omnibus or "complete" folder) are best left unticked.{/tr}</p>
					{foreach from=$st.proposal.series item=entry name=series}
						<div class="form-group">
							<input type="hidden" name="fs_folder[{$smarty.foreach.series.index}]" value="{$entry.folder|escape}" />
							<label>
								<input type="checkbox" name="fs_create[{$smarty.foreach.series.index}]" value="1"{if ($entry.books|@count >= $folderPreTick || $entry.target) && !$entry.collection} checked="checked"{/if} />
								{tr}Create{/tr}
							</label>
							<input type="text" class="form-control" name="fs_name[{$smarty.foreach.series.index}]" value="{$entry.name|escape}" size="40" />
							<select name="fs_target[{$smarty.foreach.series.index}]">
								<option value="">{tr}a new series with that name{/tr}</option>
								{foreach from=$st.children item=target}
									<option value="{$target.content_id}"{if $entry.target eq $target.content_id} selected="selected"{/if}>{tr}put in{/tr} {$target.title|escape} ({$target.count}){if $entry.target eq $target.content_id && $entry.similar} - {tr}similar name{/tr}{/if}</option>
								{/foreach}
							</select>
							<span class="help-block">{tr}from folder{/tr} {$entry.folder|escape} - {$entry.books|@count} {tr}books{/tr}{if $entry.collection} ({tr}a collection folder - it names{/tr} {$entry.named} {tr}of this author's books across series, so not preticked{/tr}){/if}:
								{foreach from=$entry.books item=book name=books}{$book.title|escape}{if $book.year} ({$book.year}){/if}{if !$smarty.foreach.books.last}, {/if}{/foreach}
							</span>
						</div>
					{/foreach}
					<div class="form-group submit">
						<input type="submit" class="btn btn-primary" name="create_folder_series" value="{tr}Create the ticked series{/tr}" />
					</div>
				{/form}
			{elseif $st.direct && $st.folder && !$st.singleFolders}
				<p class="text-muted">{tr}Nothing in the author's folders matches the books left in the author's own list.{/tr}</p>
			{/if}
			{if $st.singleFolders}
				<p class="text-muted">{$st.singleFolders} {tr}folders hold a single book each, so they are books and not series.{/tr}</p>
			{/if}
			{if $st.proposal.unmatched}
				<p>{tr}Not in any series folder{/tr}: {foreach from=$st.proposal.unmatched item=book name=unmatched}{$book.title|escape}{if !$smarty.foreach.unmatched.last}, {/if}{/foreach}</p>
			{/if}

			{if !$wd}
				<p>
					{if $st.qid}
						<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}author_workbench.php?gallery_id={$st.galleryId}&amp;wd=1">{tr}Propose series from Wikidata and Open Library{/tr}</a> |
					{/if}
					<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_series.php?gallery_id={$st.galleryId}">{tr}Rearrange books between series{/tr}</a>
				</p>
			{else}
				<h4>{tr}Proposed from Wikidata and Open Library{/tr}</h4>
				{if !$st.hasContact}
					<div class="alert alert-warning"><p>{tr}This gallery is not linked to an author contact, so there is no Wikidata id to read the series from. Create the author from the Authors page first.{/tr}</p></div>
				{elseif $st.qid eq ''}
					<div class="alert alert-warning"><p>{tr}The author's contact has no Wikidata id - fetch it from Wikidata on the contact page, then come back.{/tr}</p></div>
				{elseif $sources.failed}
					<div class="alert alert-danger"><p>{tr}Wikidata could not be asked just now - try again in a minute.{/tr}</p></div>
				{else}
					<p>{tr}Wikidata lists{/tr} {$sources.works} {tr}series entries for this author{/tr} ({$st.qid|escape}). {$sources.directCount} {tr}books are in the author's own list{/tr}; {$sources.noSeriesText} {tr}of them have no Open Library series text stored{/tr}.</p>
					{if $sources.noSeriesText}
						{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`author_workbench.php"}
							<input type="hidden" name="gallery_id" value="{$st.galleryId}" />
							<input type="hidden" name="wd" value="1" />
							<p class="help-block">{tr}Asks Open Library again for books that have no series text yet, ten at a time. Editions Open Library holds no series for stay blank, so some will always remain.{/tr}</p>
							<div class="form-group submit">
								<input type="submit" class="btn btn-default" name="lookup_series" value="{tr}Look up Open Library series text{/tr}" />
							</div>
						{/form}
					{/if}
					{if $sources.umbrellas}
						{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`author_workbench.php"}
							<input type="hidden" name="gallery_id" value="{$st.galleryId}" />
							<input type="hidden" name="wd" value="1" />
							<input type="hidden" name="ws_form" value="1" />
							{foreach from=$sources.umbrellas item=umbrella}
								<div class="form-group">
									<label><input type="checkbox" name="split[{$umbrella.key|escape}]" value="1"{if in_array($umbrella.key, $sources.split)} checked="checked"{/if} /> {tr}Keep the sub-series of{/tr} {$umbrella.name|escape} {tr}separate{/tr}</label>
									<span class="help-block">{foreach from=$umbrella.children item=child name=ch}{$child|escape}{if !$smarty.foreach.ch.last}, {/if}{/foreach}. {tr}Left unticked they are folded into{/tr} {$umbrella.name|escape}.</span>
								</div>
							{/foreach}
							<div class="form-group submit">
								<input type="submit" class="btn btn-default" name="series_update" value="{tr}Update proposal{/tr}" />
							</div>
						{/form}
					{/if}
					{if $sources.proposal.series}
						{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`author_workbench.php"}
							<input type="hidden" name="gallery_id" value="{$st.galleryId}" />
							<input type="hidden" name="wd" value="1" />
							<input type="hidden" name="ws_form" value="1" />
							{foreach from=$sources.split item=splitKey}<input type="hidden" name="split[{$splitKey|escape}]" value="1" />{/foreach}
							<p class="help-block">{tr}Each book is matched to Wikidata's works by title, with Open Library's series text alongside. Tick the series to create and rename any you like. Books go in the order chosen and leave the author's own list; each stays in any other author's gallery it is also filed in.{/tr}</p>
							{foreach from=$sources.proposal.series item=entry name=wseries}
								<div class="form-group">
									<input type="hidden" name="ws_key[{$smarty.foreach.wseries.index}]" value="{$entry.key|escape}" />
									<label>
										<input type="checkbox" name="ws_create[{$smarty.foreach.wseries.index}]" value="1"{if $entry.books|@count >= $sourcesPreTick || $entry.target} checked="checked"{/if} />
										{tr}Create{/tr}
									</label>
									<input type="text" class="form-control" name="ws_name[{$smarty.foreach.wseries.index}]" value="{$entry.name|escape}" size="40" />
									{if $entry.parent_name}<span class="help-block">{tr}inside{/tr} {$entry.parent_name|escape}</span>{/if}
									<select name="ws_order[{$smarty.foreach.wseries.index}]">
										<option value="date"{if $entry.order eq 'date'} selected="selected"{/if}>{tr}in publication order{/tr}</option>
										{if $entry.by_number}<option value="number"{if $entry.order eq 'number'} selected="selected"{/if}>{tr}in series number order{/tr} ({$entry.number_label|escape})</option>{/if}
									</select>
									<table class="table table-condensed">
										<thead><tr><th>{tr}Book{/tr}</th><th>{tr}First published{/tr}</th><th>{tr}Wikidata no.{/tr}</th><th>{tr}Open Library no.{/tr}</th><th>{tr}Source{/tr}</th></tr></thead>
										<tbody>
											{foreach from=$entry.books item=book}
												<tr>
													<td>{$book.title|escape}{if $book.also} <span class="help-block">({tr}also named in{/tr} {foreach from=$book.also item=other name=also}{$other|escape}{if !$smarty.foreach.also.last}, {/if}{/foreach})</span>{/if}</td>
													<td>{$book.year|escape}</td>
													<td>{$book.wd_ord|escape}</td>
													<td>{$book.ol_num|escape}</td>
													<td>{foreach from=$book.sources item=source name=src}{$source|escape}{if !$smarty.foreach.src.last}, {/if}{/foreach}</td>
												</tr>
											{/foreach}
										</tbody>
									</table>
								</div>
							{/foreach}
							<div class="form-group submit">
								<input type="submit" class="btn btn-primary" name="create_source_series" value="{tr}Create the ticked series{/tr}" />
							</div>
						{/form}
					{else}
						<p class="text-muted">{tr}No book left in the author's own list matches a series.{/tr}</p>
					{/if}
				{/if}
				<p><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}author_workbench.php?gallery_id={$st.galleryId}">{tr}Hide the Wikidata proposal{/tr}</a> | <a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_series.php?gallery_id={$st.galleryId}">{tr}Rearrange books between series{/tr}</a></p>
			{/if}

			{* ---- 3. Files ---- *}
			<h3>3. {tr}Files{/tr}</h3>
			{if $st.files.blocked}
				<p class="text-muted">{tr}Once the books are loaded and sorted into series, each book's folder is found through its galleries.{/tr}</p>
			{else}
				{if $st.files.books}
					<p class="help-block">{tr}Files load by themselves: a book whose folder holds files that are not all loaded yet is loaded when this page opens, all of them at once (rebuilding its ebook, audio book and extra rows).{/tr}</p>
					<table class="table table-condensed">
						<thead><tr><th>{tr}Book{/tr}</th><th>{tr}Folder{/tr}</th><th>{tr}In the folder{/tr}</th><th>{tr}Loaded{/tr}</th></tr></thead>
						<tbody>
							{foreach from=$st.files.books item=b}
								<tr>
									<td><a href="{$smarty.const.BIT_ROOT_URL}index.php?content_id={$b.id}">{$b.title|escape}</a></td>
									<td>{$b.folder|escape}</td>
									<td>{$b.ebook} {tr}ebook{/tr}, {$b.audiobook} {tr}audio{/tr}, {$b.extra} {tr}extra{/tr}</td>
									<td>{$b.have}{if $b.todo} <span class="label label-warning">{tr}to load{/tr}</span>{/if}</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
				{/if}
				{if $st.moves}
					{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`author_workbench.php"}
						<input type="hidden" name="gallery_id" value="{$st.galleryId}" />
						<p class="help-block">{tr}The Library tree follows the series (Author / Series / Book). These books have been moved into a series, but their folders are still where they were. Tick the folders to move into place.{/tr}</p>
						<ul class="list-unstyled">
							{foreach from=$st.moves item=move}
								<li><label><input type="checkbox" name="move_book[{$move.id}]" value="1" checked="checked" /> {$move.from|escape} &rarr; {$move.to|escape}</label></li>
							{/foreach}
						</ul>
						<div class="form-group submit">
							<input type="submit" class="btn btn-primary" name="move_folders" value="{tr}Move the ticked folders{/tr}" />
						</div>
					{/form}
				{/if}
				{if $st.files.noFolder}
					<p>{tr}No folder, so no files to load for{/tr}: {foreach from=$st.files.noFolder item=b}<a href="{$smarty.const.BIT_ROOT_URL}index.php?content_id={$b.id}">{$b.title|escape}</a>{if !$b@last}, {/if}{/foreach}</p>
				{/if}
				{if $st.files.unmatched}
					{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`author_workbench.php"}
						<input type="hidden" name="gallery_id" value="{$st.galleryId}" />
						<p>{tr}Folders with no book in the database (books still to catalogue). Tick the ones that are books to add them to the catalogue file; they then come up in step 1 to load, with the ISBN to find. A folder that is only a short story or an extra belongs inside another book's folder instead.{/tr}</p>
						<ul class="list-unstyled">
							{foreach from=$st.files.unmatched item=dir}
								<li><label><input type="checkbox" name="add_folder[]" value="{$dir|escape}" /> {$dir|escape}</label></li>
							{/foreach}
						</ul>
						<div class="form-group submit">
							<input type="submit" class="btn btn-default" name="add_folders" value="{tr}Add the ticked folders to the catalogue{/tr}" />
						</div>
					{/form}
				{/if}
				{if !$st.files.books && !$st.files.noFolder}
					<p class="text-muted">{tr}No book of this author has a folder yet.{/tr}</p>
				{/if}
			{/if}
		{/if}
	</div>
</div>
{/strip}

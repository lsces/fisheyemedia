{* Series workbench - see series_workbench.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{if $vaUrl}<a href="{$vaUrl|escape}" title="{tr}Open the Various Authors gallery{/tr}">{tr}Series workbench{/tr}</a>{else}{tr}Series workbench{/tr}{/if}{if $series}: {if $st.seriesUrl}<a href="{$st.seriesUrl|escape}" title="{tr}Open the series gallery{/tr}">{$series|escape}</a>{else}{$series|escape}{/if}{/if}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}
		{foreach from=$messages item=message}
			<div class="alert alert-success"><p>{$message|escape}</p></div>
		{/foreach}

		{if !$libraryRoot}
			<div class="alert alert-warning"><p>{tr}No Library folder is set: give the Book Library Storage Path in Media Library Settings.{/tr}</p></div>
		{elseif !$catalogues}
			<p>{tr}No series catalogues found: each series folder under{/tr} <code>{$libraryRoot|escape}Various Authors/</code> {tr}needs a SeriesCatalogue.csv.{/tr}</p>

		{elseif !$series}
			<p>{tr}Each series is worked in three steps - load the books its catalogue names that are not in the database, file the series, load the files of its books. A series with nothing left to do is put away.{/tr}</p>
			{if $overview}
				<table class="table table-condensed">
					<thead><tr><th>{tr}Series{/tr}</th><th>{tr}Books{/tr}</th><th>{tr}Series filing{/tr}</th><th>{tr}Files{/tr}</th><th>{tr}Next{/tr}</th></tr></thead>
					<tbody>
						{foreach from=$overview key=name item=one}
							<tr>
								<td><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}series_workbench.php?series={$name|escape:'url'}">{$name|escape}</a></td>
								{if $one.error}
									<td colspan="4">{$one.error|escape}</td>
								{else}
									<td>{$one.loaded} {tr}of{/tr} {$one.total}{if $one.missing} <span class="label label-warning">{$one.missing|@count} {tr}to load{/tr}</span>{/if}</td>
									<td>{if $one.filing.pending}<span class="label label-warning">{tr}to do{/tr}</span>{else}{tr}done{/tr}{/if}</td>
									<td>{if $one.filing.pending}<span class="text-muted">{tr}after filing{/tr}</span>{elseif $one.files.todo}<span class="label label-warning">{$one.files.todo} {tr}to load{/tr}</span>{else}{tr}done{/tr}{/if}</td>
									<td>{if $one.done}{tr}Finished{/tr}{else}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}series_workbench.php?series={$name|escape:'url'}">{if $one.next == 'books'}{tr}Load the books{/tr}{elseif $one.next == 'series'}{tr}File the series{/tr}{else}{tr}Load the files{/tr}{/if}</a>{/if}</td>
								{/if}
							</tr>
						{/foreach}
					</tbody>
				</table>
			{else}
				<p>{tr}Every series is finished.{/tr}</p>
			{/if}
			<p>{if $showAll}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}series_workbench.php">{tr}Hide the finished series{/tr}</a>{elseif $finished}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}series_workbench.php?all=1">{tr}Show the finished series{/tr} ({$finished})</a>{/if}</p>

		{else}
			<p><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}series_workbench.php">&laquo; {tr}All series{/tr}</a></p>
			{if $st.error}
				<div class="alert alert-danger"><p>{$st.error|escape}</p></div>
			{else}
				{if $st.done}<div class="alert alert-success"><p><strong>{tr}Finished{/tr}</strong>: {tr}every step of this series is done.{/tr}</p></div>{/if}

				{* ---- 1. Books ---- *}
				<h3>1. {tr}Books{/tr} <small>{$st.loaded} {tr}of{/tr} {$st.total} {tr}loaded{/tr}</small></h3>
				{if $st.missing}
					<p class="help-block">{tr}These books are in the catalogue but not in the database. Each is filed in its author's Library gallery under the ISBN the catalogue gives; at most{/tr} {$batch} {tr}are loaded per press. A book already there under the same title and first author is counted as loaded, not made again.{/tr}</p>
					{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`series_workbench.php"}
						<input type="hidden" name="series" value="{$series|escape}" />
						<table class="table table-condensed">
							<thead><tr><th></th><th>{tr}Title{/tr}</th><th>{tr}Authors{/tr}</th><th>{tr}ISBN{/tr}</th></tr></thead>
							<tbody>
								{foreach from=$st.missing item=index}
									<tr>
										<td><input type="checkbox" name="book[]" value="{$index}" checked="checked" /></td>
										<td>{$st.rows[$index].title|escape}</td>
										<td>{$st.rows[$index].authors|escape}</td>
										<td>{$st.rows[$index].isbn|escape}{if !$st.rows[$index].isbn} <span class="text-muted">{tr}none in the catalogue{/tr}</span>{/if}</td>
									</tr>
								{/foreach}
							</tbody>
						</table>
						<div class="form-group submit">
							<input type="submit" class="btn btn-primary" name="load_missing" value="{tr}Load the ticked books{/tr}" />
						</div>
					{/form}
				{else}
					<p class="text-muted">{tr}All the catalogue's books are loaded.{/tr}</p>
				{/if}
				{if $st.differs}
					<div class="alert alert-warning">
						<p>{tr}Loaded under a different ISBN from the catalogue's (the book was matched by title and author):{/tr}</p>
						<ul>{foreach from=$st.differs item=d}<li>{$d.title|escape}: {tr}catalogue{/tr} {$d.sheet|escape}, {tr}database{/tr} {$d.db|escape}</li>{/foreach}</ul>
					</div>
				{/if}

				{* ---- 2. Series filing ---- *}
				<h3>2. {tr}Series filing{/tr}</h3>
				{if $st.filing.pending}
					<p class="help-block">{tr}The loaded books are added to the series in catalogue order and each author's own series is linked under it. Ticked authors also have their loose books moved into their series.{/tr}</p>
					<ul>{foreach from=$st.filing.notes item=note}<li>{$note|escape}</li>{/foreach}</ul>
					{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`series_workbench.php"}
						<input type="hidden" name="series" value="{$series|escape}" />
						{foreach from=$st.plan.authorSeries item=own}
							{if $own.toMove}
								<div class="checkbox"><label><input type="checkbox" name="move[{$own.author_id}]" value="1" checked="checked" /> {tr}Move into{/tr} {$own.author|escape}{tr}'s series{/tr}: {foreach from=$own.toMove item=mv}{$mv.title|escape}{if !$mv@last}, {/if}{/foreach}</label></div>
							{/if}
						{/foreach}
						<div class="form-group submit">
							<input type="submit" class="btn btn-primary" name="file_series" value="{tr}File this series{/tr}" />
						</div>
					{/form}
				{else}
					<p class="text-muted">{tr}The series is filed.{/tr}</p>
				{/if}

				{* ---- 3. Files ---- *}
				<h3>3. {tr}Files{/tr}</h3>
				{if $st.files.blocked}
					<p class="text-muted">{tr}Once the series is filed, each book's folder is found through it.{/tr}</p>
				{else}
					{if $st.files.books}
						<p class="help-block">{tr}Ticked books have files in their folder that are not all loaded yet. Loading rebuilds the book's ebook, audio book and extra rows; at most{/tr} {$filesBatch} {tr}per press.{/tr}</p>
						{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`series_workbench.php"}
							<input type="hidden" name="series" value="{$series|escape}" />
							<table class="table table-condensed">
								<thead><tr><th></th><th>{tr}Book{/tr}</th><th>{tr}Folder{/tr}</th><th>{tr}In the folder{/tr}</th><th>{tr}Loaded{/tr}</th></tr></thead>
								<tbody>
									{foreach from=$st.files.books item=b}
										<tr>
											<td><input type="checkbox" name="book[]" value="{$b.id}"{if $b.todo} checked="checked"{/if} /></td>
											<td><a href="{$smarty.const.BIT_ROOT_URL}index.php?content_id={$b.id}">{$b.title|escape}</a></td>
											<td>{$b.folder|escape}</td>
											<td>{$b.ebook} {tr}ebook{/tr}, {$b.audiobook} {tr}audio{/tr}, {$b.extra} {tr}extra{/tr}</td>
											<td>{$b.have}{if $b.todo} <span class="label label-warning">{tr}to load{/tr}</span>{/if}</td>
										</tr>
									{/foreach}
								</tbody>
							</table>
							<div class="form-group submit">
								<input type="submit" class="btn btn-primary" name="register_files" value="{tr}Load the ticked books' files{/tr}" />
							</div>
						{/form}
					{/if}
					{if $st.files.noFolder}
						<p>{tr}No folder, so no files to load for{/tr}: {foreach from=$st.files.noFolder item=b}<a href="{$smarty.const.BIT_ROOT_URL}index.php?content_id={$b.id}">{$b.title|escape}</a>{if !$b@last}, {/if}{/foreach}</p>
					{/if}
					{if $st.files.unmatched}
						<p>{tr}Folders in the series folder with no book in the database (books still to catalogue){/tr}: {foreach from=$st.files.unmatched item=dir}{$dir|escape}{if !$dir@last}, {/if}{/foreach}</p>
					{/if}
					{if !$st.files.books && !$st.files.noFolder}
						<p class="text-muted">{tr}No book of this series has a folder yet.{/tr}</p>
					{/if}
				{/if}
			{/if}
		{/if}
	</div>
</div>
{/strip}

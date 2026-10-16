{* Review page for series-from-folders - see load_series.php's own docblock. Nothing here writes
   until "Create selected series" is pressed. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1><a href="{$authorUrl|escape}">{$authorTitle|escape}</a> - {tr}Series from Folders{/tr}</h1>
	</div>

	<div class="body">

		{if $result}
			{if $result.errors}
				<div class="alert alert-danger">
					<p>{tr}Failed{/tr}:</p>
					<ul>{foreach from=$result.errors item=error}<li>{$error|escape}</li>{/foreach}</ul>
				</div>
			{/if}
			{if $result.created}
				<div class="alert alert-success">
					<ul>{foreach from=$result.created item=made}<li>{$made.name|escape}: {$made.moved} {tr}books moved in{/tr}{if $made.merged} ({tr}added to the series already there{/tr}){/if}</li>{/foreach}</ul>
				</div>
			{/if}
		{/if}

		{if $moveResult}
			{if $moveResult.errors}
				<div class="alert alert-danger">
					<p>{tr}Failed{/tr}:</p>
					<ul>{foreach from=$moveResult.errors item=error}<li>{$error|escape}</li>{/foreach}</ul>
				</div>
			{/if}
			<div class="alert alert-success"><p>{$moveResult.moved} {tr}books moved{/tr}.</p></div>
		{/if}

		{if !$folders}
			<div class="alert alert-warning"><p>{tr}There is no Library folder under the storage root to read series from.{/tr}</p></div>
		{else}
			{form legend="Author's folder" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_series.php"}
				<input type="hidden" name="gallery_id" value="{$galleryId|escape}" />
				<div class="form-group">
					{formlabel label="Library folder" for="folder"}
					{forminput}
						<select name="folder" id="folder">
							<option value="">{tr}Choose a folder{/tr}</option>
							{foreach from=$folders item=name}
								<option value="{$name|escape}"{if $name eq $folder} selected="selected"{/if}>{$name|escape}</option>
							{/foreach}
						</select>
						{formhelp note="The folder of series folders for this author - the closest match to the gallery's name is chosen for you."}
					{/forminput}
				</div>
				<div class="form-group submit">
					<input type="submit" class="btn btn-default" name="scan" value="{tr}Propose series{/tr}" />
				</div>
			{/form}

			{if $proposal}
				{if $proposal.series}
					{form legend="Proposed series" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_series.php"}
						<input type="hidden" name="gallery_id" value="{$galleryId|escape}" />
						<input type="hidden" name="folder" value="{$folder|escape}" />
						<p>{tr}Each series folder is matched to this author's books by title. Tick the ones to create and rename any you like - give two folders the same name to put them in one series. Books go in publication order and leave the author's own list. Folders that only repeat other series' books (an omnibus or "complete" folder) are best left unticked.{/tr}</p>
						{foreach from=$proposal.series item=entry name=series}
							<div class="form-group">
								<input type="hidden" name="series_folder[{$smarty.foreach.series.index}]" value="{$entry.folder|escape}" />
								<label>
									<input type="checkbox" name="series_create[{$smarty.foreach.series.index}]" value="1"{if $entry.books|@count >= $preTickMin && !$entry.collection} checked="checked"{/if} />
									{tr}Create{/tr}
								</label>
								<input type="text" class="form-control" name="series_name[{$smarty.foreach.series.index}]" value="{$entry.name|escape}" size="40" />
								<span class="help-block">{tr}from folder{/tr} {$entry.folder|escape} - {$entry.books|@count} {tr}books{/tr}{if $entry.collection} ({tr}a collection folder - it names{/tr} {$entry.named} {tr}of this author's books across series, so not preticked{/tr}){/if}:
									{foreach from=$entry.books item=book name=books}{$book.title|escape}{if $book.year} ({$book.year}){/if}{if !$smarty.foreach.books.last}, {/if}{/foreach}
								</span>
							</div>
						{/foreach}
						<div class="form-group submit">
							<input type="submit" class="btn btn-primary" name="create_series" value="{tr}Create selected series{/tr}" />
						</div>
					{/form}
				{else}
					<p>{tr}Nothing in this folder matches any of the books left in the author's own list.{/tr}</p>
				{/if}

				{if $proposal.unmatched}
					<h3>{tr}Not in any series folder{/tr}</h3>
					<p>{foreach from=$proposal.unmatched item=book name=unmatched}{$book.title|escape}{if !$smarty.foreach.unmatched.last}, {/if}{/foreach}</p>
				{/if}
				{if $proposal.empty}
					<h3>{tr}Series folders with no matching book{/tr}</h3>
					<p>{foreach from=$proposal.empty item=name name=emptyfolders}{$name|escape}{if !$smarty.foreach.emptyfolders.last}, {/if}{/foreach}</p>
				{/if}
			{/if}
		{/if}

		{if $seriesTargets || $rearrange.0.books}
			{form legend="Rearrange" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_series.php"}
				<input type="hidden" name="gallery_id" value="{$galleryId|escape}" />
				<input type="hidden" name="folder" value="{$folder|escape}" />
				<p>{tr}Every book under this author, and where it is now. Choose a place for any you want to move - into a series, or back to the author's own list. A book moves out of where it is; each series touched is put back in publication order. A series left empty stays until you delete it from its own page.{/tr}</p>
				{foreach from=$rearrange item=group}
					<h3>{$group.title|escape} ({$group.books|@count})</h3>
					{if $group.books}
						<table class="table table-condensed">
							<tbody>
								{foreach from=$group.books item=book}
									<tr>
										<td>{$book.title|escape}{if $book.year} ({$book.year}){/if}</td>
										<td>
											<select name="move[{$book.content_id}]">
												<option value="">{tr}leave here{/tr}</option>
												{if $group.key ne 'author'}<option value="author">{tr}Not in a series{/tr}</option>{/if}
												{foreach from=$seriesTargets item=target}
													{if $target.content_id ne $group.key}<option value="{$target.content_id}">{$target.title|escape}</option>{/if}
												{/foreach}
											</select>
										</td>
									</tr>
								{/foreach}
							</tbody>
						</table>
					{else}
						<p>{tr}Empty.{/tr}</p>
					{/if}
				{/foreach}
				<div class="form-group submit">
					<input type="submit" class="btn btn-primary" name="move_books" value="{tr}Move selected books{/tr}" />
				</div>
			{/form}
		{/if}

	</div>
</div>
{/strip}

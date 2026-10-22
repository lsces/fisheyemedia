{* Loads books from an uploaded catalogue spreadsheet - see load_books.php's own docblock for the
   filing rule (an author gallery for two or more books, a category gallery otherwise). *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{if $topGalleryUrl}<a href="{$topGalleryUrl|escape}">{tr}Library{/tr}</a> - {/if}{tr}Load Books{/tr}</h1>
	</div>

	<div class="body">

		{if !$hasTopGallery}
			<div class="alert alert-danger"><p>{tr}There is no top-level Library gallery yet - apply the media xref scheme so it is created.{/tr}</p></div>
		{/if}

		{if $result}
			{if $result.errors}
				<div class="alert alert-danger">
					<p>{tr}Failed{/tr}:</p>
					<ul>{foreach from=$result.errors item=error}<li>{$error|escape}</li>{/foreach}</ul>
				</div>
			{/if}
			{if $result.group}
				<div class="alert alert-info"><p>{$result.group|escape}: {$result.created} {tr}loaded{/tr}{if $result.already}, {$result.already} {tr}already there{/tr}{/if}.</p></div>
				{if $result.series}<p>{tr}Filed in series{/tr}: {foreach from=$result.series key=seriesName item=count name=sr}{$seriesName|escape} ({$count}){if !$smarty.foreach.sr.last}, {/if}{/foreach}.</p>{/if}
				{if $result.details}
					<ul class="list-unstyled">{foreach from=$result.details item=detail}<li>{$detail.title|escape} - {if $detail.matched}{tr}details from Open Library{/tr}{else}{tr}no Open Library record{/tr}{/if}{if $detail.error}: {$detail.error|escape}{/if}</li>{/foreach}</ul>
				{/if}
			{/if}
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
			{if $authorGalleryId && $bookList}
				{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_books.php"}
					<input type="hidden" name="gallery_id" value="{$authorGalleryId}" />
					<input type="hidden" name="series_form" value="1" />
					<input type="hidden" name="picked_form" value="1" />
					{if $useSeries}<input type="hidden" name="use_series" value="1" />{/if}
					{if $seriesPlan}{foreach from=$seriesPlan.split item=splitKey}<input type="hidden" name="split[{$splitKey|escape}]" value="1" />{/foreach}{/if}
					<h3>{tr}This author's books still to load{/tr}</h3>
					<p class="help-block">{$booksLoaded}/{$booksTotal} {tr}loaded. The next{/tr} {$batchSize} {tr}are ticked - untick any to leave out; at most{/tr} {$batchSize} {tr}are loaded per press, and loaded books drop off this list.{/tr}</p>
					<table class="table table-condensed">
						<thead><tr><th></th><th>{tr}Title{/tr}</th><th>{tr}Published{/tr}</th><th>{tr}With{/tr}</th><th>{tr}Series{/tr}</th></tr></thead>
						<tbody>
							{foreach from=$bookList item=book}
								<tr>
									<td><input type="checkbox" name="book[]" value="{$book.index}"{if $book.ticked} checked="checked"{/if} /></td>
									<td>{$book.title|escape}</td>
									<td>{$book.year|escape}</td>
									<td>{foreach from=$book.coauthors item=other name=co}{$other|escape}{if !$smarty.foreach.co.last}, {/if}{/foreach}</td>
									<td>{$book.series|escape}</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
					<div class="form-group submit">
						<button type="submit" class="btn btn-primary" name="process_group" value="{$onlyKey|escape}"{if !$hasTopGallery} disabled="disabled"{/if}>{tr}Load the ticked books{/tr}</button>
					</div>
				{/form}
			{/if}
			{if $authorGalleryId && $coCredited}
				<h3>{tr}Co-written - filed here from the primary author's page{/tr}</h3>
				<ul class="list-unstyled">{foreach from=$coCredited item=book}<li>{$book.title|escape} ({tr}primary author{/tr}: {$book.primary|escape}) - {if $book.loaded}{tr}loaded{/tr}{else}{tr}not loaded yet{/tr}{/if}</li>{/foreach}</ul>
			{/if}

			{if $authorGalleryId}
				{if !$bookList}
					<p>{if $authorInCatalogue}{tr}Every book on the catalogue for this author is already loaded.{/tr}{else}{tr}This author has no books of their own as primary author - books they co-wrote are filed here when the primary author's books are loaded.{/tr}{/if}</p>
				{/if}
			{elseif $pending}
				{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_books.php"}
					{if $authorGalleryId}<input type="hidden" name="gallery_id" value="{$authorGalleryId}" />
						<input type="hidden" name="series_form" value="1" />
						{if $useSeries}<input type="hidden" name="use_series" value="1" />{/if}
						{if $seriesPlan}{foreach from=$seriesPlan.split item=splitKey}<input type="hidden" name="split[{$splitKey|escape}]" value="1" />{/foreach}{/if}
					{/if}
					<p>{tr}Process one group at a time - it finds or creates the gallery under Library and loads up to{/tr} {$batchSize} {tr}of its books per press; press again to carry on.{/tr}{if $pendingTotal > $groupsShown} {tr}Showing the first{/tr} {$groupsShown} {tr}of{/tr} {$pendingTotal}.{/if}</p>
					<ul class="list-unstyled">
						{foreach from=$pending item=group}
							<li>
								<button type="submit" class="btn btn-default btn-xs" name="process_group" value="{$group.key|escape}"{if !$hasTopGallery} disabled="disabled"{/if}>{tr}Process{/tr}</button>
								&nbsp;{$group.name|escape} <span class="help-block">{if $group.kind eq 'author'}{tr}author{/tr}{else}{tr}category{/tr}{/if}, {$group.loaded}/{$group.total} {tr}loaded{/tr}</span>
							</li>
						{/foreach}
					</ul>
				{/form}
			{else}
				<p>{tr}Nothing left to load - every book in the catalogue is already there.{/tr}</p>
			{/if}
		{/if}

	</div>
</div>
{/strip}

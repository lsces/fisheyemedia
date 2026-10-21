{* Review page for series from Wikidata and Open Library - see load_book_series.php's own docblock. Nothing here
   writes until "Create selected series" is pressed. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1><a href="{$authorUrl|escape}">{$authorTitle|escape}</a> - {tr}Series from Wikidata{/tr}</h1>
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
					<ul>{foreach from=$result.created item=made}<li>{$made.name|escape}: {$made.moved} {tr}books moved in{/tr}{if $made.nested} ({tr}inside its umbrella series{/tr}){/if}{if $made.merged} ({tr}added to the series already there{/tr}){/if}</li>{/foreach}</ul>
				</div>
			{/if}
		{/if}

		{if $lookup}
			<div class="alert alert-info">
				<p>{tr}Asked Open Library about{/tr} {$lookup.done} {tr}books; books with series text now{/tr}: {$lookup.with}.</p>
				{if $lookup.errors}<ul>{foreach from=$lookup.errors item=error}<li>{$error|escape}</li>{/foreach}</ul>{/if}
			</div>
		{/if}

		{if $attached !== null}
			<div class="alert alert-success"><p>{$attached} {tr}series put back under this author{/tr}.</p></div>
		{/if}
		{if $orphans}
			{form legend="Series not under any author" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_series.php"}
				<input type="hidden" name="gallery_id" value="{$galleryId|escape}" />
				<p>{tr}These series are not filed in any gallery, so they have no breadcrumb trail and cannot be reached from the Library. Tick the ones that belong to this author.{/tr}</p>
				{foreach from=$orphans item=orphan}
					<div class="form-group"><label><input type="checkbox" name="attach_series[{$orphan.content_id}]" value="1" /> {$orphan.title|escape}</label></div>
				{/foreach}
				<div class="form-group submit">
					<input type="submit" class="btn btn-default" name="attach" value="{tr}Put under this author{/tr}" />
				</div>
			{/form}
		{/if}

		{if !$hasContact}
			<div class="alert alert-warning"><p>{tr}This gallery is not linked to an author contact, so there is no Wikidata id to read the series from. Create the author from the Authors page first.{/tr}</p></div>
		{elseif $qid eq ''}
			<div class="alert alert-warning"><p>{tr}The author's contact has no Wikidata id - fetch it from Wikidata on the contact page, then come back.{/tr}</p></div>
		{elseif $wikidataFailed}
			<div class="alert alert-danger"><p>{tr}Wikidata could not be asked just now - try again in a minute.{/tr}</p></div>
		{else}
			<p>{tr}Wikidata lists{/tr} {$wikidataWorks} {tr}series entries for this author{/tr} ({$qid|escape}). {$directCount} {tr}books are in the author's own list{/tr}; {$noSeriesText} {tr}of them have no Open Library series text stored{/tr}.</p>

			{if $noSeriesText}
				{form legend="Open Library series text" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_series.php"}
					<input type="hidden" name="gallery_id" value="{$galleryId|escape}" />
					<p>{tr}Asks Open Library again for books that have no series text yet, ten at a time. Editions Open Library holds no series for stay blank, so some will always remain.{/tr}</p>
					<div class="form-group submit">
						<input type="submit" class="btn btn-default" name="lookup_series" value="{tr}Look up Open Library series text{/tr}" />
					</div>
				{/form}
			{/if}

			{if $umbrellas}
				{form legend="Sub-series" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_series.php"}
					<input type="hidden" name="gallery_id" value="{$galleryId|escape}" />
					<input type="hidden" name="series_form" value="1" />
					{foreach from=$umbrellas item=umbrella}
						<div class="form-group">
							<label><input type="checkbox" name="split[{$umbrella.key|escape}]" value="1"{if in_array($umbrella.key, $splitChosen)} checked="checked"{/if} /> {tr}Keep the sub-series of{/tr} {$umbrella.name|escape} {tr}separate{/tr}</label>
							<span class="help-block">{foreach from=$umbrella.children item=child name=ch}{$child|escape}{if !$smarty.foreach.ch.last}, {/if}{/foreach}. {tr}Left unticked they are folded into{/tr} {$umbrella.name|escape}.</span>
						</div>
					{/foreach}
					<div class="form-group submit">
						<input type="submit" class="btn btn-default" name="series_update" value="{tr}Update proposal{/tr}" />
					</div>
				{/form}
			{/if}

			{if $proposal.series}
				{form legend="Proposed series" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_series.php"}
					<input type="hidden" name="gallery_id" value="{$galleryId|escape}" />
					<input type="hidden" name="series_form" value="1" />
					{foreach from=$splitChosen item=splitKey}<input type="hidden" name="split[{$splitKey|escape}]" value="1" />{/foreach}
					<p>{tr}Each book is matched to Wikidata's works by title, with Open Library's series text alongside. Tick the series to create and rename any you like. A sub-series Wikidata invents (a trilogy inside a long series) is folded into the main series, so those books simply follow its order. Books go in the order chosen and leave the author's own list; each stays in any other author's gallery it is also filed in.{/tr}</p>
					{foreach from=$proposal.series item=entry name=series}
						<div class="form-group">
							<input type="hidden" name="series_key[{$smarty.foreach.series.index}]" value="{$entry.key|escape}" />
							<label>
								<input type="checkbox" name="series_create[{$smarty.foreach.series.index}]" value="1"{if $entry.books|@count >= $preTickMin} checked="checked"{/if} />
								{tr}Create{/tr}
							</label>
							<input type="text" class="form-control" name="series_name[{$smarty.foreach.series.index}]" value="{$entry.name|escape}" size="40" />
							{if $entry.parent_name}<span class="help-block">{tr}inside{/tr} {$entry.parent_name|escape}</span>{/if}
							<select name="series_order[{$smarty.foreach.series.index}]">
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
						<input type="submit" class="btn btn-primary" name="create_series" value="{tr}Create selected series{/tr}" />
					</div>
				{/form}
			{else}
				<p>{tr}No book left in the author's own list matches a series.{/tr}</p>
			{/if}

			{if $proposal.unmatched}
				<h3>{tr}Not in any series{/tr}</h3>
				<p>{foreach from=$proposal.unmatched item=book name=unmatched}{$book.title|escape}{if !$smarty.foreach.unmatched.last}, {/if}{/foreach}</p>
			{/if}
		{/if}

	</div>
</div>
{/strip}

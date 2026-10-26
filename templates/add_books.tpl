{* Adds books from a list of ISBNs - see add_books.php's own docblock for the two steps (look up, then load the ticked books). *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{if $topGalleryUrl}<a href="{$topGalleryUrl|escape}">{tr}Library{/tr}</a> - {/if}{tr}Add Books{/tr}</h1>
	</div>

	<div class="body">

		{if !$hasTopGallery}
			<div class="alert alert-danger"><p>{tr}There is no top-level Library gallery yet - apply the media xref scheme so it is created.{/tr}</p></div>
		{/if}

		{if $results || $loadErrors}
			{foreach from=$results item=result}
				<div class="alert alert-info">
					<p>{if $result.group_url}<a href="{$result.group_url|escape}">{$result.group|escape}</a>{else}{$result.group|escape}{/if}: {$result.created} {tr}loaded{/tr}{if $result.already}, {$result.already} {tr}already there{/tr}{/if}{if $result.filed}, {$result.filed} {tr}already-loaded books brought into the gallery{/tr}{/if}{if $result.moved}, {$result.moved} {tr}taken out of their Category gallery{/tr}{/if}.</p>
					{if $result.series}<p>{tr}Filed in series{/tr}: {foreach from=$result.series key=seriesName item=count name=sr}{$seriesName|escape} ({$count}){if !$smarty.foreach.sr.last}, {/if}{/foreach}.</p>{/if}
				</div>
				{if $result.errors}<div class="alert alert-danger"><ul>{foreach from=$result.errors item=error}<li>{$error|escape}</li>{/foreach}</ul></div>{/if}
				{if $result.details}
					<ul class="list-unstyled">{foreach from=$result.details item=detail}<li><a href="{$detail.url|escape}">{$detail.title|escape}</a> - {if $detail.matched}{tr}details from Open Library{/tr}{else}{tr}no Open Library record{/tr}{/if}{if $detail.error}: {$detail.error|escape}{/if}</li>{/foreach}</ul>
				{/if}
			{/foreach}
			{if $loadErrors}
				<div class="alert alert-danger"><p>{tr}Not loaded{/tr}:</p><ul>{foreach from=$loadErrors item=error}<li>{$error|escape}</li>{/foreach}</ul></div>
			{/if}
		{/if}

		{if $overflow}
			<div class="alert alert-warning"><p>{$overflow} {tr}ISBNs over the limit of{/tr} {$maxIsbns} {tr}per press were left off - add them in a second press.{/tr}</p></div>
		{/if}

		{if $listing}
			{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}add_books.php"}
				<input type="hidden" name="isbns" value="{$isbnText|escape}" />
				<input type="hidden" name="category" value="{$category|escape}" />
				<table class="table table-condensed">
					<thead><tr><th></th><th>{tr}ISBN{/tr}</th><th>{tr}Title{/tr}</th><th>{tr}Authors{/tr}</th><th>{tr}Filed in{/tr}</th></tr></thead>
					<tbody>
						{foreach from=$listing item=one}
							<tr>
								{if $one.row && !$one.error}
									<td><input type="checkbox" name="book[]" value="{$one.raw|escape}"{if !$one.filing.loaded} checked="checked"{else} disabled="disabled"{/if} /></td>
									<td>{$one.isbn|escape}</td>
									<td>{$one.row.title|escape}{if $one.row.published} <small>({$one.row.published|escape})</small>{/if}</td>
									<td>{foreach from=$one.row.authors item=name name=an}{$name|escape}{if !$smarty.foreach.an.last}, {/if}{/foreach}</td>
									<td>
										{if $one.filing.loaded}{tr}already in the Library{/tr}
										{elseif $one.filing.kind eq 'author'}{$one.filing.author|escape}{if !$one.filing.gallery} <small>({tr}new gallery{/tr}{if $one.filing.joins}, {tr}brings in{/tr} {$one.filing.joins} {tr}loaded{/tr}{/if})</small>{/if}
										{else}{$one.filing.name|escape} <small>({tr}category{/tr})</small>{/if}
									</td>
								{else}
									<td><input type="checkbox" name="book[]" value="{$one.raw|escape}"{if $one.row} checked="checked"{/if} /></td>
									<td>{$one.isbn|escape}</td>
									<td><input type="text" class="form-control" name="manual_title[{$one.raw|escape}]" value="{if $one.manual_title}{$one.manual_title|escape}{elseif $one.row}{$one.row.title|escape}{/if}" placeholder="{tr}Title{/tr}" /></td>
									<td><input type="text" class="form-control" name="manual_author[{$one.raw|escape}]" value="{$one.manual_author|escape}" placeholder="{tr}Author{/tr}" /></td>
									<td><span class="error">{$one.error|escape}</span> <small>{tr}Fill in the title and author to load it by hand, then look up again.{/tr}</small></td>
								{/if}
							</tr>
						{/foreach}
					</tbody>
				</table>
				<div class="form-group submit">
					<button type="submit" class="btn btn-primary" name="add_books" value="1"{if !$hasTopGallery} disabled="disabled"{/if}>{tr}Load the ticked books{/tr}</button>
					<button type="submit" class="btn btn-default" name="look_up" value="1">{tr}Look up again{/tr}</button>
				</div>
			{/form}
		{/if}

		{form legend="ISBNs" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}add_books.php"}
			<div class="form-group">
				<label for="isbns">{tr}ISBNs{/tr}</label>
				<textarea class="form-control" id="isbns" name="isbns" rows="8" placeholder="9780007637706&#10;9780007637676">{$isbnText|escape}</textarea>
				<span class="help-block">{tr}One per line, or separated by commas or spaces - up to{/tr} {$maxIsbns} {tr}at a time, best all by one author. Each is read from Open Library and listed for checking before anything is saved.{/tr}</span>
			</div>
			<div class="form-group">
				<label for="category">{tr}Category{/tr}</label>
				<input type="text" class="form-control" id="category" name="category" value="{$category|escape}" />
				<span class="help-block">{tr}Where a book goes when its author has no gallery of their own yet. An author with two or more books gets a gallery, and their books leave the Category.{/tr}</span>
			</div>
			<div class="form-group submit">
				<button type="submit" class="btn btn-primary" name="look_up" value="1">{tr}Look up{/tr}</button>
			</div>
		{/form}

		{form legend="Books with no ISBN" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}add_books.php"}
			<p>{tr}For a book that has no ISBN - older, or one on the desk. Title and author are needed; the year and publisher are optional. Check shows where each would be filed and whether it is already in the Library; nothing is saved until Load. An author given as "A; B" has both credited.{/tr}</p>
			<div class="form-group">
				<label for="nb_category">{tr}Category{/tr}</label>
				<input type="text" class="form-control" id="nb_category" name="nb_category" value="{$nbCategory|escape}" />
			</div>
			<table class="table table-condensed">
				<thead><tr><th></th><th>{tr}Title{/tr}</th><th>{tr}Author{/tr}</th><th>{tr}Year{/tr}</th><th>{tr}Publisher{/tr}</th><th>{tr}Filed in{/tr}</th></tr></thead>
				<tbody>
					{foreach from=$nbRows key=n item=row}
						<tr>
							<td>{if isset($nbFiling.$n)}<input type="checkbox" name="nb_pick[]" value="{$n}"{if !$nbFiling.$n.loaded} checked="checked"{else} disabled="disabled"{/if} />{/if}</td>
							<td><input type="text" class="form-control" name="nb_title[]" value="{$row.title|escape}" /></td>
							<td><input type="text" class="form-control" name="nb_author[]" value="{$row.author|escape}" /></td>
							<td><input type="text" class="form-control" name="nb_year[]" value="{$row.year|escape}" size="6" /></td>
							<td><input type="text" class="form-control" name="nb_publisher[]" value="{$row.publisher|escape}" /></td>
							<td>
								{if isset($nbFiling.$n)}
									{if $nbFiling.$n.loaded}{tr}already in the Library{/tr}
									{elseif $nbFiling.$n.kind eq 'author'}{$nbFiling.$n.author|escape}{if !$nbFiling.$n.gallery} <small>({tr}new gallery{/tr}{if $nbFiling.$n.joins}, {tr}brings in{/tr} {$nbFiling.$n.joins} {tr}loaded{/tr}{/if})</small>{/if}
									{else}{$nbFiling.$n.name|escape} <small>({tr}category{/tr})</small>{/if}
								{/if}
							</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
			<div class="form-group submit">
				<button type="submit" class="btn btn-default" name="nb_look" value="1">{tr}Check{/tr}</button>&nbsp;
				<button type="submit" class="btn btn-primary" name="nb_add" value="1"{if !$hasTopGallery || !$nbFiling} disabled="disabled"{/if}>{tr}Load the ticked books{/tr}</button>
			</div>
		{/form}

	</div>
</div>
{/strip}

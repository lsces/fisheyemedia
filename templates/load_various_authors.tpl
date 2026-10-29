{* Various Authors series - see load_various_authors.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Various Authors series{/tr}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}
		{foreach from=$results key=name item=done}
			<div class="alert alert-success"><p>{$name|escape}: {$done.added} {tr}books filed{/tr}, {$done.made} {tr}author series made{/tr}, {$done.linked} {tr}linked{/tr}, {$done.moved} {tr}books moved into them{/tr}{if $done.missing}, {$done.missing} {tr}not in the database{/tr}{/if}.</p></div>
		{/foreach}

		{if !$libraryRoot}
			<div class="alert alert-warning"><p>{tr}No Library folder is set: give the Book Library Storage Path in Media Library Settings.{/tr}</p></div>
		{elseif !$plans}
			<p>{tr}No series catalogues found: each series folder under{/tr} <code>{$libraryRoot|escape}Various Authors/</code> {tr}needs a SeriesCatalogue.csv.{/tr}</p>
		{else}
			<p>{tr}A series written by several authors is filed under a Various Authors gallery: the catalogue's books are added to it in catalogue order (they stay in their own author's series too) and each author's own gallery of the series is linked under it, which puts a Full Series tile on that author's page.{/tr}</p>
			{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`load_various_authors.php"}
				{foreach from=$plans key=name item=plan}
					<h3><label><input type="checkbox" name="series[]" value="{$name|escape}"{if !$plan.error} checked="checked"{/if} /> {$name|escape}</label>
						<small>{if $plan.seriesExists}{tr}gallery exists{/tr}{else}{tr}gallery will be made{/tr}{/if}</small></h3>
					{if $plan.error}
						<div class="alert alert-danger"><p>{$plan.error|escape}</p></div>
					{else}
						<p>{$plan.found} {tr}of{/tr} {$plan.rows|@count} {tr}books found in the database.{/tr}
</p>
						<ul>
							{foreach from=$plan.authorSeries item=own}
								<li>{$own.author|escape}: {if !$own.content_id}{tr}series gallery will be made{/tr}{else}{tr}has the series gallery{/tr}{/if}, {if $own.linked}{tr}linked{/tr}{else}{tr}to link{/tr}{/if}
									{if $own.toMove}<br /><label><input type="checkbox" name="move[{$name|escape}][{$own.author_id}]" value="1" /> {tr}Move into their series from the author's own list{/tr}: {foreach from=$own.toMove item=mv}{$mv.title|escape}{if !$mv@last}, {/if}{/foreach}</label>{/if}</li>
							{/foreach}
						</ul>
						<table class="table table-condensed">
							<thead><tr><th>{tr}Title{/tr}</th><th>{tr}Authors{/tr}</th><th>{tr}ISBN{/tr}</th><th></th></tr></thead>
							<tbody>
								{foreach from=$plan.rows item=row}
									<tr{if !$row.book} class="warning"{/if}>
										<td>{$row.title|escape}</td><td>{$row.authors|escape}</td><td>{$row.isbn|escape}</td>
										<td>{if $row.book}<a href="{$smarty.const.BIT_ROOT_URL}index.php?content_id={$row.book}">{tr}found{/tr}</a>{else}{tr}not in the database{/tr}{/if}</td>
									</tr>
								{/foreach}
							</tbody>
						</table>
					{/if}
				{/foreach}
				<div class="form-group submit">
					<input type="submit" class="btn btn-primary" name="file_series" value="{tr}File the ticked series{/tr}" />
				</div>
			{/form}
		{/if}
	</div>
</div>
{/strip}

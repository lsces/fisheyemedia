{* Author workbench front page - see author_workbench.php and FisheyeAuthorWorkbench::overview(). *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Author workbench{/tr}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}
		{if !$libraryRoot}
			<div class="alert alert-warning"><p>{tr}No Library folder is set: give the Book Library Storage Path in Media Library Settings.{/tr}</p></div>
		{else}
			<p>{tr}Every author folder in the Library and what is left to do for each, in the order the steps come: books to load, series to sort, folders with no book, files to load. An author with nothing left is put away.{/tr}
				{if $seriesLeft}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}series_workbench.php">{$seriesLeft} {tr}Various Authors series{/tr} {tr}still have work.{/tr}</a>{else}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}series_workbench.php">{tr}The Various Authors series are all finished.{/tr}</a>{/if}</p>
			{if $overview}
				<table class="table table-condensed">
					<thead><tr><th>{tr}Author{/tr}</th><th>{tr}Catalogue{/tr}</th><th>{tr}Left to do{/tr}</th><th>{tr}Next{/tr}</th></tr></thead>
					<tbody>
						{foreach from=$overview key=name item=row}
							<tr>
								<td>{if $row.galleryId}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}author_workbench.php?gallery_id={$row.galleryId}">{$name|escape}</a>{else}{$name|escape}{/if}</td>
								<td>{if $row.hasCatalogue}{$row.loaded} {tr}of{/tr} {$row.total} {tr}loaded{/tr}{else}<span class="text-muted">{tr}no catalogue file{/tr}</span>{/if}</td>
								<td>{if $row.needs}{foreach from=$row.needs item=need name=needs}{$need|escape}{if !$smarty.foreach.needs.last}; {/if}{/foreach}{else}<span class="text-muted">{tr}nothing{/tr}</span>{/if}</td>
								<td>
									{if $row.next == 'gallery'}
										{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`author_workbench.php"}
											<input type="hidden" name="create_gallery" value="{$name|escape}" />
											<input type="submit" class="btn btn-default btn-xs" value="{tr}Create the Library gallery{/tr}" />
										{/form}
									{elseif $row.done}{tr}Finished{/tr}
									{else}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}author_workbench.php?gallery_id={$row.galleryId}">{if $row.next == 'books'}{tr}Load the books{/tr}{elseif $row.next == 'series'}{tr}Sort the series{/tr}{elseif $row.next == 'folders'}{tr}Catalogue the folders{/tr}{elseif $row.next == 'files'}{tr}Open it (its files load){/tr}{else}{tr}Update the catalogue{/tr}{/if}</a>{/if}
								</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{else}
				<p>{tr}Every author is finished.{/tr}</p>
			{/if}
			<p>{if $showAll}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}author_workbench.php">{tr}Hide the finished authors{/tr}</a>{elseif $finished}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}author_workbench.php?all=1">{tr}Show the finished authors{/tr} ({$finished})</a>{/if}</p>
		{/if}
	</div>
</div>
{/strip}

{* Replace book covers from another site's ISBN-titled fisheye gallery - see load_book_covers.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Book Covers from a Gallery{/tr}</h1>
	</div>

	<div class="body">

		{if $results}
			<div class="alert alert-info">
				<p>{$results|@count} {tr}covers replaced{/tr}:</p>
				<ul>
					{foreach from=$results item=made}
						<li><a href="{$made.url|escape}">{$made.title|escape}</a> ({$made.isbn|escape}):
							{if $made.error}{$made.error|escape}{else}{$made.size|escape}{/if}
						</li>
					{/foreach}
				</ul>
			</div>
		{/if}

		{form legend="Source gallery" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_covers.php"}
			<div class="form-group">
				{formlabel label="Site" for="site"}
				{forminput}
					<input type="text" name="site" id="site" value="{$site|escape}" size="40" />
					{formhelp note="Another bitweaver site, with no trailing slash."}
				{/forminput}
			</div>
			<div class="form-group">
				{formlabel label="Gallery" for="gallery_id"}
				{forminput}
					<input type="text" name="gallery_id" id="gallery_id" value="{$galleryId|escape}" size="6" />
					{formhelp note="Its gallery id. The images must be titled with the book's ISBN-13, hyphens allowed."}
				{/forminput}
			</div>
			<div class="form-group submit">
				<input type="submit" class="btn btn-default" value="{tr}List{/tr}" />
			</div>
		{/form}

		{if $listError}
			<div class="alert alert-warning"><p>{$listError|escape}</p></div>
		{else}
			{form legend="Covers" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_covers.php"}
				<input type="hidden" name="site" value="{$site|escape}" />
				<input type="hidden" name="gallery_id" value="{$galleryId|escape}" />
				<p>{$rows|@count} {tr}images in the gallery;{/tr} {$matched} {tr}match a book in the Library;{/tr} {$waiting} {tr}still to bring in. Ticked covers replace the book's front cover and tile, stored at no more than 400px - its spine, back and other images stay. Each press does the first{/tr} {$batchSize} {tr}ticked.{/tr}</p>
				<table class="table table-condensed">
					<thead><tr><th></th><th>{tr}ISBN{/tr}</th><th>{tr}Book{/tr}</th><th>{tr}Now{/tr}</th><th>{tr}New{/tr}</th></tr></thead>
					<tbody>
						{foreach from=$rows item=row}
							<tr>
								<td>{if $row.book && !$row.done}<input type="checkbox" name="pick[]" value="{$row.id}" checked="checked" />{/if}</td>
								<td>{$row.title|escape}{if $row.fixed} <span class="label label-info" title="{tr}the check digit in the gallery title is wrong{/tr}">{$row.fixed|escape}</span>{elseif !$row.valid} <span class="label label-warning">{tr}not an ISBN-13{/tr}</span>{/if}</td>
								<td>{if $row.book}<a href="{$row.book.url|escape}">{$row.book.title|escape}</a>{else}<em>{tr}no book with this ISBN{/tr}</em>{/if}</td>
								<td>{if $row.old}<img src="{$row.old.thumb|escape}" alt="" /> {$row.old.size|escape}{elseif $row.book}{tr}no image{/tr}{/if}</td>
								<td><img src="{$row.thumb|escape}" alt="" />{if $row.done} {tr}brought in{/tr}{elseif $row.newSize|default:''} {$row.newSize|escape}{/if}</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
				{if $waiting}
					<div class="form-group submit">
						<input type="submit" class="btn btn-primary" name="replace" value="{tr}Replace the ticked covers{/tr}" />
					</div>
				{/if}
			{/form}
		{/if}

	</div>
</div>
{/strip}

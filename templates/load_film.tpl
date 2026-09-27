{* Lists un-registered films under Films/ (capped batch), with checkboxes to import a selection *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1><a href="{$topGalleryUrl|escape}">{tr}Films{/tr}</a>{if $folderName} - <a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_film.php">{tr}Next{/tr}</a> - <small>{$folderName|escape}</small>{/if}</h1>
	</div>

	<div class="body">

		<p>
			{if $scopeGallery}
				{tr}Linking into{/tr} <a href="{$scopeGallery->getDisplayUrl()|escape}">{$scopeGallery->getTitle()|escape}</a>
			{elseif $folderName}
				{tr}Folder:{/tr} <code>{$filmsDir|escape}</code>
				({tr}no gallery yet titled{/tr} "{$folderName|escape}" - {tr}imports won't be linked into a gallery until one exists{/tr})
			{else}
				{tr}Folder:{/tr} <code>{$filmsDir|default:'(storage root not configured)'|escape}</code>
			{/if}
			{if $folderName}({tr}folder{/tr}: <code>{$folderName|escape}</code>){/if}
		</p>

		{if $needsFolderChoice}
			<p>{tr}Pick which on-disk folder this gallery loads from - not assumed from its title.{/tr}</p>
		{/if}

		{if $subfolders}
			<div class="form-group">
				<label>{tr}Browse into a collection folder:{/tr}</label>
				<ul class="list-inline">
					{foreach from=$subfolders item=sub}
						<li><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_film.php?folder={$sub|escape:'url'}{if $scopeGallery}&gallery_id={$scopeGallery->mGalleryId}{/if}">{$sub|escape}</a></li>
					{/foreach}
				</ul>
			</div>
		{/if}

		{if $result}
			{if $result.error}
				<div class="alert alert-danger">{$result.error|escape}</div>
			{/if}
			{if $result.imported}
				<div class="alert alert-success">
					<p>{tr}Imported{/tr} {$result.imported|@count} {tr}in{/tr} {$result.total_seconds}s:</p>
					<ul>
						{foreach from=$result.imported item=row}
							<li>
								<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_film.php?content_id={$row.content_id}">{$row.path|escape}</a>
								{if $row.plex.matched}({tr}Plex metadata found{/tr}){else}({tr}no Plex match{/tr}){/if}
								{if $result.fetch_images}
									- {if $row.images.matched}{tr}images:{/tr} {$row.images.items|@count}{else}({tr}no images fetched{/tr}){/if}
								{/if}
								[{$row.seconds}s]
							</li>
						{/foreach}
					</ul>
				</div>
			{/if}
			{if $result.already}
				<div class="alert alert-warning">
					<p>{tr}Already registered - left as-is{/tr}:</p>
					<ul>{foreach from=$result.already item=row}<li>{$row.path|escape}</li>{/foreach}</ul>
				</div>
			{/if}
			{if $result.skipped}
				<div class="alert alert-warning">
					<p>{tr}No Plex match found - not imported{/tr} ({tr}fix the filename or the Plex match, then re-scan to pick it up{/tr}):</p>
					<ul>{foreach from=$result.skipped item=row}<li>{$row.path|escape}</li>{/foreach}</ul>
				</div>
			{/if}
			{if $result.errors}
				<div class="alert alert-danger">
					<p>{tr}Failed{/tr}:</p>
					<ul>{foreach from=$result.errors item=row}<li>{$row.path|escape} - {$row.error|escape}</li>{/foreach}</ul>
				</div>
			{/if}
		{/if}

		{if $candidates}
			{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_film.php"}
				{if $scopeGallery}<input type="hidden" name="gallery_id" value="{$scopeGallery->mGalleryId}" />{/if}
				{if $folderName}<input type="hidden" name="folder" value="{$folderName|escape}" />{/if}
				<p>{tr}Showing up to{/tr} {$candidateLimit} {tr}not-yet-loaded films.{/tr}</p>
				<div class="form-group">
					<label><input type="checkbox" name="fetch_images" value="1" checked="checked" /> {tr}Also fetch Plex poster/backdrop images per film (slower - only text metadata is fetched otherwise){/tr}</label>
				</div>
				<table class="table">
					<thead><tr>
						<th><input type="checkbox" onclick="var cb=this.form.elements['selected[]']; if(cb.length===undefined)cb=[cb]; for(var i=0;i<cb.length;i++)cb[i].checked=this.checked;" /></th>
						<th>{tr}Title{/tr}</th>
						<th>{tr}File{/tr}</th>
					</tr></thead>
					<tbody>
						{foreach from=$candidates item=film}
							<tr>
								<td><input type="checkbox" name="selected[]" value="{$film.relative_path|escape}" checked="checked" /></td>
								<td>{$film.title|escape}</td>
								<td><code>{$film.relative_path|escape}</code></td>
							</tr>
						{/foreach}
					</tbody>
				</table>
				<input type="submit" class="btn btn-primary" name="fImport" value="{tr}Import Selected{/tr}" />
			{/form}
		{elseif $filmsDir}
			<p>{tr}Nothing to load - every film under the Films folder is already registered.{/tr}</p>
		{/if}

	</div>
</div>
{/strip}

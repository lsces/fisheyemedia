{* Lists un-registered video files under this artist/composer gallery's own Videos/ folder, with
   checkboxes to import a selection - same shape as load_film.tpl, no folder picker needed since
   there's only ever one Videos/ folder per gallery. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1><a href="{$galleryUrl|escape}">{$galleryTitle|escape}</a> - {tr}Load Videos{/tr}</h1>
	</div>

	<div class="body">

		{if !$videosDir}
			<div class="alert alert-warning">
				{tr}No folder found on disk matching this gallery's title{/tr} ("{$galleryTitle|escape}") {tr}under Music/{/tr}.
			</div>
		{elseif !$candidates}
			<p>{tr}Folder{/tr}: <code>{$videosDir|escape}</code></p>
		{/if}

		{if $result}
			{if $result.error}
				<div class="alert alert-danger">{$result.error|escape}</div>
			{/if}
			{if $result.imported}
				<div class="alert alert-success">
					<p>{tr}Imported{/tr}:</p>
					<ul>
						{foreach from=$result.imported item=row}
							<li>
								<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_film.php?content_id={$row.content_id}">{$row.path|escape}</a>
								{if $row.plex.matched}({tr}Plex metadata found{/tr}){else}({tr}no Plex match - registered from disk only{/tr}){/if}
								{if $result.fetch_images}
									- {if $row.images.matched}{tr}images:{/tr} {$row.images.items|@count}{else}({tr}no images fetched{/tr}){/if}
								{/if}
							</li>
						{/foreach}
					</ul>
				</div>
			{/if}
			{if $result.already}
				<div class="alert alert-warning">
					<p>{tr}Already registered - linked into this Videos gallery if it wasn't already{/tr}:</p>
					<ul>{foreach from=$result.already item=row}<li>{$row.path|escape}</li>{/foreach}</ul>
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
			{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_video.php"}
				<input type="hidden" name="gallery_id" value="{$galleryIdParam}" />
				<p>{tr}Showing up to{/tr} {$candidateLimit} {tr}not-yet-loaded videos under{/tr} <code>{$videosDir|escape}</code>:</p>
				<div class="form-group">
					<label><input type="checkbox" name="fetch_images" value="1" checked="checked" /> {tr}Also fetch Plex poster/backdrop images per video, if a Plex match exists (slower){/tr}</label>
				</div>
				<table class="table">
					<thead><tr>
						<th><input type="checkbox" onclick="var cb=this.form.elements['selected[]']; if(cb.length===undefined)cb=[cb]; for(var i=0;i<cb.length;i++)cb[i].checked=this.checked;" /></th>
						<th>{tr}Title{/tr}</th>
						<th>{tr}File{/tr}</th>
					</tr></thead>
					<tbody>
						{foreach from=$candidates item=video}
							<tr>
								<td><input type="checkbox" name="selected[]" value="{$video.relative_path|escape}" checked="checked" /></td>
								<td>{$video.title|escape}</td>
								<td><code>{$video.relative_path|escape}</code></td>
							</tr>
						{/foreach}
					</tbody>
				</table>
				<input type="submit" class="btn btn-primary" name="fImport" value="{tr}Import Selected{/tr}" />
			{/form}
		{elseif $videosDir}
			<p>{tr}Nothing to load - every video under this folder is already registered.{/tr}</p>
		{/if}

	</div>
</div>
{/strip}

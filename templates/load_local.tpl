{* Loads the Local Videos folder into its own top-level gallery - see load_local.php's own docblock.
   Loose files go straight into the gallery, each collection folder into a gallery of its own. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{if $topGallery}<a href="{$topGallery->getDisplayUrl()|escape}">{tr}Local Videos{/tr}</a>{else}{tr}Local Videos{/tr}{/if} - {tr}Load{/tr}{if $folderName}: {$folderName|escape}{/if}</h1>
	</div>

	<div class="body">

		{if !$localDirExists}
			<div class="alert alert-warning"><p>{tr}There is no Local Videos folder under the storage root{/tr}: <code>{$localDir|escape}</code></p></div>
		{/if}

		{if $result}
			{if $result.error}
				<div class="alert alert-danger">{$result.error|escape}</div>
			{/if}
			{if $result.created_top}
				<div class="alert alert-success"><p>{tr}Created the Local Videos gallery.{/tr}</p></div>
			{/if}
			{if $result.imported}
				<div class="alert alert-success">
					<p>{tr}Imported{/tr}:</p>
					<ul>{foreach from=$result.imported item=row}<li><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_film.php?content_id={$row.content_id}">{$row.path|escape}</a></li>{/foreach}</ul>
				</div>
			{/if}
			{if $result.already}
				<div class="alert alert-warning">
					<p>{tr}Already registered - linked into this gallery if it was not already{/tr}:</p>
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

		{if !$topGallery}
			{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_local.php"}
				<p>{tr}There is no top-level Local Videos gallery yet.{/tr}</p>
				<input type="submit" class="btn btn-primary" name="fCreateTop" value="{tr}Create the Local Videos gallery{/tr}" />
			{/form}
		{else}
			<p>
				<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_local.php">{tr}Loose videos{/tr}</a>
				{foreach from=$folders item=folder}
					| {if $folder.series}{$folder.name|escape} ({$folder.files}, {tr}series - load as a show{/tr}){else}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_local.php?folder={$folder.name|escape:'url'}">{$folder.name|escape}</a> ({$folder.files}){/if}
				{/foreach}
			</p>

			{if $candidates}
				{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_local.php"}
					<input type="hidden" name="folder" value="{$folderName|escape}" />
					<p>{tr}Showing up to{/tr} {$candidateLimit} {tr}not-yet-loaded videos{/tr}{if $folderName} {tr}in{/tr} <code>{$folderName|escape}</code>, {tr}which becomes its own gallery under Local Videos{/tr}{/if}. {tr}Each is titled from its file name and needs no Plex match.{/tr}</p>
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
			{elseif $localDirExists}
				<p>{tr}Nothing to load here - every video is already registered.{/tr}</p>
			{/if}
		{/if}

	</div>
</div>
{/strip}

{* Admin: register an already-on-disk film via mime.film.php, with best-effort Plex backfill *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Import Film{/tr}</h1>
	</div>

	<div class="body">

		<p>{tr}Storage root:{/tr} <code>{$storageRoot|default:'(not configured)'|escape}</code></p>

		{if $result.error}
			<div class="alert alert-danger">{$result.error|escape}</div>
		{/if}
		{if $result.already}
			<div class="alert alert-warning">
				{tr}Already registered{/tr} - <a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_film.php?content_id={$result.already}">{tr}view it{/tr}</a>
			</div>
		{/if}
		{if $result.created}
			<div class="alert alert-success">
				<p>{tr}Registered{/tr} - <a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_film.php?content_id={$result.created}">{tr}view it{/tr}</a>{if !$result.linked} ({tr}could not link into the 'Films' gallery{/tr}){/if}</p>
				{if $result.plex.matched}
					<p>{tr}Plex metadata found:{/tr}</p>
					<ul>{foreach from=$result.plex.items item=line}<li>{$line|escape}</li>{/foreach}</ul>
				{else}
					<p>{tr}No matching Plex entry found - add metadata by hand via the film's own edit page.{/tr}</p>
				{/if}
			</div>
		{/if}

		{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}admin/admin_import_film.php"}
			<div class="form-group">
				{formlabel label="Relative path" for="relative_path"}
				{forminput}
					<input type="text" class="form-control" name="relative_path" id="relative_path" value="{$smarty.request.relative_path|escape}" />
					{formhelp note="Path under the storage root above, e.g. Films/Some Film (2020).mp4"}
				{/forminput}
			</div>
			<div class="form-group">
				{formlabel label="Title" for="title"}
				{forminput}
					<input type="text" class="form-control" name="title" id="title" value="{$smarty.request.title|escape}" />
					{formhelp note="Leave blank to use the filename."}
				{/forminput}
			</div>
			<input type="submit" class="btn btn-primary" name="fImport" value="{tr}Import{/tr}" />
		{/form}

	</div>
</div>
{/strip}

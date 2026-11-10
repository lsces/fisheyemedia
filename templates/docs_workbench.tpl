{* Docs workbench, one topic - see docs_workbench.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		{* the usual title trail: the Documentation gallery, this topic's gallery (once made), then this page *}
		<h1>{if $topUrl}<a href="{$topUrl|escape}">{$topTitle|escape}</a>{else}{$topTitle|escape}{/if} - {if $topicUrl}<a href="{$topicUrl|escape}">{$st.name|escape}</a>{else}{$st.name|escape}{/if} - {tr}Docs workbench{/tr}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}
		{foreach from=$messages item=message}
			<div class="alert alert-success"><p>{$message|escape}</p></div>
		{/foreach}

		{if $st.error}
			<div class="alert alert-danger"><p>{$st.error|escape}</p></div>
		{else}
			<p class="help-block"><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}docs_workbench.php">{tr}All topics{/tr}</a>{if $st.done} - <strong>{tr}Finished{/tr}</strong>: {tr}every file is loaded.{/tr}{/if}</p>

			<h3>{tr}Files{/tr} <small>{$st.loaded} {tr}of{/tr} {$st.total} {tr}loaded{/tr}</small></h3>
			{if $st.todo}
				<p class="help-block">{tr}At most{/tr} {$batch} {tr}files are loaded per press.{/tr}</p>
				{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`docs_workbench.php"}
					<input type="hidden" name="topic" value="{$st.name|escape}" />
					<input type="submit" class="btn btn-primary" name="load_files" value="{tr}Load files{/tr} ({$st.todo} {tr}left{/tr})" />
				{/form}
			{/if}
			{if $st.noHandler}<p class="help-block">{$st.noHandler} {tr}files are of a kind with no loader yet (PDFs and epubs only so far) and are listed but not loaded.{/tr}</p>{/if}
			{if $st.tooLong}
				<div class="alert alert-warning"><p>{tr}These paths are too long to be stored and are skipped:{/tr}</p><ul>{foreach from=$st.tooLong item=path}<li>{$path|escape}</li>{/foreach}</ul></div>
			{/if}
			{if $st.total}
			<table class="table table-condensed">
				<thead><tr><th>{tr}File{/tr}</th><th>{tr}Size{/tr}</th><th>{tr}State{/tr}</th></tr></thead>
				<tbody>
					{foreach from=$st.files item=file}
						<tr>
							<td>{$file.path|escape}</td>
							<td>{($file.size/1048576)|string_format:"%.1f"} MB</td>
							<td>{if $file.contentId}<a href="{$smarty.const.FISHEYE_PKG_URL}view_image.php?content_id={$file.contentId}">{tr}loaded{/tr}</a>{else}<span class="text-muted">{if $file.handler}{tr}not loaded{/tr}{else}{tr}no loader for this kind{/tr}{/if}</span>{/if}</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
			{else}
				<p class="text-muted">{tr}There are no files for this topic in the Documentation folder.{/tr}</p>
			{/if}

			{* ---- what did not come from the Documentation folder ---- *}
			<h3>{tr}Uploaded to the gallery{/tr} <small>{$uploads.uploaded|@count}</small></h3>
			{if $uploads.uploaded}
				<p class="help-block">{tr}Files that were uploaded into this gallery (a stored copy, not loaded in place).{/tr}</p>
				<table class="table table-condensed">
					<thead><tr><th>{tr}Title{/tr}</th><th>{tr}Type{/tr}</th><th>{tr}Size{/tr}</th></tr></thead>
					<tbody>
						{foreach from=$uploads.uploaded item=up}
							<tr>
								<td><a href="{$smarty.const.FISHEYE_PKG_URL}view_image.php?content_id={$up.contentId}">{$up.title|escape}</a></td>
								<td>{$up.mime|escape}</td>
								<td>{($up.size/1048576)|string_format:"%.1f"} MB</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{else}
				<p class="text-muted">{tr}Nothing was uploaded into this gallery.{/tr}</p>
			{/if}
		{/if}
	</div>
</div>
{/strip}

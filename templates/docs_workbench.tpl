{* Docs workbench, one topic - see docs_workbench.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		{* the usual title trail: the Documentation gallery, this topic's gallery (once made), then this page *}
		<h1>{if $topUrl}<a href="{$topUrl|escape}">{$topTitle|escape}</a>{else}{$topTitle|escape}{/if} - {if $topicUrl}<a href="{$topicUrl|escape}">{$st.name|escape}</a>{else}{$st.name|escape}{/if} - {$pageTitle|escape}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}
		{foreach from=$messages item=message}
			<div class="alert alert-success"><p>{$message|escape}</p></div>
		{/foreach}

		{if $st.error}
			<div class="alert alert-danger"><p>{$st.error|escape}</p></div>
		{else}
			<p class="help-block"><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}docs_workbench.php?kind={$kind}">{tr}All{/tr} {$noun|escape}s</a>{if $st.done} - <strong>{tr}Finished{/tr}</strong>: {tr}every file is loaded.{/tr}{/if}</p>

			<h3>{tr}Files{/tr} <small>{$st.loaded} {tr}of{/tr} {$st.total} {tr}loaded{/tr}</small></h3>
			{if $st.todo}
				<p class="help-block">{tr}A press loads at most{/tr} {$batch} {tr}files. Keep going works for about{/tr} {$keepSeconds} {tr}seconds, then sends the next press by itself until the files are loaded, or a press loads nothing; a file that fails is listed and tried again, and does not stop it. Close the page or press Stop to end it.{/tr}</p>
				{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`docs_workbench.php" id="docsWorkbenchForm"}
					<input type="hidden" name="kind" value="{$kind}" />
					<input type="hidden" name="topic" value="{$st.name|escape}" />
					<label><input type="checkbox" name="keep_going" value="1"{if $keepGoingChecked} checked="checked"{/if} /> {tr}Keep going{/tr}</label>
					<input type="submit" class="btn btn-primary" name="load_files" value="{tr}Load files{/tr} ({$st.todo} {tr}left{/tr})" />
					{if $continue}<a class="btn btn-default" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}docs_workbench.php?kind={$kind}&amp;topic={$st.name|escape:'url'}">{tr}Stop{/tr}</a>{/if}
				{/form}
				{if $continue}
					<p class="help-block"><em>{tr}Loading the next press...{/tr}</em></p>
					<script>window.setTimeout( function() { var f = document.getElementById( 'docsWorkbenchForm' ); if( f ) { var b = document.createElement( 'input' ); b.type = 'hidden'; b.name = 'load_files'; b.value = '1'; f.appendChild( b ); f.submit(); } }, 800 );</script>
				{/if}
			{/if}
			{if $st.noHandler}<p class="help-block">{$st.noHandler} {tr}files are of a kind with no loader yet (PDFs and epubs only so far) and are listed but not loaded.{/tr}</p>{/if}
			{if $st.tooLong}
				<div class="alert alert-warning"><p>{tr}These paths are too long to be stored and are skipped:{/tr}</p><ul>{foreach from=$st.tooLong item=path}<li>{$path|escape}</li>{/foreach}</ul></div>
			{/if}
			{if $st.folders|@count > 1}
			<table class="table table-condensed">
				<thead><tr><th>{tr}Folder{/tr}</th><th>{tr}Files{/tr}</th><th>{tr}Loaded{/tr}</th></tr></thead>
				<tbody>
					{foreach from=$st.folders key=folder item=fc}
						<tr><td>{$folder|escape}</td><td>{$fc.total}</td><td>{if $fc.loaded eq $fc.total}{$fc.loaded}{else}<strong>{$fc.loaded}</strong>{/if}</td></tr>
					{/foreach}
				</tbody>
			</table>
			{/if}
			{if $st.total && $st.total <= 150}
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
			{elseif $st.total}
				<p class="text-muted">{tr}More than 150 files: the folders above show where each stands.{/tr}</p>
			{else}
				<p class="text-muted">{tr}There are no files for this folder in the {/tr}{$topTitle|escape}{tr} folder.{/tr}</p>
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

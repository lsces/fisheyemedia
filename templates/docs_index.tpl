{* Docs workbench front page - see docs_workbench.php and FisheyeDocsWorkbench::overview(). *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Docs workbench{/tr}</h1>
	</div>

	<div class="body">
		{if !$root}
			<div class="alert alert-warning"><p>{tr}No Documentation folder was found: set the Documentation Storage Path in Media Library Settings.{/tr}</p></div>
		{else}
			{if !$hasTop}
				<div class="alert alert-warning"><p>{tr}There is no top-level gallery called{/tr} {$topTitle|escape}{tr}, so nothing can be filed. Make it first.{/tr}</p></div>
			{/if}
			<p>{tr}Every topic - the folders in Documentation, the galleries and the upload sources folders - and what is left to load. The files are loaded where they are, not copied, and filed in a gallery for their folder under{/tr} {$topTitle|escape}.</p>
			{if $overview}
				<table class="table table-condensed">
					<thead><tr><th>{tr}Topic{/tr}</th><th>{tr}Files{/tr}</th><th>{tr}Loaded{/tr}</th><th>{tr}Left to do{/tr}</th><th>{tr}Uploaded{/tr}</th><th>{tr}Not in the gallery{/tr}</th></tr></thead>
					<tbody>
						{foreach from=$overview key=name item=row}
							<tr>
								<td><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}docs_workbench.php?topic={$name|escape:'url'}">{$name|escape}</a></td>
								<td>{$row.total}</td>
								<td>{$row.loaded}</td>
								<td>{if $row.error}{$row.error|escape}{elseif $row.todo}{$row.todo}{else}<span class="text-muted">{tr}nothing{/tr}</span>{/if}</td>
								<td>{$row.uploaded}</td>
								<td>{if $row.notInGallery}{$row.notInGallery}{else}<span class="text-muted">-</span>{/if}</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{else}
				<p>{tr}Every topic is finished.{/tr}</p>
			{/if}
			<p>{if $showAll}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}docs_workbench.php">{tr}Hide the finished topics{/tr}</a>{elseif $finished}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}docs_workbench.php?all=1">{tr}Show the finished topics{/tr} ({$finished})</a>{/if}</p>
		{/if}
	</div>
</div>
{/strip}

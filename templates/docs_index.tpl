{* Docs workbench front page - see docs_workbench.php and FisheyeDocsWorkbench::overview(). *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{$pageTitle|escape}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}
		{foreach from=$messages item=message}
			<div class="alert alert-success"><p>{$message|escape}</p></div>
		{/foreach}
		{if !$root}
			<div class="alert alert-warning"><p>{tr}No {$topTitle|escape} folder was found: set the {$topTitle|escape} Storage Path in Media Library Settings.{/tr}</p></div>
		{else}
			{if !$hasTop}
				<div class="alert alert-warning"><p>{tr}There is no top-level gallery called{/tr} {$topTitle|escape}{tr}, so nothing can be filed.{/tr}</p>
					{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`docs_workbench.php"}
						<input type="hidden" name="kind" value="{$kind}" />
						<input type="submit" class="btn btn-default" name="make_top" value="{tr}Make the gallery{/tr}" />
					{/form}
				</div>
			{/if}
			<p>{tr}Every{/tr} {$noun|escape} - {tr}the folders in{/tr} {$topTitle|escape} {tr}and the galleries - and what is left to load. The files are loaded where they are, not copied, and filed in a gallery for their folder under{/tr} {$topTitle|escape}.</p>
			{if $overview}
				<table class="table table-condensed">
					<thead><tr><th>{$noun|ucfirst}</th><th>{tr}Files{/tr}</th><th>{tr}Loaded{/tr}</th><th>{tr}Left to do{/tr}</th><th>{tr}Uploaded{/tr}</th></tr></thead>
					<tbody>
						{foreach from=$overview key=name item=row}
							<tr>
								<td><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}docs_workbench.php?kind={$kind}&amp;topic={$name|escape:'url'}">{$name|escape}</a></td>
								<td>{$row.total}</td>
								<td>{$row.loaded}</td>
								<td>{if $row.error}{$row.error|escape}{elseif $row.todo}{$row.todo}{else}<span class="text-muted">{tr}nothing{/tr}</span>{/if}</td>
								<td>{$row.uploaded}</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{else}
				<p>{tr}Everything is finished.{/tr}</p>
			{/if}
			<p>{if $showAll}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}docs_workbench.php?kind={$kind}">{tr}Hide the finished ones{/tr}</a>{elseif $finished}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}docs_workbench.php?kind={$kind}&amp;all=1">{tr}Show the finished ones{/tr} ({$finished})</a>{/if}</p>
		{/if}
	</div>
</div>
{/strip}

{* Tutorial workbench front page - see tutorial_workbench.php and FisheyeTutorialWorkbench::overview(). *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Tutorial workbench{/tr}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}
		{if !$root}
			<div class="alert alert-warning"><p>{tr}No Local Tutorials folder was found: set the Tutorials Storage Path (or the External Disk Storage Path) in Media Library Settings.{/tr}</p></div>
		{else}
			{if !$hasTopGallery}
				<div class="alert alert-warning"><p>{tr}There is no top-level Local Tutorials gallery yet, so a course cannot be filed. Make it from Load Local Tutorials.{/tr}</p></div>
			{/if}
			<p>{tr}Every course folder in Local Tutorials and what is left to do for each, in the order the steps come: register the course, load its seasons, load the files that go with them. A course with nothing left is put away.{/tr}</p>
			{if $overview}
				<table class="table table-condensed">
					<thead><tr><th>{tr}Course{/tr}</th><th>{tr}Seasons{/tr}</th><th>{tr}Left to do{/tr}</th></tr></thead>
					<tbody>
						{foreach from=$overview key=name item=row}
							<tr>
								<td><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}tutorial_workbench.php?course={$name|escape:'url'}">{$name|escape}</a></td>
								<td>{$row.seasons|@count}</td>
								<td>
									{if $row.error}{$row.error|escape}
									{elseif !$row.contentId}{tr}not registered{/tr}
									{else}
										{if $row.seasonsTodo}{$row.seasonsTodo} {tr}seasons to load{/tr}{/if}{if $row.seasonsTodo && $row.filesTodo}; {/if}{if $row.filesTodo}{$row.filesTodo} {tr}seasons with files to load{/tr}{/if}{if $row.done}<span class="text-muted">{tr}nothing{/tr}</span>{/if}
									{/if}
								</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{else}
				<p>{tr}Every course is finished.{/tr}</p>
			{/if}
			<p>{if $showAll}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}tutorial_workbench.php">{tr}Hide the finished courses{/tr}</a>{elseif $finished}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}tutorial_workbench.php?all=1">{tr}Show the finished courses{/tr} ({$finished})</a>{/if}</p>
		{/if}
	</div>
</div>
{/strip}

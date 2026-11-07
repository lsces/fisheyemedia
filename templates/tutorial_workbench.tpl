{* Tutorial workbench, one course - see tutorial_workbench.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{if $st.url}<a href="{$st.url|escape}" title="{tr}Open the course{/tr}">{$st.name|escape}</a>{else}{$st.name|escape}{/if} - {tr}Tutorial workbench{/tr}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}
		{foreach from=$messages item=message}
			<div class="alert alert-success"><p>{$message|escape}</p></div>
		{/foreach}

		{if $st.error}
			<div class="alert alert-danger"><p>{$st.error|escape}</p></div>
		{else}
			<p class="help-block"><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}tutorial_workbench.php">{tr}All courses{/tr}</a>{if $st.done} - <strong>{tr}Finished{/tr}</strong>: {tr}every step is done.{/tr}{/if}</p>

			{* ---- 1. Course ---- *}
			<h3>1. {tr}Course{/tr}</h3>
			{if $st.contentId && $st.legacy}
				<div class="alert alert-warning"><p>{tr}This course was registered as a TV show, before tutorials had their own type. Convert it, and its seasons, to the tutorial types so they use the tutorial scheme (Tutorial Details, Extras). Nothing they hold is lost.{/tr}</p></div>
				{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`tutorial_workbench.php"}
					<input type="hidden" name="course" value="{$st.name|escape}" />
					<input type="submit" class="btn btn-primary" name="convert_course" value="{tr}Convert to a tutorial course{/tr}" />
				{/form}
			{elseif $st.contentId}
				<p>{tr}Registered.{/tr}</p>
			{else}
				{if !$hasTopGallery}<div class="alert alert-warning"><p>{tr}There is no top-level Local Tutorials gallery yet, so the course cannot be filed. Make it on the{/tr} <a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}tutorial_workbench.php">{tr}workbench front page{/tr}</a> {tr}first.{/tr}</p></div>{/if}
				{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`tutorial_workbench.php"}
					<input type="hidden" name="course" value="{$st.name|escape}" />
					<input type="submit" class="btn btn-primary" name="register_course" value="{tr}Register the course{/tr}" />
				{/form}
			{/if}

			{if $st.contentId}
				{* ---- 2. Seasons ---- *}
				<h3>2. {tr}Seasons{/tr} <small>{$st.seasonsLoaded} {tr}of{/tr} {$st.seasons|@count} {tr}loaded{/tr}</small></h3>
				{if $st.seasonsTodo}
					<p class="help-block">{tr}At most{/tr} {$batch} {tr}seasons are loaded per press.{/tr}</p>
					{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`tutorial_workbench.php"}
						<input type="hidden" name="course" value="{$st.name|escape}" />
						<input type="submit" class="btn btn-primary" name="load_seasons" value="{tr}Load seasons{/tr} ({$st.seasonsTodo} {tr}left{/tr})" />
					{/form}
				{else}
					<p>{tr}Every season is loaded.{/tr}</p>
				{/if}

				{* ---- 3. Files ---- *}
				<h3>3. {tr}Files{/tr}</h3>
				{if $st.filesTodo}
					<p class="help-block">{tr}Exercise archives, notes and handouts in a season's folder become Extras on that season, opened (downloaded) from there. At most{/tr} {$batch} {tr}seasons are done per press.{/tr}</p>
					{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`tutorial_workbench.php"}
						<input type="hidden" name="course" value="{$st.name|escape}" />
						<input type="submit" class="btn btn-primary" name="load_files" value="{tr}Load files{/tr} ({$st.filesTodo} {tr}seasons{/tr})" />
					{/form}
				{else}
					<p>{tr}The files of every loaded season are up to date.{/tr}</p>
				{/if}

				<table class="table table-condensed">
					<thead><tr><th>{tr}Season folder{/tr}</th><th>{tr}Loaded{/tr}</th><th>{tr}Files on disk{/tr}</th><th>{tr}Files loaded{/tr}</th></tr></thead>
					<tbody>
						{foreach from=$st.seasons item=season}
							<tr>
								<td>{$season.folder|escape}</td>
								<td>{if $season.contentId}<a href="{$smarty.const.BIT_ROOT_URL}index.php?content_id={$season.contentId}">{tr}yes{/tr}</a>{else}{tr}no{/tr}{/if}</td>
								<td>{$season.filesDisk}</td>
								<td>{if $season.contentId}{$season.filesHave}{if $season.filesTodo} <strong>{tr}differs{/tr}</strong>{/if}{else}-{/if}</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{/if}
		{/if}
	</div>
</div>
{/strip}

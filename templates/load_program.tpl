{* Two levels: no show yet = pick a show folder to register; ?show=Name = pick season folders
   under that (already-registered) show. Plain lists, not load_film.php's big table - a show's
   season count is small enough that it doesn't need one. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1><a href="{$topGalleryUrl|escape}">{tr}TV Shows{/tr}</a>{if $scopeShow} - <a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_program.php">{tr}Next{/tr}</a> - <a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_program.php?content_id={$scopeShow.content_id}">{$scopeShow.title|escape}</a>{/if}</h1>
	</div>

	<div class="body">

		{if $showResult.error}
			<div class="alert alert-danger">{$showResult.error|escape}</div>
		{elseif $showResult.no_match}
			<div class="alert alert-warning">
				{tr}Registered show{/tr} "{$scopeShow.title|escape}" -
				{tr}no Plex match, so metadata/images were not fetched.{/tr}
				<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_program.php?content_id={$showResult.created}">{tr}Search Plex to fix it{/tr}</a>
			</div>
		{elseif $showResult.created}
			<div class="alert alert-success">
				{tr}Registered show{/tr} "{$scopeShow.title|escape}"
				({tr}Plex metadata found{/tr})
				- {if $showResult.images.items}{$showResult.images.items|@count} {tr}images{/tr}{else}{tr}no images fetched{/tr}{/if}
			</div>
		{/if}

		{if $seasonResult}
			{if $seasonResult.created}
				<div class="alert alert-success">
					<p>{tr}Seasons loaded{/tr}:</p>
					<ul>
						{foreach from=$seasonResult.created item=row}
							<li>{if $row.folder == '.'}{tr}Season 1{/tr}{else}{$row.folder|escape}{/if} - {$row.episodes.items|@count} {tr}episodes{/tr}, {if $row.images.items}{$row.images.items|@count} {tr}images{/tr}{else}{tr}no images fetched{/tr}{/if}</li>
						{/foreach}
					</ul>
				</div>
			{/if}
			{if $seasonResult.errors}
				<div class="alert alert-danger">
					<p>{tr}Failed{/tr}:</p>
					<ul>{foreach from=$seasonResult.errors item=row}<li>{$row.folder|escape} - {$row.error|escape}</li>{/foreach}</ul>
				</div>
			{/if}
		{/if}

		{if $episodeReloadResult}
			<div class="alert alert-success">
				{tr}Episodes reloaded for{/tr} "{$episodeReloadResult.title|escape}" -
				{$episodeReloadResult.episodes.items|@count} {tr}episodes{/tr}
			</div>
		{/if}

		{if $reloadCandidates}
			<div class="alert alert-warning">
				<p>{tr}Seasons with more episode files on disk than currently registered{/tr}:</p>
				<ul>
					{foreach from=$reloadCandidates item=row}
						<li>
							{$row.title|escape} - {$row.registered} {tr}registered{/tr}, {$row.on_disk} {tr}on disk{/tr}
							{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_program.php"}
								<input type="hidden" name="gallery_id" value="{$scopeShow.gallery_id}" />
								<input type="hidden" name="season_content_id" value="{$row.content_id}" />
								<input type="submit" class="btn btn-primary btn-sm" name="fReloadSeasonEpisodes" value="{tr}Reload Episodes{/tr}" />
							{/form}
						</li>
					{/foreach}
				</ul>
			</div>
		{/if}

		{if !$scopeShow && $candidates}
			<p>{tr}Showing up to{/tr} {$candidateLimit} {tr}not-yet-loaded shows.{/tr}</p>
			<ul>
				{foreach from=$candidates item=show}
					<li><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_program.php?show={$show|escape:'url'}">{$show|escape}</a></li>
				{/foreach}
			</ul>
		{elseif !$scopeShow}
			<p>{tr}Nothing to load - every show folder is already registered.{/tr}</p>
		{/if}

		{if $scopeShow && $candidates}
			{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_program.php"}
				<input type="hidden" name="gallery_id" value="{$scopeShow.gallery_id}" />
				<p>{tr}Not-yet-loaded seasons for{/tr} "{$scopeShow.title|escape}":</p>
				<ul>
					{foreach from=$candidates item=season}
						<li>
							<label>
								<input type="checkbox" name="selected[]" value="{$season|escape}" checked="checked" />
								{if $season == '.'}{tr}Season 1{/tr} ({tr}no season subfolder - files directly in show folder{/tr}){else}{$season|escape}{/if}
							</label>
						</li>
					{/foreach}
				</ul>
				<input type="submit" class="btn btn-primary" name="fImportSeasons" value="{tr}Load Selected Seasons{/tr}" />
			{/form}
		{elseif $scopeShow}
			<p>{tr}Nothing to load - every season folder for this show is already registered.{/tr}</p>
		{/if}

	</div>
</div>
{/strip}

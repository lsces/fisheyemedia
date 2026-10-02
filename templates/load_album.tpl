{* Single level - the gallery (an artist/composer folder) is already known via gallery_id, no
   show->season-style discovery step needed the way load_program.php has. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		{* Breadcrumbs: Music (back to load_music, to pick the next folder) > the parent artist's own
		   load_album with this collection's strip picked (a collection's page only) > this gallery
		   itself (to look at what's been built) *}
		<h1><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_music.php">{tr}Music{/tr}</a> &rsaquo; {if $backUrl}<a href="{$backUrl|escape}">{$backTitle|escape}</a> &rsaquo; {/if}<a href="{$galleryUrl|escape}">{$galleryTitle|escape}</a> - {tr}Load Albums{/tr}</h1>
	</div>

	<div class="body">

		{if !$artistDir}
			<div class="alert alert-warning">
				{tr}No folder found on disk matching this gallery's title{/tr} ("{$galleryTitle|escape}")
				{tr}under any base folder inside Music/.{/tr}
			</div>
		{/if}

		{if $importResult}
			{if $importResult.created}
				<div class="alert alert-success">
					<p>{tr}Albums loaded{/tr}:</p>
					<ul>
						{foreach from=$importResult.created item=row}
							<li><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_album.php?content_id={$row.content_id}">{$row.folder|escape}</a>{if $row.tracks} - {$row.tracks} {tr}tracks{/tr}{/if}{if !$row.cover} ({tr}no cover art found{/tr}){/if}{if $row.discogs.url} - {tr}Discogs link found{/tr}{elseif $row.discogs.none} - {tr}no Discogs link on MusicBrainz{/tr}{elseif $row.discogs.error} - {$row.discogs.error|escape}{/if}</li>
						{/foreach}
					</ul>
				</div>
			{/if}
			{if $importResult.subgalleries}
				<div class="alert alert-success">
					<p>{tr}Sub-galleries created{/tr} ({tr}pick which albums/discs to load next{/tr}):</p>
					<ul>
						{foreach from=$importResult.subgalleries item=subgallery}
							<li>
								<a href="{$subgallery.url|escape}">{$subgallery.folder|escape}</a>
								{if $subgallery.already}({tr}already existed{/tr}){/if}
								- <a href="{$subgallery.loadUrl|escape}">{tr}Load its contents now{/tr}</a>
							</li>
						{/foreach}
					</ul>
				</div>
			{/if}
			{if $importResult.errors}
				<div class="alert alert-danger">
					<p>{tr}Failed{/tr}:</p>
					<ul>{foreach from=$importResult.errors item=row}<li>{$row.folder|escape} - {$row.error|escape}</li>{/foreach}</ul>
				</div>
			{/if}
		{/if}

		{if $groups}
			<p>{tr}Process{/tr}:&nbsp;
				{if $groupParam eq ''}<strong>{tr}Everything{/tr}</strong>{else}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_album.php?gallery_id={$galleryIdParam}">{tr}Everything{/tr}</a>{/if}
				{foreach from=$groups key=groupKey item=group}
					{if $group.to_load || $groupKey eq $groupParam}
						&nbsp;&middot;&nbsp;{if $groupKey eq $groupParam}<strong>{$group.title|escape} ({$group.to_load})</strong>{else}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_album.php?gallery_id={$galleryIdParam}&amp;group={$groupKey|escape:'url'}">{$group.title|escape} ({$group.to_load})</a>{/if}
					{/if}
				{/foreach}
			</p>
		{/if}
		{if $artistDir}
			<p>{$scanCounts.album} {tr}album folders{/tr} ({$scanCounts.album_loaded} {tr}loaded{/tr}, {$scanCounts.album-$scanCounts.album_loaded} {tr}still to load{/tr}){if $scanCounts.collection}, {$scanCounts.collection} {tr}collections{/tr} ({$scanCounts.collection_loaded} {tr}done{/tr}, {$scanCounts.collection-$scanCounts.collection_loaded} {tr}still to do{/tr}){/if}.</p>
		{/if}
		{if $candidates}
			{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_album.php"}
				<input type="hidden" name="gallery_id" value="{$galleryIdParam}" />
				<input type="hidden" name="group" value="{$groupParam|escape}" />
				<p>{tr}Showing up to{/tr} {$candidateLimit} {tr}not-yet-loaded albums for{/tr} "{$galleryTitle|escape}":</p>
				<div class="form-group">
					<label><input type="checkbox" name="fetch_discogs" value="1" checked="checked" /> {tr}Also fetch a linked Discogs release per album, if one exists on MusicBrainz (slower){/tr}</label>
				</div>
				<ul class="list-unstyled">
					{foreach from=$candidates item=candidate}
						{if $candidate.kind eq 'collection'}
							<li style="margin-bottom:4px;">
								<button type="submit" class="btn btn-default btn-xs" name="process_collection" value="{$candidate.relative|escape}">{tr}Process{/tr}</button>
								&nbsp;{$candidate.relative|escape} <span class="text-muted">({tr}collection{/tr})</span>
							</li>
						{/if}
					{/foreach}
				</ul>
				{assign var="albumCandidates" value=0}
				{foreach from=$candidates item=candidate}{if $candidate.kind eq 'album'}{assign var="albumCandidates" value=$albumCandidates+1}{/if}{/foreach}
				{if $albumCandidates}
					<p><label><input type="checkbox" id="loadAlbumToggleAll" checked="checked" /> <strong>{tr}Select All{/tr}</strong></label></p>
					<input type="submit" class="btn btn-primary" name="fImportAlbums" value="{tr}Load Selected Albums{/tr}" />
					<ul>
						{foreach from=$candidates item=candidate}
							{if $candidate.kind eq 'album'}
								<li>
									<label>
										<input type="checkbox" class="loadAlbumCheckbox" name="selected[]" value="{$candidate.relative|escape}" checked="checked" />
										{$candidate.relative|escape}
									</label>
								</li>
							{/if}
						{/foreach}
					</ul>
					<input type="submit" class="btn btn-primary" name="fImportAlbums" value="{tr}Load Selected Albums{/tr}" />
				{/if}
				<script>
					$('#loadAlbumToggleAll').on('change', function() {
						$('.loadAlbumCheckbox').prop('checked', $(this).is(':checked'));
					});
				</script>
			{/form}
		{elseif $artistDir}
			<p>{tr}Nothing to load - every album folder here is already registered.{/tr}</p>
		{/if}

	</div>
</div>
{/strip}

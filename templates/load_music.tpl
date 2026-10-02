{* Real collection folders directly under Music/ with no gallery yet - see load_music.php's own
   docblock for the "real collection vs single album" distinction. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1><a href="{$topGalleryUrl|escape}">{tr}Music{/tr}</a> - {tr}Load Music Collections{/tr}</h1>
	</div>

	<div class="body">

		{if $result}
			{if $result.errors}
				<div class="alert alert-danger">
					<p>{tr}Failed{/tr}:</p>
					<ul>{foreach from=$result.errors item=row}<li>{$row.folder|escape} - {$row.error|escape}</li>{/foreach}</ul>
				</div>
			{/if}
		{/if}

		{if $candidates}
			{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_music.php"}
				<p>{tr}Showing up to{/tr} {$candidateLimit} {tr}artist/composer folders under Music/ with no gallery yet. Process one at a time - it creates the gallery, then takes you through that artist's next steps and on to loading its albums.{/tr}</p>
				<ul class="list-unstyled">
					{foreach from=$candidates item=candidate}
						<li style="margin-bottom:4px;">
							<button type="submit" class="btn btn-default btn-xs" name="process_folder" value="{$candidate.folder|escape}">{tr}Process{/tr}</button>
							&nbsp;{$candidate.folder|escape}
						</li>
					{/foreach}
				</ul>
			{/form}
		{else}
			<p>{tr}Nothing to load - every real collection folder here already has a gallery.{/tr}</p>
		{/if}

	</div>
</div>
{/strip}

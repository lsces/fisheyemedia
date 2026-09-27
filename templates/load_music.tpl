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
			{if $result.created}
				<div class="alert alert-success">
					<p>{tr}Collections created{/tr}:</p>
					<ul>
						{foreach from=$result.created item=row}
							<li>{$row.folder|escape} - <a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_album.php?gallery_id={$row.gallery_id}">{tr}Load its albums now{/tr}</a></li>
						{/foreach}
					</ul>
				</div>
			{/if}
			{if $result.errors}
				<div class="alert alert-danger">
					<p>{tr}Failed{/tr}:</p>
					<ul>{foreach from=$result.errors item=row}<li>{$row.folder|escape} - {$row.error|escape}</li>{/foreach}</ul>
				</div>
			{/if}
		{/if}

		{if $candidates}
			{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_music.php"}
				<p>{tr}Showing up to{/tr} {$candidateLimit} {tr}artist/composer folders under Music/ with no gallery yet{/tr}:</p>
				<ul>
					{foreach from=$candidates item=candidate}
						<li>
							<label>
								<input type="checkbox" name="selected[]" value="{$candidate.folder|escape}" />
								{$candidate.folder|escape}
							</label>
						</li>
					{/foreach}
				</ul>
				<input type="submit" class="btn btn-primary" name="fCreate" value="{tr}Create Selected Galleries{/tr}" />
			{/form}
		{else}
			<p>{tr}Nothing to load - every real collection folder here already has a gallery.{/tr}</p>
		{/if}

	</div>
</div>
{/strip}

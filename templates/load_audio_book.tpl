{* A book's Audio folders: each one not yet an album can be loaded as one (see load_audio_book.php). *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1><a href="{$book->getDisplayUrl()|escape}">{$book->getTitle()|escape}</a> - {tr}Load Audio Book{/tr}</h1>
	</div>

	<div class="body">
		{if $result}
			{if $result.imported}
				<div class="alert alert-success">
					<p>{tr}Loaded{/tr}:</p>
					<ul>{foreach from=$result.imported item=row}<li><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_album.php?content_id={$row.content_id}">{$row.folder|escape}</a> ({$row.tracks} {tr}tracks{/tr})</li>{/foreach}</ul>
				</div>
			{/if}
			{if $result.already}
				<div class="alert alert-warning">
					<p>{tr}Already an album{/tr}:</p>
					<ul>{foreach from=$result.already item=row}<li><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_album.php?content_id={$row.content_id}">{$row.folder|escape}</a></li>{/foreach}</ul>
				</div>
			{/if}
			{if $result.errors}
				<div class="alert alert-danger">
					<p>{tr}Failed{/tr}:</p>
					<ul>{foreach from=$result.errors item=row}<li>{$row.folder|escape} - {$row.error|escape}</li>{/foreach}</ul>
				</div>
			{/if}
		{/if}

		{if !$folders}
			<p>{tr}This book has no Audio folder with audio files in it.{/tr}</p>
			{if $workbenchGalleryId}<p><a class="btn btn-default" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}author_workbench.php?gallery_id={$workbenchGalleryId}">{tr}Back to the author workbench{/tr}</a></p>{/if}
		{else}
			{assign var=toLoad value=0}
			{foreach from=$folders item=folder}{if !$folder.linked}{assign var=toLoad value=1}{/if}{/foreach}
			{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_audio_book.php"}
				<input type="hidden" name="content_id" value="{$book->mContentId}" />
				{if $workbenchGalleryId}<input type="hidden" name="gallery_id" value="{$workbenchGalleryId}" />{/if}
				<table class="table data">
					<tr><th></th><th>{tr}Folder{/tr}</th><th>{tr}Tracks{/tr}</th><th>{tr}Album{/tr}</th></tr>
					{foreach from=$folders item=folder}
						<tr>
							<td>{if !$folder.linked}<input type="checkbox" name="selected[]" value="{$folder.rel|escape}" checked="checked" />{/if}</td>
							<td>{$folder.name|escape}</td>
							<td>{$folder.tracks}</td>
							<td>{if $folder.linked}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_album.php?content_id={$folder.linked}">{tr}Open{/tr}</a>{/if}</td>
						</tr>
					{/foreach}
				</table>
				<div class="form-group submit">
					{* Nothing left to load (all done, or already albums): no Load button, the way back takes its place. *}
					{if $toLoad}<input type="submit" class="btn btn-primary" name="fImport" value="{tr}Load as albums{/tr}" />{/if}
					{if $workbenchGalleryId}<a class="btn {if $toLoad}btn-default{else}btn-primary{/if}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}author_workbench.php?gallery_id={$workbenchGalleryId}">{tr}Back to the author workbench{/tr}</a>{/if}
				</div>
			{/form}
		{/if}
	</div>
</div>
{/strip}

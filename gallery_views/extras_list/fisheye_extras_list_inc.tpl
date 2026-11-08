{strip}
{* An author's Extras gallery: the files of Library/<author>/Extras/ as a plain list - a thumbnail (the first page of a PDF, else the file type's icon), the
   file name and its format and size, each a link that opens the file from where it is when a browser can show it (a PDF), else downloads it (view_season_file.php), with a Download link beside. The files are the gallery's 'extra' rows. *}
<div class="display fisheye">
	<header>
		{include file="bitpackage:fisheye/gallery_icons_inc.tpl"}
		{* Library - <author> - Extras, as the title line of a book or an album: every ancestor a link, then the gallery's own title. *}
		<h1>{foreach from=$gContent->getBreadcrumbTrail() item=crumb}<a href="{$crumb.url|escape}">{$crumb.title|escape}</a> - {/foreach}{$gContent->getTitle()|escape}</h1>
	</header>

	<div class="body">
		{formfeedback success=$fisheyeSuccess error=$fisheyeErrors warning=$fisheyeWarnings}
		{assign var=extrasLists value=$gContent->getExtrasGroups()}
		{assign var=extrasShown value=0}
		<ul class="list-unstyled extras-file-list">
			{foreach from=$extrasLists.groups item=extrasGroup}
				{foreach from=$extrasGroup.items item=extrasFile}
					{assign var=extrasShown value=$extrasShown+1}
					<li class="clearfix" style="margin-bottom:14px">
						{assign var=extrasOpen value=$extrasFile.url}
						<a href="{$extrasOpen|escape}"><img src="{$extrasFile.thumb|escape}" alt="" style="height:96px; float:left; margin-right:14px" /></a>
						<a href="{$extrasOpen|escape}"><strong>{$extrasFile.title|escape}</strong></a>
						{if $extrasFile.label}<br /><span class="text-muted">{$extrasFile.label|escape}</span>{/if}
					</li>
				{/foreach}
			{/foreach}
		</ul>
		{if !$extrasShown}<p class="text-muted">{tr}No files have been loaded into this gallery yet. Use the author workbench, step 4.{/tr}</p>{/if}
	</div>
</div>
{/strip}

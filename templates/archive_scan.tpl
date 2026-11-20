{* Archive scan - see archive_scan.php and FisheyeArchiveScan. Read-only. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Archive Workbench{/tr}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors success=$messages}
		{if !$parent}
			<div class="alert alert-warning"><p>{tr}No media storage folder was found: set the Library Storage Path (or the External Disk Storage Path) in Media Library Settings.{/tr}</p></div>
		{else}
			<p>{tr}Reads a folder from its file structure alone, with no catalogue, and shows what loading it would make: a gallery for each folder that holds other folders, a book for each folder that holds a document (several documents are editions of one book), an Extras gallery for a folder called Extras, and a picture gallery for a folder of images. Nothing is written.{/tr}</p>
			{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`archive_scan.php" method="get"}
				<div class="form-group">
					<label for="base">{tr}Folder in{/tr} {$parent|escape}</label>
					<select name="base" id="base" class="form-control">
						<option value="">{tr}Choose a folder{/tr}</option>
						{foreach from=$candidates item=c}
							<option value="{$c.name|escape}"{if $c.name == $base} selected="selected"{/if}>{$c.name|escape}</option>
						{/foreach}
					</select>
				</div>
				<input type="submit" class="btn btn-primary" value="{tr}Scan{/tr}" />
			{/form}

			<h2>{tr}Loaded from a folder{/tr}</h2>
			<table class="table table-condensed" style="width:auto">
				{foreach from=$folderOverview item=f}
					{if $f.kind}
						<tr>
							<td>{$f.name|escape}</td>
							<td class="text-muted">{if $f.kind == 'archive'}{tr}archive{/tr}{else}{tr}own loader{/tr}{/if}</td>
							<td><a href="{$f.url|escape}">{$f.label|escape}</a></td>
							<td class="text-muted">{if $f.loads_as}{tr}loads as{/tr} {$f.loads_as|escape}{/if}</td>
							<td>{if $f.details_url}<a href="{$f.details_url|escape}">{tr}ISBNs and file details{/tr}</a>{/if}</td>
						</tr>
					{/if}
				{/foreach}
			</table>
			<p class="help-block">{tr}Item types{/tr}: {foreach from=$itemTypes key=guid item=name name=types}{$name|escape} ({$guid|escape}){if !$smarty.foreach.types.last}, {/if}{/foreach}. {tr}A folder's type comes from the archive's Catalogue.csv (Type column), else from the folder names.{/tr}</p>

			{if $result}
				{assign var=t value=$result.totals}
				<h2>{$result.tree.name|escape}</h2>
				<table class="table table-condensed table-striped" style="width:auto">
					<tr><th>{tr}Galleries{/tr}</th><td>{$t.galleries}</td></tr>
					<tr><th>{tr}Books{/tr}</th><td>{$t.books} <span class="text-muted">({$t.loose} {tr}loose documents{/tr}, {$t.documents} {tr}documents in all{/tr})</span></td></tr>
					<tr><th>{tr}Extras galleries{/tr}</th><td>{$t.extras_galleries}</td></tr>
					<tr><th>{tr}Picture galleries{/tr}</th><td>{$t.picture_galleries} <span class="text-muted">({$t.pictures} {tr}pictures{/tr})</span></td></tr>
					<tr><th>{tr}Other files kept as extras{/tr}</th><td>{$t.extra_files}</td></tr>
				</table>
				{if $t.warnings}
					<div class="alert alert-warning"><ul>{foreach from=$t.warnings item=w}<li>{$w|escape}</li>{/foreach}</ul></div>
				{else}
					<p class="text-muted">{tr}No two entries share a name within a gallery.{/tr}</p>
				{/if}
				{if $folderArchive}
					<p>{tr}This folder is an archive:{/tr} <a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}docs_workbench.php?kind={foreach from=$archives key=k item=a}{if $a.folder == $folderArchive.folder}{$k}{/if}{/foreach}">{$folderArchive.top|escape} {tr}workbench{/tr}</a></p>
				{else}
					{form legend="Register this folder as an archive" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`archive_scan.php"}
						<input type="hidden" name="base" value="{$base|escape}" />
						<div class="form-group"><label for="top">{tr}Top gallery title{/tr}</label><input type="text" class="form-control" name="top" id="top" value="{$base|escape}" /></div>
						<div class="form-group"><label for="kind">{tr}Short name (3 to 12 letters; the root key of the loaded files){/tr}</label><input type="text" class="form-control" name="kind" id="kind" value="{$suggestKind|escape}" maxlength="12" /></div>
						<div class="form-group"><label for="noun">{tr}A folder just under it is called a{/tr}</label>
							<select name="noun" id="noun" class="form-control"><option value="topic">{tr}topic{/tr}</option><option value="title">{tr}title{/tr}</option><option value="section">{tr}section{/tr}</option></select></div>
						<input type="submit" class="btn btn-primary" name="register" value="{tr}Register{/tr}" />
					{/form}
				{/if}
				{include file="bitpackage:fisheyemedia/archive_scan_node.tpl" node=$result.tree level=0}
			{/if}
		{/if}
	</div>
</div>
{/strip}

{* Loads books from an uploaded catalogue spreadsheet - see load_books.php's own docblock for the
   filing rule (an author gallery for two or more books, a category gallery otherwise). *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{if $topGalleryUrl}<a href="{$topGalleryUrl|escape}">{tr}Library{/tr}</a> - {/if}{tr}Load Books{/tr}</h1>
	</div>

	<div class="body">

		{if !$hasTopGallery}
			<div class="alert alert-danger"><p>{tr}There is no top-level Library gallery yet - apply the media xref scheme so it is created.{/tr}</p></div>
		{/if}

		{if $import}
			{if $import.error}
				<div class="alert alert-danger"><p>{$import.error|escape}</p></div>
			{else}
				<div class="alert alert-success"><p>{tr}Catalogue read{/tr}: {$import.rows} {tr}books{/tr}.</p></div>
			{/if}
		{/if}

		{if $result}
			{if $result.errors}
				<div class="alert alert-danger">
					<p>{tr}Failed{/tr}:</p>
					<ul>{foreach from=$result.errors item=error}<li>{$error|escape}</li>{/foreach}</ul>
				</div>
			{/if}
			{if $result.group}
				<div class="alert alert-info"><p>{$result.group|escape}: {$result.created} {tr}loaded{/tr}{if $result.already}, {$result.already} {tr}already there{/tr}{/if}.</p></div>
			{/if}
		{/if}

		{form legend="Catalogue spreadsheet" enctype="multipart/form-data"}
			<div class="form-group">
				{formlabel label="Spreadsheet (.ods)" for="catalogue_file"}
				{forminput}
					<input type="file" name="catalogue_file" id="catalogue_file" accept=".ods" />
					{formhelp note="A 'Books' sheet with one row per book - Title is the only required column. Each upload is archived and the newest is the one loaded from; books already loaded are recognised and not loaded twice."}
				{/forminput}
			</div>
			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="upload" value="{tr}Read Catalogue{/tr}" />
			</div>
		{/form}

		{if $catalogueRows}
			<p>{tr}Catalogue{/tr}: {$catalogueRows} {tr}books in{/tr} {$groupCount} {tr}groups{/tr} ({$catalogueFile|escape}, {tr}uploaded{/tr} {$catalogueImported|bit_short_datetime}). {tr}An author with{/tr} {$minAuthorBooks}+ {tr}books gets a gallery of their own; everyone else is filed under the book's Category.{/tr}</p>

			{if $pending}
				{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_books.php"}
					<p>{tr}Process one group at a time - it finds or creates the gallery under Library and loads up to{/tr} {$batchSize} {tr}of its books per press; press again to carry on.{/tr}{if $pendingTotal > $groupsShown} {tr}Showing the first{/tr} {$groupsShown} {tr}of{/tr} {$pendingTotal}.{/if}</p>
					<ul class="list-unstyled">
						{foreach from=$pending item=group}
							<li>
								<button type="submit" class="btn btn-default btn-xs" name="process_group" value="{$group.key|escape}"{if !$hasTopGallery} disabled="disabled"{/if}>{tr}Process{/tr}</button>
								&nbsp;{$group.name|escape} <span class="help-block">{if $group.kind eq 'author'}{tr}author{/tr}{else}{tr}category{/tr}{/if}, {$group.loaded}/{$group.total} {tr}loaded{/tr}</span>
							</li>
						{/foreach}
					</ul>
				{/form}
			{else}
				<p>{tr}Nothing left to load - every book in the catalogue is already there.{/tr}</p>
			{/if}
		{/if}

	</div>
</div>
{/strip}

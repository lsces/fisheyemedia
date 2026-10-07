{* Follow a renamed show folder - see relocate_show.php / FisheyeProgram::relocateFolder(). *}
{strip}
<div class="admin fisheye">
	<div class="header">
		<h1>{tr}Relocate a show's folder{/tr}: <a href="{$gContent->getDisplayUrl()|escape}">{$gContent->getTitle()|escape}</a></h1>
	</div>
	<div class="body">
		<p>{tr}Use this after a show's folder has been renamed on disk (for example "Doctor Who" to "Doctor Who (1963)"). Every episode and featurette row of the show holds its file as TV Shows/&lt;folder&gt;/... and the show's title, and its seasons', is the folder name, so a rename leaves them all pointing at nothing. Nothing changes until you press Apply.{/tr}</p>

		{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}relocate_show.php"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
			<div class="form-group">
				{formlabel label="Old folder" for="old_folder"}
				{forminput}<input type="text" class="form-control" name="old_folder" id="old_folder" value="{$oldFolder|escape}" />{formhelp note="Read from the show's own episode rows."}{/forminput}
			</div>
			<div class="form-group">
				{formlabel label="New folder" for="new_folder"}
				{forminput}<input type="text" class="form-control" name="new_folder" id="new_folder" value="{$newFolder|escape}" />
					{if $suggestions}{formhelp note="On disk: "}{foreach from=$suggestions item=s}<a href="?content_id={$gContent->mContentId}&amp;old_folder={$oldFolder|escape:'url'}&amp;new_folder={$s|escape:'url'}">{$s|escape}</a> {/foreach}{/if}
				{/forminput}
			</div>
			<div class="form-group submit">
				<input type="submit" class="btn btn-default" value="{tr}Preview{/tr}" />
			</div>
		{/form}

		{if $plan}
			{if $plan.error}
				<div class="alert alert-danger">{$plan.error|escape}</div>
			{else}
				{if $applied}
					<div class="alert alert-success">{$plan.moved} {tr}rows moved to{/tr} TV Shows/{$plan.new_folder|escape}/, {$plan.titles_changed} {tr}titles renamed.{/tr}
						{tr}Now use Reload Metadata and Load Episodes on the show to refresh what Plex says (it matches by the new paths).{/tr}</div>
				{/if}
				<p><strong>{$plan.rows}</strong> {tr}episode/featurette rows point at{/tr} TV Shows/{$plan.old_folder|escape}/ &mdash;
					<strong>{$plan.movable}</strong> {tr}have their file at the new folder and would move{/tr}{if $plan.rows > $plan.movable}, <strong class="text-danger">{$plan.rows - $plan.movable}</strong> {tr}have no file there and stay as they are{/tr}{/if}.</p>
				{if $plan.missing}<p class="text-muted">{tr}No file at the new path, e.g.{/tr} {foreach from=$plan.missing item=m name=mi}{$m|escape}{if !$smarty.foreach.mi.last}; {/if}{/foreach}</p>{/if}
				{if $plan.conflict}<div class="alert alert-danger">{$plan.conflict|escape}</div>{/if}
				<table class="table table-condensed">
					<thead><tr><th>{tr}Title{/tr}</th><th>{tr}becomes{/tr}</th></tr></thead>
					<tbody>{foreach from=$plan.titles item=t name=ti}{if $smarty.foreach.ti.iteration <= 8}<tr><td>{$t.from|escape}</td><td>{$t.to|escape}</td></tr>{/if}{/foreach}</tbody>
				</table>
				{if $plan.titles|@count > 8}<p class="text-muted">... {tr}and{/tr} {$plan.titles|@count - 8} {tr}more{/tr}</p>{/if}
				{if $plan.ok && !$applied}
					{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}relocate_show.php"}
						<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
						<input type="hidden" name="old_folder" value="{$plan.old_folder|escape}" />
						<input type="hidden" name="new_folder" value="{$plan.new_folder|escape}" />
						<input type="submit" class="btn btn-primary" name="fApply" value="{tr}Apply{/tr}" />
					{/form}
				{/if}
			{/if}
		{/if}
	</div>
</div>
{/strip}

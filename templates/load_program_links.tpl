{* BBC program links - see load_program_links.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}BBC program links{/tr}</h1>
	</div>

	<div class="body">
		{if $message}<div class="alert alert-success"><p>{$message|escape}</p></div>{/if}
		{formfeedback error=$errors}

		<p>{tr}Programs whose episodes already carry a BBC address in their summary, but that have no BBC programme link of their own:{/tr} <strong>{$remaining}</strong>.
		{tr}The BBC is asked for the programme behind one episode of each, and the id it answers with is listed for you to check.{/tr}</p>

		{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`load_program_links.php"}
			<input type="hidden" name="after" value="{$after}" />
			<input type="submit" class="btn btn-primary" name="lookup" value="{if $after}{tr}Look up the next{/tr}{else}{tr}Look up the first{/tr}{/if} {$batch}" />
			{if $after}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_program_links.php">{tr}Start again from the top{/tr}</a>{/if}
		{/form}

		{if $rows}
			{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`load_program_links.php"}
				<input type="hidden" name="after" value="{$after}" />
				<table class="table table-condensed">
					<thead><tr><th></th><th>{tr}Program{/tr}</th><th>{tr}BBC calls it{/tr}</th><th>{tr}Programme id{/tr}</th><th></th></tr></thead>
					<tbody>
						{foreach from=$rows item=row}
							<tr{if !$row.match} class="warning"{/if}>
								<td>{if !$row.failed}<input type="checkbox" name="program[]" value="{$row.content_id}"{if $row.ticked} checked="checked"{/if} />{/if}</td>
								<td>{if $row.url}<a href="{$row.url}">{$row.title|escape}</a>{else}{$row.title|escape}{/if}</td>
								<td>{if $row.failed}{tr}The BBC did not answer for episode{/tr} {$row.episode_pid|escape}
									{else}{$row.bbc_title|escape} ({$row.bbc_type|escape}){if !$row.match} - {tr}not the same title, check{/tr}{/if}{/if}</td>
								<td><input type="text" class="form-control" name="pid[{$row.content_id}]" value="{$row.pid|escape}" size="10" maxlength="8" /></td>
								<td>{if $row.pid}<a href="https://www.bbc.co.uk/programmes/{$row.pid|escape}" target="_blank" rel="noopener">{tr}BBC page{/tr}</a>{/if}</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
				<p>{tr}The lines with the same title as the BBC's are ticked (at most{/tr} {$batch} {tr}are saved at a time); the shaded ones are not - a folder of your own such as BBC Documentaries has no programme of its own, so leave it. A wrong id can be typed over before saving.{/tr}</p>
				<input type="submit" class="btn btn-primary" name="add" value="{tr}Add the ticked{/tr}" />
			{/form}
		{/if}
	</div>
</div>
{/strip}

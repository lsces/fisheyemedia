{* Film, programme, season and album descriptions still held as plain text, and the button that stores them as HTML (convert_descriptions.php). *}
{strip}
{if $more}<meta http-equiv="refresh" content="1;url={$smarty.const.FISHEYEMEDIA_PKG_URL}convert_descriptions.php?convert=1&amp;after={$after}&amp;converted={$converted}" />{/if}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Descriptions to HTML{/tr}</h1>
	</div>

	<div class="body">
		{if $ran}
			<div class="alert alert-success">{$converted} {tr}converted{/tr}{if $more} - {tr}carrying on{/tr}...{/if}</div>
		{/if}
		{if $failed}
			<div class="alert alert-danger">
				<p>{tr}Not stored{/tr}:</p>
				<ul>{foreach from=$failed item=row}<li><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}extras.php?content_id={$row.content_id}">{$row.title|escape}</a> ({$row.content_id}) - {$row.error|escape}</li>{/foreach}</ul>
			</div>
		{/if}

		{if $left}
			<table class="table data">
				<tr><th>{tr}Type{/tr}</th><th>{tr}Plain-text descriptions{/tr}</th></tr>
				{foreach from=$left key=type item=n}<tr><td>{$type|escape}</td><td>{$n}</td></tr>{/foreach}
			</table>
			{if !$more}
				{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}convert_descriptions.php"}
					<div class="form-group submit">
						<input type="submit" class="btn btn-primary" name="convert" value="{tr}Convert to HTML{/tr}" />
					</div>
				{/form}
			{/if}
		{else}
			<p>{tr}Every film, programme, season and album description is HTML.{/tr}</p>
		{/if}
	</div>
</div>
{/strip}

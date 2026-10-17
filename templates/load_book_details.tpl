{* Batch fill of the Library's books from Open Library - see load_book_details.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Book Details from Open Library{/tr}</h1>
	</div>

	<div class="body">

		{if $results}
			<div class="alert alert-info">
				<ul>
					{foreach from=$results item=made}
						<li><a href="{$made.url|escape}">{$made.title|escape}</a>:
							{if $made.error}{$made.error|escape}
							{elseif $made.items}{foreach from=$made.items item=line name=lines}{$line|escape}{if !$smarty.foreach.lines.last}; {/if}{/foreach}
							{elseif $made.matched}{tr}nothing missing that Open Library holds{/tr}
							{else}{tr}not on Open Library{/tr}{/if}
						</li>
					{/foreach}
				</ul>
			</div>
		{/if}

		{if !$pendingCount}
			<div class="alert alert-success"><p>{tr}Every book with an ISBN has an Open Library edition and an image.{/tr}</p></div>
		{else}
			{form legend="Books" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_details.php"}
				<input type="hidden" name="start" value="{$start|escape}" />
				<p>{$pendingCount} {tr}books with an ISBN are missing an Open Library edition id or an image. Each press looks up the next{/tr} {$batchSize} {tr}and fills in what is blank - nothing already entered is replaced.{/tr}</p>
				<div class="form-group submit">
					<input type="submit" class="btn btn-primary" name="fetch" value="{tr}Fetch the next batch{/tr}" />
				</div>
			{/form}
		{/if}

	</div>
</div>
{/strip}

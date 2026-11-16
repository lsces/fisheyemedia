{* A book's details, read only: credits, ISBN, label/value lines, description and links. Needs creditGroups, isbn, details, externalLinks (FisheyeBookDetails::collect). Changing them, and looking them up, is the edit page's job. *}
{strip}
{foreach from=$creditGroups item=group}
	<p><strong>{tr}{$group.role|capitalize}{/tr}:</strong>
		{foreach from=$group.people item=person name=people}
			{if $person.url}<a href="{$person.url|escape}">{$person.name|escape}</a>{else}{$person.name|escape}{/if}{if !$smarty.foreach.people.last}, {/if}
		{/foreach}
	</p>
{/foreach}

{if $isbn}
	<p><strong>{tr}ISBN{/tr}:</strong> {$isbn.isbn13|escape}{if $isbn.isbn10} ({$isbn.isbn10|escape}){/if}</p>
{/if}

{foreach from=$details item=line}
	<p><strong>{$line.label|escape}:</strong> {$line.value|escape}</p>
{/foreach}

{if !$hideDescription && $gContent->mInfo.data}{if strpos( $gContent->mInfo.data, '<' ) !== false}<div class="book-description">{$gContent->mInfo.data}</div>{else}<p>{$gContent->mInfo.data|escape|nl2br}</p>{/if}{/if}

{if $externalLinks|@count}
	<p class="book-external-links"><strong>{tr}Links{/tr}:</strong>
		{foreach from=$externalLinks item=link name=externalLinks}
			<a href="{$link.url|escape}" target="_blank" rel="noopener">{$link.title|escape}</a>{if !$smarty.foreach.externalLinks.last} &middot; {/if}
		{/foreach}
	</p>
{/if}
{/strip}

{strip}
<div class="display fisheye">
	<header>
		<div class="floaticon">
			{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='icon' serviceHash=$gContent->mInfo}
			{if $gContent->hasUpdatePermission()}
				<a title="{tr}Edit{/tr}" href="{$gContent->getEditUrl()|escape}">{biticon ipackage="icons" iname="edit" iexplain="Edit"}</a>
			{/if}
		</div>
		<h1>{foreach from=$gContent->getBreadcrumbTrail() item=crumb}<a href="{$crumb.url|escape}">{$crumb.title|escape}</a> - {/foreach}{$gContent->getTitle()|escape}</h1>
	</header>

	<div class="body">
		<div class="row">
			{assign var=coverUrl value=$gContent->getThumbnailUrl('medium')}
			{if $coverUrl}
				<div class="col-md-3">
					<a href="{$gContent->getThumbnailUrl('large')|escape}" target="_blank" rel="noopener"><img class="thumb img-responsive" src="{$coverUrl|escape}" alt="{$gContent->getTitle()|escape}" /></a>
				</div>
			{/if}
			<div class="{if $coverUrl}col-md-9{else}col-md-12{/if}">
				{foreach from=$creditGroups item=group}
					<p><strong>{tr}{$group.role|capitalize}{/tr}:</strong>
						{foreach from=$group.people item=person name=people}
							{if $person.url}<a href="{$person.url|escape}">{$person.name|escape}</a>{else}{$person.name|escape}{/if}{if !$smarty.foreach.people.last}, {/if}
						{/foreach}
					</p>
				{/foreach}

				{if $isbn}
					<p><strong>{tr}ISBN{/tr}:</strong> {$isbn.isbn13|escape}{if $isbn.isbn10} ({$isbn.isbn10|escape}){/if}
						{if $isbn.entered} &middot; {tr}entered as{/tr} {$isbn.entered|escape}{/if}
						{if $isbn.note} &middot; {$isbn.note|escape}{/if}
					</p>
				{/if}

				{foreach from=$details item=line}
					<p><strong>{$line.label|escape}:</strong> {$line.value|escape}</p>
				{/foreach}

				{if $gContent->mInfo.data}<p>{$gContent->mInfo.data|escape}</p>{/if}

				{if $externalLinks|@count}
					<p class="book-external-links"><strong>{tr}Links{/tr}:</strong>
						{foreach from=$externalLinks item=link name=externalLinks}
							<a href="{$link.url|escape}" target="_blank" rel="noopener">{$link.title|escape}</a>{if !$smarty.foreach.externalLinks.last} &middot; {/if}
						{/foreach}
					</p>
				{/if}
			</div>
		</div>
	</div><!-- end .body -->

	{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='view' serviceHash=$gContent->mInfo}

	{if $gContent->getPreference('allow_comments') eq 'y'}
		{include file="bitpackage:liberty/comments.tpl"}
	{/if}
</div><!-- end .fisheye -->
{/strip}

{strip}
<div class="display fisheye">
	<header>
		<div class="floaticon">
			{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='icon' serviceHash=$gContent->mInfo}
			{if $gContent->hasUpdatePermission()}
				<a title="{tr}Edit{/tr}" href="{$gContent->getEditUrl()|escape}">{biticon ipackage="icons" iname="edit" iexplain="Edit"}</a>
			{/if}
		</div>
		<h1>{foreach from=$gContent->getBreadcrumbTrail() item=crumb}<a href="{$crumb.url|escape}">{$crumb.title|escape}</a> - {/foreach}{$gContent->getTitle()|escape}{include file="bitpackage:fisheyemedia/extras_inc.tpl"}</h1>
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
				{include file="bitpackage:fisheyemedia/book_details_inc.tpl"}
			</div>
		</div>
	</div><!-- end .body -->

	{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='view' serviceHash=$gContent->mInfo}

	{if $gContent->getPreference('allow_comments') eq 'y'}
		{include file="bitpackage:liberty/comments.tpl"}
	{/if}
</div><!-- end .fisheye -->
{/strip}

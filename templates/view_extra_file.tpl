{strip}
<div class="display fisheye">
	<header>
		{* Library - <author> - Extras - <file>: the owner's title line, then the file. *}
		<h1>{foreach from=$gContent->getBreadcrumbTrail() item=crumb}<a href="{$crumb.url|escape}">{$crumb.title|escape}</a> - {/foreach}<a href="{$galleryUrl|escape}">{$gContent->getTitle()|escape}</a> - {$fileName|escape}</h1>
	</header>
	<div class="body">
		{* The file is fetched by the viewer from view_season_file.php or view_book_file.php (same site, same login); the search term is found and highlighted (pdf.js #search=, epub.js ?search=). *}
		<iframe id="extra-viewer" src="{$viewerSrc|escape}" width="100%" style="height:85vh; border:0"></iframe>
		{* The viewer takes the keyboard (arrow keys turn the pages) as soon as the pointer is over it or it has loaded. *}
		<script>
		( function() {ldelim}
			var f = document.getElementById( 'extra-viewer' );
			function take() {ldelim} try {ldelim} f.contentWindow.focus(); {rdelim} catch( e ) {ldelim} {rdelim} {rdelim}
			f.addEventListener( 'load', take ); f.addEventListener( 'mouseenter', take );
		{rdelim} )();
		</script>
	</div>
</div>
{/strip}

{strip}
<div class="display fisheye view-film">
	<header>
		{* film_icons_inc.tpl, not gallery_icons_inc.tpl - the gallery one's download/image-order/
		   upload/permissions icons all key off $gContent->mGalleryId, which a plain film content
		   item doesn't have; a film only ever needs the one Edit icon. *}
		{include file="bitpackage:fisheyemedia/film_icons_inc.tpl"}
		{* Own breadcrumb instead of the shared gallery_breadcrumb_inc.tpl - that one hardcodes
		   a pretty-url 'gallery/<id>' link that always lands on the generic gallery view
		   regardless of type, same reasoning view_season.tpl's own title-link already worked
		   around. getBreadcrumbTrail() (FisheyeBase) walks the real ancestor chain -
		   "Films" always leads, then any Collection sub-gallery the film actually sits in, each
		   segment's own URL type-correct (film_grid galleries route the same as any other). *}
		<h1>{foreach from=$gContent->getBreadcrumbTrail() item=crumb}<a href="{$crumb.url|escape}">{$crumb.title|escape}</a> - {/foreach}{$gContent->getTitle()|escape}</h1>
	</header>

	<section class="body">
		<div class="row">
			<div class="col-md-5">
				{* player.tpl directly, not video/view.tpl via getMimeTemplate() - skips its
				   trailing mime_meta_inc.tpl include (Uploaded by/Downloads/Last Modified/
				   Hits), which just duplicates/clutters what this page already shows better *}
				{include file="bitpackage:liberty/mime/video/player.tpl" attachment=$gContent->mInfo.image_file}
			</div>
			<div class="col-md-4 film-facts">
				{if $gContent->mInfo.data}
					<p class="film-summary">{$gContent->mInfo.data|escape}</p>
				{/if}
				{if $directors|@count || $stars|@count || $narrators|@count}
					<p class="film-credits">
						{if $directors|@count}<strong>{tr}Director{/tr}{if $directors|@count > 1}s{/if}:</strong> {foreach from=$directors item=person name=dir}{if $creditUrls.$person}<a href="{$creditUrls.$person|escape}">{$person|escape}</a>{else}{$person|escape}{/if}{if !$smarty.foreach.dir.last}, {/if}{/foreach}<br />{/if}
						{if $narrators|@count}<strong>{tr}Narrated by{/tr}:</strong> {include file="bitpackage:fisheyemedia/credit_names_inc.tpl" names=$narrators limit=null}<br />{/if}
						{if $stars|@count}<strong>{tr}Starring{/tr}:</strong> {foreach from=$stars item=star name=cast}{if $smarty.foreach.cast.iteration <= 6}{if !$smarty.foreach.cast.first}, {/if}{if $creditUrls.$star}<a href="{$creditUrls.$star|escape}">{$star|escape}</a>{else}{$star|escape}{/if}{if $creditRoles.$star} <span class="text-muted">{tr}as{/tr} {$creditRoles.$star|escape}</span>{/if}{/if}{/foreach}{if $stars|@count > 6} <span class="film-more-cast">{tr}and{/tr} {$stars|@count - 6} {tr}more{/tr}</span>{/if}{/if}
					</p>
				{/if}
				{if $genres|@count}
					<p class="film-genres">
						{foreach from=$genres item=genre}<span class="label label-default">{$genre|escape}</span> {/foreach}
					</p>
				{/if}
				<dl class="film-info">
					{if $contentRating}<dt>{tr}Rating{/tr}</dt><dd>{$contentRating|escape}</dd>{/if}
					{if $durationMs}<dt>{tr}Duration{/tr}</dt><dd>{($durationMs/1000)|display_duration}</dd>{/if}
					{if $resolution}<dt>{tr}Video{/tr}</dt><dd>{$resolution|escape}</dd>{/if}
					{if $audio}<dt>{tr}Audio{/tr}</dt><dd>{$audio|escape}</dd>{/if}
					{if $writers|@count}<dt>{tr}Writer{/tr}{if $writers|@count > 1}s{/if}</dt><dd>{foreach from=$writers item=person name=wri}{if $creditUrls.$person}<a href="{$creditUrls.$person|escape}">{$person|escape}</a>{else}{$person|escape}{/if}{if !$smarty.foreach.wri.last}, {/if}{/foreach}</dd>{/if}
				</dl>
				{if $externalLinks|@count}
					<p class="film-external-links">
						{foreach from=$externalLinks item=link name=externalLinks}
							<a href="{$link.url|escape}" target="_blank" rel="noopener">{$link.title|escape}</a>{if !$smarty.foreach.externalLinks.last} &middot; {/if}
						{/foreach}
					</p>
				{/if}
			</div>
			{if $gContent->getThumbnailUrl('medium')}
				<div class="col-md-3 film-poster">
					<img class="img-responsive" src="{$gContent->getThumbnailUrl('medium')|escape}" alt="{$gContent->getTitle()|escape}" />
				</div>
			{/if}
		</div>
	</section>

	{* Featurettes/Images tabs - same fisheye-content-tabs/fisheye-tab-panel structure and
	   fisheyeShowContentTab() (content_tabs_js_inc.tpl) as view_season.tpl/
	   view_program_single_season.tpl, just without an Episodes tab. A film has no swappable
	   top-right facts column the way a season does (this page's own col-md-4 film-facts column
	   above is the FILM's own static info, not swappable) - so unlike Season, the featurette
	   detail panel lives directly inside the Featurettes tab, under its thumbnail grid, rather
	   than in a separate shared area. *}
	<div class="fisheye-content-tabs">
		<ul class="nav nav-tabs">
			{if $featurettes|@count}<li class="fisheye-tab-item{if $firstFilmTab == 'featurettes'} active{/if}"><a class="fisheye-tab-link" href="#" onclick="return fisheyeShowContentTab('featurettes', this);">{tr}Featurettes{/tr}</a></li>{/if}
			{if $filmImages|@count}<li class="fisheye-tab-item{if $firstFilmTab == 'images'} active{/if}"><a class="fisheye-tab-link" href="#" onclick="return fisheyeShowContentTab('images', this);">{tr}Images{/tr}</a></li>{/if}
		</ul>

		{if $featurettes|@count}
			<div class="fisheye-tab-panel" id="fisheye-tab-featurettes"{if $firstFilmTab != 'featurettes'} style="display:none;"{/if}>
				{if $gContent->hasUpdatePermission()}
					<div class="fisheye-tab-header"><a title="{tr}Reload Featurettes{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_film.php?content_id={$gContent->mContentId}&amp;fReloadFeaturettes=1">{biticon ipackage="icons" iname="view-refresh" iexplain="Reload Featurettes"}</a></div>
				{/if}
				{include file="bitpackage:fisheyemedia/featurette_grid_inc.tpl" featurettes=$featurettes}
				{include file="bitpackage:fisheyemedia/featurette_detail_panels_inc.tpl" featurettes=$featurettes playToggleFn="fisheyeToggleFeaturette"}
			</div>
		{/if}
		{if $filmImages|@count}
			<div class="fisheye-tab-panel" id="fisheye-tab-images"{if $firstFilmTab != 'images'} style="display:none;"{/if}>
				{if $gContent->hasUpdatePermission()}
					<div class="fisheye-tab-header"><a title="{tr}Add Image{/tr}" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}add_image_xref.php?content_id={$gContent->mContentId}">{biticon ipackage="icons" iname="go-up" iexplain="Add Image"}</a></div>
				{/if}
				{include file="bitpackage:fisheyemedia/images_grid_inc.tpl" images=$filmImages imagesAltText="Images"}
			</div>
		{/if}
	</div>
	{include file="bitpackage:fisheyemedia/content_tabs_js_inc.tpl"}

	{if $featurettes|@count}
		<script>
			{* Same "the button itself is the back control" treatment as episode_detail_panels_inc.tpl's
			   Play -> Stop toggle, but pinching the film's own always-present player
			   (id="liberty-video-player", player.tpl) rather than swapping a poster for a
			   hidden player - a film's page always has something playing, so "stop" means
			   "go back to the film" rather than "go idle". Only one featurette button is ever
			   in "playing" state at a time (fisheyePlayingFeaturetteBtn); clicking it again, or
			   another featurette's button, goes back to the film first. Original film source
			   captured lazily off the player's own <source> the first time any featurette plays. *}
			var fisheyeFilmSourceUrl = null;
			var fisheyePlayingFeaturetteBtn = null;
			var FISHEYE_FEATURETTE_PLAY_LABEL = '▶ {tr}Play{/tr}';
			var FISHEYE_FEATURETTE_FILM_LABEL = '◀ {tr}Film{/tr}';

			function fisheyeResetToFilm() {
				var player = document.getElementById( 'liberty-video-player' );
				var source = player ? player.querySelector( 'source' ) : null;
				if( player && source && fisheyeFilmSourceUrl !== null ) {
					player.pause();
					source.src = fisheyeFilmSourceUrl;
					player.load();
					player.play();
				}
				if( fisheyePlayingFeaturetteBtn ) {
					fisheyePlayingFeaturetteBtn.textContent = FISHEYE_FEATURETTE_PLAY_LABEL;
					fisheyePlayingFeaturetteBtn = null;
				}
			}

			function fisheyeToggleFeaturette( btn, url ) {
				var player = document.getElementById( 'liberty-video-player' );
				var source = player ? player.querySelector( 'source' ) : null;
				if( !player || !source ) {
					return true;
				}
				if( btn === fisheyePlayingFeaturetteBtn ) {
					fisheyeResetToFilm();
					return false;
				}
				if( fisheyeFilmSourceUrl === null ) {
					fisheyeFilmSourceUrl = source.src;
				}
				if( fisheyePlayingFeaturetteBtn ) {
					fisheyePlayingFeaturetteBtn.textContent = FISHEYE_FEATURETTE_PLAY_LABEL;
				}
				player.pause();
				source.src = url;
				player.load();
				player.play();
				btn.textContent = FISHEYE_FEATURETTE_FILM_LABEL;
				fisheyePlayingFeaturetteBtn = btn;
				player.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				return false;
			}
		</script>
	{/if}
</div>
{/strip}

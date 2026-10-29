{* The "Full Series" tile that leads an author's part of a series spanning several authors (FisheyeMediaGallery::getFullSeries()): a rendering
   facet, not content - it links to the combined series under Various Authors and is not in the gallery's own item list. *}
<div class="media-grid-item">
	{box class="box fullseries"}
		<a href="{$fullSeries.url|escape}" title="{tr}All the books in{/tr} {$fullSeries.title|escape}">
			<img class="thumb img-responsive center-block media-cover" src="{$smarty.const.FISHEYEMEDIA_PKG_URL}icons/full_series.svg" alt="{tr}Full Series{/tr}" />
		</a>
		<h4>{tr}Full Series{/tr}</h4>
	{/box}
</div>

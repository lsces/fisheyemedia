{* Tile and strip styling shared by the media grid layouts that render a pool of galleries and
   a gallery's categorized strips (library_grid). Square tiles so names line up along a row,
   non-square art shown whole, and a name tile where there is no art. *}
<style>
	.media-cover { width: 100%; aspect-ratio: 1 / 1; object-fit: contain; }
	.media-name-tile { aspect-ratio: 1 / 1; display: flex; align-items: center; justify-content: center; padding: 8px; background: rgba(128,128,128,.25); border: 1px solid rgba(128,128,128,.4); font-weight: bold; text-align: center; overflow: hidden; }
	.media-strip-title { margin: 20px 0 8px; }
	.media-strip-title:first-child { margin-top: 0; }
	.media-strip { display: flex; flex-wrap: wrap; margin: 0 -5px; }
	.media-grid-item { box-sizing: border-box; padding: 5px; text-align: center; width: 12.5%; }
	@media (max-width: 1199px) { .media-grid-item { width: 25%; } }
	@media (max-width: 767px) { .media-grid-item { width: 50%; } }
</style>

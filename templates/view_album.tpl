{* Grid, not a plain list - # / Title / Artist / Duration, same reasoning view_gallery_images_inc.
   tpl's own duration column already established. A persistent <audio id="fisheye-track-player">
   stays on the page (same "one player element, swap its source" shape view_film.tpl's own
   featurette Play/Stop toggle uses for liberty-video-player) rather than play_track.php opening on
   its own in a new tab - clicking a track swaps the source and plays in place, and reaching the
   end of one auto-advances to the next row in document order (spanning a disc boundary on a
   multi-disc album, not just within one table) via the player's own 'ended' event. *}
{strip}
<div class="display fisheye">
	<header>
		{* Same shape as view_season.tpl/view_program.tpl's own floaticon - this is a single
		   album's item view, not a gallery view, so the generic gallery_icons_inc.tpl (which
		   expects $gContent->mGalleryId) doesn't apply here, just like it didn't for those. *}
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
			<div class="col-md-3">
				<img class="thumb img-responsive" src="{$gContent->getThumbnailUrl('medium')|escape}" alt="{$gContent->getTitle()|escape}" />
			</div>
			<div class="col-md-9">
				{if $artist}<p><strong>{tr}Artist{/tr}:</strong> {$artist|escape}</p>{/if}
				{if $gContent->mInfo.data}<p>{$gContent->mInfo.data|escape}</p>{/if}
				{if $externalLinks|@count}
					<p class="album-external-links">
						{foreach from=$externalLinks item=link name=externalLinks}
							<a href="{$link.url|escape}" target="_blank" rel="noopener">{$link.title|escape}</a>{if !$smarty.foreach.externalLinks.last} &middot; {/if}
						{/foreach}
					</p>
				{/if}

				{if $discs|@count}
					<section class="album-tracks">
						<h2>{tr}Tracks{/tr}</h2>
						<audio id="fisheye-track-player" controls style="width:100%;margin-bottom:1em;display:none;"></audio>
						{foreach from=$discs item=discTracks key=discNum}
							{if $multiDisc}<h3>{tr}Disc{/tr} {$discNum|escape}{if $discSubtitles.$discNum}: {$discSubtitles.$discNum|escape}{/if}</h3>{/if}
							<table class="table table-condensed album-track-grid">
								<thead>
									<tr>
										<th>#</th>
										<th>{tr}Title{/tr}</th>
										<th>{tr}Artist{/tr}</th>
										<th>{tr}Duration{/tr}</th>
										<th></th>
									</tr>
								</thead>
								<tbody>
									{foreach from=$discTracks item=track name=trackRow}
										<tr class="fisheye-track-row" data-xref-id="{$track.xref_id}">
											<td>{$track.trackNum|default:$smarty.foreach.trackRow.iteration}</td>
											<td>{$track.title|escape}</td>
											<td>{if $track.artist}{$track.artist|escape}{/if}</td>
											<td>{if $track.durationMs}{($track.durationMs/1000)|display_duration}{/if}</td>
											<td><button type="button" class="btn btn-default btn-sm track-play-btn" onclick="return fisheyeToggleTrack(this);">▶ {tr}Play{/tr}</button></td>
										</tr>
									{/foreach}
								</tbody>
							</table>
						{/foreach}
					</section>
				{/if}
			</div>
		</div>
	</div><!-- end .body -->

	{include file="bitpackage:liberty/services_inc.tpl" serviceLocation='view' serviceHash=$gContent->mInfo}

	{if $gContent->getPreference('allow_comments') eq 'y'}
		{include file="bitpackage:liberty/comments.tpl"}
	{/if}

	{if $discs|@count}
		<script>
			{* Same "the button itself is the toggle control" shape as view_film.tpl's own
			   fisheyeToggleFeaturette, but with no "go back to X" state to return to - toggling
			   the currently-playing row's own button just pauses in place. Auto-advance on
			   'ended' walks the flat, already-in-document-order list of .fisheye-track-row
			   elements (built once, spans every disc table on the page in sequence) rather than
			   anything disc-aware, so a multi-disc album keeps playing straight through a disc
			   boundary the same as within one. *}
			var fisheyePlayingTrackBtn = null;
			var FISHEYE_TRACK_PLAY_LABEL = '▶ {tr}Play{/tr}';
			var FISHEYE_TRACK_PAUSE_LABEL = '❚❚ {tr}Pause{/tr}';

			function fisheyeTrackRows() {
				return Array.prototype.slice.call( document.querySelectorAll( '.fisheye-track-row' ) );
			}

			function fisheyeStopTrack() {
				var player = document.getElementById( 'fisheye-track-player' );
				if( player ) {
					player.pause();
				}
				if( fisheyePlayingTrackBtn ) {
					fisheyePlayingTrackBtn.textContent = FISHEYE_TRACK_PLAY_LABEL;
					fisheyePlayingTrackBtn = null;
				}
			}

			function fisheyePlayTrackRow( row ) {
				var player = document.getElementById( 'fisheye-track-player' );
				var btn = row ? row.querySelector( '.track-play-btn' ) : null;
				if( !player || !row || !btn ) {
					return;
				}
				if( fisheyePlayingTrackBtn && fisheyePlayingTrackBtn !== btn ) {
					fisheyePlayingTrackBtn.textContent = FISHEYE_TRACK_PLAY_LABEL;
				}
				player.style.display = '';
				player.src = '{$smarty.const.FISHEYEMEDIA_PKG_URL}play_track.php?xref_id=' + encodeURIComponent( row.dataset.xrefId );
				player.play();
				btn.textContent = FISHEYE_TRACK_PAUSE_LABEL;
				fisheyePlayingTrackBtn = btn;
			}

			function fisheyeToggleTrack( btn ) {
				var row = btn.closest( '.fisheye-track-row' );
				if( btn === fisheyePlayingTrackBtn ) {
					fisheyeStopTrack();
					return false;
				}
				fisheyePlayTrackRow( row );
				return false;
			}

			( function() {
				var player = document.getElementById( 'fisheye-track-player' );
				if( !player ) {
					return;
				}
				player.addEventListener( 'ended', function() {
					var rows = fisheyeTrackRows();
					var currentIndex = fisheyePlayingTrackBtn
						? rows.findIndex( function( row ) { return row.querySelector( '.track-play-btn' ) === fisheyePlayingTrackBtn; } )
						: -1;
					var nextRow = currentIndex > -1 ? rows[currentIndex + 1] : null;
					if( nextRow ) {
						fisheyePlayTrackRow( nextRow );
					} else {
						fisheyeStopTrack();
					}
				} );
			} )();
		</script>
	{/if}
</div><!-- end .fisheye -->
{/strip}

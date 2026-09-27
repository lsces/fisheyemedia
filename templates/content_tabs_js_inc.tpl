{* Shared Episodes/Featurettes/Images tab switcher for view_season.tpl/
   view_program_single_season.tpl - generic by tab name, not tied to which three tabs a given
   page actually has. Also switches which of the two "detail groups" (#episode-details-group/
   #featurette-details-group) is visible in the shared top-right facts column, so Episodes and
   Featurettes each show their own selected item's info/play-button there without needing two
   separate facts columns. *}
<script>
	function fisheyeShowContentTab( name, linkEl ) {
		document.querySelectorAll( '.fisheye-tab-panel' ).forEach( function( el ) { el.style.display = 'none'; } );
		document.querySelectorAll( '.fisheye-tab-link' ).forEach( function( el ) { el.parentElement.classList.remove( 'active' ); } );
		var panel = document.getElementById( 'fisheye-tab-' + name );
		if( panel ) { panel.style.display = ''; }
		if( linkEl ) { linkEl.parentElement.classList.add( 'active' ); }

		var episodeGroup = document.getElementById( 'episode-details-group' );
		var featuretteGroup = document.getElementById( 'featurette-details-group' );
		if( episodeGroup ) { episodeGroup.style.display = ( name === 'episodes' ) ? '' : 'none'; }
		if( featuretteGroup ) { featuretteGroup.style.display = ( name === 'featurettes' ) ? '' : 'none'; }
		return false;
	}

	{* Shared by episode_grid_inc.tpl and featurette_grid_inc.tpl - a picker grid where clicking
	   a card swaps which detail block (by index, within the ".<type>-detail"/".<type>-item"
	   pair) is shown, no per-item request. Generalized from an episode-only fisheyeShowEpisode()
	   once a second, near-identical copy was needed for featurettes. *}
	function fisheyeShowGridItem( type, idx ) {
		document.querySelectorAll( '.' + type + '-detail' ).forEach( function( el ) { el.style.display = 'none'; } );
		document.querySelectorAll( '.' + type + '-item' ).forEach( function( el ) { el.classList.remove( 'active' ); } );
		var detail = document.getElementById( type + '-detail-' + idx );
		if( detail ) { detail.style.display = ''; }
		var items = document.querySelectorAll( '.' + type + '-item' );
		if( items[idx] ) { items[idx].classList.add( 'active' ); }
	}
</script>

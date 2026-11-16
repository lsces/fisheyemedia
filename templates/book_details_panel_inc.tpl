{* A book's details as a button beside the title that opens a panel over the top of the page body, as the Extras button does (extras_inc.tpl). Put it inside the <h1>.
   Read only; needs what FisheyeBookDetails::collect() gives and hasDetails. Plain JS: a second press, Esc or a press outside closes it. *}
{if $hasDetails}
	<style>
		.display > header, .display > .header { position: relative; }
		.details-toggle { margin-left: 14px; vertical-align: middle; padding: 4px 16px; font-size: 18px; line-height: 1.3; color: #fff; background-color: #4a4a4a; border: 1px solid #333; border-radius: 4px; }
		.details-toggle:hover, .details-toggle:focus { color: #fff; background-color: #666; border-color: #444; }
		.details-panel { display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 1000; margin: 4px 0 0; font-size: 14px; font-weight: normal; box-shadow: 0 6px 16px rgba(0,0,0,.3); }
		.details-panel.open { display: block; }
	</style>
	<button type="button" class="btn details-toggle" id="details-toggle" aria-expanded="false" aria-controls="details-panel">{tr}Details{/tr}</button>
	<div class="panel panel-default details-panel" id="details-panel"><div class="panel-body">{include file="bitpackage:fisheyemedia/book_details_inc.tpl"}</div></div>
	<script>
		( function() {
			var button = document.getElementById( 'details-toggle' ), panel = document.getElementById( 'details-panel' );
			function setOpen( open ) {
				panel.className = 'panel panel-default details-panel' + ( open ? ' open' : '' );
				button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			}
			button.addEventListener( 'click', function( e ) { e.stopPropagation(); setOpen( panel.className.indexOf( 'open' ) < 0 ); } );
			panel.addEventListener( 'click', function( e ) { e.stopPropagation(); } );
			document.addEventListener( 'click', function() { setOpen( false ); } );
			document.addEventListener( 'keydown', function( e ) { if( e.key === 'Escape' ) { setOpen( false ); } } );
		} )();
	</script>
{/if}

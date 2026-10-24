{* Extras: a button beside the item's title that opens a panel over the top of the page body. Put it inside the <h1>.
   The list is fetched from extras.php?fragment=1 when first opened, so a page that never opens it pays nothing. Hidden when
   there is nothing to show and nothing this user could add. Plain JS: a second press, Esc or a press outside closes it. *}
{assign var=extrasCounts value=$gContent->getExtrasCounts()}
{assign var=extrasCount value=$extrasCounts.featurettes+$extrasCounts.extras}
{if $extrasCount || $gContent->hasUpdatePermission()}
	<style>
		.display > header { position: relative; }
		.extras-toggle { margin-left: 14px; vertical-align: middle; padding: 4px 16px; font-size: 18px; line-height: 1.3; color: #fff; background-color: #4a4a4a; border: 1px solid #333; border-radius: 4px; }
		.extras-toggle:hover, .extras-toggle:focus { color: #fff; background-color: #666; border-color: #444; }
		.extras-panel .extras-links { float: right; margin: 0 0 4px 12px; }
		.extras-panel { display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 1000; margin: 4px 0 0; font-size: 14px; font-weight: normal; box-shadow: 0 6px 16px rgba(0,0,0,.3); }
		.extras-panel.open { display: block; }
	</style>
	<button type="button" class="btn extras-toggle" id="extras-toggle" aria-expanded="false" aria-controls="extras-panel" data-url="{$smarty.const.FISHEYEMEDIA_PKG_URL}extras.php?content_id={$gContent->mContentId}&amp;fragment=1">{if $extrasCounts.featurettes}{tr}Featurettes{/tr} ({$extrasCounts.featurettes}){/if}{if $extrasCounts.extras || !$extrasCount}{if $extrasCounts.featurettes} {/if}{tr}Extras{/tr}{if $extrasCounts.extras} ({$extrasCounts.extras}){/if}{/if}</button>
	<div class="panel panel-default extras-panel" id="extras-panel"><div class="panel-body" id="extras-body">{tr}Loading...{/tr}</div></div>
	<script>
		( function() {
			var button = document.getElementById( 'extras-toggle' ), panel = document.getElementById( 'extras-panel' ), body = document.getElementById( 'extras-body' ), loaded = false;
			function setOpen( open ) {
				panel.className = 'panel panel-default extras-panel' + ( open ? ' open' : '' );
				button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				if( open && !loaded ) {
					loaded = true;
					var request = new XMLHttpRequest();
					request.open( 'GET', button.getAttribute( 'data-url' ) );
					request.onload = function() { body.innerHTML = request.status === 200 ? request.responseText : '{tr}The list could not be loaded.{/tr}'; };
					request.onerror = function() { body.innerHTML = '{tr}The list could not be loaded.{/tr}'; };
					request.send();
				}
			}
			button.addEventListener( 'click', function( e ) { e.stopPropagation(); setOpen( panel.className.indexOf( 'open' ) < 0 ); } );
			// A featurette's Play swaps the page's own player: let that run, then get out of the way so it can be seen.
			panel.addEventListener( 'click', function( e ) {
				e.stopPropagation();
				var tab = e.target.closest ? e.target.closest( '.extras-tab-link' ) : null;
				if( tab ) {
					e.preventDefault();
					panel.querySelectorAll( '.extras-tab-panel' ).forEach( function( el ) { el.hidden = ( el.id !== 'extras-tab-' + tab.getAttribute( 'data-tab' ) ); } );
					panel.querySelectorAll( '.nav-tabs li' ).forEach( function( li ) { li.className = ( li === tab.parentNode ) ? 'active' : ''; } );
					return;
				}
				if( e.target.closest && e.target.closest( '.episode-play-action .btn-primary' ) ) { setTimeout( function() { setOpen( false ); }, 0 ); }
			} );
			document.addEventListener( 'click', function() { setOpen( false ); } );
			document.addEventListener( 'keydown', function( e ) { if( e.key === 'Escape' ) { setOpen( false ); } } );
		} )();
	</script>
{/if}

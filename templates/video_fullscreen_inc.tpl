{* A full screen icon button (the green view-fullscreen-green icon) after the shared episode player (#fisheye-episode-player), for the built-in webOS TV
   browser, whose own control bar has no full screen button (most desktop browsers' do). Shown in every browser while we
   find out why it did not appear on the TV when it was limited to a Web0S/webOS user agent. Included from
   episode_detail_panels_inc.tpl, so it comes with the player's own page.

   Asks the video for real full screen (standard, then webkit, then iOS webkitEnterFullscreen); with none of those,
   or a refusal, the video fills the browser window instead (.video-fill / .video-fill-page, themes base.css).
   Full screen holds one history entry so the remote's Back key (keyCode 461) or Escape leaves it instead of the
   page. The button follows the player's visibility - the player stays hidden until Play Episode is pressed. *}
{capture assign=fsIcon}{biticon ipackage="icons" iname="view-fullscreen-green" iexplain="Full screen" iforce="icon"}{/capture}
<script>
	var FISHEYE_FS_LABEL = '{tr}Full screen{/tr}';
	var FISHEYE_FS_EXIT_LABEL = '{tr}Exit full screen{/tr}';
	var FISHEYE_FS_ICON = '{$fsIcon|escape:"javascript"}';
{literal}
	( function() {
		var v = document.getElementById( 'fisheye-episode-player' );
		if( !v ) {
			return;
		}
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'btn btn-link btn-sm video-fullscreen';
		btn.innerHTML = FISHEYE_FS_ICON;
		btn.title = FISHEYE_FS_LABEL;
		v.parentNode.insertBefore( btn, v.nextSibling );
		var pushed = false;
		var active = false;
		var fsElement = function() {
			return document.fullscreenElement || document.webkitFullscreenElement || null;
		};
		var enter = function() {
			if( active ) {
				return;
			}
			active = true;
			try {
				history.pushState( { fisheyeVideoFs: 1 }, '' );
				pushed = true;
			} catch( e ) {}
		};
		var leave = function() {
			if( !active ) {
				return;
			}
			active = false;
			v.classList.remove( 'video-fill' );
			document.documentElement.classList.remove( 'video-fill-page' );
			btn.title = FISHEYE_FS_LABEL;
			if( fsElement() ) {
				( document.exitFullscreen || document.webkitExitFullscreen ).call( document );
			}
			if( pushed && history.state && history.state.fisheyeVideoFs ) {
				history.back();
			}
			pushed = false;
		};
		var fillOn = function() {
			enter();
			v.classList.add( 'video-fill' );
			document.documentElement.classList.add( 'video-fill-page' );
			btn.title = FISHEYE_FS_EXIT_LABEL;
		};
		btn.addEventListener( 'click', function() {
			if( active ) {
				leave();
				return;
			}
			var request = v.requestFullscreen || v.webkitRequestFullscreen || v.webkitEnterFullscreen;
			if( !request ) {
				fillOn();
				return;
			}
			try {
				var result = request.call( v );
				if( result && result.catch ) {
					result.catch( fillOn );
				}
			} catch( e ) {
				fillOn();
			}
		}, false );
		var onFullscreenChange = function() {
			if( fsElement() === v ) {
				enter();
			} else if( active && !v.classList.contains( 'video-fill' ) ) {
				leave();
			}
		};
		document.addEventListener( 'fullscreenchange', onFullscreenChange, false );
		document.addEventListener( 'webkitfullscreenchange', onFullscreenChange, false );
		// Back pops the entry itself: only the video is left to put back
		window.addEventListener( 'popstate', function() {
			if( active ) {
				pushed = false;
				leave();
			}
		}, false );
		document.addEventListener( 'keydown', function( e ) {
			if( active && ( e.key == 'Escape' || e.keyCode == 461 ) ) {
				e.preventDefault();
				leave();
			}
		}, false );
		var sync = function() {
			btn.hidden = ( window.getComputedStyle( v ).display == 'none' );
		};
		sync();
		if( window.MutationObserver ) {
			new MutationObserver( sync ).observe( v, { attributes: true, attributeFilter: [ 'style', 'class' ] } );
		}
	} )();
{/literal}
</script>

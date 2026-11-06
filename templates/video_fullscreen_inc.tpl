{* A full screen icon button (the green view-fullscreen-green icon) after the shared episode player (#fisheye-episode-player), for the built-in webOS TV
   browser, whose own control bar has no full screen button (most desktop browsers' do). Shown in every browser while we
   find out why it did not appear on the TV when it was limited to a Web0S/webOS user agent. Included from
   episode_detail_panels_inc.tpl, so it comes with the player's own page.

   Asks the video for real full screen (standard, then webkit, then iOS webkitEnterFullscreen); with none of those,
   or a refusal, the video fills the browser window instead (.video-fill / .video-fill-page, themes base.css).
   Full screen holds one history entry so the remote's Back key (keyCode 461) or Escape leaves it instead of the
   page. Pass playerId to use it on another player (the film page passes liberty-video-player). The button is placed next to the Play / Stop button of the episode or featurette that is playing, and hidden when nothing is. *}
{* On the film page (playerId passed) the icon is Play: it starts the film and goes full screen *}
{if !empty( $playerId )}{capture assign=fsIcon}{biticon ipackage="icons" iname="media-playback-start-large" iexplain="Play full screen" iforce="icon" isize="large"}{/capture}
{else}{capture assign=fsIcon}{biticon ipackage="icons" iname="view-fullscreen-green" iexplain="Full screen" iforce="icon" isize="large"}{/capture}{/if}
<script>
	var FISHEYE_FS_LABEL = '{if !empty( $playerId )}{tr}Play full screen{/tr}{else}{tr}Full screen{/tr}{/if}';
	var FISHEYE_FS_EXIT_LABEL = '{tr}Exit full screen{/tr}';
	var FISHEYE_FS_ICON = '{$fsIcon|escape:"javascript"}';
	var FISHEYE_FS_PLAYER_ID = '{$playerId|default:'fisheye-episode-player'}';
	var FISHEYE_PLAY_FULLSCREEN = {if $gBitSystem->isFeatureActive( 'fisheyemedia_play_fullscreen' )}true{else}false{/if};
{literal}
	( function() {
		var v = document.getElementById( FISHEYE_FS_PLAYER_ID );
		if( !v ) {
			return;
		}
		// A film's page has its own player, always showing the film (liberty's player.tpl, view_film.tpl) and no Play or Stop
		// button of ours: the icon sits beside the player, and Play is the player's own control (see FISHEYE_PLAY_FULLSCREEN
		// below). The episode and season pages swap their player in and out under Play / Stop buttons instead.
		var ownPlayer = ( FISHEYE_FS_PLAYER_ID != 'fisheye-episode-player' );
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'btn btn-link btn-sm video-fullscreen';
		btn.innerHTML = FISHEYE_FS_ICON;
		btn.title = FISHEYE_FS_LABEL;
		btn.hidden = !ownPlayer;
		if( ownPlayer ) {
			v.parentNode.insertBefore( btn, v.nextSibling );
		}
		// Sits beside the Play / Stop button of whichever episode or featurette is playing (episode_detail_panels_inc.tpl
		// calls this on play and on stop), not beside the <video>, which sits among the page's floated columns
		var gap = document.createTextNode( ' ' );
		window.fisheyePlaceFullscreen = function( pAnchor ) {
			if( pAnchor ) {
				pAnchor.parentNode.insertBefore( gap, pAnchor.nextSibling );
				pAnchor.parentNode.insertBefore( btn, gap.nextSibling );
				btn.hidden = false;
			} else {
				leave();
				btn.hidden = true;
			}
		};
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
		// pStop: leaving by Back, Escape or the browser's own exit, with "Play episodes full screen" on, also stops the
		// episode - back to the poster and episode list, as if Play had not been pressed
		var leave = function( pStop ) {
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
			if( pStop && FISHEYE_PLAY_FULLSCREEN ) {
				if( window.fisheyeResetEpisodePlayer && !ownPlayer ) {
					fisheyeResetEpisodePlayer();
				} else {
					v.pause();
				}
			}
		};
		var fillOn = function() {
			enter();
			v.classList.add( 'video-fill' );
			document.documentElement.classList.add( 'video-fill-page' );
			btn.title = FISHEYE_FS_EXIT_LABEL;
		};
		var requestedAt = 0;
		var startFullscreen = function() {
			// the play event and a click can both ask within the same moment: one request is enough
			if( Date.now() - requestedAt < 1000 ) {
				return;
			}
			requestedAt = Date.now();
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
		};
		btn.addEventListener( 'click', function() {
			if( active ) {
				leave();
				return;
			}
			startFullscreen();
			if( ownPlayer ) {
				v.play();
			}
		}, false );
		// "Play episodes full screen": the play code calls this from the Play click itself, which is the user gesture the
		// browser needs to allow the full screen request
		if( FISHEYE_PLAY_FULLSCREEN ) {
			window.fisheyePlayFullscreen = function() {
				if( !active ) {
					startFullscreen();
				}
			};
			// The film page's player starts from its own control (or a featurette button), not our Play button: its play
			// event follows that click closely enough for the browser to still count it as the user's gesture
			if( ownPlayer ) {
				v.addEventListener( 'play', window.fisheyePlayFullscreen, false );
			}
		}
		var onFullscreenChange = function() {
			if( fsElement() === v ) {
				enter();
			} else if( active && !v.classList.contains( 'video-fill' ) ) {
				leave( true );
			}
		};
		document.addEventListener( 'fullscreenchange', onFullscreenChange, false );
		document.addEventListener( 'webkitfullscreenchange', onFullscreenChange, false );
		// Back pops the entry itself: only the video is left to put back
		window.addEventListener( 'popstate', function() {
			if( active ) {
				pushed = false;
				leave( true );
			}
		}, false );
		document.addEventListener( 'keydown', function( e ) {
			if( active && ( e.key == 'Escape' || e.keyCode == 461 ) ) {
				e.preventDefault();
				leave( true );
			}
		}, false );
	} )();
{/literal}
</script>

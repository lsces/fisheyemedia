{* fisheyemedia's own section of fisheye's menu - contributed through the generic 'fisheye_menu_tpl'
   service (see fisheyemedia's bit_setup_inc.php), so fisheye's menu_fisheye.tpl never names this
   package. List items only - fisheye's own <ul> wraps them. Each goes through library.php, which
   finds the top-level gallery of that title (gallery ids differ per site). *}
{strip}
{if $gBitUser->hasPermission('p_fisheye_view')}
	<li class="divider"></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}library.php?root=Films">{biticon ipackage="icons" iname="video-x-generic" iexplain="Films" ilocation=menu}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}library.php?root=Music">{biticon ipackage="icons" iname="audio-x-generic" iexplain="Music" ilocation=menu}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}library.php?root=TV+Shows">{biticon ipackage="icons" iname="video-display" iexplain="TV Shows" ilocation=menu}</a></li>
	<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}library.php?root=Library">{biticon ipackage="icons" iname="folder-open" iexplain="Library" ilocation=menu}</a></li>
{/if}
{/strip}

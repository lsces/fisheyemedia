{* fisheyemedia's own section of fisheye's menu - contributed through the generic 'fisheye_menu_tpl'
   service (see fisheyemedia's bit_setup_inc.php), so fisheye's menu_fisheye.tpl never names this
   package. List items only - fisheye's own <ul> wraps them. Each goes through library.php, which
   finds the top-level gallery of that title (gallery ids differ per site). *}
{strip}
{if $gBitUser->hasPermission('p_fisheye_view')}
	<li class="divider"></li>
	{if 'Documentation'|library_visible}<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}library.php?root=Documentation">{biticon ipackage="icons" iname="document" iexplain="Documentation" ilocation=menu}</a></li>{/if}
	{if 'Films'|library_visible}<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}library.php?root=Films">{biticon ipackage="icons" iname="video-x-generic" iexplain="Films" ilocation=menu}</a></li>{/if}
	{if 'Library'|library_visible}<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}library.php?root=Library">{biticon ipackage="icons" iname="folder-open" iexplain="Library" ilocation=menu}</a></li>{/if}
	{if 'Local Tutorials'|library_visible}<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}library.php?root=Local+Tutorials">{biticon ipackage="icons" iname="applications-education" iexplain="Local Tutorials" ilocation=menu}</a></li>{/if}
	{if 'Local Videos'|library_visible}<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}library.php?root=Local+Videos">{biticon ipackage="icons" iname="video-x-generic" iexplain="Local Videos" ilocation=menu}</a></li>{/if}
	{if 'Magazines'|library_visible}<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}library.php?root=Magazines">{biticon ipackage="icons" iname="x-office-document" iexplain="Magazines" ilocation=menu}</a></li>{/if}
	{if 'Music'|library_visible}<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}library.php?root=Music">{biticon ipackage="icons" iname="audio-x-generic" iexplain="Music" ilocation=menu}</a></li>{/if}
	{if 'TV Shows'|library_visible}<li><a class="item" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}library.php?root=TV+Shows">{biticon ipackage="icons" iname="video-display" iexplain="TV Shows" ilocation=menu}</a></li>{/if}
{/if}
{/strip}

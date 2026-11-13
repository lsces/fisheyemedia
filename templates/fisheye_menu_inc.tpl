{* fisheyemedia's own section of fisheye's menu - contributed through the generic 'fisheye_menu_tpl'
   service (see fisheyemedia's bit_setup_inc.php), so fisheye's menu_fisheye.tpl never names this
   package. List items only - fisheye's own <ul> wraps them. The entries are the site's top-level
   galleries that the viewer can open, alphabetically (FisheyeLibraryMenu::entries()): none is named
   here, so a new top-level gallery appears by itself and a gallery restricted to a role is absent for
   anyone outside it. *}
{strip}
{if $gBitUser->hasPermission('p_fisheye_view')}
	{assign var=libraryEntries value=''|library_entries}
	{if $libraryEntries}<li class="divider"></li>{/if}
	{foreach $libraryEntries as $entry}
		<li><a class="item" href="{$entry.url|escape}">{biticon ipackage="icons" iname=$entry.icon iexplain=$entry.title ilocation=menu}</a></li>
	{/foreach}
{/if}
{/strip}

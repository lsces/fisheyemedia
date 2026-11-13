<?php
/**
 * The entries of the media library's section of fisheye's menu: the site's top-level galleries, as the Galleries list gives them.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeGallery;

class FisheyeLibraryMenu {
	/** @var list<array{title:string,url:string}>|null the entries kept for the request: the menu is drawn on every page */
	private static ?array $entries = null;

	/**
	 * The menu's entries: the site's top-level galleries as the Galleries list gives them (FisheyeGallery::getList() with root_only - so a gallery restricted to
	 * Registered is not in it for an anonymous visitor, by the same filter as the list page), alphabetically. Nothing is named here.
	 *
	 * @return list<array{content_id:int,title:string,url:string,icon:string}>
	 */
	public static function entries(): array {
		global $gBitDb;
		if( self::$entries === null ) {
			self::$entries = [];
			$hash = [ 'root_only' => true, 'no_thumbnails' => true, 'sort_mode' => 'title_asc', 'max_records' => 200 ];
			$rows = ( new FisheyeGallery() )->getList( $hash );
			// each gallery's own menu icon (a gallery preference, set on its Edit page), in one query
			$icons = $rows ? ( $gBitDb->getAssoc( "SELECT `content_id`, `pref_value` FROM `".BIT_DB_PREFIX."liberty_content_prefs` WHERE `pref_name` = ? AND `content_id` IN (".implode( ',', array_map( fn( $r ) => (int)$r['content_id'], $rows ) ).")", [ FisheyeGallery::MENU_ICON_PREFERENCE ] ) ?: [] ) : [];
			$choices = FisheyeGallery::menuIconChoices();
			foreach( $rows as $row ) {
				$icon = (string)( $icons[(int)$row['content_id']] ?? '' );
				self::$entries[] = [ 'content_id' => (int)$row['content_id'], 'title' => (string)$row['title'], 'url' => FisheyeGallery::getDisplayUrlFromHash( $row ), 'icon' => isset( $choices[$icon] ) ? $icon : 'folder-open' ];
			}
			usort( self::$entries, fn( $a, $b ) => strcasecmp( $a['title'], $b['title'] ) );
		}
		return self::$entries;
	}
}

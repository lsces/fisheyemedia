<?php
/**
 * The media library's contribution to search's "Search in" list (the 'search_scopes_class' service): one scope for each top-level gallery the viewer can open
 * - the same list the media menu shows - so a search can be limited to one gallery or several, whatever mix of content types is inside. Search itself
 * names no package: it asks this class what it offers and which content ids a chosen scope covers.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeGallery;

class FisheyeSearchScopes {
	/**
	 * @return array{heading:string,scopes:array<string,string>,claims:list<string>}  the scopes (key => label), the heading they are listed under, and the
	 *   content types they cover - which search then leaves out of its own per-type entries
	 */
	public static function searchScopes(): array {
		global $gBitSystem, $gLibertySystem;
		$scopes = [];
		foreach( FisheyeLibraryMenu::entries() as $entry ) {
			$scopes[(string)$entry['content_id']] = $entry['title'];   // the key is the gallery's content_id, as a link would carry it
		}
		$claims = [];
		foreach( $gLibertySystem->mContentTypes as $guid => $type ) {
			if( in_array( strtolower( (string)$type['handler_package'] ), [ 'fisheye', 'fisheyemedia' ], true ) ) {
				$claims[] = $guid;
			}
		}
		return [ 'heading' => (string)$gBitSystem->getConfig( 'fisheye_menu_text' ) ?: 'Media', 'scopes' => $scopes, 'claims' => $claims ];
	}

	/**
	 * The content ids of a scope key this class offers: a gallery's content_id, giving the gallery and everything inside it. Null for a key that is not one of its galleries - including a
	 * gallery the viewer cannot open, since only the menu's own list is consulted.
	 *
	 * @return array{label:string,ids:list<int>}|null
	 */
	public static function searchScopeIds( string $pKey ): ?array {
		if( !preg_match( '/^\d+$/', $pKey ) ) {
			return null;
		}
		foreach( FisheyeLibraryMenu::entries() as $entry ) {
			if( $entry['content_id'] === (int)$pKey ) {
				return [ 'label' => $entry['title'], 'ids' => array_merge( [ $entry['content_id'] ], FisheyeGallery::containedContentIds( $entry['content_id'] ) ) ];
			}
		}
		return null;
	}
}

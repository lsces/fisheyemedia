<?php
/**
 * Which of a gallery's sub-galleries are kept out of its list. A gallery can be a storage location for another part of the library (the pictures
 * of the books) and sit inside the gallery it serves without being something to browse there; the parent lists such galleries by id in its
 * preference hidden_children, set on the parent's Edit page, and the Library and Docs grids leave them out. It decides what is listed, not who may
 * open it - the gallery stays reachable by its own link and its access is the access setting's business (FisheyeAccess).
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

class FisheyeGalleryList {
	public const PREFERENCE = 'hidden_children';

	/** @var array<int,array<int,bool>> the ids kept out, per parent gallery, for the request: a grid asks once per tile */
	private static array $cache = [];

	/** Content ids of the sub-galleries a gallery keeps out of its list. */
	public static function hiddenIds( $pGallery ): array {
		$parent = (int)$pGallery->mContentId;
		if( !isset( self::$cache[$parent] ) ) {
			$ids = array_filter( array_map( 'intval', explode( ',', (string)$pGallery->getPreference( self::PREFERENCE ) ) ) );
			self::$cache[$parent] = array_fill_keys( $ids, true );
		}
		return array_keys( self::$cache[$parent] );
	}

	/** Smarty modifier listed_in: {if $itemContentId|listed_in:$gContent} - false for a sub-gallery its parent keeps out of its list. */
	public static function listed( $pContentId, $pGallery ): bool {
		if( !is_object( $pGallery ) ) {
			return true;
		}
		self::hiddenIds( $pGallery );
		return !isset( self::$cache[(int)$pGallery->mContentId][(int)$pContentId] );
	}

	/** The galleries directly inside a gallery, content_id => title, for the Edit page's list. */
	public static function childGalleries( int $pParentContentId ): array {
		global $gBitDb;
		$rows = $gBitDb->getAll(
			"SELECT g.`content_id`, lc.`title` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
			 JOIN `".BIT_DB_PREFIX."fisheye_gallery` g ON g.`content_id` = m.`item_content_id`
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = g.`content_id`
			 WHERE m.`gallery_content_id` = ? ORDER BY lc.`title`", [ $pParentContentId ] ) ?: [];
		return array_column( $rows, 'title', 'content_id' );
	}

	/** Keep these sub-galleries out of the list (ids that are not sub-galleries of this gallery are ignored; none removes the setting). */
	public static function save( $pGallery, array $pIds ): void {
		$children = self::childGalleries( (int)$pGallery->mContentId );
		$keep = array_values( array_filter( array_map( 'intval', $pIds ), fn( $id ) => isset( $children[$id] ) ) );
		sort( $keep );
		$pGallery->storePreference( self::PREFERENCE, $keep ? implode( ',', $keep ) : null );
		unset( self::$cache[(int)$pGallery->mContentId] );
	}
}

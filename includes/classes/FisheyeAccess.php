<?php
/**
 * The access of a gallery's contents. Protector keeps access per content item (the roles that may view it, liberty_content_role_map), so restricting a
 * gallery alone hides only the gallery's own page: everything inside it stays open. Fisheye copies a target gallery's roles onto an upload or a
 * new sub-gallery; this does the same for what the Magazines workbench loads and makes, and can apply a gallery's access to everything already
 * inside it (the backfill). Nothing here knows more about protector than its own store call and its role table.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

class FisheyeAccess {
	/** Role ids restricting a content item, sorted; [] when it has no restriction of its own. */
	public static function rolesOf( int $pContentId ): array {
		global $gBitDb;
		$roles = array_map( 'intval', $gBitDb->getCol( "SELECT `role_id` FROM `".BIT_DB_PREFIX."liberty_content_role_map` WHERE `content_id` = ?", [ $pContentId ] ) ?: [] );
		sort( $roles );
		return $roles;
	}

	/** The role_id value protector's store hook takes for these roles: a single role (-1 for none) or a list, as the site's single-role setting says. */
	public static function storeValue( array $pRoles ) {
		global $gBitSystem;
		return $gBitSystem->isFeatureActive( 'protector_single_role' ) ? ( $pRoles[0] ?? -1 ) : $pRoles;
	}

	/** Protector's form value for a new item or gallery filed under this gallery, or null when the gallery has no restriction (nothing to pass). */
	public static function inheritedFor( int $pGalleryContentId ) {
		$roles = self::rolesOf( $pGalleryContentId );
		return $roles ? self::storeValue( $roles ) : null;
	}

	/** Content ids of everything inside a gallery at any depth (galleries and items), without the gallery itself. */
	public static function contentsInside( int $pGalleryContentId ): array {
		global $gBitDb;
		$seen = [ $pGalleryContentId => true ];
		$queue = [ $pGalleryContentId ];
		$found = [];
		while( $queue ) {
			$batch = array_splice( $queue, 0, 400 );
			$rows = $gBitDb->getCol( "SELECT `item_content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` WHERE `gallery_content_id` IN (".implode( ',', array_fill( 0, count( $batch ), '?' ) ).")", $batch ) ?: [];
			foreach( $rows as $cid ) {
				$cid = (int)$cid;
				if( !isset( $seen[$cid] ) ) {
					$seen[$cid] = true;
					$found[] = $cid;
					$queue[] = $cid;
				}
			}
		}
		return $found;
	}

	/** Of everything inside a gallery, the content ids whose own roles differ from the gallery's. */
	public static function differing( int $pGalleryContentId ): array {
		global $gBitDb;
		$want = self::rolesOf( $pGalleryContentId );
		$inside = self::contentsInside( $pGalleryContentId );
		$have = [];
		foreach( array_chunk( $inside, 500 ) as $chunk ) {
			foreach( $gBitDb->getAll( "SELECT `content_id`, `role_id` FROM `".BIT_DB_PREFIX."liberty_content_role_map` WHERE `content_id` IN (".implode( ',', array_fill( 0, count( $chunk ), '?' ) ).")", $chunk ) ?: [] as $row ) {
				$have[(int)$row['content_id']][] = (int)$row['role_id'];
			}
		}
		$out = [];
		foreach( $inside as $cid ) {
			$roles = $have[$cid] ?? [];
			sort( $roles );
			if( $roles !== $want ) {
				$out[] = $cid;
			}
		}
		return $out;
	}

	/**
	 * Give everything inside a gallery the gallery's own roles (none when it has none): protector's bulk store, one delete and one insert per role for each
	 * block of items, in one transaction.
	 *
	 * @return array{changed:int,errors:list<string>}
	 */
	public static function applyInside( int $pGalleryContentId ): array {
		global $gBitDb, $gBitSystem, $gProtector;
		$ret = [ 'changed' => 0, 'errors' => [] ];
		if( !$gBitSystem->isPackageActive( 'protector' ) || !is_object( $gProtector ) || !method_exists( $gProtector, 'storeProtectionMany' ) ) {
			$ret['errors'][] = 'The protector package is not active, so there is no access to apply.';
			return $ret;
		}
		@set_time_limit( 300 );
		$value = self::storeValue( self::rolesOf( $pGalleryContentId ) );
		$gBitDb->StartTrans();
		$ret['changed'] = $gProtector->storeProtectionMany( self::differing( $pGalleryContentId ), $value );
		$gBitDb->CompleteTrans();
		return $ret;
	}
}

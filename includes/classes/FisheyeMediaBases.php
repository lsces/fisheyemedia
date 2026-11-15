<?php
/**
 * The media library's base galleries - Library, Films, Music, TV Shows, Magazines, Documentation - each kept by the content_id in the site config
 * (fisheyemedia_<role>_gallery), never found by its name. A kept id also says that media type is installed. The first time on a site that already has
 * the gallery, it is adopted by its title (at the top level) and its id kept; a site with none has none (null), and a loader that makes it keeps its id
 * with remember(). Local Videos, Local Tutorials and Pictures are plain fisheye galleries and are not here.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeGallery;

class FisheyeMediaBases {
	/** The roles and the title a base is first adopted by. */
	public const BASES = [ 'library' => 'Library', 'films' => 'Films', 'music' => 'Music', 'tv' => 'TV Shows', 'magazines' => 'Magazines', 'docs' => 'Documentation' ];

	/** The roles and the title each base is kept under: the fixed list and the registered archives (FisheyeArchives). @return array<string,string> */
	public static function bases(): array {
		return self::BASES + FisheyeArchives::roleTitles();
	}

	/** @var array<string,?int> answers kept for the request */
	private static array $kept = [];

	/** The site config entry that keeps a role's content_id (a config name is 40 characters at most). */
	public static function configKey( string $pRole ): string {
		return 'fisheyemedia_'.$pRole.'_gallery';
	}

	/** content_id of the base gallery, or null when this site has none. */
	public static function contentId( string $pRole ): ?int {
		global $gBitSystem, $gBitDb;
		if( !isset( self::bases()[$pRole] ) ) {
			return null;
		}
		if( array_key_exists( $pRole, self::$kept ) ) {
			return self::$kept[$pRole];
		}
		$id = (int)$gBitSystem->getConfig( self::configKey( $pRole ), 0 );
		if( !$id || !$gBitDb->getOne( "SELECT `content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery` WHERE `content_id` = ?", [ $id ] ) ) {
			$id = 0;
			if( $galleryId = FisheyeGallery::getTopGalleryId( self::bases()[$pRole] ) ) {
				$id = (int)$gBitDb->getOne( "SELECT `content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery` WHERE `gallery_id` = ?", [ $galleryId ] );
				if( $id ) {
					$gBitSystem->storeConfig( self::configKey( $pRole ), $id, FISHEYEMEDIA_PKG_NAME );
				}
			}
		}
		return self::$kept[$pRole] = $id ?: null;
	}

	/** gallery_id of the base gallery, or null - what addToGalleries() and the galleries' own pages take. */
	public static function galleryId( string $pRole ): ?int {
		global $gBitDb;
		$id = self::contentId( $pRole );
		$galleryId = $id ? $gBitDb->getOne( "SELECT `gallery_id` FROM `".BIT_DB_PREFIX."fisheye_gallery` WHERE `content_id` = ?", [ $id ] ) : null;
		return $galleryId ? (int)$galleryId : null;
	}

	/** Whether this site has that media type (a base gallery for it). */
	public static function installed( string $pRole ): bool {
		return self::contentId( $pRole ) !== null;
	}

	/** Keep the id of a base gallery just made. */
	public static function remember( string $pRole, int $pContentId ): void {
		global $gBitSystem;
		if( isset( self::bases()[$pRole] ) && $pContentId > 0 ) {
			$gBitSystem->storeConfig( self::configKey( $pRole ), $pContentId, FISHEYEMEDIA_PKG_NAME );
			self::$kept[$pRole] = $pContentId;
		}
	}
}

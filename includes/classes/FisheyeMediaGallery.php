<?php
/**
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeGallery;
use Bitweaver\Liberty\LibertyContent;

// liberty_content_types.content_type_guid is VARCHAR(16) - 'fisheyemediagallery' (19 chars)
// overflowed it, same class of issue already hit and abbreviated around for contactwiki's own
// content-type guids.
define( 'FISHEYEMEDIAGALLERY_CONTENT_TYPE_GUID', 'fisheyemediagal' );

/**
 * Layer between base fisheye's FisheyeGallery and the media content types that need gallery
 * container semantics - holds logic that only makes sense once media is involved (an artist's own
 * discography strip layout, unloaded-album/video folder detection), kept off FisheyeGallery itself
 * so a plain photo-gallery site never carries it. Used two ways: as the base class FisheyeProgram
 * extends (a TV show, still fundamentally a gallery of its own seasons), and directly, as its own
 * registered content type (content_type_guid='fisheyemediagal', abbreviated - see the VARCHAR(16)
 * comment above) for a Music artist/composer gallery - load_music.php creates these directly rather
 * than a plain FisheyeGallery, specifically so the music_grid layout's own
 * getCategorizedItems()/hasUnloadedAlbumCandidates() calls resolve.
 *
 * @package fisheyemedia
 */
#[\AllowDynamicProperties]
class FisheyeMediaGallery extends FisheyeGallery {
	use FisheyeMediaTrait;

	public function __construct( $pGalleryId = null, $pContentId = null ) {
		parent::__construct( $pGalleryId, $pContentId );
		$this->mContentTypeGuid = FISHEYEMEDIAGALLERY_CONTENT_TYPE_GUID;
		$this->registerContentType( FISHEYEMEDIAGALLERY_CONTENT_TYPE_GUID, [
			'content_type_guid' => FISHEYEMEDIAGALLERY_CONTENT_TYPE_GUID,
			'content_name'      => 'Media Gallery',
			'handler_class'     => 'FisheyeMediaGallery',
			'handler_package'   => 'fisheyemedia',
			'handler_file'      => 'FisheyeMediaGallery.php',
			'maintainer_url'    => 'https://www.bitweaver.org',
		] );
	}

	/**
	 * A music gallery's own folder, relative to the storage root ('Music/.../', trailing slash), or
	 * null if none is found. Shared by load_album.php, load_video.php and the hasUnloaded*()
	 * checks below. Tried in order: Music/<title>/ (an artist/composer gallery); Music/<parent>/
	 * <title>/ (a box set nested directly under its artist); Music/<parent>/<category>/<title>/ (a
	 * box set kept inside a discography category folder - Studio/Live/... - which gets no gallery
	 * of its own, see FisheyeAlbum::isCategoryFolder()).
	 *
	 * @param FisheyeGallery $pGallery any gallery object - static so callers holding a plain
	 *                                 FisheyeGallery can use it too
	 * @return string|null
	 */
	public static function resolveMusicFolder( FisheyeGallery $pGallery ): ?string {
		$root = \Bitweaver\Liberty\mime_film_get_storage_root();
		$galleryTitle = $pGallery->getTitle();
		if( empty( $root ) || empty( $galleryTitle ) ) {
			return null;
		}
		$musicDir = $root.'Music/';
		if( is_dir( $musicDir.$galleryTitle.'/' ) ) {
			return 'Music/'.$galleryTitle.'/';
		}
		$parentGalleries = $pGallery->getParentGalleries();
		$parentTitle = $parentGalleries ? current( $parentGalleries )['title'] : null;
		if( empty( $parentTitle ) || !is_dir( $musicDir.$parentTitle.'/' ) ) {
			return null;
		}
		if( is_dir( $musicDir.$parentTitle.'/'.$galleryTitle.'/' ) ) {
			return 'Music/'.$parentTitle.'/'.$galleryTitle.'/';
		}
		foreach( scandir( $musicDir.$parentTitle.'/' ) ?: [] as $entry ) {
			if( FisheyeAlbum::isCategoryFolder( $entry ) && is_dir( $musicDir.$parentTitle.'/'.$entry.'/'.$galleryTitle.'/' ) ) {
				return 'Music/'.$parentTitle.'/'.$entry.'/'.$galleryTitle.'/';
			}
		}
		return null;
	}

	/**
	 * Whether this artist/composer gallery's own folder under Music/ has at least one album folder
	 * not yet registered - cheap short-circuit (stops at the first match) version of load_album.php's
	 * own candidate scan, so music_gallery_icons_inc.tpl can hide "Load Album" entirely once there's
	 * genuinely nothing left, rather than showing a button that always just says "nothing to load".
	 * Same folder-resolution and category-flattening logic as that page - see its own docblock.
	 *
	 * @return bool
	 */
	public function hasUnloadedAlbumCandidates(): bool {
		global $gBitDb;
		$root = \Bitweaver\Liberty\mime_film_get_storage_root();
		if( empty( $root ) ) {
			return false;
		}
		$artistRelative = self::resolveMusicFolder( $this );
		$artistDir = $artistRelative ? $root.$artistRelative : null;
		if( !$artistDir ) {
			return false;
		}

		$checkFolder = function( string $pFolder, string $pTitle ) use ( $gBitDb ): bool {
			if( str_starts_with( basename( $pFolder ), '.' ) || !FisheyeAlbum::folderHasTracks( $pFolder ) ) {
				return false;
			}
			return !$gBitDb->getOne(
				"SELECT content_id FROM liberty_content WHERE content_type_guid = 'fisheyealbum' AND title = ?",
				[ $pTitle ]
			);
		};
		foreach( scandir( $artistDir ) ?: [] as $entry ) {
			if( str_starts_with( $entry, '.' ) || !is_dir( $artistDir.$entry ) ) {
				continue;
			}
			if( FisheyeAlbum::isCategoryFolder( $entry ) ) {
				foreach( scandir( $artistDir.$entry.'/' ) ?: [] as $categoryEntry ) {
					if( str_starts_with( $categoryEntry, '.' ) || !is_dir( $artistDir.$entry.'/'.$categoryEntry ) ) {
						continue;
					}
					if( $checkFolder( $artistDir.$entry.'/'.$categoryEntry.'/', $categoryEntry ) ) {
						return true;
					}
				}
				continue;
			}
			if( $checkFolder( $artistDir.$entry.'/', $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Same idea as hasUnloadedAlbumCandidates(), for this gallery's own Videos/ subfolder (see
	 * load_video.php's own docblock for the folder shape).
	 *
	 * @return bool
	 */
	public function hasUnloadedVideoCandidates(): bool {
		global $gBitDb;
		$root = \Bitweaver\Liberty\mime_film_get_storage_root();
		if( empty( $root ) ) {
			return false;
		}
		$artistRelative = self::resolveMusicFolder( $this );
		$videosDir = $artistRelative ? $root.$artistRelative.'Videos/' : null;
		if( !$videosDir || !is_dir( $videosDir ) ) {
			return false;
		}

		$videoExtensions = [ 'mkv', 'mp4', 'm4v', 'avi' ];
		$checkFile = function( string $pRelative ) use ( $gBitDb, $videoExtensions ): bool {
			$ext = strtolower( pathinfo( $pRelative, PATHINFO_EXTENSION ) );
			if( !in_array( $ext, $videoExtensions, true ) ) {
				return false;
			}
			return !$gBitDb->getOne(
				"SELECT la.content_id FROM liberty_attachments la INNER JOIN liberty_files lf ON lf.file_id = la.foreign_id WHERE la.attachment_plugin_guid = 'mimefilm' AND lf.file_name = ?",
				[ $pRelative ]
			);
		};
		foreach( scandir( $videosDir ) ?: [] as $entry ) {
			$fullPath = $videosDir.$entry;
			if( is_file( $fullPath ) ) {
				if( $checkFile( $artistRelative.'Videos/'.$entry ) ) {
					return true;
				}
			} elseif( is_dir( $fullPath ) && !str_starts_with( $entry, '.' ) ) {
				foreach( scandir( $fullPath ) ?: [] as $subEntry ) {
					if( is_file( $fullPath.'/'.$subEntry ) && $checkFile( $artistRelative.'Videos/'.$entry.'/'.$subEntry ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Groups this gallery's own items for the artist-page "strip" layout (Plex-style, one row
	 * per discography category) that fisheye_music_grid_inc.tpl renders instead of a single
	 * paginated grid. Loads every item in one call (see the large max_records below) since
	 * strips flow down the page rather than paging, then buckets each FisheyeAlbum item by its
	 * own 'category' xref (FISHEYEALBUM_CATEGORY_FOLDER_NAMES order) - a single bulk query, not
	 * one per item. A nested FisheyeGallery item (a box set or the "Videos" subgallery - see
	 * FisheyeAlbum::createSubGallery()/findOrCreateNestedGallery(), both addItem() straight into
	 * this gallery the same as a plain album) carries no category of its own, so those land in a
	 * trailing 'collections' bucket instead. The 'other' bucket (last of
	 * FISHEYEALBUM_CATEGORY_FOLDER_NAMES) also catches any album whose category doesn't match a
	 * known name - every album registered through the current flow always gets a real category, so
	 * in practice this should stay empty.
	 *
	 * @return array<string, LibertyContent[]> keyed by category, empty groups dropped, fixed
	 *         FISHEYEALBUM_CATEGORY_FOLDER_NAMES order with 'collections' last
	 */
	public function getCategorizedItems(): array {
		// loadImages() takes its param by reference - can't pass the array literal directly.
		$listHash = [ 'page' => -1, 'offset' => 0, 'max_records' => 1000 ];
		$this->loadImages( $listHash );

		$groups = [];
		foreach( FISHEYEALBUM_CATEGORY_FOLDER_NAMES as $category ) {
			$groups[$category] = [];
		}
		$collections = [];

		if( $this->mItems ) {
			$albumContentIds = [];
			foreach( $this->mItems as $contentId => $item ) {
				if( $item->isContentType( 'fisheyealbum' ) ) {
					$albumContentIds[] = $contentId;
				}
			}

			$categoryMap = [];
			if( $albumContentIds ) {
				$placeholders = implode( ',', array_fill( 0, count( $albumContentIds ), '?' ) );
				$rows = $this->mDb->getAll(
					"SELECT content_id, xkey_ext FROM `".BIT_DB_PREFIX."liberty_xref` WHERE item = 'category' AND content_id IN ( $placeholders )",
					$albumContentIds
				);
				foreach( $rows as $row ) {
					$categoryMap[$row['content_id']] = strtolower( $row['xkey_ext'] );
				}
			}

			foreach( $this->mItems as $contentId => $item ) {
				if( $item->isContentType( 'fisheyegallery' ) ) {
					$collections[$contentId] = $item;
					continue;
				}
				$category = $categoryMap[$contentId] ?? 'other';
				if( !isset( $groups[$category] ) ) {
					$category = 'other';
				}
				$groups[$category][$contentId] = $item;
			}
		}

		$groups = array_filter( $groups );
		if( $collections ) {
			$groups['collections'] = $collections;
		}
		return $groups;
	}
}

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
	 * Find (by title) or create a gallery nested one level inside a given parent gallery - a box
	 * set's own gallery under its artist (FisheyeAlbum::createSubGallery()) or an artist's "Videos"
	 * gallery (load_video.php). Always created as a FisheyeMediaGallery, since the music layouts
	 * call this class's own methods. Deliberately cheap - just the gallery row, no scanning or
	 * importing of whatever will eventually live inside it.
	 *
	 * The parent is passed by content_id, not title, and both lookups match any gallery subclass
	 * (anything with a fisheye_gallery row), so a parent that is itself a FisheyeMediaGallery is found.
	 *
	 * @param string      $pTitle             the nested gallery's own title
	 * @param int         $pParentContentId   content_id of the existing gallery this one is linked into
	 * @param string|null $pGalleryPagination layout to set at creation (see the store() note below)
	 * @return array 'gallery_id'=>int, 'content_id'=>int, plus 'already'=>true if it already
	 *               existed, or 'error'=>string on failure
	 */
	public static function findOrCreateNestedGallery( string $pTitle, int $pParentContentId, ?string $pGalleryPagination = null ): array {
		global $gBitDb;

		// Scoped to an existing child of THIS parent, not a bare title match - "Compilation"/
		// "Studio"/"Live"/"Videos" are common enough names that two different artists genuinely
		// having their own is the normal case, not a collision to dedupe.
		$existingRow = $gBitDb->getRow(
			"SELECT lc.content_id, fg.gallery_id FROM `".BIT_DB_PREFIX."liberty_content` lc
			 INNER JOIN `".BIT_DB_PREFIX."fisheye_gallery` fg ON fg.content_id = lc.content_id
			 INNER JOIN `".BIT_DB_PREFIX."fisheye_gallery_image_map` map ON map.item_content_id = lc.content_id
			 WHERE lc.title = ? AND map.gallery_content_id = ?",
			[ $pTitle, $pParentContentId ]
		);
		if( $existingRow ) {
			// 'gallery_id' is fisheye_gallery's own PK (fg.gallery_id) - the one every gallery-URL
			// builder (getDisplayUrlFromHash() etc.) expects under this key, not content_id.
			return [ 'gallery_id' => $existingRow['gallery_id'], 'content_id' => $existingRow['content_id'], 'already' => true ];
		}

		$gallery = new FisheyeMediaGallery();
		$storeHash = [ 'title' => $pTitle ];
		if( $pGalleryPagination !== null ) {
			// Must be set in this same store() call, not a storePreference() bolted on afterward -
			// verifyGalleryData() (called from inside store()) only forces rows_per_page/cols_per_page
			// to the fixed grid size when gallery_pagination is already present in the param hash.
			$storeHash['gallery_pagination'] = $pGalleryPagination;
		}
		if( !$gallery->store( $storeHash ) ) {
			return [ 'error' => implode( '; ', $gallery->mErrors ) ];
		}
		$galleryContentId = $gallery->mContentId;

		$parentGallery = new FisheyeGallery( null, $pParentContentId );
		$parentGallery->load();
		$parentGallery->addItem( $galleryContentId );

		return [ 'gallery_id' => $gallery->mGalleryId, 'content_id' => $galleryContentId ];
	}

	/**
	 * Whether one candidate folder inside an artist/composer gallery's own folder is already loaded:
	 * an album registered under that title, or - for a box set folder (FisheyeAlbum::isBoxSetFolder())
	 * - its own nested gallery already existing inside this parent. A box set never becomes an album
	 * itself, so the album test alone kept offering it again; its discs are loaded from its own page.
	 * Shared by load_album.php's candidate list and hasUnloadedAlbumCandidates().
	 *
	 * @param string $pAbsoluteFolder  the candidate folder, trailing slash
	 * @param string $pTitle           its folder name (= album or box set gallery title)
	 * @param int    $pParentContentId content_id of the artist/composer gallery being loaded
	 * @return bool
	 */
	public static function isFolderLoaded( string $pAbsoluteFolder, string $pTitle, int $pParentContentId, string $pCategory = '' ): bool {
		global $gBitDb;
		if( FisheyeAlbum::isBoxSetFolder( $pAbsoluteFolder ) ) {
			// A collection is done once its gallery exists AND every album inside it is loaded -
			// a part-done one stays offered, one Process click from carrying on.
			$boxSetContentId = $gBitDb->getOne(
				"SELECT lc.content_id FROM `".BIT_DB_PREFIX."liberty_content` lc
				 INNER JOIN `".BIT_DB_PREFIX."fisheye_gallery` fg ON fg.content_id = lc.content_id
				 INNER JOIN `".BIT_DB_PREFIX."fisheye_gallery_image_map` map ON map.item_content_id = lc.content_id
				 WHERE lc.title = ? AND map.gallery_content_id = ?",
				[ $pTitle, $pParentContentId ]
			);
			if( !$boxSetContentId ) {
				return false;
			}
			$folder = rtrim( $pAbsoluteFolder, '/' ).'/';
			foreach( scandir( $folder ) ?: [] as $entry ) {
				if( !str_starts_with( $entry, '.' ) && is_dir( $folder.$entry ) && FisheyeAlbum::folderHasTracks( $folder.$entry.'/' )
					&& self::albumIdInGallery( $entry, (int)$boxSetContentId ) === null ) {
					return false;
				}
			}
			return true;
		}
		return self::albumIdInGallery( $pTitle, $pParentContentId, $pCategory ) !== null;
	}

	/**
	 * content_id of the album with this title already linked into the given gallery, or null.
	 * Scoped to that gallery because album titles aren't unique - "Time" under one artist says
	 * nothing about "Time" under another, or about a box set's own discs.
	 *
	 * The same title can also legitimately appear twice under one artist, once per strip (Jethro
	 * Tull's Studio and Remasters both have "Aqualung"), so the strip (the album's 'category' xref,
	 * '' for an album outside any strip) is part of its identity too.
	 *
	 * @param string $pTitle             album title (= its folder name)
	 * @param int    $pGalleryContentId  the gallery it should be linked into
	 * @param string $pCategory          the strip folder name, or '' for none
	 * @return int|null
	 */
	public static function albumIdInGallery( string $pTitle, int $pGalleryContentId, string $pCategory = '' ): ?int {
		global $gBitDb;
		$contentId = $gBitDb->getOne(
			"SELECT lc.content_id FROM `".BIT_DB_PREFIX."liberty_content` lc
			 INNER JOIN `".BIT_DB_PREFIX."fisheye_gallery_image_map` map ON map.item_content_id = lc.content_id
			 WHERE lc.content_type_guid = 'fisheyealbum' AND lc.title = ? AND map.gallery_content_id = ?
			   AND COALESCE((SELECT x.xkey_ext FROM `".BIT_DB_PREFIX."liberty_xref` x
			                 WHERE x.content_id = lc.content_id AND x.item = 'category' AND x.end_date IS NULL), '') = ?",
			[ $pTitle, $pGalleryContentId, $pCategory ]
		);
		return $contentId ? (int)$contentId : null;
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
			if( is_dir( $musicDir.$parentTitle.'/'.$entry.'/'.$galleryTitle.'/' ) && FisheyeAlbum::isGroupFolder( $musicDir.$parentTitle.'/'.$entry.'/' ) ) {
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

		$checkFolder = function( string $pFolder, string $pTitle, string $pCategory ): bool {
			if( str_starts_with( basename( $pFolder ), '.' ) || ( !FisheyeAlbum::folderHasTracks( $pFolder ) && !FisheyeAlbum::isBoxSetFolder( $pFolder ) ) ) {
				return false;
			}
			return !self::isFolderLoaded( $pFolder, $pTitle, (int)$this->mContentId, $pCategory );
		};
		foreach( scandir( $artistDir ) ?: [] as $entry ) {
			if( str_starts_with( $entry, '.' ) || !is_dir( $artistDir.$entry ) ) {
				continue;
			}
			if( FisheyeAlbum::isGroupFolder( $artistDir.$entry.'/' ) ) {
				foreach( scandir( $artistDir.$entry.'/' ) ?: [] as $categoryEntry ) {
					if( str_starts_with( $categoryEntry, '.' ) || !is_dir( $artistDir.$entry.'/'.$categoryEntry ) ) {
						continue;
					}
					if( $checkFolder( $artistDir.$entry.'/'.$categoryEntry.'/', $categoryEntry, $entry ) ) {
						return true;
					}
				}
				continue;
			}
			if( $checkFolder( $artistDir.$entry.'/', $entry, '' ) ) {
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
	 * An artist/composer page (music_grid, anything but the top-level "Music" pool - same split as
	 * fisheye_music_grid_inc.tpl) shows every item in strips, never a grid page - so load them all
	 * up front rather than the 4*8 first page, which silently dropped everything past the 32nd.
	 *
	 * @param array $pListHash
	 */
	public function prepDisplayList( array &$pListHash ): void {
		if( $this->getLayout() === FISHEYE_PAGINATION_MUSIC_GRID && $this->getTitle() !== 'Music' ) {
			$pListHash['page'] = -1;
			$pListHash['offset'] = 0;
			$pListHash['max_records'] = -1;
		}
	}

	/**
	 * Groups this gallery's own items into the artist page's strips (fisheye_music_grid_inc.tpl),
	 * mirroring the artist folder on disk: everything sitting directly in it first, as an unlabelled
	 * strip (key ''); then one strip per group folder (FisheyeAlbum::isGroupFolder()), titled with
	 * the folder's own name - familiar names (Studio, Live, Compilation...) in
	 * FISHEYEALBUM_CATEGORY_FOLDER_NAMES order, any others (Baroque, Modern...) in natural name
	 * order after them; then the Videos subgallery's own videos last. An album's group comes from
	 * its own 'category' xref (the folder name it was loaded from - one bulk query); a nested
	 * gallery (a box set) has none, so its group is read off its folder path instead. Loads every
	 * item in one call since strips flow down the page rather than paging.
	 *
	 * @return array<string, LibertyContent[]> keyed by strip title ('' = unlabelled, then group
	 *         folder names, then 'Videos'), empty strips dropped
	 */
	public function getCategorizedItems(): array {
		// loadImages() takes its param by reference - can't pass the array literal directly. A
		// no-op when the gallery page already loaded every item (prepDisplayList() above).
		$listHash = [ 'page' => -1, 'offset' => 0, 'max_records' => -1 ];
		$this->loadImages( $listHash );

		$ungrouped = [];
		$groups = [];      // lower-cased group name => items
		$groupTitles = []; // lower-cased group name => folder name as written
		$videos = [];
		$addToGroup = function( string $pGroup, $pContentId, $pItem ) use ( &$groups, &$groupTitles ) {
			$key = strtolower( $pGroup );
			$groupTitles[$key] = $groupTitles[$key] ?? $pGroup;
			$groups[$key][$pContentId] = $pItem;
		};

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
					"SELECT content_id, xkey_ext FROM `".BIT_DB_PREFIX."liberty_xref` WHERE item = 'category' AND end_date IS NULL AND content_id IN ( $placeholders )",
					$albumContentIds
				);
				foreach( $rows as $row ) {
					if( trim( (string)$row['xkey_ext'] ) !== '' ) {
						$categoryMap[$row['content_id']] = trim( $row['xkey_ext'] );
					}
				}
			}

			foreach( $this->mItems as $contentId => $item ) {
				// Any gallery object, not just content_type_guid 'fisheyegallery' - nested galleries
				// are created as FisheyeMediaGallery (findOrCreateNestedGallery() above).
				if( $item instanceof FisheyeGallery ) {
					if( $item->getTitle() === FISHEYEMEDIA_VIDEOS_GALLERY_TITLE ) {
						// load_video.php's own Videos gallery - its videos are shown directly in the
						// last strip rather than as one more tile to click into.
						$videosHash = [ 'page' => -1, 'offset' => 0, 'max_records' => -1 ];
						$item->loadImages( $videosHash );
						$videos += (array)$item->mItems;
						continue;
					}
					// A box set inside a group folder (Music/<artist>/<group>/<box set>/).
					$folder = self::resolveMusicFolder( $item );
					$segments = $folder ? explode( '/', trim( $folder, '/' ) ) : [];
					if( count( $segments ) >= 4 ) {
						$addToGroup( $segments[count( $segments ) - 2], $contentId, $item );
					} else {
						$ungrouped[$contentId] = $item;
					}
					continue;
				}
				if( isset( $categoryMap[$contentId] ) ) {
					$addToGroup( $categoryMap[$contentId], $contentId, $item );
				} else {
					$ungrouped[$contentId] = $item;
				}
			}
		}

		// Familiar names first in their usual order, then the rest by natural name order.
		$order = array_flip( FISHEYEALBUM_CATEGORY_FOLDER_NAMES );
		$keys = array_keys( $groups );
		usort( $keys, function( $a, $b ) use ( $order ) {
			$oa = $order[$a] ?? PHP_INT_MAX;
			$ob = $order[$b] ?? PHP_INT_MAX;
			return $oa !== $ob ? $oa <=> $ob : strnatcasecmp( $a, $b );
		} );

		$ret = [];
		if( $ungrouped ) {
			$ret[''] = $ungrouped;
		}
		foreach( $keys as $key ) {
			$ret[$groupTitles[$key]] = $groups[$key];
		}
		if( $videos ) {
			$ret[FISHEYEMEDIA_VIDEOS_GALLERY_TITLE] = $videos;
		}
		return $ret;
	}
}

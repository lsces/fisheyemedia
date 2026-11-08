<?php
/**
 * An author's Extras: the loose files of an author (papers, adaptations, fan compilations - material that is not one of the books) kept in
 * Library/<author>/Extras/ and shown as one gallery, "Extras", under the author's gallery. The files are 'extra' rows holding only a path under the
 * Library root, listed on the Extras strip and served from where they are by view_season_file.php - never copied into storage, so a file lives once
 * (the tutorial files work the same way, see FisheyeTutorialFilesTrait). Made and loaded from the author workbench.
 *
 * The files' text is not put into the search index (a file referenced in place passes through no mime plugin) - a known gap, see the Library notes.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

// liberty_content_types.content_type_guid is VARCHAR(16): 'fisheyeextras' is 13.
define( 'FISHEYEEXTRASGALLERY_CONTENT_TYPE_GUID', 'fisheyeextras' );

class FisheyeExtrasGallery extends FisheyeMediaGallery {
	use FisheyeTutorialFilesTrait;

	/** The folder under an author's Library folder, and the title of the gallery. */
	public const FOLDER = 'Extras';

	public function __construct( $pGalleryId = null, $pContentId = null ) {
		parent::__construct( $pGalleryId, $pContentId );
		$this->mContentTypeGuid = FISHEYEEXTRASGALLERY_CONTENT_TYPE_GUID;
		$this->registerContentType( FISHEYEEXTRASGALLERY_CONTENT_TYPE_GUID, [
			'content_type_guid' => FISHEYEEXTRASGALLERY_CONTENT_TYPE_GUID,
			'content_name'      => 'Extras Gallery',
			'handler_class'     => 'FisheyeExtrasGallery',
			'handler_package'   => 'fisheyemedia',
			'handler_file'      => 'FisheyeExtrasGallery.php',
			'maintainer_url'    => 'https://www.bitweaver.org',
		] );
	}

	/** Thumbnails of the files (the drawn first page of a PDF) are kept in this gallery's own storage branch, as for a book or a season - a gallery has no storage root of its own. */
	public function getExtraImagePath( string $pRelativePath ): string {
		return STORAGE_PKG_PATH.\Bitweaver\Liberty\liberty_mime_get_storage_branch( [ 'attachment_id' => $this->mContentId ] ).$pRelativePath;
	}

	/** The author's Extras folder: [ 'dir' => absolute with closing slash, 'relative' => '<author folder>/Extras' ], or null when there is none. */
	public static function folderFor( ?string $pAuthorFolder ): ?array {
		$root = FisheyeBookFiles::libraryRoot();
		if( $root === '' || $pAuthorFolder === null || $pAuthorFolder === '' ) {
			return null;
		}
		$relative = $pAuthorFolder.'/'.self::FOLDER;
		return is_dir( $root.$relative ) ? [ 'dir' => $root.$relative.'/', 'relative' => $relative ] : null;
	}

	/** The files in an Extras folder, as scanExtraFilesIn() gives them (rel under the folder, name, ext, bytes). */
	public static function scanFiles( string $pDir ): array {
		return FisheyeSection::scanExtraFilesIn( $pDir );
	}

	/** The content id of the author's Extras gallery, or 0. */
	public static function findFor( int $pAuthorContentId ): int {
		global $gBitDb;
		return (int)$gBitDb->getOne(
			"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."liberty_content` lc
			 INNER JOIN `".BIT_DB_PREFIX."fisheye_gallery_image_map` map ON map.`item_content_id` = lc.`content_id`
			 WHERE lc.`content_type_guid` = ? AND map.`gallery_content_id` = ?",
			[ FISHEYEEXTRASGALLERY_CONTENT_TYPE_GUID, $pAuthorContentId ] );
	}

	/** How many 'extra' rows the gallery holds now. */
	public static function rowCount( int $pContentId ): int {
		global $gBitDb;
		return $pContentId ? (int)$gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'extra' AND `end_date` IS NULL", [ $pContentId ] ) : 0;
	}

	/**
	 * Make the Extras gallery under an author (or find it) and load the folder's files into it as 'extra' rows.
	 *
	 * @return array{galleryId:int,files:int,errors:list<string>}
	 */
	public static function makeAndLoad( int $pAuthorContentId, ?string $pAuthorFolder ): array {
		$out = [ 'galleryId' => 0, 'files' => 0, 'errors' => [] ];
		$folder = self::folderFor( $pAuthorFolder );
		if( !$folder ) {
			$out['errors'][] = 'There is no Extras folder under this author in the Library.';
			return $out;
		}
		if( !self::extraItemDefinedFor( FISHEYEEXTRASGALLERY_CONTENT_TYPE_GUID ) ) {
			$out['errors'][] = 'The media scheme has no "extra" item for Extras galleries yet: apply it from the Local Scheme page.';
			return $out;
		}
		$made = FisheyeMediaGallery::findOrCreateNestedGallery( self::FOLDER, $pAuthorContentId, FISHEYE_PAGINATION_EXTRAS_LIST, self::class );
		if( !empty( $made['error'] ) ) {
			$out['errors'][] = (string)$made['error'];
			return $out;
		}
		$out['galleryId'] = (int)$made['gallery_id'];
		$gallery = new self( null, (int)$made['content_id'] );
		$gallery->load();
		// A gallery made before the list layout existed (or edited since) is put back on it, so its page shows the files.
		if( $gallery->getPreference( 'gallery_pagination' ) !== FISHEYE_PAGINATION_EXTRAS_LIST ) {
			$gallery->storePreference( 'gallery_pagination', FISHEYE_PAGINATION_EXTRAS_LIST );
		}
		$stored = $gallery->storeExtraFileRows( $folder['relative'], self::scanFiles( $folder['dir'] ) );
		$out['files'] = $stored['files'];
		$out['errors'] = array_merge( $out['errors'], $stored['errors'] );
		return $out;
	}
}

<?php
/**
 * Docs workbench: the files in the Documentation folder, one topic folder at a time, loaded in place as fisheye images (the mimeext* mime
 * plugins (one per file type: mimeextpdf and mimeextepub) keeps the file where it is and puts a link to it in storage/), filed in a gallery tree that mirrors the folders under the existing
 * top-level "Documentation" gallery. The text of a PDF goes into liberty_content_data for search. Each step is recomputed from the database and
 * the disk, so it can be repeated; a file counts as loaded when an attachment holds its path (the extdoc_path preference).
 *
 * Smaller documents can instead be uploaded into the gallery as zips (a stored copy), so this is for the big ones. The root is the Documentation
 * Storage Path setting (kernel_config mimeext_root_documentation); the same plugin takes a 'library' root for the Library's extras.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeGallery;
use Bitweaver\Fisheye\FisheyeImage;

class FisheyeDocsWorkbench {
	/** Files loaded per press (each PDF is read twice: text and first-page thumbnail). */
	public const BATCH = 10;

	/** Title of the existing top-level gallery the topic galleries go under. */
	public const TOP_TITLE = 'Documentation';

	/** The mime plugin for each file extension; a file of any other kind is listed but not loaded until its type has a plugin (mimeextmobi ...). */
	public const HANDLERS = [ 'pdf' => 'mimeextpdf', 'epub' => 'mimeextepub' ];

	/** The preference value is a VARCHAR(250). */
	private const SPEC_MAX = 250;

	/** The Documentation folder with its closing slash, or '' when there is none. */
	public static function root(): string {
		global $gBitSystem;
		$root = realpath( (string)$gBitSystem->getConfig( 'mimeext_root_documentation', '' ) );
		return $root && is_dir( $root ) ? $root.'/' : '';
	}

	/** content_id of the top-level Documentation gallery, or null when there is not one. */
	public static function topContentId(): ?int {
		global $gBitDb;
		$galleryId = FisheyeGallery::getTopGalleryId( self::TOP_TITLE );
		$id = $galleryId ? $gBitDb->getOne( "SELECT `content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery` WHERE `gallery_id` = ?", [ $galleryId ] ) : null;
		return $id ? (int)$id : null;
	}

	/** The topic folder names, alphabetically. */
	public static function topics(): array {
		$root = self::root();
		$names = $root === '' ? [] : array_values( array_filter( scandir( $root ) ?: [], fn( $d ) => $d[0] !== '.' && is_dir( $root.$d ) ) );
		sort( $names, SORT_STRING | SORT_FLAG_CASE );
		return $names;
	}

	/** Loaded files: "documentation|<path>" => content_id. */
	public static function loaded(): array {
		global $gBitDb;
		$rows = $gBitDb->getAll(
			"SELECT p.`pref_value`, a.`content_id` FROM `".BIT_DB_PREFIX."liberty_attachment_prefs` p
			 JOIN `".BIT_DB_PREFIX."liberty_attachments` a ON a.`attachment_id` = p.`attachment_id`
			 WHERE p.`pref_name` = 'extdoc_path' AND a.`attachment_plugin_guid` LIKE 'mimeext%'" ) ?: [];
		return array_column( $rows, 'content_id', 'pref_value' );
	}

	/** The regular files under a topic folder as paths relative to the root, sorted. */
	public static function filesIn( string $pTopic ): array {
		$root = self::root();
		$dir = $root.$pTopic;
		if( $root === '' || $pTopic === '' || str_contains( $pTopic, '..' ) || !is_dir( $dir ) ) {
			return [];
		}
		$files = [];
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach( $it as $file ) {
			if( $file->isFile() && $file->getFilename()[0] !== '.' ) {
				$files[] = substr( $file->getPathname(), strlen( $root ) );
			}
		}
		sort( $files, SORT_STRING | SORT_FLAG_CASE );
		return $files;
	}

	/**
	 * Where a topic stands: its files with the content_id of each that is loaded, and the counts.
	 *
	 * @return array{name:string,error:?string,files:array,total:int,loaded:int,todo:int,noHandler:int,tooLong:array,done:bool}
	 */
	public static function status( string $pTopic ): array {
		$st = [ 'name' => $pTopic, 'error' => null, 'files' => [], 'total' => 0, 'loaded' => 0, 'todo' => 0, 'noHandler' => 0, 'tooLong' => [], 'done' => false ];
		if( self::root() === '' ) {
			$st['error'] = 'No Documentation folder was found: set the Documentation Storage Path in Media Library Settings.';
			return $st;
		}
		$loaded = self::loaded();
		foreach( self::filesIn( $pTopic ) as $path ) {
			$cid = $loaded['documentation|'.$path] ?? null;
			$handler = self::HANDLERS[strtolower( pathinfo( $path, PATHINFO_EXTENSION ) )] ?? null;
			$st['files'][] = [ 'path' => $path, 'name' => basename( $path ), 'contentId' => $cid ? (int)$cid : null, 'handler' => $handler, 'size' => (int)@filesize( self::root().$path ) ];
			if( $cid ) {
				$st['loaded']++;
			} elseif( !$handler ) {
				$st['noHandler']++;
			} elseif( strlen( 'documentation|'.$path ) > self::SPEC_MAX ) {
				$st['tooLong'][] = $path;
			} else {
				$st['todo']++;
			}
		}
		$st['total'] = count( $st['files'] );
		$st['done'] = $st['total'] > 0 && $st['todo'] === 0;
		return $st;
	}

	/** Topic names that have a gallery under Documentation or a folder in the upload sources path, whether or not Documentation still holds files for them. */
	private static function topicsWithUploads(): array {
		global $gBitDb;
		$names = [];
		if( $top = self::topContentId() ) {
			foreach( $gBitDb->getAll( "SELECT lc.`title` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id` WHERE m.`gallery_content_id` = ? AND lc.`content_type_guid` = 'fisheyegallery'", [ $top ] ) ?: [] as $row ) {
				$names[] = (string)$row['title'];
			}
		}
		if( $zips = self::zipsRoot() ) {
			foreach( scandir( $zips ) ?: [] as $dir ) {
				if( $dir[0] !== '.' && is_dir( $zips.$dir ) ) {
					$names[] = preg_replace( '/ part \d+$/', '', $dir );
				}
			}
		}
		return array_values( array_unique( $names ) );
	}

	/** Every topic with its counts, for the front page. */
	public static function overview(): array {
		$all = [];
		foreach( self::topics() as $topic ) {
			$all[$topic] = self::status( $topic );
		}
		// topics that exist only as uploads (their Documentation folder is empty or gone) still belong on the page
		foreach( self::topicsWithUploads() as $topic ) {
			$all[$topic] = $all[$topic] ?? self::status( $topic );
		}
		ksort( $all, SORT_STRING | SORT_FLAG_CASE );
		foreach( $all as $topic => &$row ) {
			$up = self::uploads( $topic );
			$row['uploaded'] = count( $up['uploaded'] );
			$row['notInGallery'] = count( $up['missing'] );
		}
		unset( $row );
		return $all;
	}

	/** URL of the gallery with this content_id, or null. */
	public static function galleryUrl( ?int $pContentId ): ?string {
		$id = $pContentId ? self::galleryIdOf( $pContentId ) : null;
		return $id ? FISHEYE_PKG_URL.'view.php?gallery_id='.$id : null;
	}

	/** content_id of a topic's gallery under the Documentation gallery, or null when it has not been made. */
	public static function topicContentId( string $pTopic ): ?int {
		global $gBitDb;
		$top = self::topContentId();
		if( !$top ) {
			return null;
		}
		$id = $gBitDb->getOne(
			"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
			 WHERE m.`gallery_content_id` = ? AND lc.`title` = ? AND lc.`content_type_guid` = 'fisheyegallery'", [ $top, $pTopic ] );
		return $id ? (int)$id : null;
	}

	/** The upload sources folder (the zips that are uploaded into the gallery are made from it; setting fisheyemedia_docs_zips_path) with its closing slash, '' when there is none. */
	public static function zipsRoot(): string {
		global $gBitSystem;
		$dir = realpath( (string)$gBitSystem->getConfig( 'fisheyemedia_docs_zips_path', '' ) );
		return $dir && is_dir( $dir ) ? $dir.'/' : '';
	}

	/**
	 * What the topic gallery holds that did not come from the Documentation folder - the files uploaded as zips (stored copies) - and which files of
	 * the topic's folders in the upload sources path (the topic's own and its "part N" folders) are not in the gallery at all: matched by file name against
	 * the titles, ignoring case, the extension and punctuation.
	 *
	 * @return array{uploaded:array,missing:array}
	 */
	public static function uploads( string $pTopic ): array {
		global $gBitDb;
		$out = [ 'uploaded' => [], 'missing' => [] ];
		$topicContentId = self::topicContentId( $pTopic );
		$titles = [];
		if( $topicContentId ) {
			$rows = $gBitDb->getAll(
				"SELECT lc.`content_id`, lc.`title`, a.`attachment_plugin_guid` AS guid, lf.`mime_type`, lf.`file_size` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
				 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
				 LEFT JOIN `".BIT_DB_PREFIX."liberty_attachments` a ON a.`content_id` = lc.`content_id`
				 LEFT JOIN `".BIT_DB_PREFIX."liberty_files` lf ON lf.`file_id` = a.`foreign_id`
				 WHERE m.`gallery_content_id` = ? AND lc.`content_type_guid` <> 'fisheyegallery' ORDER BY lc.`title`", [ $topicContentId ] ) ?: [];
			foreach( $rows as $row ) {
				$titles[self::normalise( (string)$row['title'] )] = true;
				if( !str_starts_with( (string)$row['guid'], 'mimeext' ) ) {
					$out['uploaded'][] = [ 'contentId' => (int)$row['content_id'], 'title' => (string)$row['title'], 'mime' => (string)$row['mime_type'], 'size' => (int)$row['file_size'] ];
				}
			}
		}
		$zips = self::zipsRoot();
		if( $zips !== '' ) {
			foreach( scandir( $zips ) ?: [] as $dir ) {
				if( $dir[0] === '.' || !is_dir( $zips.$dir ) || ( $dir !== $pTopic && !preg_match( '/^'.preg_quote( $pTopic, '/' ).' part \d+$/', $dir ) ) ) {
					continue;
				}
				$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $zips.$dir, \FilesystemIterator::SKIP_DOTS ) );
				foreach( $it as $file ) {
					if( $file->isFile() && $file->getFilename()[0] !== '.' && !isset( $titles[self::normalise( pathinfo( $file->getFilename(), PATHINFO_FILENAME ) )] ) ) {
						$out['missing'][] = [ 'path' => $dir.'/'.substr( $file->getPathname(), strlen( $zips.$dir ) + 1 ), 'size' => (int)$file->getSize(), 'kind' => strtolower( $file->getExtension() ) ];
					}
				}
			}
			usort( $out['missing'], fn( $a, $b ) => strcasecmp( $a['path'], $b['path'] ) );
		}
		return $out;
	}

	/** A name for matching a file against a title: lower case, letters and digits only. */
	private static function normalise( string $pName ): string {
		return preg_replace( '/[^a-z0-9]+/', '', strtolower( $pName ) ) ?? '';
	}

	/**
	 * Load the next BATCH unloaded files of a topic: each is filed in the gallery for its folder (made under Documentation when missing),
	 * stored in place, and added to that gallery.
	 *
	 * @return array{loaded:int,errors:array}
	 */
	public static function loadTopic( string $pTopic ): array {
		global $gLibertySystem;
		$result = [ 'loaded' => 0, 'errors' => [] ];
		$top = self::topContentId();
		if( !$top ) {
			$result['errors'][] = 'There is no top-level '.self::TOP_TITLE.' gallery.';
			return $result;
		}
				$st = self::status( $pTopic );
		$todo = array_filter( $st['files'], fn( $f ) => !$f['contentId'] && $f['handler'] && !in_array( $f['path'], $st['tooLong'], true ) );
		foreach( array_slice( $todo, 0, self::BATCH ) as $file ) {
			$galleryContentId = self::galleryFor( dirname( $file['path'] ), $top );
			$galleryId = $galleryContentId ? self::galleryIdOf( $galleryContentId ) : null;
			if( !$galleryId ) {
				$result['errors'][] = $file['path'].': the gallery could not be made.';
				continue;
			}
			self::activatePlugin( $file['handler'] );
			$image = new FisheyeImage();
			$hash = [
				'title' => pathinfo( $file['name'], PATHINFO_FILENAME ),
				'mimeplugin' => [ $file['handler'] => [ 'root' => 'documentation', 'path' => $file['path'] ] ],
				'process_storage' => STORAGE_IMAGE,
			];
			if( $image->store( $hash ) && $image->mContentId ) {
				$image->addToGalleries( [ $galleryId ] );
				$result['loaded']++;
			} else {
				$result['errors'][] = $file['path'].': '.implode( '; ', array_map( 'strval', array_values( $image->mErrors ) ) );
			}
		}
		return $result;
	}

	/** content_id of the gallery for a folder path under Documentation (Architecture, Architecture/Sub), each level made when missing. */
	private static function galleryFor( string $pDir, int $pTop ): ?int {
		$parent = $pTop;
		foreach( explode( '/', $pDir ) as $part ) {
			if( $part === '' || $part === '.' ) {
				continue;
			}
			$parent = self::findOrMakeGallery( $part, $parent );
			if( !$parent ) {
				return null;
			}
		}
		return $parent;
	}

	private static function galleryIdOf( int $pContentId ): ?int {
		global $gBitDb;
		$id = $gBitDb->getOne( "SELECT `gallery_id` FROM `".BIT_DB_PREFIX."fisheye_gallery` WHERE `content_id` = ?", [ $pContentId ] );
		return $id ? (int)$id : null;
	}

	private static function findOrMakeGallery( string $pTitle, int $pParentContentId ): ?int {
		global $gBitDb;
		$found = $gBitDb->getOne(
			"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
			 WHERE m.`gallery_content_id` = ? AND lc.`title` = ? AND lc.`content_type_guid` = 'fisheyegallery'", [ $pParentContentId, $pTitle ] );
		if( $found ) {
			return (int)$found;
		}
		$gallery = new FisheyeGallery();
		$storeHash = [ 'title' => $pTitle, 'gallery_pagination' => FISHEYE_PAGINATION_DOCS_GRID ];   // store() takes it by reference
		if( !$gallery->store( $storeHash ) ) {
			return null;
		}
		$gallery->storePreference( 'gallery_pagination', FISHEYE_PAGINATION_DOCS_GRID );
		$gallery->addToGalleries( [ self::galleryIdOf( $pParentContentId ) ] );
		return (int)$gallery->mContentId;
	}

	/** The plugin is found by the plugin scan on a page that loads no upload first, and a new plugin is switched off until its status is stored. */
	private static function activatePlugin( string $pGuid ): void {
		global $gLibertySystem, $gBitSystem;
		$gLibertySystem->scanAllPlugins( null, "mime\." );
		if( !$gLibertySystem->isPluginActive( $pGuid ) && isset( $gLibertySystem->mPlugins[$pGuid] ) ) {
			$gBitSystem->storeConfig( 'liberty_plugin_status_'.$pGuid, 'y', LIBERTY_PKG_NAME );
			$gLibertySystem->mPlugins[$pGuid]['is_active'] = 'y';
		}
	}
}

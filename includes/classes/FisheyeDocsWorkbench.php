<?php
/**
 * Docs workbench: the files in the Documentation folder, one topic folder at a time (and, as a second kind, the Magazines folder, one title at a time), loaded in place as fisheye images (the mimeext* mime
 * plugins (one per file type: mimeextpdf and mimeextepub) keeps the file where it is and puts a link to it in storage/), filed in a gallery tree that mirrors the folders under the existing
 * top-level "Documentation" gallery. The text of a PDF goes into liberty_content_data for search. Each step is recomputed from the database and
 * the disk, so it can be repeated; a file counts as loaded when an attachment holds its path (the extdoc_path preference).
 *
 * Smaller documents can instead be uploaded into the gallery as archives (a stored copy), so this is for the big ones. The root is the Documentation
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

	/** Title of the existing top-level gallery the topic galleries go under (Documentation kind; see topTitle()). */
	public const TOP_TITLE = 'Documentation';

	/**
	 * The collections this workbench loads, each a source folder, a top-level gallery and a root key for the mimeext* plugins: Documentation (one folder
	 * per topic) and Magazines (one folder per title, then year or volume folders). 'setting' is the kernel_config entry holding the folder, 'folder'
	 * the name looked for under the External Disk Storage Path when the setting is blank.
	 */
	public const KINDS = [
		'documentation' => [ 'top' => 'Documentation', 'folder' => 'Documentation', 'noun' => 'topic' ],
		'magazines'     => [ 'top' => 'Magazines',     'folder' => 'Magazines',     'noun' => 'title' ],
	];

	/** Seconds a keep-going press works before it hands over to the next one (one file may overrun it; nginx allows 60). */
	public const KEEP_GOING_SECONDS = 40;

	/** The kind in use for this request: one of the KINDS keys. */
	private static string $sKind = 'documentation';

	public static function setKind( string $pKind ): void {
		self::$sKind = isset( self::KINDS[$pKind] ) ? $pKind : 'documentation';
	}

	public static function kind(): string {
		return self::$sKind;
	}

	public static function topTitle(): string {
		return self::KINDS[self::$sKind]['top'];
	}

	/** Word for one folder of the kind, for the pages ("topic" or "title"). */
	public static function noun(): string {
		return self::KINDS[self::$sKind]['noun'];
	}

	/** The mime plugin for each file extension; a file of any other kind is listed but not loaded until its type has a plugin (mimeextmobi ...). */
	public const HANDLERS = [ 'pdf' => 'mimeextpdf', 'epub' => 'mimeextepub' ];

	/** The preference value is a VARCHAR(250). */
	private const SPEC_MAX = 250;

	/** The folder of the kind in use, with its closing slash, or '' when there is none (see rootFor()). */
	public static function root(): string {
		return self::rootFor( self::$sKind );
	}

	/**
	 * The source folder for a root key with its closing slash, or '' when there is none: the Storage Path setting (kernel_config mimeext_root_<key>),
	 * else the kind's folder under the External Disk Storage Path (as the Library and Tutorials paths fall back). A key that is not a kind has only its setting.
	 */
	public static function rootFor( string $pKey ): string {
		global $gBitSystem;
		$set = trim( (string)$gBitSystem->getConfig( 'mimeext_root_'.$pKey, '' ) );
		if( $set === '' && isset( self::KINDS[$pKey] ) ) {
			$disk = function_exists( '\\Bitweaver\\Liberty\\mime_film_get_storage_root' ) ? \Bitweaver\Liberty\mime_film_get_storage_root() : '';
			$set = $disk !== '' ? $disk.self::KINDS[$pKey]['folder'].'/' : '';
		}
		$root = $set !== '' ? realpath( $set ) : false;
		return $root && is_dir( $root ) ? $root.'/' : '';
	}

	/** content_id of the top-level Documentation gallery, or null when there is not one. */
	public static function topContentId(): ?int {
		global $gBitDb;
		$galleryId = FisheyeGallery::getTopGalleryId( self::topTitle() );
		$id = $galleryId ? $gBitDb->getOne( "SELECT `content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery` WHERE `gallery_id` = ?", [ $galleryId ] ) : null;
		return $id ? (int)$id : null;
	}

	/** Make the kind's top-level gallery (Docs Grid layout, no parent) when there is none; returns its content_id, or null on failure. */
	public static function makeTop(): ?int {
		if( $existing = self::topContentId() ) {
			return $existing;
		}
		$gallery = new FisheyeGallery();
		$storeHash = [ 'title' => self::topTitle(), 'gallery_pagination' => FISHEYE_PAGINATION_DOCS_GRID ];   // store() takes it by reference
		if( !$gallery->store( $storeHash ) ) {
			return null;
		}
		$gallery->storePreference( 'gallery_pagination', FISHEYE_PAGINATION_DOCS_GRID );
		return (int)$gallery->mContentId;
	}

	/** The topic folder names, alphabetically. */
	public static function topics(): array {
		$root = self::root();
		$names = $root === '' ? [] : array_values( array_filter( scandir( $root ) ?: [], fn( $d ) => $d[0] !== '.' && is_dir( $root.$d ) ) );
		sort( $names, SORT_STRING | SORT_FLAG_CASE );
		return $names;
	}

	/** Loaded files: "<kind>|<path>" => content_id. */
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
		$st = [ 'name' => $pTopic, 'error' => null, 'files' => [], 'total' => 0, 'loaded' => 0, 'todo' => 0, 'noHandler' => 0, 'tooLong' => [], 'folders' => [], 'done' => false ];
		if( self::root() === '' ) {
			$st['error'] = 'No '.self::topTitle().' folder was found: set the '.self::topTitle().' Storage Path in Media Library Settings.';
			return $st;
		}
		$loaded = self::loaded();
		foreach( self::filesIn( $pTopic ) as $path ) {
			$cid = $loaded[self::$sKind.'|'.$path] ?? null;
			$handler = self::HANDLERS[strtolower( pathinfo( $path, PATHINFO_EXTENSION ) )] ?? null;
			$folder = strlen( dirname( $path ) ) > strlen( $pTopic ) ? substr( dirname( $path ), strlen( $pTopic ) + 1 ) : '(top level)';
			$st['folders'][$folder] = $st['folders'][$folder] ?? [ 'total' => 0, 'loaded' => 0 ];
			$st['folders'][$folder]['total']++;
			$cid && $st['folders'][$folder]['loaded']++;
			$st['files'][] = [ 'path' => $path, 'name' => basename( $path ), 'contentId' => $cid ? (int)$cid : null, 'handler' => $handler, 'size' => (int)@filesize( self::root().$path ) ];
			if( $cid ) {
				$st['loaded']++;
			} elseif( !$handler ) {
				$st['noHandler']++;
			} elseif( strlen( self::$sKind.'|'.$path ) > self::SPEC_MAX ) {
				$st['tooLong'][] = $path;
			} else {
				$st['todo']++;
			}
		}
		$st['total'] = count( $st['files'] );
		$st['done'] = $st['total'] > 0 && $st['todo'] === 0;
		return $st;
	}

	/** Topic names that have a gallery under Documentation, whether or not Documentation still holds files for them. */
	private static function topicsWithUploads(): array {
		global $gBitDb;
		$names = [];
		if( $top = self::topContentId() ) {
			foreach( $gBitDb->getAll( "SELECT lc.`title` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id` WHERE m.`gallery_content_id` = ? AND lc.`content_type_guid` = 'fisheyegallery'", [ $top ] ) ?: [] as $row ) {
				$names[] = (string)$row['title'];
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

	/**
	 * What the topic gallery holds that did not come from the Documentation folder - the files uploaded into it (stored copies).
	 *
	 * @return array{uploaded:array}
	 */
	public static function uploads( string $pTopic ): array {
		global $gBitDb;
		$out = [ 'uploaded' => [] ];
		$topicContentId = self::topicContentId( $pTopic );
		if( $topicContentId ) {
			$rows = $gBitDb->getAll(
				"SELECT lc.`content_id`, lc.`title`, a.`attachment_plugin_guid` AS guid, lf.`mime_type`, lf.`file_size` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
				 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
				 LEFT JOIN `".BIT_DB_PREFIX."liberty_attachments` a ON a.`content_id` = lc.`content_id`
				 LEFT JOIN `".BIT_DB_PREFIX."liberty_files` lf ON lf.`file_id` = a.`foreign_id`
				 WHERE m.`gallery_content_id` = ? AND lc.`content_type_guid` <> 'fisheyegallery' ORDER BY lc.`title`", [ $topicContentId ] ) ?: [];
			foreach( $rows as $row ) {
				if( !str_starts_with( (string)$row['guid'], 'mimeext' ) ) {
					$out['uploaded'][] = [ 'contentId' => (int)$row['content_id'], 'title' => (string)$row['title'], 'mime' => (string)$row['mime_type'], 'size' => (int)$row['file_size'] ];
				}
			}
		}
		return $out;
	}

	/**
	 * Load the next unloaded files of a topic: each is filed in the gallery for its folder (made under the kind's top gallery when missing),
	 * stored in place, and added to that gallery. A plain press loads BATCH files; with $pSeconds it keeps loading until that many seconds have
	 * gone (the file in hand is finished first), which is what the keep-going option uses.
	 *
	 * @return array{loaded:int,errors:array,seconds:int}
	 */
	public static function loadTopic( string $pTopic, int $pSeconds = 0 ): array {
		global $gLibertySystem;
		$started = microtime( true );
		if( $pSeconds > 0 ) {
			@set_time_limit( $pSeconds + 60 );
		}
		$result = [ 'loaded' => 0, 'errors' => [], 'seconds' => 0 ];
		$top = self::topContentId();
		if( !$top ) {
			$result['errors'][] = 'There is no top-level '.self::topTitle().' gallery.';
			$result['seconds'] = (int)round( microtime( true ) - $started );
		return $result;
		}
				$st = self::status( $pTopic );
		$todo = array_filter( $st['files'], fn( $f ) => !$f['contentId'] && $f['handler'] && !in_array( $f['path'], $st['tooLong'], true ) );
		foreach( $pSeconds > 0 ? $todo : array_slice( $todo, 0, self::BATCH ) as $file ) {
			if( $pSeconds > 0 && ( microtime( true ) - $started ) >= $pSeconds ) {
				break;
			}
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
				'mimeplugin' => [ $file['handler'] => [ 'root' => self::$sKind, 'path' => $file['path'] ] ],
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

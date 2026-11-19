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
	 * The collections this workbench loads, each a source folder, a top-level gallery and a root key for the mimeext* plugins - Documentation (one folder per
	 * topic), Magazines (one folder per title, then year or volume folders) and any folder registered as an archive (FisheyeArchives, from the archive scan
	 * page). 'folder' is the name looked for under the External Disk Storage Path when the kind's setting (mimeext_root_<kind>) is blank.
	 */
	public static function kinds(): array {
		return FisheyeArchives::all();
	}

	/** Seconds a keep-going press works before it hands over to the next one (one file may overrun it; nginx allows 60). */
	public const KEEP_GOING_SECONDS = 40;

	/** The kind in use for this request: one of the keys of kinds(). */
	private static string $sKind = 'documentation';

	public static function setKind( string $pKind ): void {
		self::$sKind = isset( self::kinds()[$pKind] ) ? $pKind : 'documentation';
	}

	public static function kind(): string {
		return self::$sKind;
	}

	public static function topTitle(): string {
		return self::kinds()[self::$sKind]['top'];
	}

	/** Word for one folder of the kind, for the pages ("topic" or "title"). */
	public static function noun(): string {
		return self::kinds()[self::$sKind]['noun'];
	}

	/** The mime plugin for each file extension; a file of any other kind is listed but not loaded until its type has a plugin (mimeextmobi ...). */
	public const HANDLERS = [ 'pdf' => 'mimeextpdf', 'epub' => 'mimeextepub' ];

	/** The pictures a registered archive loads in place (mimeextimage); the built-in kinds still list them without loading, as they always have. */
	public const IMAGE_HANDLERS = [ 'jpg' => 'mimeextimage', 'jpeg' => 'mimeextimage', 'png' => 'mimeextimage', 'gif' => 'mimeextimage', 'webp' => 'mimeextimage', 'bmp' => 'mimeextimage' ];

	/**
	 * Whether the kind in use still follows the rules the built-in kinds had before archives were registered: pictures listed but not loaded, a gallery for
	 * every folder, always a Docs Grid. Documentation follows the same rules as any registered archive now; Magazines keeps the old ones until it is moved to books.
	 */
	private static function oldRules(): bool {
		return self::$sKind === 'magazines';
	}

	/** The mime plugin that loads a file of the kind in use, or null when none does yet. */
	public static function handlerFor( string $pPath ): ?string {
		$ext = strtolower( pathinfo( $pPath, PATHINFO_EXTENSION ) );
		return self::HANDLERS[$ext] ?? ( self::oldRules() ? null : ( self::IMAGE_HANDLERS[$ext] ?? null ) );
	}

	/** The handlers whose files are Books (a PDF or epub, which can carry an ISBN); the pictures a registered archive loads stay plain Images. */
	public const DOCUMENT_HANDLERS = [ 'mimeextpdf', 'mimeextepub' ];

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
		if( $set === '' && isset( self::kinds()[$pKey] ) ) {
			$disk = function_exists( '\\Bitweaver\\Liberty\\mime_film_get_storage_root' ) ? \Bitweaver\Liberty\mime_film_get_storage_root() : '';
			$set = $disk !== '' ? $disk.self::kinds()[$pKey]['folder'].'/' : '';
		}
		if( $set === '' && $pKey === 'library' ) {
			$set = FisheyeBookFiles::libraryRoot();   // a book's own files are kept in place under the Library
		}
		$root = $set !== '' ? realpath( $set ) : false;
		return $root && is_dir( $root ) ? $root.'/' : '';
	}

	/** content_id of the top-level Documentation gallery, or null when there is not one. */
	public static function topContentId(): ?int {
		return FisheyeMediaBases::contentId( self::kinds()[self::$sKind]['role'] );
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
		FisheyeMediaBases::remember( self::kinds()[self::$sKind]['role'], (int)$gallery->mContentId );
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
		// the documents of a folder that can be one Book are loaded only by its Make Book (as rows of that Book), never by Load files
		$project = array_flip( array_merge( [], ...array_column( self::projectFolders( $pTopic ), 'files' ) ) );
		foreach( self::filesIn( $pTopic ) as $path ) {
			$cid = $loaded[self::$sKind.'|'.$path] ?? null;
			$handler = self::handlerFor( $path );
			$folder = strlen( dirname( $path ) ) > strlen( $pTopic ) ? substr( dirname( $path ), strlen( $pTopic ) + 1 ) : '(top level)';
			$st['folders'][$folder] = $st['folders'][$folder] ?? [ 'total' => 0, 'loaded' => 0 ];
			$st['folders'][$folder]['total']++;
			$cid && $st['folders'][$folder]['loaded']++;
			$st['files'][] = [ 'path' => $path, 'name' => basename( $path ), 'contentId' => $cid ? (int)$cid : null, 'handler' => $handler, 'size' => (int)@filesize( self::root().$path ), 'project' => isset( $project[$path] ) ];
			if( $cid ) {
				$st['loaded']++;
			} elseif( isset( $project[$path] ) ) {
				$st['awaitingBook'] = ( $st['awaitingBook'] ?? 0 ) + 1;
			} elseif( !$handler ) {
				$st['noHandler']++;
			} elseif( strlen( self::$sKind.'|'.$path ) > self::SPEC_MAX ) {
				$st['tooLong'][] = $path;
			} else {
				$st['todo']++;
			}
		}
		$st['total'] = count( $st['files'] );
		$st['done'] = $st['total'] > 0 && $st['todo'] === 0 && empty( $st['awaitingBook'] );
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
		$todo = array_filter( $st['files'], fn( $f ) => !$f['contentId'] && !$f['project'] && $f['handler'] && !in_array( $f['path'], $st['tooLong'], true ) );
		foreach( $pSeconds > 0 ? $todo : array_slice( $todo, 0, self::BATCH ) as $file ) {
			if( $pSeconds > 0 && ( microtime( true ) - $started ) >= $pSeconds ) {
				break;
			}
			$galleryContentId = self::galleryFor( self::galleryDirFor( $file['path'] ), $top );
			$galleryId = $galleryContentId ? self::galleryIdOf( $galleryContentId ) : null;
			if( !$galleryId ) {
				$result['errors'][] = $file['path'].': the gallery could not be made.';
				continue;
			}
			self::activatePlugin( $file['handler'] );
			// a magazine file is a Magazine Issue, any other PDF or epub a Book (which holds the file itself, as an attachment kept in place), a picture a plain Image
			$image = self::$sKind === 'magazines' ? new FisheyeIssue() : ( in_array( $file['handler'], self::DOCUMENT_HANDLERS, true ) ? new FisheyeBook() : new FisheyeImage() );
			$hash = [
				'title' => pathinfo( $file['name'], PATHINFO_FILENAME ),
				'mimeplugin' => [ $file['handler'] => [ 'root' => self::$sKind, 'path' => $file['path'] ] ],
				'process_storage' => STORAGE_IMAGE,
			];
			// an item filed in a restricted gallery is restricted the same way (as fisheye's own uploads do): restricting a gallery hides only its page
			if( ( $inherit = FisheyeAccess::inheritedFor( $galleryContentId ) ) !== null ) {
				$hash['protector']['role_id'] = $inherit;
			}
			if( $image->store( $hash ) && $image->mContentId ) {
				$image->addToGalleries( [ $galleryId ] );
				$result['loaded']++;
			} else {
				$result['errors'][] = $file['path'].': '.implode( '; ', array_map( 'strval', array_values( $image->mErrors ) ) );
			}
		}
		return $result;
	}

	/**
	 * Magazine issues loaded before they had their own type are plain Images: how many, and (convertLoadedIssues) change them to Magazine Issues, keeping
	 * every row, thumbnail and file reference. Done in the application (not by hand in the database), in one transaction, repeatable.
	 */
	public static function plainImageIssues(): array {
		global $gBitDb;
		$rows = $gBitDb->getCol(
			"SELECT DISTINCT a.`content_id` FROM `".BIT_DB_PREFIX."liberty_attachment_prefs` p
			 JOIN `".BIT_DB_PREFIX."liberty_attachments` a ON a.`attachment_id` = p.`attachment_id`
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = a.`content_id`
			 WHERE p.`pref_name` = 'extdoc_path' AND p.`pref_value` LIKE 'magazines|%' AND lc.`content_type_guid` = 'fisheyeimage'" ) ?: [];
		return array_map( 'intval', $rows );
	}

	/** @return array{converted:int,errors:list<string>} */
	public static function convertLoadedIssues(): array {
		global $gBitDb;
		$ret = [ 'converted' => 0, 'errors' => [] ];
		$ids = self::plainImageIssues();
		if( !$ids ) {
			return $ret;
		}
		$gBitDb->StartTrans();
		foreach( $ids as $cid ) {
			$gBitDb->query( "UPDATE `".BIT_DB_PREFIX."liberty_content` SET `content_type_guid` = 'fisheyeissue' WHERE `content_id` = ? AND `content_type_guid` = 'fisheyeimage'", [ $cid ] );
			$ret['converted']++;
		}
		$gBitDb->CompleteTrans();
		// getLibertyObject() looks a content item's type up through a query cached for an hour: without this flush a converted issue keeps coming back as an Image.
		if( !empty( $gBitDb->mDb ) && method_exists( $gBitDb->mDb, 'CacheFlush' ) ) {
			$gBitDb->mDb->CacheFlush();
		}
		return $ret;
	}

	/**
	 * PDFs and epubs loaded as plain Images: the content_ids of those in the archive in use (not Magazines, which have issues). convertLoadedBooks() changes them
	 * to Books, the way convertLoadedIssues() does for issues.
	 *
	 * @return list<int>
	 */
	public static function plainImageBooks(): array {
		global $gBitDb;
		if( self::$sKind === 'magazines' ) {
			return [];
		}
		$rows = $gBitDb->getCol(
			"SELECT DISTINCT a.`content_id` FROM `".BIT_DB_PREFIX."liberty_attachment_prefs` p
			 JOIN `".BIT_DB_PREFIX."liberty_attachments` a ON a.`attachment_id` = p.`attachment_id`
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = a.`content_id`
			 WHERE p.`pref_name` = 'extdoc_path' AND p.`pref_value` LIKE ? AND a.`attachment_plugin_guid` IN ( 'mimeextpdf', 'mimeextepub' )
			   AND lc.`content_type_guid` = 'fisheyeimage'", [ self::$sKind.'|%' ] ) ?: [];
		// and the PDFs and epubs uploaded into the archive's galleries (a zip or the upload page store the file with the item, mimepdf/mimeepub): the same Images
		$galleries = self::galleryTreeContentIds();
		if( $galleries ) {
			$marks = implode( ',', array_fill( 0, count( $galleries ), '?' ) );
			$rows = array_merge( $rows, $gBitDb->getCol(
				"SELECT DISTINCT m.`item_content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
				 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
				 JOIN `".BIT_DB_PREFIX."liberty_attachments` a ON a.`content_id` = lc.`content_id`
				 WHERE m.`gallery_content_id` IN ( $marks ) AND a.`attachment_plugin_guid` IN ( 'mimepdf', 'mimeepub' ) AND lc.`content_type_guid` = 'fisheyeimage'", $galleries ) ?: [] );
		}
		return array_values( array_unique( array_map( 'intval', $rows ) ) );
	}

	/**
	 * The content_id of the kind's top gallery and of every gallery below it.
	 *
	 * @return list<int>
	 */
	public static function galleryTreeContentIds(): array {
		global $gBitDb;
		$top = self::topContentId();
		if( !$top ) {
			return [];
		}
		$all = [ $top ];
		$level = [ $top ];
		while( $level ) {
			$marks = implode( ',', array_fill( 0, count( $level ), '?' ) );
			$level = array_diff( array_map( 'intval', $gBitDb->getCol(
				"SELECT m.`item_content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
				 WHERE m.`gallery_content_id` IN ( $marks ) AND lc.`content_type_guid` = 'fisheyegallery'", $level ) ?: [] ), $all );
			$all = array_merge( $all, $level );
		}
		return $all;
	}

	/** Make these Images Books (only the content type changes: every row, thumbnail and file reference is kept). Returns how many were Images. */
	public static function makeBooks( array $pContentIds ): int {
		global $gBitDb;
		$count = 0;
		$gBitDb->StartTrans();
		foreach( $pContentIds as $cid ) {
			$gBitDb->query( "UPDATE `".BIT_DB_PREFIX."liberty_content` SET `content_type_guid` = 'fisheyebook' WHERE `content_id` = ? AND `content_type_guid` = 'fisheyeimage'", [ (int)$cid ] );
			$count++;
		}
		$gBitDb->CompleteTrans();
		// the content type of an item is looked up through a query cached for an hour: flush it so a converted item stops coming back as an Image
		if( !empty( $gBitDb->mDb ) && method_exists( $gBitDb->mDb, 'CacheFlush' ) ) {
			$gBitDb->mDb->CacheFlush();
		}
		return $count;
	}

	/** @return array{converted:int,errors:list<string>} */
	public static function convertLoadedBooks(): array {
		global $gBitDb;
		$ret = [ 'converted' => 0, 'errors' => [] ];
		$ids = self::plainImageBooks();
		if( !$ids ) {
			return $ret;
		}
		$ret['converted'] = self::makeBooks( $ids );
		return $ret;
	}

	/**
	 * Whether a folder is ONE book: in a registered archive, a folder that holds exactly one PDF or epub and nothing else that could be loaded below it. Such a
	 * folder is not made a gallery; its document goes straight into the gallery above (several documents in a folder are editions of one book and keep their
	 * gallery; a folder called Extras always stays a gallery). Magazines keeps a gallery for every folder, as it always has.
	 */
	public static function isSingleDocumentFolder( string $pDir ): bool {
		$root = self::root();
		if( $pDir === '' || $pDir === '.' || strcasecmp( basename( $pDir ), 'Extras' ) === 0 || $root === '' || self::oldRules() || !is_dir( $root.$pDir ) ) {
			return false;
		}
		$count = 0;
		$direct = false;
		foreach( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root.$pDir, \FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			if( $f->isFile() && ( $handler = self::handlerFor( $f->getFilename() ) ) ) {
				$count++;
				$direct = in_array( $handler, self::DOCUMENT_HANDLERS, true ) && $f->getPath() === rtrim( $root.$pDir, '/' );
				if( $count > 1 ) {
					return false;
				}
			}
		}
		return $count === 1 && $direct;
	}

	/** The folder whose gallery a file is filed in: its own folder, or the one above when its folder is a single-document folder. */
	public static function galleryDirFor( string $pPath ): string {
		$dir = dirname( $pPath );
		return self::isSingleDocumentFolder( $dir ) ? dirname( $dir ) : $dir;
	}

	/**
	 * Loaded documents sitting alone in a gallery made for their own folder, where the folder is a single-document folder: [ item content_id => [ gallery content_id,
	 * parent gallery content_id ] ]. Only a gallery that holds just that item, has a single parent and carries the folder's name is listed.
	 *
	 * @return array<int,array{0:int,1:int}>
	 */
	public static function singleDocumentGalleries(): array {
		global $gBitDb;
		$out = [];
		$rows = $gBitDb->getAll(
			"SELECT a.`content_id`, p.`pref_value` FROM `".BIT_DB_PREFIX."liberty_attachment_prefs` p
			 JOIN `".BIT_DB_PREFIX."liberty_attachments` a ON a.`attachment_id` = p.`attachment_id`
			 WHERE p.`pref_name` = 'extdoc_path' AND p.`pref_value` LIKE ? AND a.`attachment_plugin_guid` IN ( 'mimeextpdf', 'mimeextepub' )", [ self::$sKind.'|%' ] ) ?: [];
		foreach( $rows as $row ) {
			$path = substr( (string)$row['pref_value'], strlen( self::$sKind ) + 1 );
			$dir = dirname( $path );
			if( !self::isSingleDocumentFolder( $dir ) ) {
				continue;
			}
			$cid = (int)$row['content_id'];
			$in = $gBitDb->getCol( "SELECT `gallery_content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` WHERE `item_content_id` = ?", [ $cid ] ) ?: [];
			if( count( $in ) !== 1 ) {
				continue;
			}
			$gallery = (int)$in[0];
			$parents = $gBitDb->getCol( "SELECT `gallery_content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` WHERE `item_content_id` = ?", [ $gallery ] ) ?: [];
			$held = (int)$gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` WHERE `gallery_content_id` = ?", [ $gallery ] );
			$title = (string)$gBitDb->getOne( "SELECT `title` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ?", [ $gallery ] );
			if( $held === 1 && count( $parents ) === 1 && $title === basename( $dir ) ) {
				$out[$cid] = [ $gallery, (int)$parents[0] ];
			}
		}
		return $out;
	}

	/**
	 * File each such document straight into the gallery above and remove the empty gallery left behind (the document itself, its thumbnails and its link are not touched).
	 *
	 * @return array{moved:int,errors:list<string>}
	 */
	public static function flattenSingleDocuments(): array {
		$ret = [ 'moved' => 0, 'errors' => [] ];
		foreach( self::singleDocumentGalleries() as $cid => [ $gallery, $parent ] ) {
			$item = \Bitweaver\Liberty\LibertyBase::getLibertyObject( $cid );
			$old = \Bitweaver\Liberty\LibertyBase::getLibertyObject( $gallery );
			$parentId = self::galleryIdOf( $parent );
			if( !$item || !$old || !$parentId ) {
				$ret['errors'][] = 'Item '.$cid.': the galleries could not be found.';
				continue;
			}
			$item->addToGalleries( [ $parentId ] );
			$old->removeItem( $cid );
			$old->expunge();
			$ret['moved']++;
		}
		return $ret;
	}

	/** The preference a project Book carries to say which folder it was made from (<kind>|<folder path>): the folder is its identity, not its title. */
	public const PROJECT_PREF = 'docs_project_folder';

	/** content_id of the project Book made from a folder (path under the root), or null when there is none yet. */
	public static function projectBookId( string $pDir ): ?int {
		global $gBitDb;
		$id = $gBitDb->getOne( "SELECT p.`content_id` FROM `".BIT_DB_PREFIX."liberty_content_prefs` p JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = p.`content_id` WHERE p.`pref_name` = ? AND p.`pref_value` = ?", [ self::PROJECT_PREF, self::$sKind.'|'.$pDir ] );
		return $id ? (int)$id : null;
	}

	/**
	 * The folders of a topic that can be made ONE Book: a folder that holds two or more differently named documents directly and has no document below it (a folder with
	 * documents below it is a gallery; a folder under Extras is never a Book). The folder's content decides whether it is a project (a model's manual and instructions)
	 * or only a series of separate books, so the Book is made by a button, not guessed. Not offered for Magazines.
	 *
	 * @return list<array{dir:string,files:list<string>,contentId:?int,url:string}>
	 */
	public static function projectFolders( string $pTopic ): array {
		if( self::oldRules() ) {
			return [];
		}
		$byDir = [];
		foreach( self::filesIn( $pTopic ) as $path ) {
			if( in_array( self::handlerFor( $path ), self::DOCUMENT_HANDLERS, true ) ) {
				$byDir[dirname( $path )][] = $path;
			}
		}
		$out = [];
		foreach( $byDir as $dir => $paths ) {
			if( $dir === $pTopic || str_contains( '/'.$dir.'/', '/Extras/' ) || count( array_unique( array_map( fn( $p ) => strtolower( pathinfo( $p, PATHINFO_FILENAME ) ), $paths ) ) ) < 2 ) {
				continue;
			}
			foreach( array_keys( $byDir ) as $other ) {
				if( str_starts_with( $other, $dir.'/' ) ) {
					continue 2;
				}
			}
			$bookId = self::projectBookId( $dir );
			$book = $bookId ? \Bitweaver\Fisheye\FisheyeImage::lookup( [ 'content_id' => $bookId ] ) : null;
			$out[] = [ 'dir' => $dir, 'files' => $paths, 'contentId' => $bookId, 'url' => $book && $book->isValid() ? (string)$book->getDisplayUrl() : '' ];
		}
		return $out;
	}

	/** What tells each file of a project apart: its name without the words they all begin with ("Manual", "Instructions"). */
	private static function editionLabels( array $pPaths ): array {
		$stems = array_map( fn( $p ) => pathinfo( $p, PATHINFO_FILENAME ), $pPaths );
		$prefix = $stems[0];
		foreach( $stems as $stem ) {
			while( $prefix !== '' && stripos( $stem, $prefix ) !== 0 ) {
				$prefix = substr( $prefix, 0, -1 );
			}
		}
		$prefix = (string)preg_replace( '/\S*$/', '', $prefix );
		$labels = [];
		foreach( $pPaths as $i => $path ) {
			$label = trim( substr( $stems[$i], strlen( $prefix ) ), " -" );
			$labels[$path] = $label !== '' ? $label : $stems[$i];
		}
		return $labels;
	}

	/**
	 * Make a folder ONE Book: a Book with no file of its own, titled for the folder and filed in the gallery above it, with an 'ebook' row for each document, labelled for
	 * what tells it apart, pointing at that document as an item loaded in place. The item is in no gallery (identity and placement are separate: it is found by search and listed by the Book's row,
	 * and no per-book pictures folder is made). A document already loaded keeps the item it has and the gallery it is in. Repeatable: a Book already made from the folder only gains rows for documents it lacks.
	 *
	 * @return array{created?:int,existing?:int,rows:int,errors:list<string>}
	 */
	public static function makeProjectBook( string $pDir ): array {
		$result = [ 'rows' => 0, 'errors' => [] ];
		$topic = explode( '/', $pDir )[0];
		$folder = null;
		foreach( self::projectFolders( $topic ) as $f ) {
			if( $f['dir'] === $pDir ) {
				$folder = $f;
			}
		}
		if( !$folder ) {
			$result['errors'][] = $pDir.': this folder is not one that can be made a Book.';
			return $result;
		}
		if( !( $top = self::topContentId() ) ) {
			$result['errors'][] = 'There is no top-level '.self::topTitle().' gallery.';
			return $result;
		}
		$parentContentId = self::galleryFor( dirname( $pDir ), $top );
		if( !$parentContentId ) {
			$result['errors'][] = $pDir.': the gallery above could not be made.';
			return $result;
		}
		$bookId = $folder['contentId'];
		$book = new FisheyeBook();
		if( $bookId ) {
			$book = new FisheyeBook( null, $bookId );
			$book->load();
			$result['existing'] = $bookId;
		} else {
			$hash = [ 'title' => basename( $pDir ) ];
			if( ( $inherit = FisheyeAccess::inheritedFor( $parentContentId ) ) !== null ) {
				$hash['protector']['role_id'] = $inherit;
			}
			if( !$book->store( $hash ) || !$book->mContentId ) {
				$result['errors'][] = $pDir.': '.implode( '; ', array_map( 'strval', array_values( $book->mErrors ) ) );
				return $result;
			}
			$book->load();
			$book->storePreference( self::PROJECT_PREF, self::$sKind.'|'.$pDir );
			$gallery = new FisheyeGallery( null, $parentContentId );
			$gallery->load();
			$gallery->addItem( $book->mContentId );
			$result['created'] = (int)$book->mContentId;
		}
		global $gBitDb;
		$has = $gBitDb->getCol( "SELECT `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'ebook' AND `end_date` IS NULL", [ (int)$book->mContentId ] ) ?: [];
		$loaded = self::loaded();
		$labels = self::editionLabels( $folder['files'] );
		$order = 0;
		foreach( $folder['files'] as $path ) {
			$order++;
			$spec = self::$sKind.'|'.$path;
			if( in_array( $spec, $has, true ) ) {
				continue;
			}
			$itemId = isset( $loaded[$spec] ) ? (int)$loaded[$spec] : 0;
			if( !$itemId ) {
				$handler = self::handlerFor( $path );
				self::activatePlugin( $handler );
				$item = new \Bitweaver\Fisheye\FisheyeImage();
				$itemHash = [ 'title' => pathinfo( $path, PATHINFO_FILENAME ), 'mimeplugin' => [ $handler => [ 'root' => self::$sKind, 'path' => $path ] ], 'process_storage' => STORAGE_IMAGE ];
				if( ( $inherit = FisheyeAccess::inheritedFor( $parentContentId ) ) !== null ) {
					$itemHash['protector']['role_id'] = $inherit;
				}
				if( !$item->store( $itemHash ) || !$item->mContentId ) {
					$result['errors'][] = $path.': '.implode( '; ', array_map( 'strval', array_values( $item->mErrors ) ) );
					continue;
				}
				$itemId = (int)$item->mContentId;   // not filed in any gallery: it is identified (searchable, opens in the viewer) and listed only through the Book's ebook row
			}
			$data = [ 'format' => strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), 'size' => number_format( (int)@filesize( self::root().$path ) / 1048576, 1 ).' MB', 'edition' => $labels[$path], 'multi' => true ];
			$row = [ 'content_id' => (int)$book->mContentId, 'item' => 'ebook', 'xkey_ext' => $spec, 'xref' => $itemId, 'edit' => json_encode( $data ), 'xorder' => $order ];
			if( $book->storeXref( $row ) ) {
				$result['rows']++;
			} else {
				$result['errors'][] = $path.': the item was made (content_id '.$itemId.') but its ebook row was not stored: '.implode( '; ', array_map( 'strval', array_values( $book->mErrors ) ) );
			}
		}
		// a Book with no file of its own has no thumbnail until it is given one: the first page of the first PDF of the folder (a Book that already has a cover keeps it)
		if( !$book->hasThumbnail() ) {
			foreach( $folder['files'] as $path ) {
				if( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) === 'pdf' ) {
					if( ( $err = $book->coverFromPdfFile( self::$sKind.'|'.$path ) ) !== '' ) {
						$result['errors'][] = $path.': '.$err;
					}
					break;
				}
			}
		}
		return $result;
	}

	/** content_id of the gallery for a folder path under Documentation (Architecture, Architecture/Sub), each level made when missing. */
	private static function galleryFor( string $pDir, int $pTop ): ?int {
		$parent = $pTop;
		$walked = '';
		foreach( explode( '/', $pDir ) as $part ) {
			if( $part === '' || $part === '.' ) {
				continue;
			}
			$walked = ltrim( $walked.'/'.$part, '/' );
			$parent = self::findOrMakeGallery( $part, $parent, self::styleFor( $walked ) );
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

	/**
	 * The gallery style for a folder (path under the root) a gallery is made for: a registered archive's folder holding more pictures than documents is an Images Grid,
	 * anything else - and every folder of Magazines - a Docs Grid. Decided once, when the gallery is made.
	 */
	private static function styleFor( string $pRel ): string {
		if( self::oldRules() || ( $root = self::root() ) === '' || !is_dir( $root.$pRel ) ) {
			return FISHEYE_PAGINATION_DOCS_GRID;
		}
		$pictures = $documents = 0;
		foreach( array_diff( scandir( $root.$pRel ) ?: [], [ '.', '..' ] ) as $name ) {
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if( is_file( $root.$pRel.'/'.$name ) ) {
				isset( self::IMAGE_HANDLERS[$ext] ) ? $pictures++ : ( isset( self::HANDLERS[$ext] ) ? $documents++ : null );
			}
		}
		return $pictures > $documents ? FISHEYE_PAGINATION_IMAGES_GRID : FISHEYE_PAGINATION_DOCS_GRID;
	}

	private static function findOrMakeGallery( string $pTitle, int $pParentContentId, string $pStyle = FISHEYE_PAGINATION_DOCS_GRID ): ?int {
		global $gBitDb;
		$found = $gBitDb->getOne(
			"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
			 WHERE m.`gallery_content_id` = ? AND lc.`title` = ? AND lc.`content_type_guid` = 'fisheyegallery'", [ $pParentContentId, $pTitle ] );
		if( $found ) {
			return (int)$found;
		}
		$gallery = new FisheyeGallery();
		$storeHash = [ 'title' => $pTitle, 'gallery_pagination' => $pStyle ];   // store() takes it by reference
		// a gallery made inside a restricted gallery is restricted the same way
		if( ( $inherit = FisheyeAccess::inheritedFor( $pParentContentId ) ) !== null ) {
			$storeHash['protector']['role_id'] = $inherit;
		}
		if( !$gallery->store( $storeHash ) ) {
			return null;
		}
		$gallery->storePreference( 'gallery_pagination', $pStyle );
		$gallery->addToGalleries( [ self::galleryIdOf( $pParentContentId ) ] );
		return (int)$gallery->mContentId;
	}

	/** The plugin is found by the plugin scan on a page that loads no upload first, and a new plugin is switched off until its status is stored. */
	public static function activatePlugin( string $pGuid ): void {
		global $gLibertySystem, $gBitSystem;
		$gLibertySystem->scanAllPlugins( null, "mime\." );
		if( !$gLibertySystem->isPluginActive( $pGuid ) && isset( $gLibertySystem->mPlugins[$pGuid] ) ) {
			$gBitSystem->storeConfig( 'liberty_plugin_status_'.$pGuid, 'y', LIBERTY_PKG_NAME );
			$gLibertySystem->mPlugins[$pGuid]['is_active'] = 'y';
		}
	}
}

<?php
/**
 * Docs workbench: the files in the Documentation folder, one topic folder at a time (and, as a second kind, the Magazines folder, one title at a time), loaded in place as fisheye images (the mimeext* mime
 * plugins (one per file type: mimeextpdf and mimeextepub) keeps the file where it is and puts a link to it in storage/), filed in a gallery tree that mirrors the folders under the existing
 * top-level "Documentation" gallery. The text of a PDF goes into liberty_content_data for search. Each step is recomputed from the database and
 * the disk, so it can be repeated; a file counts as loaded when an attachment holds its path (the extdoc_path preference).
 *
 * Smaller documents can instead be uploaded into the gallery as archives (a stored copy), so this is for the big ones. The root is the Documentation
 * Disk Path setting (kernel_config mimeext_root_documentation: the disk holding Documentation/, blank = the External Disk Storage Path); the same plugin takes a 'library' root for the Library's extras.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeGallery;
use Bitweaver\Fisheye\FisheyeImage;
use Bitweaver\KernelTools;

class FisheyeDocsWorkbench {
	/** Files loaded per press (each PDF is read twice: text and first-page thumbnail). */
	public const BATCH = 10;

	/** Title of the existing top-level gallery the topic galleries go under (Documentation kind; see topTitle()). */
	public const TOP_TITLE = 'Documentation';

	/**
	 * The collections this workbench loads, each a source folder, a top-level gallery and a root key for the mimeext* plugins - Documentation (one folder per
	 * topic), Magazines (one folder per title, then year or volume folders) and any folder registered as an archive (FisheyeArchives, from the archive scan
	 * page). 'folder' is the name looked for on the disk named by the kind's setting (mimeext_root_<kind>), or on the External Disk Storage Path when that is blank.
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

	/** The pictures an archive loads in place (mimeextimage). */
	public const IMAGE_HANDLERS = [ 'jpg' => 'mimeextimage', 'jpeg' => 'mimeextimage', 'png' => 'mimeextimage', 'gif' => 'mimeextimage', 'webp' => 'mimeextimage', 'bmp' => 'mimeextimage' ];

	/** Other files kept beside the books (a zip of code, a rar of maps) an archive loads in place as plain items (mimeextfile): served and listed, nothing derived from them. */
	public const FILE_HANDLERS = [ 'zip' => 'mimeextfile', 'rar' => 'mimeextfile', '7z' => 'mimeextfile' ];

	/** The archive's own rules (FisheyeArchives::BUILTIN / DEFAULTS): whether every one of its folders is a gallery, however little it holds, and none is a Book. */
	public static function foldersAll(): bool {
		return ( self::kinds()[self::$sKind]['folders'] ?? 'collapse' ) === 'all';
	}

	/** Whether the archive's PDFs and epubs are issues of a magazine (its rule 'type'), not books. */
	public static function isIssueArchive(): bool {
		return ( self::kinds()[self::$sKind]['type'] ?? 'fisheyebook' ) === 'fisheyeissue';
	}

	/** The mime plugin that loads a file of the kind in use, or null when none does yet. */
	public static function handlerFor( string $pPath ): ?string {
		$ext = strtolower( pathinfo( $pPath, PATHINFO_EXTENSION ) );
		return self::HANDLERS[$ext] ?? self::IMAGE_HANDLERS[$ext] ?? self::FILE_HANDLERS[$ext] ?? null;
	}

	/** The handlers whose files are Books (a PDF or epub, which can carry an ISBN); the pictures a registered archive loads stay plain Images. */
	public const DOCUMENT_HANDLERS = [ 'mimeextpdf', 'mimeextepub' ];

	/** What a folder holds when the archive's Catalogue.csv says nothing about it: drawings, maps and extra files load as plain Images (matched on any folder of the path). */
	public const PLAIN_FOLDERS = [ 'pictures', 'models', 'extras' ];

	/** What a folder can hold, the content types of what is loaded into it: a plain Image, or a document of one of FisheyeBook's schemas (a Book, a Magazine Issue). */
	public const TYPE_IMAGE = 'fisheyeimage';
	public const TYPE_BOOK = 'fisheyebook';
	public const TYPE_ISSUE = 'fisheyeissue';

	/** @var array<string,list<array{0:string,1:string}>> kind => [ folder path as written, content type ] rows of the archive root's Catalogue.csv */
	private static array $sCatalogues = [];

	/**
	 * The archive's Catalogue.csv, one row per folder: Path (relative to the archive root, no closing slash) and Type (fisheyeimage, fisheyebook or fisheyeissue). It is how a new
	 * clean gallery is added without a code change: a folder with a row is made a gallery of that type's style (Images Grid for fisheyeimage, Docs Grid for the documents),
	 * loads that type into it, and the row covers every folder below it until a deeper row says otherwise. Folders with no row are decided by name (PLAIN_FOLDERS)
	 * and by what they hold, as before. Any other Type is ignored; extra columns are ignored.
	 *
	 * @return list<array{0:string,1:string}>
	 */
	public static function catalogueRows(): array {
		if( isset( self::$sCatalogues[self::$sKind] ) ) {
			return self::$sCatalogues[self::$sKind];
		}
		$rows = [];
		$file = self::root().'Catalogue.csv';
		if( self::root() !== '' && is_file( $file ) && ( $fh = fopen( $file, 'r' ) ) ) {
			$head = fgetcsv( $fh, 0, ',', '"', '' ) ?: [];
			$head = array_map( fn( $h ) => strtolower( trim( (string)preg_replace( '/^\xEF\xBB\xBF/', '', (string)$h ) ) ), $head );
			$pathCol = array_search( 'path', $head, true );
			$typeCol = array_search( 'type', $head, true );
			while( $pathCol !== false && $typeCol !== false && ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
				$type = strtolower( trim( (string)( $row[$typeCol] ?? '' ) ) );
				$path = trim( trim( (string)( $row[$pathCol] ?? '' ) ), '/' );
				if( $path !== '' && !self::hasParentSegment( $path ) && in_array( $type, [ self::TYPE_IMAGE, self::TYPE_BOOK, self::TYPE_ISSUE ], true ) ) {
					$rows[] = [ $path, $type ];
				}
			}
			fclose( $fh );
		}
		return self::$sCatalogues[self::$sKind] = $rows;
	}

	/** @return array<string,string> lower-case folder path => content type */
	public static function catalogue(): array {
		$map = [];
		foreach( self::catalogueRows() as [ $path, $type ] ) {
			$map[strtolower( $path )] = $type;
		}
		return $map;
	}

	/** The type the Catalogue.csv gives a FOLDER (its own row, else the nearest row above) and how deep that row is, or null when there is none. @return ?array{0:string,1:int} */
	private static function catalogueMatch( string $pDir ): ?array {
		$parts = array_values( array_filter( explode( '/', $pDir ), fn( $d ) => $d !== '' && $d !== '.' ) );
		$map = self::catalogue();
		for( $n = count( $parts ); $n >= 1; $n-- ) {
			$key = strtolower( implode( '/', array_slice( $parts, 0, $n ) ) );
			if( isset( $map[$key] ) ) {
				return [ $map[$key], $n ];
			}
		}
		return null;
	}

	/** The type the Catalogue.csv gives a FOLDER, or null when it has none. */
	public static function catalogueType( string $pDir ): ?string {
		return self::catalogueMatch( $pDir )[0] ?? null;
	}

	/**
	 * What a FOLDER holds as far as the Catalogue.csv and the folder names say, or null when neither does. The deepest decision wins: a row governs its folder and every
	 * folder below it, but a Pictures, Models or Extras folder below that row is still plain Images unless a row deeper still says otherwise.
	 */
	public static function decidedType( string $pDir ): ?string {
		$parts = array_values( array_filter( explode( '/', $pDir ), fn( $d ) => $d !== '' && $d !== '.' ) );
		$plain = 0;
		foreach( $parts as $i => $part ) {
			if( in_array( strtolower( $part ), self::PLAIN_FOLDERS, true ) ) {
				$plain = $i + 1;
			}
		}
		$row = self::catalogueMatch( $pDir );
		if( $row !== null && $row[1] >= $plain ) {
			return $row[0];
		}
		return $plain > 0 ? self::TYPE_IMAGE : null;
	}

	/** The names of the content types an archive can load into a folder: guid => name (FisheyeBook::SCHEMAS and the plain Image). */
	public static function typeLabels(): array {
		return FisheyeBook::SCHEMAS + [ self::TYPE_IMAGE => 'Image' ];
	}

	/**
	 * The content types an archive loads, for the Archive Workbench: what its documents are by default, plus every type its Catalogue.csv names.
	 *
	 * @return list<string> guids, the default first
	 */
	public static function loadsAs( string $pKind ): array {
		$previous = self::$sKind;
		self::setKind( $pKind );
		$types = array_values( array_unique( array_merge( [ self::defaultType() ], array_column( self::catalogueRows(), 1 ) ) ) );
		self::setKind( $previous );
		return $types;
	}

	/** What a PDF or epub is loaded as when nothing in the Catalogue.csv or the folder names says: a magazine archive holds issues, any other archive books. */
	public static function defaultType(): string {
		return self::isIssueArchive() ? self::TYPE_ISSUE : self::TYPE_BOOK;
	}

	/** What a FILE path (under the archive root) is loaded as: TYPE_IMAGE, TYPE_BOOK or TYPE_ISSUE (see decidedType()). */
	public static function typeFor( string $pPath ): string {
		return self::decidedType( dirname( $pPath ) ) ?? self::defaultType();
	}

	/** Whether a FILE path is loaded as a plain Image (see typeFor()); pass a folder as `<dir>/x`. */
	public static function isPlainPath( string $pPath ): bool {
		return self::typeFor( $pPath ) === self::TYPE_IMAGE;
	}

	/** The preference value is a VARCHAR(250). */
	private const SPEC_MAX = 250;

	/** The folder of the kind in use, with its closing slash, or '' when there is none (see rootFor()). */
	public static function root(): string {
		return self::rootFor( self::$sKind );
	}

	/**
	 * The source folder for a root key with its closing slash, or '' when there is none: the kind's folder (its name is part of the registration) on the disk named by the
	 * setting (kernel_config mimeext_root_<key>), else on the External Disk Storage Path - the same rule as the Library and Tutorials. A key that is not a kind takes its setting as the folder.
	 */
	public static function rootFor( string $pKey ): string {
		global $gBitSystem;
		$set = trim( (string)$gBitSystem->getConfig( 'mimeext_root_'.$pKey, '' ) );
		if( isset( self::kinds()[$pKey] ) ) {
			// a kind's setting is the disk that holds its folder (the name belongs to the registration); blank is the External Disk Storage Path
			$disk = $set !== '' ? rtrim( $set, '/' ).'/' : ( function_exists( '\\Bitweaver\\Liberty\\mime_film_get_storage_root' ) ? \Bitweaver\Liberty\mime_film_get_storage_root() : '' );
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
		if( $root === '' || $pTopic === '' || self::hasParentSegment( $pTopic ) || !is_dir( $dir ) ) {
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
			// a PDF or epub is a document of the schema its folder gives (typeFor(): a Book, or a Magazine Issue in a magazine archive), which holds the file itself as an
			// attachment kept in place; except in a Pictures, Models or Extras folder, which hold plain Images, as does a picture
			$schema = in_array( $file['handler'], self::DOCUMENT_HANDLERS, true ) ? self::typeFor( $file['path'] ) : self::TYPE_IMAGE;
			$document = $schema !== self::TYPE_IMAGE;
			$isBook = $schema === self::TYPE_BOOK;   // only a Book carries an ISBN, authors and a publisher to read
			$unsure = false;
			$found = $isBook ? FisheyeArchiveIsbn::fileIsbns( self::root().$file['path'], $unsure ) : [];
			$meta = $isBook ? FisheyeArchiveMeta::fileMeta( self::root().$file['path'] ) : [];
			$image = $document ? new FisheyeBook( null, null, $schema ) : new FisheyeImage();
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
				self::pullSiblingsIn( $file['path'], $st['files'], $galleryContentId );
				// a Book made from a document starts with the ISBN, the people, the publisher and the date its file carries; an Issue with the number, volume and date its name gives (a magazine issue or a picture has none to read)
				if( $isBook || $schema === self::TYPE_ISSUE ) {
					global $gBitDb;
					$attId = (int)$gBitDb->getOne( "SELECT MAX(`attachment_id`) FROM `".BIT_DB_PREFIX."liberty_attachments` WHERE `content_id` = ? AND `attachment_plugin_guid` LIKE 'mimeext%'", [ (int)$image->mContentId ] );
					if( $attId && $isBook ) {
						FisheyeArchiveIsbn::tagFile( (int)$image->mContentId, $attId, self::root().$file['path'], $found, $unsure );
						FisheyeArchiveMeta::tagFile( (int)$image->mContentId, $attId, self::root().$file['path'], $meta );
					} elseif( $attId ) {
						FisheyeIssueDetails::tagFile( (int)$image->mContentId, $attId, $file['path'] );   // an Issue starts with the number, volume and date its name gives
						FisheyeIssueIndex::tagFile( self::$sKind, (int)$image->mContentId, $attId, $file['path'] );   // and what its title's index sheet says, when there is one
					}
				}
			} else {
				$result['errors'][] = $file['path'].': '.implode( '; ', array_map( 'strval', array_values( $image->mErrors ) ) );
			}
		}
		return $result;
	}

	/**
	 * A folder that was one book on its own (its document filed straight in the gallery above) gains a second loadable file, a zip of code: the folder now
	 * needs its gallery, and the document already loaded moves into it so the two sit together. Only a document filed in the gallery above and nowhere else is moved.
	 *
	 * @param list<array<string,mixed>> $pFiles the topic's files, as status() lists them
	 */
	private static function pullSiblingsIn( string $pPath, array $pFiles, int $pGalleryContentId ): void {
		global $gBitDb;
		$dir = dirname( $pPath );
		if( self::isSingleDocumentFolder( $dir ) ) {
			return;
		}
		$parent = self::galleryFor( dirname( $dir ), self::topContentId() );
		foreach( $pFiles as $f ) {
			if( dirname( $f['path'] ) !== $dir || empty( $f['contentId'] ) || !in_array( $f['handler'], self::DOCUMENT_HANDLERS, true ) ) {
				continue;
			}
			$in = $gBitDb->getCol( "SELECT `gallery_content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` WHERE `item_content_id` = ?", [ (int)$f['contentId'] ] ) ?: [];
			if( count( $in ) !== 1 || (int)$in[0] !== (int)$parent ) {
				continue;
			}
			$dest = new FisheyeGallery( null, $pGalleryContentId );
			$dest->load();
			if( $dest->addItem( (int)$f['contentId'] ) ) {
				$old = new FisheyeGallery( null, (int)$parent );
				$old->load();
				$old->removeItem( (int)$f['contentId'] );
			}
		}
	}

	/**
	 * Magazine issues loaded before they had their own type are plain Images: how many, and (convertLoadedIssues) change them to Magazine Issues, keeping
	 * every row, thumbnail and file reference. Done in the application (not by hand in the database), in one transaction, repeatable.
	 */
	public static function plainImageIssues(): array {
		global $gBitDb;
		if( !self::isIssueArchive() ) {
			return [];
		}
		$rows = $gBitDb->getAll(
			"SELECT DISTINCT a.`content_id`, p.`pref_value` AS spec FROM `".BIT_DB_PREFIX."liberty_attachment_prefs` p
			 JOIN `".BIT_DB_PREFIX."liberty_attachments` a ON a.`attachment_id` = p.`attachment_id`
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = a.`content_id`
			 WHERE p.`pref_name` = 'extdoc_path' AND p.`pref_value` LIKE ? AND lc.`content_type_guid` = 'fisheyeimage'", [ self::$sKind.'|%' ] ) ?: [];
		return array_map( 'intval', array_column( $rows, 'content_id' ) );   // the rows are [ content_id, spec ]: it is the id that is wanted
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
			$gBitDb->query( "UPDATE `".BIT_DB_PREFIX."liberty_content` SET `content_type_guid` = ? WHERE `content_id` = ? AND `content_type_guid` = 'fisheyeimage'", [ self::TYPE_ISSUE, $cid ] );
		}
		$gBitDb->CompleteTrans();
		// getLibertyObject() looks a content item's type up through a query cached for an hour: without this flush a converted issue keeps coming back as an Image.
		if( !empty( $gBitDb->mDb ) && method_exists( $gBitDb->mDb, 'CacheFlush' ) ) {
			$gBitDb->mDb->CacheFlush();
		}
		// what was changed is counted from the database, not from what was attempted
		$left = count( self::plainImageIssues() );
		$ret['converted'] = count( $ids ) - $left;
		if( $left ) {
			$ret['errors'][] = $left.' '.KernelTools::tra( 'items could not be changed to Magazine Issues.' );
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
		if( self::isIssueArchive() ) {
			return [];
		}
		$rows = $gBitDb->getAll(
			"SELECT DISTINCT a.`content_id`, p.`pref_value` AS spec FROM `".BIT_DB_PREFIX."liberty_attachment_prefs` p
			 JOIN `".BIT_DB_PREFIX."liberty_attachments` a ON a.`attachment_id` = p.`attachment_id`
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = a.`content_id`
			 WHERE p.`pref_name` = 'extdoc_path' AND p.`pref_value` LIKE ? AND a.`attachment_plugin_guid` IN ( 'mimeextpdf', 'mimeextepub' )
			   AND lc.`content_type_guid` = 'fisheyeimage'", [ self::$sKind.'|%' ] ) ?: [];
		// drawings, maps and extra files stay plain Images
		$rows = array_column( array_filter( $rows, fn( $r ) => !self::isPlainPath( substr( (string)$r['spec'], strlen( self::$sKind ) + 1 ) ) ), 'content_id' );
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
		// the documents of a project Book are Images by design (listed through the Book's ebook rows, filed in no gallery): not offered
		$items = array_map( 'intval', $gBitDb->getCol( "SELECT DISTINCT `xref` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `item` = 'ebook' AND `xref` > 0 AND `end_date` IS NULL" ) ?: [] );
		return array_values( array_diff( array_unique( array_map( 'intval', $rows ) ), $items ) );
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
		// a folder with a row in the Catalogue.csv is a gallery, whatever it holds, and so is any folder that holds plain Images (Pictures, Models, Extras)
		if( isset( self::catalogue()[strtolower( trim( $pDir, '/' ) )] ) || self::isPlainPath( rtrim( $pDir, '/' ).'/x' ) ) {
			return false;
		}
		$root = self::root();
		if( $pDir === '' || $pDir === '.' || strcasecmp( basename( $pDir ), 'Extras' ) === 0 || $root === '' || self::foldersAll() || !is_dir( $root.$pDir ) ) {
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

	/** Whether a path has a '..' folder in it (a way out of the root). Dots inside a name - a title ending "Title....epub" - are not one. */
	public static function hasParentSegment( string $pPath ): bool {
		return (bool)preg_match( '#(^|[\\/])\.\.([\\/]|$)#', $pPath );
	}

	/** The trailing edition token of a title ("V2", "V3"), or ''. */
	private static function editionToken( string $pStem ): string {
		return preg_match( '/\s+(V\d+)$/i', $pStem, $m ) ? strtoupper( $m[1] ) : '';
	}

	/**
	 * Whether the documents of a folder are editions of ONE book: two or more files whose titles are the same once a trailing edition token (V2) is taken off - the
	 * same book in another format (an epub and a PDF) or in a second version. A folder of such files is made one Book with a row for each, like a model's manual and
	 * instructions.
	 */
	private static function editionsOfOne( array $pPaths ): bool {
		if( count( $pPaths ) < 2 ) {
			return false;
		}
		$titles = array_unique( array_map( fn( $p ) => strtolower( trim( (string)preg_replace( '/\s+V\d+$/i', '', pathinfo( $p, PATHINFO_FILENAME ) ) ) ), $pPaths ) );
		return count( $titles ) === 1 && $titles[array_key_first( $titles )] !== '';
	}

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
		if( self::foldersAll() ) {
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
			$editions = self::editionsOfOne( $paths );
			if( $dir === $pTopic || self::isPlainPath( $dir.'/x' ) || ( !$editions && count( array_unique( array_map( fn( $p ) => strtolower( pathinfo( $p, PATHINFO_FILENAME ) ), $paths ) ) ) < 2 ) ) {
				continue;
			}
			foreach( array_keys( $byDir ) as $other ) {
				if( str_starts_with( $other, $dir.'/' ) ) {
					continue 2;
				}
			}
			if( !$editions && ( !self::looksLikeProject( $paths ) || self::differentIsbns( $paths ) >= 2 ) ) {
				continue;   // differently named books, or files that each carry an ISBN of their own (a series folder): Load files loads each as a Book of its own
			}
			$bookId = self::projectBookId( $dir );
			$book = $bookId ? \Bitweaver\Fisheye\FisheyeImage::lookup( [ 'content_id' => $bookId ] ) : null;
			$out[] = [ 'dir' => $dir, 'files' => $paths, 'contentId' => $bookId, 'url' => $book && $book->isValid() ? (string)$book->getDisplayUrl() : '' ];
		}
		return $out;
	}

	/**
	 * Whether the documents of a folder read as the parts of ONE thing (a model's manual and instructions) and not as separate books: their names begin with the same
	 * two or more words and what is left to tell them apart is a word or three ("Acme Kit 498 Model Ship Instructions" / "... Manual"). Separate books
	 * (Al-Qadim - Arabian Adventures, Maztica - Campaign Setting) share no such beginning. Only a folder that passes is held back from Load files for a Make Book.
	 */
	private static function looksLikeProject( array $pPaths ): bool {
		$words = array_map( fn( $p ) => preg_split( '/\s+/', trim( pathinfo( $p, PATHINFO_FILENAME ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: [], $pPaths );
		$common = 0;
		while( true ) {
			$w = strtolower( $words[0][$common] ?? '' );
			if( $w === '' ) {
				break;
			}
			foreach( $words as $list ) {
				if( strtolower( $list[$common] ?? '' ) !== $w ) {
					break 2;
				}
			}
			$common++;
		}
		if( $common < 2 ) {
			return false;
		}
		$numbered = true;
		foreach( $words as $list ) {
			$left = count( $list ) - $common;
			if( $left < 1 || $left > 3 ) {
				return false;
			}
			// what is left is only a number or a Roman numeral (Volume I, II, III ... / Part 2): the volumes of a series, not the parts of one thing
			$numbered = $numbered && !array_filter( array_slice( $list, $common ), fn( $w ) => !preg_match( '/^(?:\d+|[ivxlc]+)[,.]?$/i', $w ) );
		}
		return !$numbered;
	}

	/** How many different ISBNs the first file of each of these documents gives (it stops reading at two): a manual and its instructions give none or one, separate books one each. */
	private static function differentIsbns( array $pPaths ): int {
		$seen = [];
		foreach( $pPaths as $path ) {
			$found = FisheyeArchiveIsbn::fileIsbns( self::root().$path );
			if( $found ) {
				$seen[$found[0]] = true;
				if( count( $seen ) >= 2 ) {
					break;
				}
			}
		}
		return count( $seen );
	}

	/** What tells each file of a project apart: its name without the words they all begin with ("Manual", "Instructions"). */
	private static function editionLabels( array $pPaths ): array {
		if( self::editionsOfOne( $pPaths ) ) {
			// the edition token when the file has one (V2), else its format (EPUB, PDF); a label two files share gets the format added
			$labels = [];
			foreach( $pPaths as $path ) {
				$token = self::editionToken( pathinfo( $path, PATHINFO_FILENAME ) );
				$labels[$path] = $token !== '' ? $token : strtoupper( pathinfo( $path, PATHINFO_EXTENSION ) );
			}
			foreach( array_count_values( $labels ) as $label => $n ) {
				if( $n > 1 ) {
					foreach( $labels as $path => $l ) {
						$labels[$path] = $l === $label ? $label.' '.strtoupper( pathinfo( $path, PATHINFO_EXTENSION ) ) : $l;
					}
				}
			}
			return $labels;
		}
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
		// the Book is the work and its files are its editions, so it takes what they say about themselves: the people, publisher and date of the first file that gives
		// them, and the ISBN of every file as an edition of its own. Nothing the Book already holds is replaced. (The files are items, not Books: they are not read one by one.)
		$meta = [ 'authors' => [], 'publisher' => '', 'date' => '' ];
		$isbns = [];
		foreach( $folder['files'] as $path ) {
			$fileMeta = FisheyeArchiveMeta::fileMeta( self::root().$path );
			$meta['authors'] = $meta['authors'] ?: $fileMeta['authors'];
			$meta['publisher'] = $meta['publisher'] !== '' ? $meta['publisher'] : $fileMeta['publisher'];
			$meta['date'] = $meta['date'] !== '' ? $meta['date'] : $fileMeta['date'];
			$unsure = false;
			foreach( FisheyeArchiveIsbn::fileIsbns( self::root().$path, $unsure ) as $number ) {
				if( !$unsure && !in_array( $number, $isbns, true ) ) {
					$isbns[] = $number;
				}
			}
		}
		$book->addArchiveMetadata( $meta );
		if( $isbns ) {
			FisheyeArchiveIsbn::addIsbns( (int)$book->mContentId, $isbns );
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
		// a folder of epubs only has no PDF to draw a cover from: the cover its epub carries (a Book that already has a cover keeps it)
		if( !$book->hasThumbnail() && ( $cover = $book->coverFromEpub() ) && $cover['error'] !== '' ) {
			$result['errors'][] = $pDir.': '.$cover['error'];
		}
		return $result;
	}

	/** content_id of the gallery for a folder path under Documentation (Architecture, Architecture/Sub), each level made when missing. */
	/** The gallery for a folder path if it has been made (no gallery is made), else null. */
	private static function findGallery( string $pDir, int $pTop ): ?int {
		global $gBitDb;
		$parent = $pTop;
		foreach( array_filter( explode( '/', $pDir ), fn( $d ) => $d !== '' && $d !== '.' ) as $part ) {
			$found = $gBitDb->getOne(
				"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
				 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
				 WHERE m.`gallery_content_id` = ? AND lc.`title` = ? AND lc.`content_type_guid` = 'fisheyegallery'", [ $parent, $part ] );
			if( !$found ) {
				return null;
			}
			$parent = (int)$found;
		}
		return $parent;
	}

	/** How many of the Catalogue.csv's folders have no gallery yet. */
	public static function catalogueGalleriesMissing(): int {
		$top = self::topContentId();
		if( !$top ) {
			return 0;
		}
		$missing = 0;
		foreach( self::catalogueRows() as [ $path ] ) {
			$missing += self::findGallery( $path, $top ) ? 0 : 1;
		}
		return $missing;
	}

	/**
	 * Make a gallery for every folder in the Catalogue.csv (and for the folders above it), styled for its type, so they are there to be filled: by the workbench for
	 * a book folder, by an uploaded zip (the archive's Zips folder) for an image folder. A gallery that exists is left as it is.
	 *
	 * @return array{made:int,errors:list<string>}
	 */
	public static function makeCatalogueGalleries(): array {
		$out = [ 'made' => 0, 'errors' => [] ];
		if( !( $top = self::topContentId() ) ) {
			$out['errors'][] = 'There is no top-level '.self::topTitle().' gallery.';
			return $out;
		}
		foreach( self::catalogueRows() as [ $path ] ) {
			if( self::findGallery( $path, $top ) ) {
				continue;
			}
			self::galleryFor( $path, $top ) ? $out['made']++ : $out['errors'][] = $path.': the gallery could not be made.';
		}
		return $out;
	}

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
		if( ( $type = self::decidedType( $pRel ) ) !== null ) {
			return $type === self::TYPE_IMAGE ? FISHEYE_PAGINATION_IMAGES_GRID : FISHEYE_PAGINATION_DOCS_GRID;
		}
		if( self::foldersAll() || ( $root = self::root() ) === '' || !is_dir( $root.$pRel ) ) {
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

<?php
/**
 * The registry of folder archives: each a folder under the media storage root that the Docs workbench loads in place, one top-level folder (a topic) at a
 * time, into a gallery tree under a top gallery of its own. Documentation and Magazines are the two built in; any other folder can be registered from the
 * archive scan page, and every one is then loaded by the same workbench - the kind is the only thing that differs.
 *
 * A registered archive is kept in the site config: the list of kinds in fisheyemedia_archives and, for each, fisheyemedia_archive_<kind> holding a small
 * JSON of its top gallery title, its folder name and the noun for one of its folders. Nothing in it names a machine or a path: the folder is found under the
 * External Disk Storage Path as Documentation's is, so the same registration works wherever the database goes. The kind is also the root key of the mimeext*
 * plugins (mimeext_root_<kind> overrides the folder) and, with the 'role' key, names the top gallery's content_id in FisheyeMediaBases.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

class FisheyeArchives {

	/** The built-in kinds: the top-level gallery's title, the folder under the storage root, the noun for one of its folders, the FisheyeMediaBases role. */
	public const BUILTIN = [
		'documentation' => [ 'top' => 'Documentation', 'folder' => 'Documentation', 'noun' => 'topic', 'role' => 'docs' ],
		'magazines'     => [ 'top' => 'Magazines',     'folder' => 'Magazines',     'noun' => 'title', 'role' => 'magazines' ],
	];

	/** Words that already name a media base (FisheyeMediaBases::BASES) or a built-in kind. */
	private const RESERVED = [ 'library', 'films', 'music', 'tv', 'docs', 'magazines', 'documentation', 'images' ];

	/** Config names are 40 characters: 'fisheyemedia_<kind>_images_gallery' is the longest made from a kind. */
	private const KIND_PATTERN = '/^[a-z]{3,12}$/';

	private const LIST_KEY = 'fisheyemedia_archives';
	private const ENTRY_PREFIX = 'fisheyemedia_archive_';

	/** @var array<string,array>|null the answer for this request */
	private static ?array $kept = null;

	/**
	 * Every archive, built-in first: kind => [ top, folder, noun, role, builtin ].
	 *
	 * @return array<string,array{top:string,folder:string,noun:string,role:string,builtin:bool}>
	 */
	public static function all(): array {
		global $gBitSystem;
		if( self::$kept !== null ) {
			return self::$kept;
		}
		$all = [];
		foreach( self::BUILTIN as $kind => $spec ) {
			$all[$kind] = $spec + [ 'builtin' => true ];
		}
		foreach( array_filter( array_map( 'trim', explode( ',', (string)$gBitSystem->getConfig( self::LIST_KEY, '' ) ) ) ) as $kind ) {
			$spec = json_decode( (string)$gBitSystem->getConfig( self::ENTRY_PREFIX.$kind, '' ), true );
			if( !isset( $all[$kind] ) && preg_match( self::KIND_PATTERN, $kind ) && is_array( $spec ) && !empty( $spec['top'] ) && !empty( $spec['folder'] ) ) {
				$all[$kind] = [ 'top' => (string)$spec['top'], 'folder' => (string)$spec['folder'], 'noun' => (string)( $spec['noun'] ?? 'topic' ), 'role' => $kind, 'builtin' => false ];
			}
		}
		return self::$kept = $all;
	}

	/**
	 * The admin menu's entries, one per archive: the top gallery's title and the address of its workbench. Nothing is named in the menu, so an archive
	 * registered on the Archive scan page appears by itself.
	 *
	 * @return list<array{kind:string,title:string,url:string}>
	 */
	public static function menuEntries(): array {
		$entries = [];
		foreach( self::all() as $kind => $spec ) {
			$entries[] = [ 'kind' => $kind, 'title' => $spec['top'], 'url' => FISHEYEMEDIA_PKG_URL.'docs_workbench.php?kind='.$kind ];
		}
		return $entries;
	}

	/** Media folders that have a loader of their own rather than being archives: lower-case folder name => [ label, page ]. */
	private const LOADERS = [
		'library'         => [ 'Author workbench', 'author_workbench.php' ],
		'films'           => [ 'Load films', 'load_film.php' ],
		'tv shows'        => [ 'Load programs', 'load_program.php' ],
		'music'           => [ 'Load music', 'load_music.php' ],
		'local videos'    => [ 'Load local videos', 'load_local.php' ],
		'local tutorials' => [ 'Tutorial workbench', 'tutorial_workbench.php' ],
	];

	/**
	 * What handles each folder of the media storage folder, for the Archive scan page: a registered or built-in archive (its workbench), a media
	 * type with its own loader, or nothing yet (it can be scanned and registered).
	 *
	 * @param list<array{name:string}> $pFolders FisheyeArchiveScan::candidates()
	 * @return list<array{name:string,kind:string,label:string,url:string,details_url:string}> kind is 'archive', 'loader' or ''
	 */
	public static function folderOverview( array $pFolders ): array {
		$out = [];
		foreach( $pFolders as $folder ) {
			$row = [ 'name' => $folder['name'], 'kind' => '', 'label' => '', 'url' => '', 'details_url' => '' ];
			foreach( self::all() as $kind => $spec ) {
				if( strcasecmp( $spec['folder'], $folder['name'] ) === 0 ) {
					$row = [ 'name' => $folder['name'], 'kind' => 'archive', 'label' => $spec['top'].' workbench', 'url' => FISHEYEMEDIA_PKG_URL.'docs_workbench.php?kind='.$kind,
						'details_url' => $kind === 'magazines' ? '' : FISHEYEMEDIA_PKG_URL.'archive_isbn.php?kind='.$kind ];   // the ISBN and file details page works on Books, so a magazine archive has none
					break;
				}
			}
			if( $row['kind'] === '' && isset( self::LOADERS[strtolower( $folder['name'] )] ) ) {
				[ $label, $page ] = self::LOADERS[strtolower( $folder['name'] )];
				$row = [ 'name' => $folder['name'], 'kind' => 'loader', 'label' => $label, 'url' => FISHEYEMEDIA_PKG_URL.$page, 'details_url' => '' ];
			}
			$out[] = $row;
		}
		return $out;
	}

	/** One archive by kind, or null. @return array{top:string,folder:string,noun:string,role:string,builtin:bool}|null */
	public static function get( string $pKind ): ?array {
		return self::all()[$pKind] ?? null;
	}

	/** The registered (not built-in) archives. */
	public static function registered(): array {
		return array_filter( self::all(), fn( $a ) => !$a['builtin'] );
	}

	/** FisheyeMediaBases role => top gallery title for the registered archives. @return array<string,string> */
	public static function roleTitles(): array {
		$out = [];
		foreach( self::registered() as $kind => $spec ) {
			$out[$spec['role']] = $spec['top'];
		}
		return $out;
	}

	/**
	 * The archive a gallery belongs to, and which of its folders: the top gallery of the gallery's trail (or the gallery itself when it is the top) is compared
	 * with each archive's kept content_id, never with a title. 'topic' is the folder just under the top ('' on the top gallery itself).
	 *
	 * @return array{kind:string,topic:string}|null
	 */
	public static function forGallery( $pGallery ): ?array {
		global $gBitDb;
		if( !is_object( $pGallery ) || !$pGallery->isValid() || !method_exists( $pGallery, 'getBreadcrumbLinks' ) ) {
			return null;
		}
		$ancestors = $pGallery->getBreadcrumbLinks( false );   // gallery_id => title, the top first
		$topContentId = $ancestors
			? (int)$gBitDb->getOne( "SELECT `content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery` WHERE `gallery_id` = ?", [ (int)array_key_first( $ancestors ) ] )
			: (int)$pGallery->mContentId;
		foreach( self::all() as $kind => $spec ) {
			if( $topContentId && FisheyeMediaBases::contentId( $spec['role'] ) === $topContentId ) {
				return [ 'kind' => $kind, 'topic' => count( $ancestors ) > 1 ? (string)array_values( $ancestors )[1] : ( count( $ancestors ) === 1 ? (string)$pGallery->getTitle() : '' ) ];
			}
		}
		return null;
	}

	/** A short, lower-case form of a folder name to offer as its kind: the initials of its main words when that makes three letters or more, else its first word; letters only, at most 12. */
	public static function suggestKind( string $pFolder ): string {
		$words = array_values( array_filter( preg_split( '/[^A-Za-z]+/', strtolower( $pFolder ), -1, PREG_SPLIT_NO_EMPTY ) ?: [], fn( $w ) => !in_array( $w, [ 'and', 'of', 'the', 'a', 'an' ], true ) ) );
		$initials = implode( '', array_map( fn( $w ) => $w[0], $words ) );
		return substr( count( $words ) > 1 && strlen( $initials ) >= 3 ? $initials : ( $words[0] ?? '' ), 0, 12 );
	}

	/**
	 * Register a folder as an archive. The folder must be a sub-folder of the media storage root; the kind must be new. Returns an error message, or null when it is done.
	 */
	public static function register( string $pKind, string $pTop, string $pFolder, string $pNoun = 'topic' ): ?string {
		global $gBitSystem;
		$pKind = strtolower( trim( $pKind ) );
		$pTop = trim( $pTop );
		if( !preg_match( self::KIND_PATTERN, $pKind ) ) {
			return 'The short name must be 3 to 12 letters, a-z.';
		}
		if( in_array( $pKind, self::RESERVED, true ) || isset( self::all()[$pKind] ) ) {
			return 'That short name is already in use.';
		}
		if( $pTop === '' || $pTop !== basename( $pTop ) || str_contains( $pTop, '/' ) ) {
			return 'Give the top gallery a title.';
		}
		$disk = function_exists( '\\Bitweaver\\Liberty\\mime_film_get_storage_root' ) ? \Bitweaver\Liberty\mime_film_get_storage_root() : '';
		if( $disk === '' || $pFolder === '' || $pFolder !== basename( $pFolder ) || $pFolder[0] === '.' || !is_dir( rtrim( $disk, '/' ).'/'.$pFolder ) ) {
			return 'That folder was not found in the media storage folder.';
		}
		foreach( self::all() as $other ) {
			if( strcasecmp( $other['folder'], $pFolder ) === 0 ) {
				return 'That folder is already an archive ('.$other['top'].').';
			}
		}
		if( in_array( strtolower( $pFolder ), [ 'library', 'music', 'films', 'tv shows', 'oldlibrary', 'oldmusic' ], true ) ) {
			return 'That folder belongs to a media tree of its own.';
		}
		$entry = json_encode( [ 'top' => $pTop, 'folder' => $pFolder, 'noun' => in_array( $pNoun, [ 'topic', 'title', 'section' ], true ) ? $pNoun : 'topic' ] );
		$list = array_filter( array_map( 'trim', explode( ',', (string)$gBitSystem->getConfig( self::LIST_KEY, '' ) ) ) );
		$list[] = $pKind;
		$gBitSystem->storeConfig( self::ENTRY_PREFIX.$pKind, $entry, FISHEYEMEDIA_PKG_NAME );
		$gBitSystem->storeConfig( self::LIST_KEY, implode( ',', array_unique( $list ) ), FISHEYEMEDIA_PKG_NAME );
		self::$kept = null;
		return null;
	}

	/** Take an archive out of the registry. Its galleries and items are not touched; the top gallery's kept id stays in the config. */
	public static function unregister( string $pKind ): bool {
		global $gBitSystem;
		if( !isset( self::registered()[$pKind] ) ) {
			return false;
		}
		$list = array_diff( array_filter( array_map( 'trim', explode( ',', (string)$gBitSystem->getConfig( self::LIST_KEY, '' ) ) ) ), [ $pKind ] );
		$gBitSystem->storeConfig( self::LIST_KEY, implode( ',', $list ), FISHEYEMEDIA_PKG_NAME );
		$gBitSystem->storeConfig( self::ENTRY_PREFIX.$pKind, null, FISHEYEMEDIA_PKG_NAME );
		self::$kept = null;
		return true;
	}
}

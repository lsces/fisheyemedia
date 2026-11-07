<?php
/**
 * One section of a video course in the Local Tutorials library - a FisheyeSeason with its own content type, so the xref scheme for tutorials
 * (Tutorial Details, Lessons, Extras - no cast, characters or featurettes) is its own set of rows in the media scheme, edited like any other.
 * What it adds to a TV season is the files that go with its lessons (exercise archives, notes, handouts): registered from the section's folder as
 * 'extra' rows holding only the path, and served from where they are by view_season_file.php - never copied into storage.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

define( 'FISHEYESECTION_CONTENT_TYPE_GUID', 'fisheyesection' );

class FisheyeSection extends FisheyeSeason {
	use FisheyeTutorialFilesTrait;

	public function __construct( $pImageId = null, $pContentId = null ) {
		parent::__construct( $pImageId, $pContentId );
		$this->mContentTypeGuid = FISHEYESECTION_CONTENT_TYPE_GUID;
		$this->registerContentType( FISHEYESECTION_CONTENT_TYPE_GUID, [
			'content_type_guid' => FISHEYESECTION_CONTENT_TYPE_GUID,
			'content_name'      => 'Tutorial Section',
			'handler_class'     => 'FisheyeSection',
			'handler_package'   => 'fisheyemedia',
			'handler_file'      => 'FisheyeSection.php',
			'maintainer_url'    => 'https://www.bitweaver.org',
		] );
	}

	/** A section has its own edit page: edit_season.php is the TV season's, Plex-shaped. */
	public function getEditUrl( $pContentId = null, $pMixed = null ) {
		$contentId = \Bitweaver\BitBase::verifyId( $pContentId ) ? $pContentId : $this->mContentId;
		$ret = FISHEYEMEDIA_PKG_URL.'edit_section.php?content_id='.$contentId;
		foreach( (array)$pMixed as $key => $value ) {
			if( $key !== 'content_id' ) {
				$ret .= '&'.urlencode( (string)$key ).'='.urlencode( (string)$value );
			}
		}
		return $ret;
	}

	/** Extensions that are the course's lessons or their subtitles, not files that go with it. */
	private const NOT_EXTRA_FILES = [ 'mkv', 'mp4', 'm4v', 'avi', 'srt', 'vtt' ];

	/**
	 * The files in this season's folder that are not lessons: everything under it (exercise sub-folders included) but the videos, subtitles, a
	 * Featurettes folder and hidden or system files, in natural order.
	 *
	 * @return list<array{rel:string,name:string,ext:string,bytes:int}>  rel is under the season's folder
	 */
	public function scanExtraFiles(): array {
		$dirInfo = $this->resolveSeasonDirectoryFromDisk();
		return $dirInfo ? self::scanExtraFilesIn( $dirInfo['dir'] ) : [];
	}

	/** scanExtraFiles() for a season folder given by path (absolute, closing slash) - what the tutorial workbench counts without loading every season. */
	public static function scanExtraFilesIn( string $pDir ): array {
		$found = [];
		$walk = function( string $pRel ) use ( &$walk, &$found, $pDir ) {
			$names = array_diff( scandir( $pDir.$pRel ) ?: [], [ '.', '..' ] );
			natcasesort( $names );
			foreach( $names as $name ) {
				if( $name[0] === '.' || strtolower( $name ) === 'thumbs.db' || strtolower( $name ) === 'featurettes' ) {
					continue;
				}
				if( is_dir( $pDir.$pRel.$name ) ) {
					$walk( $pRel.$name.'/' );
					continue;
				}
				$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
				if( !in_array( $ext, self::NOT_EXTRA_FILES, true ) ) {
					$found[] = [ 'rel' => $pRel.$name, 'name' => $name, 'ext' => $ext, 'bytes' => (int)filesize( $pDir.$pRel.$name ) ];
				}
			}
		};
		if( is_dir( $pDir ) ) {
			$walk( '' );
		}
		return $found;
	}

	/**
	 * Rebuild this season's 'extra' rows from its folder (see scanExtraFiles()): one row per file, xkey_ext the path under the tutorials root,
	 * served by view_season_file.php by xref_id only. A season with no folder found keeps what it has.
	 *
	 * @return array{files:int,errors:list<string>}
	 */
	public function registerFilesFromDisk(): array {
		$dirInfo = $this->resolveSeasonDirectoryFromDisk();
		if( !$dirInfo ) {
			return [ 'files' => 0, 'errors' => [] ];
		}
		if( !self::extraItemDefinedFor( FISHEYESECTION_CONTENT_TYPE_GUID ) ) {
			return [ 'files' => 0, 'errors' => [ 'The media scheme has no "extra" item for tutorial sections yet: apply it from the Local Scheme page.' ] ];
		}
		return $this->storeExtraFileRows( $dirInfo['relative'], $this->scanExtraFiles() );
	}

	/** Whether the site's media scheme has the tutorial section's 'extra' item (see extraItemDefinedFor()). */
	public static function extraItemDefined(): bool {
		return self::extraItemDefinedFor( FISHEYESECTION_CONTENT_TYPE_GUID );
	}

	/** Whether a file name is one that goes with the lessons (not a video or subtitle, not hidden or a system file). */
	public static function isExtraFileName( string $pName ): bool {
		return $pName !== '' && $pName[0] !== '.' && strtolower( $pName ) !== 'thumbs.db'
			&& !in_array( strtolower( pathinfo( $pName, PATHINFO_EXTENSION ) ), self::NOT_EXTRA_FILES, true );
	}

	/** The lesson video extensions. */
	private const LESSON_VIDEO = [ 'mkv', 'mp4', 'm4v', 'avi' ];

	/** Whether a folder holds at least one lesson video, at any depth: a folder that does is a section, one that does not holds files that go with the course. */
	public static function folderHasLessons( string $pDir ): bool {
		foreach( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $pDir, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if( $file->isFile() && in_array( strtolower( $file->getExtension() ), self::LESSON_VIDEO, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** The real path of a file registered under xkey_ext, only when it lies under the tutorials root; null otherwise. */
	public static function extraFilePath( string $pXkeyExt ): ?string {
		$root = realpath( FisheyeProgram::getTutorialsRoot() );
		if( !$root || $pXkeyExt === '' || str_contains( $pXkeyExt, '..' ) ) {
			return null;
		}
		$real = realpath( FisheyeProgram::getTutorialsRoot().$pXkeyExt );
		return $real && is_file( $real ) && str_starts_with( $real, $root.'/' ) ? $real : null;
	}

}

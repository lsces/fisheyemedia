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

	/** The Extras button counts the section's files along with its pictures. */
	public function getExtrasCounts(): array {
		$counts = parent::getExtrasCounts();
		$vis = $this->getVisibleXrefSql( 'x' );
		$counts['extras'] += (int)$this->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` x WHERE x.`content_id` = ? AND x.`item` = 'extra' AND x.`end_date` IS NULL".$vis['where_sql'], [ $this->mContentId, ...$vis['bind_vars'] ] );
		return $counts;
	}

	/** The section's files lead the plain Extras strip, ahead of its pictures. */
	public function getExtrasGroups(): array {
		$extras = parent::getExtrasGroups();
		if( $files = $this->getExtraFileItems() ) {
			foreach( $extras['groups'] as &$group ) {
				if( $group['id'] === 'pictures' ) {
					$group['items'] = array_merge( $files, $group['items'] );
					unset( $group );
					return $extras;
				}
			}
			unset( $group );
			$extras['groups'][] = [ 'id' => 'pictures', 'kind' => 'Extras', 'items' => $files, 'links' => [] ];
		}
		return $extras;
	}

	/** The season's files (exercise archives, notes) as Extras items: the file type's own icon, the file name, and the download link. */
	private function getExtraFileItems(): array {
		$vis = $this->getVisibleXrefSql( 'x' );
		$rows = $this->mDb->getAll( "SELECT x.`xref_id`, x.`xkey_ext`, x.`data` FROM `".BIT_DB_PREFIX."liberty_xref` x WHERE x.`content_id` = ? AND x.`item` = 'extra' AND x.`end_date` IS NULL".$vis['where_sql']." ORDER BY x.`xorder`", [ $this->mContentId, ...$vis['bind_vars'] ] ) ?: [];
		$items = [];
		foreach( $rows as $row ) {
			$data = json_decode( (string)$row['data'], true ) ?: [];
			$name = basename( (string)$row['xkey_ext'] );
			$items[] = [ 'thumb' => $this->getFileThumbUrl( (int)$row['xref_id'], $name ), 'url' => FISHEYEMEDIA_PKG_URL.'view_season_file.php?xref_id='.(int)$row['xref_id'], 'title' => $name,
				'label' => strtoupper( (string)( $data['format'] ?? '' ) ).( !empty( $data['size'] ) ? ' '.$data['size'] : '' ) ];
		}
		return $items;
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
		$summary = [ 'files' => 0, 'errors' => [] ];
		$dirInfo = $this->resolveSeasonDirectoryFromDisk();
		if( !$dirInfo ) {
			return $summary;
		}
		if( !self::extraItemDefined() ) {
			$summary['errors'][] = 'The media scheme has no "extra" item for tutorial sections yet: apply it from the Local Scheme page.';
			return $summary;
		}
		$files = $this->scanExtraFiles();
		\Bitweaver\Liberty\LibertyContent::deleteXrefByItem( (int)$this->mContentId, [ 'extra' ] );
		foreach( $files as $order => $file ) {
			$size = $file['bytes'] >= 1048576 ? round( $file['bytes'] / 1048576, 1 ).' MB' : max( 1, (int)round( $file['bytes'] / 1024 ) ).' KB';
			$hash = [ 'content_id' => $this->mContentId, 'item' => 'extra', 'xkey_ext' => $dirInfo['relative'].'/'.$file['rel'],
				'edit' => json_encode( [ 'format' => $file['ext'], 'size' => $size, 'note' => '' ] ), 'xorder' => $order + 1 ];
			if( $this->storeXref( $hash ) ) {
				$summary['files']++;
			} else {
				$summary['errors'][] = $file['rel'].': '.implode( '; ', array_map( 'strval', array_values( $this->mErrors ) ) );
			}
		}
		return $summary;
	}

	/** Whether the site's media scheme has the tutorial section's 'extra' item - without it strict xref refuses every file row (apply the scheme from the Local Scheme page). */
	public static function extraItemDefined(): bool {
		global $gBitDb;
		return (bool)$gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref_item` WHERE `content_type_guid` = 'fisheyesection' AND `item` = 'extra'" );
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

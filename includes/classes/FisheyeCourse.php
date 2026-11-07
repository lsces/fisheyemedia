<?php
/**
 * A video course in the Local Tutorials library - a FisheyeProgram with its own content type, so the xref scheme for tutorials (Tutorial Details,
 * Extras - no cast) is its own set of rows in the media scheme, edited like any other. The course's folder is Local Tutorials/<title>/, its
 * sections FisheyeSection (see FisheyeProgram::locateShowFolder()).
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

define( 'FISHEYECOURSE_CONTENT_TYPE_GUID', 'fisheyecourse' );

class FisheyeCourse extends FisheyeProgram {
	use FisheyeTutorialFilesTrait;

	public function __construct( $pGalleryId = null, $pContentId = null ) {
		parent::__construct( $pGalleryId, $pContentId );
		$this->mContentTypeGuid = FISHEYECOURSE_CONTENT_TYPE_GUID;
		$this->registerContentType( FISHEYECOURSE_CONTENT_TYPE_GUID, [
			'content_type_guid' => FISHEYECOURSE_CONTENT_TYPE_GUID,
			'content_name'      => 'Tutorial Course',
			'handler_class'     => 'FisheyeCourse',
			'handler_package'   => 'fisheyemedia',
			'handler_file'      => 'FisheyeCourse.php',
			'maintainer_url'    => 'https://www.bitweaver.org',
		] );
	}

	/** A course has its own edit page: edit_program.php is the TV show's, Plex-shaped. */
	public function getEditUrl( $pContentId = null, $pMixed = null ) {
		$contentId = \Bitweaver\BitBase::verifyId( $pContentId ) ? $pContentId : $this->mContentId;
		$ret = FISHEYEMEDIA_PKG_URL.'edit_course.php?content_id='.$contentId;
		foreach( (array)$pMixed as $key => $value ) {
			if( $key !== 'content_id' ) {
				$ret .= '&'.urlencode( (string)$key ).'='.urlencode( (string)$value );
			}
		}
		return $ret;
	}

	/** The course's folder under the tutorials root: [ 'dir' => absolute with closing slash, 'relative' => 'Local Tutorials/<title>' ], or null when it is not there. */
	public function courseFolder(): ?array {
		$root = FisheyeProgram::getTutorialsRoot();
		$relative = FisheyeProgram::TUTORIALS_FOLDER.'/'.$this->getTitle();
		return $root !== '' && $this->getTitle() !== '' && is_dir( $root.$relative ) ? [ 'dir' => $root.$relative.'/', 'relative' => $relative ] : null;
	}

	/**
	 * The files that belong to the whole course, not to one of its sections: those loose in the course folder, and everything inside a folder of it that
	 * holds no lesson video (the project files of a course, its exercise files). Videos and subtitles are not files here, as for a section.
	 *
	 * @return list<array{rel:string,name:string,ext:string,bytes:int}>  rel is under the course folder
	 */
	public static function scanFilesIn( string $pCourseDir ): array {
		$found = [];
		$names = array_diff( is_dir( $pCourseDir ) ? ( scandir( $pCourseDir ) ?: [] ) : [], [ '.', '..' ] );
		natcasesort( $names );
		foreach( $names as $name ) {
			if( $name[0] === '.' ) {
				continue;
			}
			if( is_dir( $pCourseDir.$name ) ) {
				if( !FisheyeSection::folderHasLessons( $pCourseDir.$name ) ) {
					foreach( FisheyeSection::scanExtraFilesIn( $pCourseDir.$name.'/' ) as $file ) {
						$found[] = [ 'rel' => $name.'/'.$file['rel'] ] + $file;
					}
				}
				continue;
			}
			// a loose file: the same test as inside a section
			if( FisheyeSection::isExtraFileName( $name ) ) {
				$found[] = [ 'rel' => $name, 'name' => $name, 'ext' => strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), 'bytes' => (int)filesize( $pCourseDir.$name ) ];
			}
		}
		return $found;
	}

	/** Rebuild the course's own 'extra' rows from its folder (see scanFilesIn()). A course whose folder is not found keeps what it has. */
	public function registerFilesFromDisk(): array {
		$folder = $this->courseFolder();
		if( !$folder ) {
			return [ 'files' => 0, 'errors' => [] ];
		}
		if( !self::extraItemDefinedFor( FISHEYECOURSE_CONTENT_TYPE_GUID ) ) {
			return [ 'files' => 0, 'errors' => [ 'The media scheme has no "extra" item for tutorial courses yet: apply it from the Local Scheme page.' ] ];
		}
		return $this->storeExtraFileRows( $folder['relative'], self::scanFilesIn( $folder['dir'] ) );
	}
}

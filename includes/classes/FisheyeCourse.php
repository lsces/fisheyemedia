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
}

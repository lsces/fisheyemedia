<?php
/**
 * Magazine Issue - one issue of a magazine, a FisheyeMediaImage with its own content type so issues are found, listed and searched as Magazine
 * Issues, not as the plain Images they were. The issue's PDF is kept where it is (the mimeextpdf plugin, see FisheyeDocsWorkbench) and its text is in
 * liberty_content_data for search; the title is the file name until a magazine/issue scheme gives it a proper issue number and date.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

define( 'FISHEYEISSUE_CONTENT_TYPE_GUID', 'fisheyeissue' );

class FisheyeIssue extends FisheyeMediaImage {

	public function __construct( $pImageId = null, $pContentId = null ) {
		parent::__construct( $pImageId, $pContentId );
		$this->mContentTypeGuid = FISHEYEISSUE_CONTENT_TYPE_GUID;
		$this->registerContentType( FISHEYEISSUE_CONTENT_TYPE_GUID, [
			'content_type_guid' => FISHEYEISSUE_CONTENT_TYPE_GUID,
			'content_name'      => 'Magazine Issue',
			'handler_class'     => 'FisheyeIssue',
			'handler_package'   => 'fisheyemedia',
			'handler_file'      => 'FisheyeIssue.php',
			'maintainer_url'    => 'https://www.bitweaver.org',
		] );
	}
}

<?php
/**
 * Service functions fisheyemedia registers (see bit_setup_inc.php). LibertyContent::invokeServices() calls a service by its name inside
 * the Bitweaver\Liberty namespace, so they are declared there.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Liberty;

use Bitweaver\Fisheyemedia\FisheyeMediaGallery;

/**
 * A gallery shown with the Images Grid layout (an item's pictures folder) is given {$folderOwner} - the item it belongs to - for its
 * title to link to. Runs for every content display, so it leaves anything else alone at once.
 */
function fisheyemedia_content_display( $pObject, &$pParamHash ) {
	if( $pObject->isValid() && $pObject->getContentType() === 'fisheyegallery' && $pObject->getLayout() === FISHEYE_PAGINATION_IMAGES_GRID ) {
		global $gBitSmarty;
		$gBitSmarty->assign( 'folderOwner', FisheyeMediaGallery::getPicturesFolderOwner( $pObject ) );
	}
	// A gallery filed with the Library Grid layout is an author or series whatever its content type - galleries made by hand are plain
	// 'fisheyegallery' - so the layout, not the class, decides. {$libraryGallery} is that gallery as a FisheyeMediaGallery, which carries
	// the author's contact, the Full Series link and the categorized strips.
	if( $pObject->isValid() && method_exists( $pObject, 'getLayout' ) && $pObject->getLayout() === FISHEYE_PAGINATION_LIBRARY_GRID ) {
		global $gBitSmarty;
		if( method_exists( $pObject, 'getAuthorContactUrl' ) ) {
			$gBitSmarty->assign( 'libraryGallery', $pObject );
		} else {
			$libraryGallery = new FisheyeMediaGallery( $pObject->mGalleryId, $pObject->mContentId );
			$libraryGallery->load();
			$gBitSmarty->assign( 'libraryGallery', $libraryGallery );
		}
	}
}

/**
 * Image Order's Copy/Move-to-gallery pickers list every gallery; ordering a Library Grid gallery (an author, series or book list) offers
 * only other Library Grid galleries. Literal values in the join: the list's own binds come ahead of a service join's. Runs for every content list, so it does nothing outside that one page.
 */
function fisheyemedia_content_list_sql( $pObject, $pParamHash = null ) {
	global $gContent;
	if( basename( $_SERVER['SCRIPT_NAME'] ?? '' ) !== 'image_order.php' || !is_object( $gContent ) || !method_exists( $gContent, 'getLayout' )
		|| !$gContent->isValid() || $gContent->getLayout() !== FISHEYE_PAGINATION_LIBRARY_GRID ) {
		return [];
	}
	// loadImages() asks the same service for the gallery's own items, which must all stay - only the gallery picker (getList) is narrowed.
	$picker = false;
	foreach( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 6 ) as $frame ) {
		if( ( $frame['function'] ?? '' ) === 'getList' ) {
			$picker = true;
			break;
		}
	}
	if( !$picker ) {
		return [];
	}
	return [
		'join_sql'  => " INNER JOIN `".BIT_DB_PREFIX."liberty_content_prefs` fmlg ON( fmlg.`content_id`=lc.`content_id` AND fmlg.`pref_name`='gallery_pagination' AND fmlg.`pref_value`='".FISHEYE_PAGINATION_LIBRARY_GRID."' ) ",
	];
}

/**
 * Pictures uploaded into an item's pictures folder: the first becomes the item's thumbnail when it has none (see
 * FisheyeMediaTrait::folderImageAdded()). Runs for every upload, so a gallery that is not a pictures folder is left alone at once.
 */
function fisheyemedia_content_post_upload( $pGallery, &$pParamHash ) {
	$uploaded = reset( $pParamHash['uploaded_objects'] );
	if( !$pGallery->isValid() || $pGallery->getContentType() !== 'fisheyegallery' || !$uploaded || empty( $uploaded->mContentId ) ) {
		return;
	}
	$values = $pGallery->mDb->getCol(
		"SELECT `pref_value` FROM `".BIT_DB_PREFIX."liberty_content_prefs` WHERE `content_id` = ? AND `pref_name` LIKE '%!_content!_id' ESCAPE '!'",
		[ $pGallery->mContentId ]
	) ?: [];
	foreach( $values as $value ) {
		if( !ctype_digit( (string)$value ) || (int)$value === (int)$pGallery->mContentId ) {
			continue;
		}
		$owner = LibertyContent::getLibertyObject( (int)$value );
		if( $owner && $owner->isValid() && method_exists( $owner, 'folderImageAdded' ) ) {
			$owner->folderImageAdded( (int)$uploaded->mContentId );
			return;
		}
	}
}

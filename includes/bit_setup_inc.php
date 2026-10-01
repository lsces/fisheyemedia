<?php
global $gBitSystem;

$pRegisterHash = [
	'package_name' => 'fisheyemedia',
	'package_path' => dirname( dirname( __FILE__ ) ).'/',
	'homeable'     => false,
];
// fix to quieten down VS Code which can't see the dynamic creation of these ...
define( 'FISHEYEMEDIA_PKG_NAME', $pRegisterHash['package_name'] );
define( 'FISHEYEMEDIA_PKG_URL', BIT_ROOT_URL . basename( $pRegisterHash['package_path'] ) . '/' );
define( 'FISHEYEMEDIA_PKG_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/' );
define( 'FISHEYEMEDIA_PKG_INCLUDE_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/includes/');
define( 'FISHEYEMEDIA_PKG_CLASS_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/includes/classes/');
define( 'FISHEYEMEDIA_PKG_ADMIN_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/admin/');

$gBitSystem->registerPackage( $pRegisterHash );

// fisheyemedia extends fisheye's own classes - nothing here can run before fisheye's own
// bit_setup_inc.php has registered it. registerPackage() has no built-in "requires" concept
// (checked BitSystem::registerPackage() directly), so this is a manual guard.
if( !$gBitSystem->isPackageActive( 'fisheye' ) ) {
	return;
}

if( $gBitSystem->isPackageActive( 'fisheyemedia' ) ) {

	// Content-type-specific grid variants - same rows*cols grid pagination as fixed_grid, own
	// gallery_views/ template (still under base fisheye, pure presentation, harmless if unused -
	// see fisheyemedia.md) so each swaps its floaticon set for the type's own workflow.
	define( 'FISHEYE_PAGINATION_FILM_GRID', 'film_grid' );
	define( 'FISHEYE_PAGINATION_PROGRAM_GRID', 'program_grid' );
	define( 'FISHEYE_PAGINATION_MUSIC_GRID', 'music_grid' );
	// Title of the nested gallery load_video.php keeps an artist's videos in - getCategorizedItems()
	// lists its contents as the artist page's own Videos strip.
	define( 'FISHEYEMEDIA_VIDEOS_GALLERY_TITLE', 'Videos' );

	global $gLibertySystem;

	// Register sub-type content types at startup so getLibertyObject() can resolve them.
	// registerContentType() is a no-op in memory once the row exists in the DB - same pattern
	// contact's own bit_setup_inc.php uses for ContactPerson/ContactBusiness.
	$gLibertySystem->registerContentType( 'fisheyefilm', [
		'content_type_guid' => 'fisheyefilm',
		'content_name'      => 'Film',
		'handler_class'     => 'FisheyeFilm',
		'handler_package'   => 'fisheyemedia',
		'handler_file'      => 'FisheyeFilm.php',
	] );
	$gLibertySystem->registerContentType( 'fisheyeseason', [
		'content_type_guid' => 'fisheyeseason',
		'content_name'      => 'TV Season',
		'handler_class'     => 'FisheyeSeason',
		'handler_package'   => 'fisheyemedia',
		'handler_file'      => 'FisheyeSeason.php',
	] );
	$gLibertySystem->registerContentType( 'fisheyealbum', [
		'content_type_guid' => 'fisheyealbum',
		'content_name'      => 'Music Album',
		'handler_class'     => 'FisheyeAlbum',
		'handler_package'   => 'fisheyemedia',
		'handler_file'      => 'FisheyeAlbum.php',
	] );
	// FisheyeProgram extends FisheyeMediaGallery (not FisheyeMediaImage) - the show level, a
	// phantom subclass so a show can carry its own metadata + a genuinely selected thumbnail
	// while remaining a real gallery (still holds its seasons via addItem()/loadImages()).
	$gLibertySystem->registerContentType( 'fisheyeprogram', [
		'content_type_guid' => 'fisheyeprogram',
		'content_name'      => 'TV Show',
		'handler_class'     => 'FisheyeProgram',
		'handler_package'   => 'fisheyemedia',
		'handler_file'      => 'FisheyeProgram.php',
	] );

	// Contributes the three grid layouts above into FisheyeGallery via the generic
	// registerService()/getServiceValues() extension point - any package could do the same for
	// its own layout, this isn't special-cased to fisheyemedia in the base package. 'layouts'
	// feeds the admin layout picker (getAllLayouts()); 'grid_pagination' tells FisheyeGallery
	// which layout values use rows*cols grid pagination math (loadImages()) and what their
	// row/col counts are (verifyGalleryData()); 'package' tells it which package's own
	// gallery_views/ folder to look in for these layouts' render templates
	// (getGalleryViewsPath()) - base fisheye has no hardcoded knowledge of film_grid/
	// program_grid/music_grid, or of the fisheyemedia package name, anywhere in its own code.
	$gLibertySystem->registerService( 'fisheye_gallery_layout', FISHEYEMEDIA_PKG_NAME, [
		'package' => FISHEYEMEDIA_PKG_NAME,
		'layouts' => [
			FISHEYE_PAGINATION_FILM_GRID    => 'Film Grid',
			FISHEYE_PAGINATION_PROGRAM_GRID => 'TV Show Grid',
			FISHEYE_PAGINATION_MUSIC_GRID   => 'Music Grid',
		],
		// rows/cols per the 2026-09-24 tuning (film/program trimmed to 3*8; music_grid keeps
		// 4*8 stored even though its own template no longer paginates - see
		// FisheyeGallery::getCategorizedItems()'s docblock).
		'grid_pagination' => [
			FISHEYE_PAGINATION_FILM_GRID    => [ 'rows' => 3, 'cols' => 8 ],
			FISHEYE_PAGINATION_PROGRAM_GRID => [ 'rows' => 3, 'cols' => 8 ],
			FISHEYE_PAGINATION_MUSIC_GRID   => [ 'rows' => 4, 'cols' => 8 ],
		],
	] );

	// Films/Music/TV Shows/Library jumps inside fisheye's own menu - fisheye's menu_fisheye.tpl
	// includes every 'fisheye_menu_tpl' generically, same arrangement as contactwiki's section of
	// contact's menu. Own service name: registerService() keys by it, so sharing
	// 'fisheye_gallery_layout' above would overwrite that registration.
	$gLibertySystem->registerService( FISHEYEMEDIA_PKG_NAME, FISHEYEMEDIA_PKG_NAME, [
		'fisheye_menu_tpl' => 'bitpackage:fisheyemedia/fisheye_menu_inc.tpl',
	] );
}

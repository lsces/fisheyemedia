<?php

// No tables of its own - Film/Season/Program/Album reuse fisheye's own fisheye_gallery/
// fisheye_image tables (they're subclasses of FisheyeGallery/FisheyeImage, not new content
// shapes). No liberty_xref_group/liberty_xref_item defaults registered here either - not a
// generic feature every install with fisheyemedia active should get, applied privately per-site
// instead via LibertyXrefScheme::apply() (liberty).

global $gBitInstaller;

$gBitInstaller->registerPackageInfo( FISHEYEMEDIA_PKG_NAME, [
	'description' => 'Plex-backed Film/TV Show/Music cataloguing, extending fisheye with Film/Season/Program/Album content types.',
	'license'     => '<a href="http://www.gnu.org/licenses/licenses.html#LGPL">LGPL</a>',
] );

$gBitInstaller->registerRequirements( FISHEYEMEDIA_PKG_NAME, [
	'liberty' => [ 'min' => '5.0.0' ],
	'fisheye' => [ 'min' => '5.0.0' ],
]);

$gBitInstaller->registerContentObjects( FISHEYEMEDIA_PKG_NAME, [
	'FisheyeFilm'=>FISHEYEMEDIA_PKG_CLASS_PATH.'FisheyeFilm.php',
	'FisheyeSeason'=>FISHEYEMEDIA_PKG_CLASS_PATH.'FisheyeSeason.php',
	'FisheyeAlbum'=>FISHEYEMEDIA_PKG_CLASS_PATH.'FisheyeAlbum.php',
	'FisheyeProgram'=>FISHEYEMEDIA_PKG_CLASS_PATH.'FisheyeProgram.php',
] );

// ### Default User Permissions
// fisheye's own permissions still gate the actual Film/TV/Music load/edit/view flows - these are
// fisheye galleries/images like any other, no need for a duplicate permission concept.
// p_fisheyemedia_admin is only for this package's own admin pages (the admin menu itself is gated
// on 'p_'.$package.'_admin').
$gBitInstaller->registerUserPermissions( FISHEYEMEDIA_PKG_NAME, [
	[ 'p_fisheyemedia_admin', 'Can admin Fisheye Media settings', 'admin', FISHEYEMEDIA_PKG_NAME ],
] );

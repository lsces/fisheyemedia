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

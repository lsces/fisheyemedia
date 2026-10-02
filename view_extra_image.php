<?php
/**
 * Streams one of a film/season's own alternate images (FisheyeSeason::reloadPlexImages()'s
 * shared images/ folder under the TV storage root, a season's own per-episode Plex thumb, or
 * FisheyeFilm's own downloaded Plex alternates, which live in storage/attachments/<branch>/
 * instead) - a small PHP-mediated server, same "no nginx location
 * for that tree yet" situation mime_film_download() is already in for the external tree (see
 * mime.film.php's own note on this).
 *
 * Takes xref_id only, never a raw path - the file actually served is always exactly what's
 * already stored server-side against that xref row, resolved after confirming the row is really
 * an 'image' or 'episode' item, so there is no path-traversal surface: nothing here ever builds a
 * filesystem path from user-supplied text. Resolved via the owning content object's own
 * getExtraImagePath() (FisheyeBase's default is getImageStorageRoot()-relative, correct for
 * Season/Program; FisheyeFilm overrides it for its own different storage location) rather than
 * building the path here directly - a season's own images/episode thumbs live under the
 * TV-specific root, not the plain film one; an install where both roots happen to resolve to the
 * same physical location can mask a real bug here that shows up the moment they diverge, the same
 * category of mistake found earlier in edit_xref.php.
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\HttpStatusCodes;
use Bitweaver\Fisheye\FisheyeImage;

require_once '../kernel/includes/setup_inc.php';
global $gBitSystem, $gBitDb;

$gBitSystem->verifyPackage( 'fisheyemedia' );

$xrefId = (int)( $_REQUEST['xref_id'] ?? 0 );
$row = $xrefId ? $gBitDb->getRow(
	"SELECT content_id, item, xkey_ext, data FROM `".BIT_DB_PREFIX."liberty_xref` WHERE xref_id = ? AND item IN ('image','episode','featurette')",
	[ $xrefId ]
) : null;
if( !$row ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No such image' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

$gContent = FisheyeImage::lookup( [ 'content_id' => $row['content_id'] ] );
if( !$gContent || !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No such image' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
// same viewer permission as the film/season itself - an alternate poster is no more sensitive
// than the film's own primary artwork, but shouldn't bypass a private gallery's access control.
$gContent->verifyViewPermission();

// an 'episode'/'featurette' row's own xkey_ext is its real video file - the image to serve here
// is the thumb path stashed in its JSON data blob instead (see FisheyeSeason::
// reloadPlexEpisodes()'s and FisheyeBase::registerFeaturettesFromFolder()'s own 'thumb' keys).
if( $row['item'] === 'episode' || $row['item'] === 'featurette' ) {
	$itemData = !empty( $row['data'] ) ? json_decode( $row['data'], true ) : [];
	$relativePath = $itemData['thumb'] ?? null;
} else {
	$relativePath = $row['xkey_ext'];
}
if( empty( $relativePath ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No such image' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

$path = $gContent->getExtraImagePath( $relativePath );
if( empty( $path ) || !is_file( $path ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'Image file not found' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

header( 'Content-Type: '.$gBitSystem->verifyMimeType( $path ) );
header( 'Content-Length: '.filesize( $path ) );
header( 'Cache-Control: private, max-age=86400' );
while( ob_get_level() > 0 ) {
	ob_end_clean();
}
readfile( $path );

<?php
/**
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeImage;

/**
 * Intermediate layer between base fisheye's FisheyeImage and the media content types that need
 * leaf/attached-file semantics (FisheyeFilm, FisheyeSeason, FisheyeAlbum) - holds logic that only
 * makes sense once media is involved, kept off FisheyeImage itself so a plain photo attachment
 * never carries it.
 *
 * @package fisheyemedia
 */
#[\AllowDynamicProperties]
class FisheyeMediaImage extends FisheyeImage {
	use FisheyeMediaTrait;
}

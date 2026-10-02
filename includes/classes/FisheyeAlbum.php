<?php
/**
 * Music album — extends FisheyeImage with content_type_guid='fisheyealbum'.
 *
 * The real, folder-leaf content_id in the artist->album->track tree (an "artist"/"composer"
 * itself has no content_id at all - it's a computed browsing level over its
 * albums, not a stored one, not yet built). Ring-fences album-level metadata
 * (artist/composer, MusicBrainz+Discogs links) plus the TRACK xref item away from plain
 * fisheyeimage photo rows and from FisheyeFilm/FisheyeSeason's own item sets - no other
 * behavioural difference from FisheyeImage, same pattern as Contact/ContactPerson/ContactBusiness.
 *
 * Track (mirrors Season's own Episode) is a raw 'track' liberty_xref row - xkey_ext is the file's
 * own path relative to fisheye_disk_storage_root, not a real LibertyMime attachment (there's no
 * per-track file management need beyond streaming it, same reasoning as episodes). The album's
 * own cover image DOES use a real thumbnail attachment though (attachThumbnail() below, same
 * private method FisheyeSeason/FisheyeProgram each already carry their own copy of) - unlike a
 * season, an album commonly already has a real cover.jpg/folder.jpg sitting in its own folder, so
 * this reads directly off disk rather than needing a Plex-fetch round trip first.
 *
 * Embedded per-track tags (real ffprobe format_tags - TITLE/ARTIST/ALBUM/track/disc, and for a
 * well-tagged classical release, full MUSICBRAINZ_* IDs) are the primary metadata source, not
 * Plex - confirmed live 2026-09-05 against a Classic Composers release that Plex's own matching
 * had mishandled (composer/performer attribution) despite the files themselves being properly
 * tagged via MusicBrainz already. Plex is a supplementary cross-reference only here, not the
 * source of truth the Film/TV side treats it as.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;

// mime_film_get_storage_root() below is only auto-loaded via the LibertyMime attachment-plugin
// dispatch, which never fires for an album (no LibertyMime attachment of its own - see this
// class's own docblock). Same fix as FisheyeProgram.php/FisheyeSeason.php/FisheyeFilm.php.
require_once dirname( __DIR__, 3 ).'/liberty/plugins/mime.film.php';

define( 'FISHEYEALBUM_CONTENT_TYPE_GUID', 'fisheyealbum' );

const FISHEYEALBUM_TRACK_EXTENSIONS = [ 'mp3', 'flac', 'm4a', 'ogg', 'wav' ];
const FISHEYEALBUM_COVER_NAMES = [ 'cover.jpg', 'folder.jpg', 'front.jpg', 'cover.png', 'folder.png' ];
// A box set's own per-release subfolder naming - CDxx for a set of otherwise-anonymous discs
// (Stravinsky's "Works of Igor Stravinsky", CD01..CD22), or Volume/Vol. N when each one already
// has a real distinguishing name of its own (Pachelbel's "Joseph Payne - 10 CD" - despite the
// parent folder's own "10 CD" name, each "The Complete Organ Works, Volume N" is its own distinct
// MusicBrainz release with its own date, same as Beethoven's "Complete Beethoven Edition Vol. N"
// set turned out to be - not one shared box, confirmed by checking real tag data before assuming
// "Volume" always means this).
// Not anchored to the start - real folder names commonly lead with the release/performer name
// instead ("Alkan - Organ Works, Vol. 1 - Bowyer", "The Complete Organ Works, Volume 1"), not
// "CD01"-style bare disc numbering alone. Checked against real album titles that must NOT match
// ("CD Pool - Dance Hits...", "100 Hits Christmas") - the required digit immediately after CD/Vol
// keeps this from false-firing on ordinary prose.
const FISHEYEALBUM_DISC_FOLDER_PATTERN = '/(CD|Vol(ume)?\.?)\s*\d+/i';
// An artist/composer's own discography split by release type (Lester moving away from one flat
// "Discography" dumping-ground folder) - Studio/Live/Compilation/Remaster/Single etc.
// sit directly under the artist folder and themselves contain the real album folders, same
// "container, not an album itself" shape as a box set folder but not disc-numbered so
// FISHEYEALBUM_DISC_FOLDER_PATTERN doesn't (and shouldn't) match them. Fixed list rather than
// generic "any subfolder with no tracks of its own but real album subfolders inside" detection -
// deliberately chosen since a name-based list is simpler to reason about than a content-shape
// heuristic. Singular only, standardised naming going forward (Lester) - no
// "Albums"/plurals, and no separate EP category, EPs file under Single. Case-insensitive exact
// match against the whole folder name (see isCategoryFolder()) - not a substring/prefix match, so
// a real album title that happens to start with one of these words never false-fires.
const FISHEYEALBUM_CATEGORY_FOLDER_NAMES = [ 'studio', 'live', 'compilation', 'remaster', 'single', 'soundtrack', 'tribute', 'other' ];
// Neither album-level nor worth keeping per-track - dropped outright rather than promoted:
// ID3V2_PRIV.* are opaque binary loudness-normalization frames (MP3Gain/ReplayGain-adjacent), not
// human-readable metadata at all; TLEN (ID3v2 track length in ms) just duplicates the file's own
// real duration, already probed separately; SCRIPT is just the writing-system code (e.g. "Latn"),
// no display value; ALBUM duplicates the album object's own already-known title; TSO2/
// ALBUMARTISTSORT (raw ID3v2 frame and Vorbis spellings of the same "album artist sort order"
// concept) duplicate the value the 'artist' xref item already promotes; TRACKTOTAL/TOTALTRACKS and
// DISCTOTAL/TOTALDISCS (again two spellings each for the same two concepts) are just counts already
// implicit in how many track xrefs actually got stored, not real metadata to display; COMMENT/
// ID3V1COMMENT are ripper-tool artifacts (a matrix/pressing code, an "ExactAudioCopy v0.99pb4"
// version string) rather than human-authored notes, found live cluttering every single track row
// once the json-list view actually rendered this data for the first time.
const FISHEYEALBUM_IGNORED_TAG_KEYS = [
	'ID3V2_PRIV_PEAKVALUE', 'ID3V2_PRIV_AVERAGELEVEL', 'TLEN', 'SCRIPT', 'ALBUM', 'TSO2', 'ALBUMARTISTSORT',
	'TRACKTOTAL', 'TOTALTRACKS', 'DISCTOTAL', 'TOTALDISCS', 'COMMENT', 'ID3V1COMMENT',
	'REPLAYGAINALBUMGAIN', 'REPLAYGAINTRACKGAIN', 'REPLAYGAINALBUMPEAK', 'REPLAYGAINTRACKPEAK',
];

// Embedded tag name -> xref item, for tags that only ever make sense at album/disc level, not
// per-track. Only promoted to a real xref if the value is identical across every track on the
// album (see extractCommonTags()) - one that genuinely varies per track (an artist id on a
// various-artists compilation, a disc id on a multi-disc set) stays out of these and in each
// track's own data instead.
const FISHEYEALBUM_COMMON_TAG_MAP = [
	'GENRE'                      => 'genre',
	'CATALOGNUMBER'              => 'catalog_number',
	'BARCODE'                    => 'barcode',
	'MUSICBRAINZ_ALBUMID'        => 'mbid',
	'MUSICBRAINZ_RELEASEGROUPID' => 'mb_releasegroupid',
	'MUSICBRAINZ_DISCID'         => 'mb_discid',
	'ASIN'                       => 'asin',
	'COMPILATION'                => 'compilation',
];
// A handful of xref items have more than one possible tag name (taggers disagree between Vorbis-
// style, ID3v2/MusicBrainz-Picard-style, and raw ID3v2 4-letter frame IDs for the exact same
// concept, or a fallback makes sense) - first match wins, same preference order FisheyeSeason/
// FisheyeFilm's own Plex metadata already uses elsewhere for "prefer the more specific field".
const FISHEYEALBUM_COMMON_TAG_ALTERNATES = [
	// TORY is the raw ID3v2.3 frame id for "original release year" - same concept as ORIGINALYEAR.
	'release_date'   => [ 'DATE', 'ORIGINALDATE', 'ORIGINALYEAR', 'TORY' ],
	'mb_artistid'    => [ 'MUSICBRAINZ_ALBUMARTISTID', 'MUSICBRAINZ_ARTISTID' ],
	// TMED/IMED are two different taggers' own raw frame ids for "media type" - same concept as MEDIA.
	'format'         => [ 'MEDIA', 'TMED', 'IMED' ],
	// PUBLISHER (ID3v2 TPUB) and LABEL (Vorbis) - same "record label" concept, different naming.
	'label'          => [ 'LABEL', 'PUBLISHER' ],
	'artist'         => [ 'ALBUM_ARTIST', 'ARTIST' ],
	// ARTISTS/ARTISTSORT hold genuinely different per-credit information from plain ARTIST on a
	// various-artists compilation (each track's own performer, still correctly promoted away when
	// they don't actually vary - see extractCommonTags()'s own "only if identical across every
	// track" rule, same as everything else here); LANGUAGE is almost always album-wide too. Found
	// live cluttering every single track row identically once the json-list view actually rendered
	// this data for the first time - none of the three were wired into the promotion check at all
	// before, unlike plain ARTIST just above.
	'artists'        => [ 'ARTISTS' ],
	'artistsort'     => [ 'ARTISTSORT' ],
	'language'       => [ 'LANGUAGE' ],
	// IMUS is another non-standard tagger's own frame for composer, same concept as COMPOSER.
	'composer'       => [ 'COMPOSER', 'IMUS' ],
	'conductor'      => [ 'CONDUCTOR' ],
	// ICNT is another non-standard tagger's own frame for country, same concept as RELEASECOUNTRY.
	'country'        => [ 'RELEASECOUNTRY', 'MUSICBRAINZ_ALBUM_RELEASE_COUNTRY', 'ICNT' ],
	'release_type'   => [ 'RELEASETYPE', 'MUSICBRAINZ_ALBUM_TYPE' ],
	'release_status' => [ 'RELEASESTATUS', 'MUSICBRAINZ_ALBUM_STATUS' ],
];

// Album credits - one row per person, in the xref item for their job (media.php's own role items).
// Built by buildCredits(), never from a raw credit string.
const FISHEYEALBUM_CREDIT_ITEMS = [ 'artist', 'composer', 'conductor', 'orchestra', 'performer' ];
// Common-tag items the credit builder consumes instead of storing them as album rows of their own
// (still promoted, so the matching raw tags stay out of every track's data). The ones that aren't
// themselves credit items are retired - a reload archives any old row still carrying them.
const FISHEYEALBUM_CREDIT_SOURCE_ITEMS = [ 'artist', 'artists', 'artistsort', 'mb_artistid', 'composer', 'conductor' ];
const FISHEYEALBUM_RETIRED_ITEMS = [ 'artists', 'artistsort', 'mb_artistid' ];
// MusicBrainz's own special "Various Artists" artist - a compilation marker, not a person to credit.
const FISHEYEALBUM_MB_VARIOUS_ARTISTS = '89ad4ac3-39f7-470e-963a-56509c546377';

// getID3's own ID3v2 comment names -> the normalized key readTrackTags() stores, matching the name
// ffprobe used to produce for the same frame, so stored track data and every constant above keep
// working unchanged. '' drops the frame (a derived track total, TMCL which no v2.3 file here
// carries, MCDI's binary TOC, WXXX links, lyrics). 'comment' is read from the raw COMM frames instead (see
// readTrackTags()), and TDAT (DDMM) is folded back into DATE there. Anything not listed falls through as normalizeTagKey($name).
const FISHEYEALBUM_ID3V2_KEY_MAP = [
	'title'                   => 'TITLE',
	'artist'                  => 'ARTIST',
	'album'                   => 'ALBUM',
	'track_number'            => 'TRACK',
	'track'                   => 'TRACK',
	'part_of_a_set'           => 'DISC',
	'genre'                   => 'GENRE',
	'year'                    => 'DATE',
	'recording_time'          => 'DATE',
	'date'                    => 'TDAT',
	'original_year'           => 'TORY',
	'media_type'              => 'TMED',
	'publisher'               => 'PUBLISHER',
	'band'                    => 'ALBUMARTIST',
	'conductor'               => 'CONDUCTOR',
	'composer'                => 'COMPOSER',
	'lyricist'                => 'LYRICIST',
	'remixer'                 => 'REMIXER',
	'album_artist_sort_order' => 'TSO2',
	'performer_sort_order'    => 'ARTISTSORT',
	'title_sort_order'        => 'TITLESORT',
	'album_sort_order'        => 'ALBUMSORT',
	'isrc'                    => 'ISRC',
	'part_of_a_compilation'   => 'COMPILATION',
	'set_subtitle'            => 'TSST',
	'length'                  => 'TLEN',
	'comment'                 => '',
	'encoded_by'              => 'ENCODEDBY',
	'encoder_settings'        => 'ENCODER',
	'language'                => 'LANGUAGE',
	'totaltracks'             => '',
	'musician_credits_list'   => '',
	'music_cd_identifier'     => '',
	'copyright_message'       => 'COPYRIGHT',
	'content_group_description' => 'GROUPING',
	'url_user'                => '',
	'unsynchronised_lyric'    => '',
];
// Raw tags already surfaced as a track's own structured title/disc/track fields - left out of the
// per-track data so each isn't shown twice.
const FISHEYEALBUM_TRACK_DATA_EXCLUDED_KEYS = [ 'TITLE' => true, 'DISC' => true, 'TRACK' => true, 'TRACKNUMBER' => true ];
// Vorbis comment names that ffprobe renamed (and so everything downstream expects renamed).
const FISHEYEALBUM_VORBIS_KEY_MAP = [
	'TRACKNUMBER' => 'TRACK',
	'DISCNUMBER'  => 'DISC',
];

class FisheyeAlbum extends FisheyeMediaImage {

	public function __construct( $pImageId = null, $pContentId = null ) {
		parent::__construct( $pImageId, $pContentId );
		$this->mContentTypeGuid = FISHEYEALBUM_CONTENT_TYPE_GUID;
		$this->registerContentType( FISHEYEALBUM_CONTENT_TYPE_GUID, [
			'content_type_guid' => FISHEYEALBUM_CONTENT_TYPE_GUID,
			'content_name'      => 'Music Album',
			'handler_class'     => 'FisheyeAlbum',
			'handler_package'   => 'fisheyemedia',
			'handler_file'      => 'FisheyeAlbum.php',
			'maintainer_url'    => 'https://www.bitweaver.org',
		] );
		// mPackageGuid='fisheyemedia' is set automatically by registerContentType()
		// because handler_package('fisheyemedia') != content_type_guid('fisheyealbum').
	}

	/**
	 * The root this album's own 'track' xref rows (xkey_ext) live relative to - play_track.php
	 * calls this generically via method_exists(), same convention FisheyeSeason's own version
	 * already established. Unlike a season (A-M/N-Z per-show split, but still one fixed root for
	 * every season), this is genuinely per-album: this album's own real folder
	 * (Music/<gallery>/<title>/), not the bare fisheye_disk_storage_root - so a track's own
	 * xkey_ext only ever needs to store its bare filename (or "CDxx/filename" for an album that
	 * kept its own CD-subfolder layer without being split into a box set), not the whole nested
	 * path down to it. Found necessary live against a real box set - Firebird's xkey_ext column
	 * is capped at 250 characters, and a deeply-nested path (artist/box set/CDxx/long track title)
	 * already overflowed that on its own without this.
	 *
	 * A box set disc's own album sits one level deeper than a normal top-level album though
	 * (Music/<artist>/<box set>/<disc folder>/, not Music/<box set>/<disc folder>/) - walks up the
	 * real gallery chain, trying an extra level each time, until a candidate actually exists on
	 * disk (a box set is never nested more than one level deep, see
	 * FisheyeAlbum::isBoxSetFolder()'s own docblock, so this never needs to go further than that).
	 *
	 * @return string  the deepest-resolved guess if this album isn't linked into any gallery yet
	 *                 (folder can't be resolved) or nothing on disk actually matches, or the bare
	 *                 storage root if the config is unset
	 */
	public function getImageStorageRoot(): string {
		$root = \Bitweaver\Liberty\mime_film_get_storage_root();
		if( empty( $root ) ) {
			return $root;
		}
		$pathSegments = [ $this->getTitle() ];
		// A discography-category album (registerFromDisk()'s own $pCategory param) is linked
		// directly into its artist's own gallery (the flattened design - no separate category
		// gallery to walk up through), but its real folder still sits one level deeper on disk
		// inside that category folder - the gallery-parent walk below has no way to know that on
		// its own since nothing in the gallery hierarchy carries it, so it has to come from here
		// instead (found live: reloadTracks() on a category-flattened album resolved one level too
		// shallow, then walked past the top-level Music gallery itself trying to compensate,
		// producing a literal "Music/Music/<artist>/<title>/" path that obviously never exists).
		$this->loadXrefInfo();
		if( $this->mXrefInfo ) {
			foreach( $this->liveXrefs() as $xref ) {
				if( $xref['item'] === 'category' && !empty( $xref['xkey_ext'] ) ) {
					array_unshift( $pathSegments, $xref['xkey_ext'] );
					break;
				}
			}
		}
		// The parent gallery's own folder first (FisheyeMediaGallery::resolveMusicFolder() - also
		// covers a box set kept inside a Studio/Live/... category folder, which the walk below
		// can't see since that category has no gallery of its own).
		$parentGalleries = $this->getParentGalleries();
		if( $parentGalleries ) {
			$parentGallery = new FisheyeGallery( null, current( $parentGalleries )['content_id'] );
			$parentGallery->load();
			if( $parentRelative = FisheyeMediaGallery::resolveMusicFolder( $parentGallery ) ) {
				$candidate = $root.$parentRelative.implode( '/', $pathSegments ).'/';
				if( is_dir( $candidate ) ) {
					return $candidate;
				}
			}
		}
		$contentId = $this->mContentId;
		for( $i = 0; $i < 3; $i++ ) {
			$candidate = $root.'Music/'.implode( '/', $pathSegments ).'/';
			if( is_dir( $candidate ) ) {
				return $candidate;
			}
			$parentGalleries = $this->getParentGalleries( $contentId );
			if( empty( $parentGalleries ) ) {
				break;
			}
			// getParentGalleries() keys its result by gallery_id (fisheye_gallery's own PK), not
			// content_id - using key() here instead of the row's own 'content_id' field looked up
			// the WRONG next level's parent every time, silently truncating this walk to one level
			// short of the real depth (found live: a category-nested album resolved to
			// Music/<category>/<album>/ instead of Music/<artist>/<category>/<album>/, since the
			// second iteration's parent lookup used a gallery_id where a content_id belonged).
			$parentRow = current( $parentGalleries );
			$contentId = $parentRow['content_id'];
			array_unshift( $pathSegments, $parentRow['title'] );
		}
		return $root.'Music/'.implode( '/', $pathSegments ).'/';
	}

	/**
	 * FisheyeImage's own generic getDisplayUrl() only ever routes to view_film.php or
	 * view_image.php (branches on attachment_plugin_guid==='mimefilm') - an album has no
	 * attachment plugin of its own at all, so it would silently fall through to view_image.php,
	 * the wrong page. Same fix FisheyeSeason/FisheyeProgram each already needed for themselves.
	 *
	 * @return string
	 */
	public function getDisplayUrl( $pContentId = null, $pMixed = null ) {
		$contentId = \Bitweaver\BitBase::verifyId( $pContentId ) ? $pContentId : $this->mContentId;
		return FISHEYEMEDIA_PKG_URL.'view_album.php?content_id='.$contentId;
	}

	/**
	 * Override LibertyContent::getEditUrl()'s generic '<package>/edit.php' default - same fatal-
	 * error bug FisheyeFilm/Season/Program each already hit ("Call to undefined method
	 * ...::getAllLayouts()"), fisheye's own edit.php being the GALLERY edit page, not an album's.
	 * See FisheyeSeason::getEditUrl()'s identical override.
	 *
	 * @param int|null $pContentId
	 * @param array|null $pMixed
	 * @return string
	 */
	public function getEditUrl( $pContentId = null, $pMixed = null ) {
		$contentId = \Bitweaver\BitBase::verifyId( $pContentId ) ? $pContentId : $this->mContentId;
		$ret = FISHEYEMEDIA_PKG_URL.'edit_album.php?content_id='.$contentId;
		foreach( (array)$pMixed as $key => $value ) {
			if( $key !== 'content_id' ) {
				$ret .= '&'.$key.'='.$value;
			}
		}
		return $ret;
	}

	/**
	 * Override of FisheyeBase's own getImageStorageRoot()-relative default - an album's own
	 * downloaded Plex alternates live in storage/attachments/<branch>/, not the external music
	 * library tree, same fix FisheyeFilm got 2026-09-04 (see getImageStorageBranchPath()'s own
	 * docblock) and Album now needs too, since it copied Season's now-outdated shared images/
	 * folder approach when it was first built.
	 */
	public function getExtraImagePath( string $pRelativePath ): string {
		return $this->getImageStorageBranchPath().$pRelativePath;
	}

	/**
	 * This album's own storage/attachments/<branch>/ path - home for its downloaded Plex image
	 * alternates and any manual uploads, same convention FisheyeFilm::getImageStorageBranchPath()
	 * already established. Always
	 * nginx-writable by construction, unlike the external music library tree.
	 *
	 * @return string
	 */
	private function getImageStorageBranchPath(): string {
		return STORAGE_PKG_PATH.\Bitweaver\Liberty\liberty_mime_get_storage_branch( [ 'attachment_id' => $this->mContentId ] );
	}

	/**
	 * Generic file-lifecycle hook liberty/edit_xref.php calls (via method_exists()) when a file
	 * is uploaded to replace an xref row's own referenced file - see FisheyeFilm::
	 * replaceXrefFile()'s identical docblock for the fuller reasoning, same method, same shape.
	 *
	 * @param string $pItem
	 * @param string $pXkeyExt
	 * @param string $pTmpPath  the uploaded file's own tmp_name
	 * @return bool
	 */
	public function replaceXrefFile( string $pItem, string $pXkeyExt, string $pTmpPath ): bool {
		if( $pItem !== 'image' || empty( $pXkeyExt ) ) {
			return false;
		}
		return move_uploaded_file( $pTmpPath, $this->getImageStorageBranchPath().$pXkeyExt );
	}

	/**
	 * Generic file-lifecycle hook liberty/edit_xref.php calls (via method_exists()) on a real
	 * hard-delete (expunge=3) of an xref row - see FisheyeFilm::deleteXrefFile()'s identical
	 * docblock for the fuller reasoning.
	 *
	 * @param string $pItem
	 * @param string $pXkeyExt
	 * @return bool
	 */
	public function deleteXrefFile( string $pItem, string $pXkeyExt ): bool {
		if( $pItem !== 'image' || empty( $pXkeyExt ) ) {
			return false;
		}
		$path = $this->getImageStorageBranchPath().$pXkeyExt;
		if( !is_file( $path ) ) {
			return false;
		}
		return @unlink( $path );
	}

	/**
	 * Promote one of this album's already-downloaded 'image' xref alternates into its actual
	 * displayed thumbnail - same shape as FisheyeFilm::promoteImageToThumbnail() (regenerates
	 * thumbs/ directly from the chosen alternate, already sitting in the same branch as the
	 * thumbs themselves, rather than a full re-store via attachThumbnail()).
	 *
	 * @param string $pRelativePath  an 'image' xref row's own xkey_ext value (a bare filename)
	 * @return bool
	 */
	public function promoteImageToThumbnail( string $pRelativePath ): bool {
		$branchPath = $this->getImageStorageBranchPath();
		$sourcePath = $branchPath.$pRelativePath;
		if( !is_file( $sourcePath ) ) {
			return false;
		}
		foreach( glob( $branchPath.'thumbs/*' ) ?: [] as $oldThumb ) {
			@unlink( $oldThumb );
		}
		$fileHash = [ 'type' => 'image/jpeg', 'source_file' => $sourcePath, 'dest_branch' => \Bitweaver\Liberty\liberty_mime_get_storage_branch( [ 'attachment_id' => $this->mContentId ] ) ];
		$ok = \Bitweaver\Liberty\liberty_generate_thumbnails( $fileHash );
		$this->load();
		return $ok;
	}

	/**
	 * Locate this album in the local Plex library, matched via one of its own 'track' xref rows'
	 * file path (same approach as FisheyeSeason::matchPlexSeasonMetadataItem() matching via an
	 * 'episode' xref) - Plex's music schema: track=metadata_type 10, its parent_id is the album
	 * (metadata_type 9), whose own parent_id is the artist (metadata_type 8). Only the album level
	 * is needed here.
	 *
	 * @return array{db:\PDO,id:int,root:string}|null
	 */
	private function matchPlexAlbumMetadataItem(): ?array {
		global $gBitSystem;

		$dbPath = $gBitSystem->getConfig( 'fisheye_plex_db_path', '' );
		if( empty( $dbPath ) || !is_file( $dbPath ) ) {
			return null;
		}

		$this->loadXrefInfo();
		$trackXref = $this->mXrefInfo ? $this->mXrefInfo->findRowByItem( 'track' ) : null;
		if( !$trackXref || empty( $trackXref['xkey_ext'] ) ) {
			return null;
		}

		$root = $this->getImageStorageRoot();
		if( empty( $root ) ) {
			return null;
		}

		$realPath = realpath( $root.$trackXref['xkey_ext'] );
		if( empty( $realPath ) ) {
			return null;
		}

		try {
			$plexDb = new \PDO( 'sqlite:'.$dbPath );
		} catch( \Exception $e ) {
			return null;
		}

		$stmt = $plexDb->prepare(
			"SELECT mi.parent_id FROM media_parts mp
			 JOIN media_items mi2 ON mi2.id = mp.media_item_id
			 JOIN metadata_items mi ON mi.id = mi2.metadata_item_id
			 WHERE mp.file = ? AND mi.metadata_type = 10"
		);
		$stmt->execute( [ $realPath ] );
		$albumMetadataItemId = $stmt->fetchColumn();
		if( !$albumMetadataItemId ) {
			return null;
		}

		return [ 'db' => $plexDb, 'id' => (int)$albumMetadataItemId, 'root' => $root ];
	}

	/**
	 * Attach a cover from this album's own folder - a real cover.jpg/folder.jpg/front.jpg
	 * (FISHEYEALBUM_COVER_NAMES) first, falling back to whatever cover art is embedded directly in
	 * the first track's own tags (FLAC/MP3 METADATA_BLOCK_PICTURE/APIC, common on a single-CD
	 * classical release with no separate cover file) - same two-step logic registerFromDisk() has
	 * always used at import time, factored out here so reloadPlexImages() can fall back to it too
	 * (an album Plex has no music-library match for - the normal case for most personal rips - used
	 * to have no path back to its own already-working disk/embedded cover at all).
	 *
	 * @param string $pAbsoluteFolder  this album's own real folder (getImageStorageRoot())
	 * @param array  $pTrackFiles      scanTrackFiles() shape - only trackFiles[0]['relative'] is
	 *                                 used, for the embedded-art fallback
	 * @return string|null  the cover filename attached, 'embedded' for the embedded-art fallback,
	 *                       or null if neither source yielded anything
	 */
	public function attachCoverFromDisk( string $pAbsoluteFolder, array $pTrackFiles ): ?string {
		foreach( FISHEYEALBUM_COVER_NAMES as $coverName ) {
			if( is_file( $pAbsoluteFolder.$coverName ) ) {
				return $this->attachThumbnail( $pAbsoluteFolder.$coverName ) ? $coverName : null;
			}
		}
		if( empty( $pTrackFiles[0]['relative'] ) ) {
			return null;
		}
		$embeddedCover = self::extractEmbeddedCoverArt( $pAbsoluteFolder.$pTrackFiles[0]['relative'] );
		if( !$embeddedCover ) {
			return null;
		}
		$coverAttached = null;
		if( $this->attachThumbnail( $embeddedCover ) ) {
			$coverAttached = 'embedded';
			// Also kept as a real 'image' xref alternate (same storage/attachments/<branch>/ home as
			// a Plex-fetched alternate) - attachThumbnail() above already deleted its own copy of the
			// original once thumbs/ existed, so without this there would be no way back to the
			// embedded art if the primary thumbnail later gets changed to something else (a Plex
			// poster, a manual upload).
			$embeddedFileName = 'embedded-cover.jpg';
			copy( $embeddedCover, $this->getImageStorageBranchPath().$embeddedFileName );
			$embeddedXrefHash = [ 'content_id' => $this->mContentId, 'item' => 'image', 'xkey_ext' => $embeddedFileName, 'xorder' => 1 ];
			$this->storeXref( $embeddedXrefHash );
		}
		@unlink( $embeddedCover );
		return $coverAttached;
	}

	/**
	 * Fetch an alternate cover image from Plex for this album - simpler than FisheyeSeason's own
	 * reloadPlexImages() (no separate 'art'/backdrop type for a music album, just one poster per
	 * fetch, no per-type 5-cap loop needed), same 'selected' pick + xref-based storage shape
	 * otherwise. See that method's own docblock for the fuller reasoning not repeated here.
	 *
	 * Falls back to attachCoverFromDisk() when Plex has no match at all (the normal case for a
	 * personal rip never scanned into Plex's own music library) - previously left an album stuck
	 * with "no image" forever if its own disk/embedded cover hadn't been picked up at import time,
	 * with no way to retry that half short of a full reloadTracks() + re-registration.
	 *
	 * @return array Summary of what was found/stored, for the calling page's result display.
	 */
	public function reloadPlexImages(): array {
		global $gBitSystem;
		$summary = [ 'matched' => false, 'items' => [] ];

		$plexMatch = $this->matchPlexAlbumMetadataItem();
		if( !$plexMatch ) {
			$absoluteFolder = $this->getImageStorageRoot();
			if( !empty( $absoluteFolder ) && is_dir( $absoluteFolder ) ) {
				$trackFiles = self::scanTrackFiles( $absoluteFolder );
				$coverAttached = $this->attachCoverFromDisk( $absoluteFolder, $trackFiles );
				if( $coverAttached ) {
					$summary['items'][] = 'Cover attached from disk ('.$coverAttached.').';
				}
			}
			return $summary;
		}
		$summary['matched'] = true;
		$metadataItemId = $plexMatch['id'];
		$root = $plexMatch['root'];

		$plexToken = $gBitSystem->getConfig( 'fisheye_plex_token', '' );
		if( empty( $plexToken ) ) {
			$summary['items'][] = 'fisheye_plex_token is not configured - the posters endpoint needs it.';
			return $summary;
		}

		// Auto-pick the real thumbnail attachment (once only) from Plex's own currently-selected
		// cover ('selected="1"' in the /posters listing) - see FisheyeSeason::reloadPlexImages()'s
		// identical comment for the fuller reasoning.
		if( empty( $this->mStorage ) ) {
			$postersXml = @file_get_contents( "http://localhost:32400/library/metadata/$metadataItemId/posters?X-Plex-Token=".urlencode( $plexToken ) );
			if( $postersXml !== false && preg_match_all( '#<Photo\b[^>]*/>#', $postersXml, $tagMatches ) ) {
				foreach( $tagMatches[0] as $tag ) {
					if( str_contains( $tag, 'selected="1"' ) && preg_match( '#\bthumb="([^"]+)"#', $tag, $m ) ) {
						$thumb = html_entity_decode( $m[1] );
						$thumbUrl = str_starts_with( $thumb, '/' )
							? "http://localhost:32400$thumb".( str_contains( $thumb, '?' ) ? '&' : '?' )."X-Plex-Token=".urlencode( $plexToken )
							: $thumb;
						if( $this->attachThumbnail( $thumbUrl ) ) {
							$summary['items'][] = 'thumbnail: attached from Plex\'s own selected cover';
						}
						break;
					}
				}
			}
		}

		$existingImagePaths = [];
		$xorder = 0;
		foreach( $this->liveXrefs() as $xref ) {
			if( $xref['item'] === 'image' ) {
				$existingImagePaths[] = $xref['xkey_ext'];
				$xorder = max( $xorder, (int)$xref['xorder'] );
			}
		}
		if( $existingImagePaths ) {
			$summary['items'][] = 'already has stored images - not re-fetched (delete them first to force a re-fetch).';
			return $summary;
		}

		// Lives in this album's own storage/attachments/<branch>/ - same fix FisheyeFilm got
		// 2026-09-04, not the external music library tree ($root, still used above only to
		// resolve the Plex match via a track's own file path).
		$destBranch = \Bitweaver\Liberty\liberty_mime_get_storage_branch( [ 'attachment_id' => $this->mContentId ] );
		$imagesDir = STORAGE_PKG_PATH.$destBranch;
		KernelTools::mkdir_p( $imagesDir );
		$baseName = $this->getTitle();

		$apiUrl = "http://localhost:32400/library/metadata/$metadataItemId/posters?X-Plex-Token=".urlencode( $plexToken );
		$xml = @file_get_contents( $apiUrl );
		if( $xml === false || !preg_match_all( '#<Photo[^>]*\bkey="([^"]+)"#', $xml, $matches ) ) {
			return $summary;
		}
		$fetched = 0;
		foreach( $matches[1] as $imageUrl ) {
			if( $fetched >= 5 ) {
				break;
			}
			$imageUrl = html_entity_decode( $imageUrl );
			// Plex's own newer agents serve bundled art via a local proxy path rather than a
			// direct https:// URL - no remote size variant to swap in for these, fetch as-is
			// through the local API instead
			$imageUrl = str_starts_with( $imageUrl, '/' )
				? "http://localhost:32400$imageUrl".( str_contains( $imageUrl, '?' ) ? '&' : '?' )."X-Plex-Token=".urlencode( $plexToken )
				: str_replace( '/original/', '/w342/', $imageUrl );
			$imageData = @file_get_contents( $imageUrl );
			if( $imageData === false ) {
				continue;
			}
			$fetched++;
			$fileName = "$baseName-poster-$fetched.jpg";
			// xkey_ext is just the bare filename, resolved against this album's own
			// storage/attachments/<branch>/ (see getImageStorageBranchPath()) - no directory
			// component needed, the branch is already per-content_id.
			$tmpFile = tempnam( sys_get_temp_dir(), 'fisheye_alt_' );
			file_put_contents( $tmpFile, $imageData );
			$resized = self::resizeImageFile( $tmpFile, $imagesDir.$fileName, 400 );
			@unlink( $tmpFile );
			if( !$resized ) {
				continue;
			}
			$xrefHash = [ 'content_id' => $this->mContentId, 'item' => 'image', 'xkey_ext' => $fileName, 'xorder' => ++$xorder ];
			$this->storeXref( $xrefHash );
			$summary['items'][] = "image: $fileName";
		}

		return $summary;
	}

	/**
	 * Every real track's embedded tags, normalized tag name => value. A tag holding one value is a
	 * plain string; a genuinely multi-valued one (several MusicBrainz artist ids, several ARTISTS
	 * names) is a list, in the tagger's own order - Picard writes ids and names in matching order,
	 * so the two lists pair up index for index.
	 *
	 * MP3 and FLAC (the whole library) go through getID3 (util/includes/getid3), not ffprobe:
	 * ffprobe flattens a multi-valued Vorbis field into one ';'-joined string and never outputs an
	 * MP3's UFID frame at all, which is where Picard stores the MusicBrainz recording id - the key
	 * to per-recording performer/composer credits. Every MP3 here is ID3v2.3, which has no native
	 * multi-value text frames, so Picard '/'-joins them in the file itself; those are split back
	 * into lists here (see splitId3v23MultiValues()). Key names match what ffprobe produced (TRACK,
	 * DISC, ALBUMARTIST, ARTISTSORT...), so nothing downstream sees a rename. Any other container
	 * falls back to ffprobe.
	 *
	 * Deliberately never calls getid3_lib::CopyTagsToComments() - it merges an MP3's own
	 * "MusicBrainz Album Artist Id" TXXX into "MusicBrainz Artist Id", silently giving every track
	 * the whole album credit's ids (confirmed on a Samuel Barber release).
	 *
	 * @param string $pAbsolutePath
	 * @return array<string,string|list<string>>  normalized tag name => value, empty if none found
	 */
	private static function readTrackTags( string $pAbsolutePath ): array {
		$ext = strtolower( pathinfo( $pAbsolutePath, PATHINFO_EXTENSION ) );
		if( !in_array( $ext, [ 'mp3', 'flac' ], true ) ) {
			return self::readTrackTagsFfprobe( $pAbsolutePath );
		}
		static $getID3 = null;
		if( $getID3 === null ) {
			require_once UTIL_PKG_INCLUDE_PATH.'getid3/getid3/getid3.php';
			$getID3 = new \getID3();
		}
		$info = $getID3->analyze( $pAbsolutePath );

		$lists = [];
		$add = function( string $pKey, $pValues ) use ( &$lists ) {
			foreach( (array)$pValues as $value ) {
				if( !is_scalar( $value ) ) {
					continue;
				}
				$value = trim( (string)$value );
				if( $value !== '' && !in_array( $value, $lists[$pKey] ?? [], true ) ) {
					$lists[$pKey][] = $value;
				}
			}
		};

		if( !empty( $info['tags']['vorbiscomment'] ) ) {
			foreach( $info['tags']['vorbiscomment'] as $key => $values ) {
				$key = self::normalizeTagKey( $key );
				$add( FISHEYEALBUM_VORBIS_KEY_MAP[$key] ?? $key, $values );
			}
		}
		if( !empty( $info['id3v2']['comments'] ) ) {
			foreach( $info['id3v2']['comments'] as $key => $values ) {
				if( $key === 'text' ) {
					// TXXX user frames, keyed by their own description ("MusicBrainz Artist Id")
					foreach( $values as $description => $value ) {
						$add( self::normalizeTagKey( $description ), $value );
					}
				} elseif( isset( FISHEYEALBUM_ID3V2_KEY_MAP[$key] ) ) {
					if( FISHEYEALBUM_ID3V2_KEY_MAP[$key] !== '' ) {
						$add( FISHEYEALBUM_ID3V2_KEY_MAP[$key], $values );
					}
				} elseif( !in_array( $key, [ 'picture', 'involved_people_list' ], true ) ) {
					$add( self::normalizeTagKey( $key ), $values );
				}
			}
			// COMM frames raw - the merged 'comment' list drops each frame's own description, which
			// is what distinguishes an old rip's "Performers" credit comment from ripper noise.
			foreach( $info['id3v2']['COMM'] ?? [] as $comm ) {
				$description = trim( \getid3_lib::iconv_fallback( $comm['encoding'], 'UTF-8', $comm['description'] ?? '' ) );
				$add( $description === '' ? 'COMMENT' : self::normalizeTagKey( $description ), \getid3_lib::iconv_fallback( $comm['encoding'], 'UTF-8', $comm['data'] ?? '' ) );
			}
			// ID3v2.3 splits a full release date across TYER (year) and TDAT (DDMM).
			if( isset( $lists['DATE'], $lists['TDAT'] ) && preg_match( '/^\d{4}$/', $lists['DATE'][0] ) && preg_match( '/^(\d{2})(\d{2})$/', $lists['TDAT'][0], $dm ) ) {
				$lists['DATE'] = [ $lists['DATE'][0].'-'.$dm[2].'-'.$dm[1] ];
			}
			unset( $lists['TDAT'] );
			foreach( $info['id3v2']['UFID'] ?? [] as $ufid ) {
				if( ( $ufid['ownerid'] ?? '' ) === 'http://musicbrainz.org' ) {
					$add( 'MUSICBRAINZTRACKID', $ufid['data'] ?? '' );
				}
			}
			// IPLS (v2.3) / TIPL (v2.4) - getID3 already splits these into role/person pairs.
			foreach( [ 'IPLS', 'TIPL' ] as $frame ) {
				foreach( $info['id3v2'][$frame] ?? [] as $entry ) {
					foreach( (array)( $entry['data'] ?? [] ) as $pair ) {
						if( is_array( $pair ) && !empty( $pair['person'] ) ) {
							$add( 'INVOLVEDPEOPLE', trim( ( $pair['position'] ?? '' ).': '.$pair['person'], ': ' ) );
						}
					}
				}
			}
			$lists = self::splitId3v23MultiValues( $lists );
		} elseif( !empty( $info['id3v1']['comments'] ) ) {
			foreach( $info['id3v1']['comments'] as $key => $values ) {
				$add( FISHEYEALBUM_ID3V2_KEY_MAP[$key] ?? self::normalizeTagKey( $key ), $values );
			}
		}

		$tags = [];
		foreach( $lists as $key => $values ) {
			$tags[$key] = count( $values ) === 1 ? $values[0] : $values;
		}
		return $tags;
	}

	/**
	 * ID3v2.3 has no native multi-value text frame, so Picard '/'-joins several values in one
	 * frame. MusicBrainz id fields are split whenever every part is a real MBID (an id can never
	 * contain '/'). ARTISTS - plain names, where '/' can be part of a real name ("AC/DC") - is only
	 * split when the parts line up exactly with an already-split MUSICBRAINZARTISTID list, the same
	 * one-name-per-id pairing Picard writes.
	 *
	 * @param array<string,list<string>> $pLists
	 * @return array<string,list<string>>
	 */
	private static function splitId3v23MultiValues( array $pLists ): array {
		$uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
		foreach( $pLists as $key => $values ) {
			if( str_starts_with( $key, 'MUSICBRAINZ' ) && count( $values ) === 1 && preg_match( "#^$uuid(/$uuid)+$#i", $values[0] ) ) {
				$pLists[$key] = explode( '/', $values[0] );
			}
		}
		if( isset( $pLists['ARTISTS'], $pLists['MUSICBRAINZARTISTID'] ) && count( $pLists['ARTISTS'] ) === 1 ) {
			$parts = array_map( 'trim', explode( '/', $pLists['ARTISTS'][0] ) );
			if( count( $parts ) > 1 && count( $parts ) === count( $pLists['MUSICBRAINZARTISTID'] ) ) {
				$pLists['ARTISTS'] = $parts;
			}
		}
		return $pLists;
	}

	/**
	 * A tag value as one plain string - for the handful of callers that only ever want a single
	 * value (track/disc number, title, disc subtitle) regardless of whether the file carried one.
	 *
	 * @param string|list<string>|null $pValue
	 * @return string|null
	 */
	private static function tagString( $pValue ): ?string {
		if( is_array( $pValue ) ) {
			return $pValue ? implode( '; ', $pValue ) : null;
		}
		return $pValue;
	}

	/**
	 * Fallback for containers getID3 isn't used for (anything but mp3/flac - not present in the
	 * current library): every embedded format_tag via a single ffprobe call, one process per file.
	 * Multi-valued fields arrive flattened to one ';'-joined string here.
	 *
	 * @param string $pAbsolutePath
	 * @return array<string,string>  normalized tag name => value, empty if ffprobe found none
	 */
	private static function readTrackTagsFfprobe( string $pAbsolutePath ): array {
		$cmd = 'ffprobe -v error -show_entries format_tags -of default=noprint_wrappers=1 '.escapeshellarg( $pAbsolutePath ).' 2>/dev/null';
		$output = shell_exec( $cmd ) ?? '';
		$tags = [];
		foreach( explode( "\n", $output ) as $line ) {
			if( !str_starts_with( $line, 'TAG:' ) ) {
				continue;
			}
			$parts = explode( '=', substr( $line, 4 ), 2 );
			if( count( $parts ) === 2 ) {
				$tags[self::normalizeTagKey( $parts[0] )] = $parts[1];
			}
		}
		return $tags;
	}

	/**
	 * Same embedded tag, different spelling depending on container/tagger: Vorbis comments (FLAC/
	 * OGG) conventionally get written upper-case-with-underscores (MUSICBRAINZ_ALBUMID), while an
	 * ID3v2 TXXX frame (MP3) has no fixed enum for a MusicBrainz field - it's a free-text
	 * description, and Picard writes the human-readable form there ("MusicBrainz Album Id")
	 * instead. Both are the same tag - stripping every non-alphanumeric character before comparing
	 * collapses both spellings (and any stray case/spacing variant) to one key, rather than the
	 * ID3v2 form silently reading as "not tagged" against FISHEYEALBUM_COMMON_TAG_MAP's Vorbis-
	 * style constants.
	 */
	private static function normalizeTagKey( string $pKey ): string {
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $pKey ) );
	}

	/**
	 * Split embedded tags into album-common (identical on every track - real MusicBrainz ids,
	 * label/catalog/barcode/country/etc, genre/composer/artist) vs track-specific (everything
	 * else, including a common-shaped tag that happens to vary per track this time - a various-
	 * artists compilation, or a disc id that only applies within one disc of a multi-disc set).
	 *
	 * @param array $pTrackFiles  registerFromDisk()'s own $trackFiles, each with a 'tags' entry
	 * @return array{0: array<string,string>, 1: array<string,true>}  [ xref item => value,
	 *         normalized tag name => true for every tag that got promoted to an xref OR is on
	 *         FISHEYEALBUM_IGNORED_TAG_KEYS (so the caller can strip exactly those out of each
	 *         track's own data) ]
	 */
	private static function extractCommonTags( array $pTrackFiles ): array {
		$common = [];
		$promotedTagKeys = [];
		foreach( FISHEYEALBUM_IGNORED_TAG_KEYS as $tagKey ) {
			$promotedTagKeys[self::normalizeTagKey( $tagKey )] = true;
		}

		foreach( FISHEYEALBUM_COMMON_TAG_MAP as $tagKey => $xrefItem ) {
			$value = self::commonTagValue( $pTrackFiles, $tagKey );
			if( $value !== null ) {
				$common[$xrefItem] = $value;
				// Keyed by the normalized form, same as $track['tags'] itself (readTrackTags()
				// normalizes on the way in) - the raw constant spelling here is Vorbis-style
				// ('MUSICBRAINZ_ALBUMID'), which never matches an ID3v2-tagged track's own
				// normalized key ('MUSICBRAINZALBUMID') in the array_diff_key() below otherwise,
				// silently leaving every promoted tag sitting in track data anyway.
				$promotedTagKeys[self::normalizeTagKey( $tagKey )] = true;
			}
		}
		foreach( FISHEYEALBUM_COMMON_TAG_ALTERNATES as $xrefItem => $tagKeys ) {
			$matched = false;
			foreach( $tagKeys as $tagKey ) {
				$value = self::commonTagValue( $pTrackFiles, $tagKey );
				if( $value !== null && !$matched ) {
					$common[$xrefItem] = $value;
					$matched = true;
				}
				// Every alternate gets marked as promoted once any one of them wins, not just the
				// winner - a tagger commonly writes more than one of these redundantly (DATE and
				// ORIGINALDATE with the same value, say), and a losing alternate still duplicates
				// exactly what the winner already promoted, so it belongs out of track data too.
				// commonTagValue() already only returns non-null for a value that's identical
				// across every track, so a genuinely track-varying alternate (a various-artists
				// compilation's own per-track MUSICBRAINZ_ARTISTID, say) correctly stays null here
				// and never gets marked - only true album-wide duplicates do.
				if( $value !== null ) {
					$promotedTagKeys[self::normalizeTagKey( $tagKey )] = true;
				}
			}
		}
		return [ $common, $promotedTagKeys ];
	}

	/**
	 * A single tag's value if present and identical across every track, null otherwise (missing
	 * from any track, or differing between tracks - either way, not safe to treat as album-wide).
	 *
	 * @param array $pTrackFiles
	 * @param string $pTagKey  embedded tag name (normalizeTagKey() applied here, so callers can
	 *                         pass FISHEYEALBUM_COMMON_TAG_MAP/_ALTERNATES' own Vorbis-style
	 *                         spelling regardless of how this particular file's tagger wrote it)
	 * @return string|list<string>|null  a list only when the tag is multi-valued (and the same
	 *                                    list, in the same order, on every track)
	 */
	private static function commonTagValue( array $pTrackFiles, string $pTagKey ): string|array|null {
		$pTagKey = self::normalizeTagKey( $pTagKey );
		$value = null;
		foreach( $pTrackFiles as $track ) {
			$trackValue = $track['tags'][$pTagKey] ?? null;
			if( $trackValue === null || ( $value !== null && $trackValue !== $value ) ) {
				return null;
			}
			$value = $trackValue;
		}
		return $value;
	}

	/**
	 * Pair MusicBrainz artist ids with their names, index for index. Picard writes the ids in the
	 * same order as the credited names, so a names list of matching length pairs directly. A single
	 * credit string (an album-artist tag, "Samuel Barber, Ernst Toch, Paul Creston; The Louisville
	 * Orchestra") is only split when it's one id per piece - one id and the string stays whole, so
	 * "Emerson, Lake & Palmer" or "Diana Ross & The Supremes" is never broken up. Anything that
	 * doesn't line up keeps the ids with no name (a later MusicBrainz lookup can fill it in).
	 *
	 * @param string|list<string>|null $pIds
	 * @param string|list<string>|null $pNames
	 * @return list<array{mbid:string, name:?string}>
	 */
	private static function pairCredits( string|array|null $pIds, string|array|null $pNames ): array {
		$ids = array_values( array_filter( array_map( 'trim', (array)$pIds ) ) );
		if( !$ids ) {
			return [];
		}
		$names = null;
		if( is_array( $pNames ) && count( $pNames ) === count( $ids ) ) {
			$names = array_values( $pNames );
		} elseif( is_string( $pNames ) && $pNames !== '' ) {
			$parts = count( $ids ) === 1 ? [ $pNames ] : preg_split( '#\s*(?:;|,|/| & | and | feat\.? | featuring | with )\s*#i', $pNames );
			if( count( $parts ) === count( $ids ) ) {
				$names = $parts;
			}
		}
		$pairs = [];
		foreach( $ids as $i => $id ) {
			$pairs[] = [ 'mbid' => $id, 'name' => isset( $names[$i] ) ? trim( $names[$i] ) : null ];
		}
		return $pairs;
	}

	/**
	 * The content (in practice a contactwiki individual/group, but nothing here depends on that)
	 * holding a given MusicBrainz artist id in its own 'musicbrainz' xref, plus its best external id
	 * for a credit row's xkey: its Wikidata qid ('Q...') when it has one, otherwise its Discogs artist
	 * id (plain digits - a contact created from MusicBrainz alone often has only that). The two are
	 * told apart by format alone; a MusicBrainz id (36 chars) doesn't fit xkey (32). Cached per
	 * request: an album repeats the same few ids on every track.
	 *
	 * @return array{content_id:int, external_id:?string}|null
	 */
	private static function contactForMusicBrainzId( string $pMbid ): ?array {
		static $cache = [];
		$mbid = strtolower( trim( $pMbid ) );
		if( !array_key_exists( $mbid, $cache ) ) {
			global $gBitDb;
			$contentId = $gBitDb->getOne(
				"SELECT x.`content_id` FROM `".BIT_DB_PREFIX."liberty_xref` x
				 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = x.`content_id`
				 WHERE x.`item` = 'musicbrainz' AND x.`xkey_ext` = ? AND x.`end_date` IS NULL",
				[ $mbid ]
			);
			$externalId = null;
			if( $contentId ) {
				$ids = $gBitDb->getAssoc(
					"SELECT `item`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref`
					 WHERE `content_id` = ? AND `item` IN ( 'wikidata', 'discogs_artist' ) AND `end_date` IS NULL",
					[ $contentId ]
				) ?: [];
				$externalId = ( $ids['wikidata'] ?? null ) ?: ( $ids['discogs_artist'] ?? null ) ?: null;
			}
			$cache[$mbid] = $contentId ? [ 'content_id' => (int)$contentId, 'external_id' => $externalId ] : null;
		}
		return $cache[$mbid];
	}

	/**
	 * This album's own credits, one entry per person, each with the job it goes under - built from
	 * the tags every track shares: the album-artist credit (ids + names), the shared track artist,
	 * and COMPOSER/CONDUCTOR where the files carry them. A credited person named in COMPOSER or
	 * CONDUCTOR gets that job; anyone else with a contact gets one from the contact's own type tags
	 * (see the refinement step below); everyone left over stays 'artist'.
	 * Never a raw credit string: no ids at all is the one case a whole album-artist string is kept,
	 * as a single unpaired credit, since there's nothing to split it by safely.
	 *
	 * @param array $pTrackFiles
	 * @return list<array{role:string, name:?string, mbid:?string}>
	 */
	private static function buildCredits( array $pTrackFiles ): array {
		$asList = function( $pValue ): array {
			$values = [];
			foreach( (array)$pValue as $v ) {
				foreach( preg_split( '#\s*/\s*#', (string)$v ) as $part ) {
					if( trim( $part ) !== '' ) {
						$values[] = trim( $part );
					}
				}
			}
			return $values;
		};
		$composers  = array_map( 'mb_strtolower', $asList( self::commonTagValue( $pTrackFiles, 'COMPOSER' ) ?? self::commonTagValue( $pTrackFiles, 'IMUS' ) ) );
		$conductors = array_map( 'mb_strtolower', $asList( self::commonTagValue( $pTrackFiles, 'CONDUCTOR' ) ) );

		$credits = [];
		$add = function( ?string $pName, ?string $pMbid, string $pRole ) use ( &$credits ) {
			if( $pMbid === FISHEYEALBUM_MB_VARIOUS_ARTISTS || ( $pName === null && $pMbid === null ) ) {
				return;
			}
			$key = $pMbid ?: 'name:'.mb_strtolower( (string)$pName );
			if( isset( $credits[$key] ) ) {
				// Already credited (album artist and shared track artist are often the same person) -
				// keep the more specific job and fill a missing name.
				if( $credits[$key]['role'] === 'artist' ) {
					$credits[$key]['role'] = $pRole;
				}
				$credits[$key]['name'] ??= $pName;
				return;
			}
			$credits[$key] = [ 'role' => $pRole, 'name' => $pName, 'mbid' => $pMbid ];
		};
		$roleFor = function( ?string $pName ) use ( $composers, $conductors ): string {
			$name = mb_strtolower( (string)$pName );
			return in_array( $name, $composers, true ) ? 'composer' : ( in_array( $name, $conductors, true ) ? 'conductor' : 'artist' );
		};

		$albumArtist = self::commonTagValue( $pTrackFiles, 'ALBUMARTIST' );
		$trackArtist = self::commonTagValue( $pTrackFiles, 'ARTISTS' ) ?? self::commonTagValue( $pTrackFiles, 'ARTIST' );
		$pairs = array_merge(
			self::pairCredits( self::commonTagValue( $pTrackFiles, 'MUSICBRAINZALBUMARTISTID' ), $albumArtist ),
			self::pairCredits( self::commonTagValue( $pTrackFiles, 'MUSICBRAINZARTISTID' ), $trackArtist )
		);
		foreach( $pairs as $pair ) {
			$add( $pair['name'], $pair['mbid'], $roleFor( $pair['name'] ) );
		}
		if( !$pairs && ( $albumArtist ?? $trackArtist ) !== null ) {
			$add( self::tagString( $albumArtist ?? $trackArtist ), null, 'artist' );
		}
		// Anyone the role tags name who isn't in the id-bearing credits - name only.
		$byName = [];
		foreach( $credits as $credit ) {
			$byName[mb_strtolower( (string)$credit['name'] )] = true;
		}
		foreach( [ 'composer' => self::commonTagValue( $pTrackFiles, 'COMPOSER' ), 'conductor' => self::commonTagValue( $pTrackFiles, 'CONDUCTOR' ) ] as $role => $value ) {
			foreach( $asList( $value ) as $name ) {
				if( !isset( $byName[mb_strtolower( $name )] ) ) {
					$add( $name, null, $role );
				}
			}
		}

		// Anyone the tags left as plain 'artist' gets a job from their contact's own type tags, once
		// they're a contact (see creditRolesForContact()). Classical files tagged by Picard carry the
		// composer as each track's own artist, while conductors/orchestras/soloists only appear in the
		// album-artist credit - so 'composer' only counts for someone who is a track artist somewhere
		// on this album (a conductor who also composes, credited here as conductor, stays conductor).
		$trackArtistIds = [];
		foreach( $pTrackFiles as $track ) {
			foreach( (array)( $track['tags']['MUSICBRAINZARTISTID'] ?? [] ) as $id ) {
				$trackArtistIds[strtolower( trim( $id ) )] = true;
			}
		}
		foreach( $credits as &$credit ) {
			if( $credit['role'] !== 'artist' || empty( $credit['mbid'] ) ) {
				continue;
			}
			$roles = self::creditRolesForContact( $credit['mbid'] );
			if( isset( $trackArtistIds[strtolower( $credit['mbid'] )] ) && in_array( 'composer', $roles, true ) ) {
				$credit['role'] = 'composer';
				continue;
			}
			foreach( [ 'conductor', 'orchestra', 'performer', 'composer' ] as $role ) {
				if( in_array( $role, $roles, true ) ) {
					$credit['role'] = $role;
					break;
				}
			}
		}
		unset( $credit );
		return array_values( $credits );
	}

	/**
	 * The credit jobs (composer/conductor/orchestra/performer) a person's contact says they do, from
	 * its own type-tag xrefs mapped through whatever 'credit_role_map' services are registered
	 * (contactwiki contributes its WPxx/WBxx codes) - fisheyemedia knows no contact vocabulary
	 * itself. Empty when the id has no contact yet, or no map is registered.
	 *
	 * @return list<string>
	 */
	private static function creditRolesForContact( string $pMbid ): array {
		global $gLibertySystem, $gBitDb;
		static $map = null;
		if( $map === null ) {
			$map = [];
			foreach( (array)$gLibertySystem->getServiceValues( 'credit_role_map' ) as $serviceMap ) {
				$map += (array)$serviceMap;
			}
		}
		$contact = self::contactForMusicBrainzId( $pMbid );
		if( !$contact || !$map ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $map ), '?' ) );
		$items = $gBitDb->getCol(
			"SELECT `item` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `end_date` IS NULL AND `item` IN ( $placeholders )",
			array_merge( [ $contact['content_id'] ], array_keys( $map ) )
		);
		return array_values( array_unique( array_map( fn( $item ) => $map[$item], $items ) ) );
	}

	/**
	 * Bring one xref item's live rows on this album in line with what the files now say, without
	 * ever wiping them - the xref table records when each row was created and last edited, and a
	 * reload must keep that. Rows are matched by a natural key (a track's own file path, a person's
	 * MusicBrainz id, or '' for a single-valued item):
	 *   - no live row for a key          -> insert
	 *   - live row locally owned         -> left alone (see below), whatever the files say
	 *   - live row, different value      -> old row archived as-is (end_date set, fully reversible
	 *                                       via the xref history), new value inserted in its place
	 *   - live row, same value           -> untouched, nothing written
	 *   - live row the files no longer
	 *     mention, not locally owned     -> archived
	 *
	 * "Locally owned" = hand-edited: last_update_date later than entry_date. This routine only ever
	 * inserts or archives, never edits a live row in place, so a live row carrying a later update
	 * stamp can only have come from a hand edit (the xref edit page) - a correction a reload must
	 * never overwrite. A few seconds' grace covers entry_date/last_update_date being stamped by two
	 * separate clock reads on insert. With $pStrictOwnership (credit rows), a row with no 'source'
	 * in its data is also locally owned - every row this code writes carries one, so a row without
	 * it was added by hand. The one exception is xorder 0: the old single-row common-tag storage
	 * (the whole credit string in one 'artist' row, say) never carried a source either, and is
	 * exactly what the per-person rows replace.
	 *
	 * Not LibertyXref::stepXref(expunge=2): that writes the incoming values onto the row it closes
	 * (losing the old value) and numbers the continuation xorder+1 (colliding with the next track).
	 *
	 * @param string $pItem
	 * @param list<array{key:string, xorder:int, xref?:int, xkey_ext?:string, xkey?:string, data?:array}> $pWanted
	 * @param string|callable|null $pKey  how a live row's key is read: a column name ('xkey_ext'
	 *                                    for a track's path), a callable taking the row, or null
	 *                                    for a single-valued item
	 * @param bool $pStrictOwnership
	 * @return array<string,int>  counts: inserted/archived/unchanged/kept_local
	 */
	private function reconcileXrefItem( string $pItem, array $pWanted, string|callable|null $pKey, bool $pStrictOwnership = false ): array {
		$counts = [ 'inserted' => 0, 'archived' => 0, 'unchanged' => 0, 'kept_local' => 0 ];
		$live = $this->mDb->getAll(
			"SELECT `xref_id`, `xorder`, `xref`, `xkey`, `xkey_ext`, `data`, `entry_date`, `last_update_date`
			 FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = ? AND `end_date` IS NULL",
			[ $this->mContentId, $pItem ]
		);
		$liveByKey = [];
		foreach( $live as $row ) {
			$key = $pKey === null ? '' : ( is_callable( $pKey ) ? $pKey( $row ) : (string)$row[$pKey] );
			$liveByKey[$key][] = $row;
		}
		$isLocal = function( array $row ) use ( $pStrictOwnership ): bool {
			if( (int)$row['last_update_date'] - (int)$row['entry_date'] > 5 ) {
				return true;
			}
			if( $pStrictOwnership && (int)$row['xorder'] > 0 ) {
				$data = !empty( $row['data'] ) ? json_decode( $row['data'], true ) : null;
				return empty( $data['source'] );
			}
			return false;
		};
		$archive = function( array $row ) use ( &$counts ) {
			$stepHash = [ 'xref_id' => (int)$row['xref_id'], 'expunge' => 1 ];
			$this->stepXref( $stepHash );
			$counts['archived']++;
		};

		foreach( $pWanted as $want ) {
			$candidates = $liveByKey[$want['key']] ?? [];
			unset( $liveByKey[$want['key']] );
			if( array_filter( $candidates, $isLocal ) ) {
				// A hand-corrected row wins outright; any other live row under the same key is
				// left too rather than second-guessed.
				$counts['kept_local']++;
				continue;
			}
			$current = array_shift( $candidates );
			foreach( $candidates as $duplicate ) {
				$archive( $duplicate );
			}
			if( $current
				&& (int)$current['xorder'] === (int)$want['xorder']
				&& (int)$current['xref'] === (int)( $want['xref'] ?? 0 )
				&& (string)$current['xkey_ext'] === (string)( $want['xkey_ext'] ?? '' )
				&& (string)$current['xkey'] === (string)( $want['xkey'] ?? '' )
				&& ( !empty( $current['data'] ) ? json_decode( $current['data'], true ) : null ) == ( $want['data'] ?? null ) ) {
				$counts['unchanged']++;
				continue;
			}
			if( $current ) {
				$archive( $current );
			}
			$xrefHash = [ 'content_id' => $this->mContentId, 'item' => $pItem, 'xorder' => (int)$want['xorder'] ];
			if( !empty( $want['xref'] ) ) {
				$xrefHash['xref'] = (int)$want['xref'];
			}
			foreach( [ 'xkey_ext', 'xkey' ] as $field ) {
				if( isset( $want[$field] ) && $want[$field] !== '' ) {
					$xrefHash[$field] = $want[$field];
				}
			}
			if( isset( $want['data'] ) ) {
				$xrefHash['edit'] = json_encode( $want['data'] );
			}
			$this->storeXref( $xrefHash );
			$counts['inserted']++;
		}

		// Whatever's left is no longer in the files - archived, unless locally owned.
		foreach( $liveByKey as $rows ) {
			foreach( $rows as $row ) {
				if( $isLocal( $row ) ) {
					$counts['kept_local']++;
				} else {
					$archive( $row );
				}
			}
		}
		return $counts;
	}

	/**
	 * The one path both registerFromDisk() (a new album - every row an insert) and reloadTracks()
	 * (an existing one) take to write an album's track/common-tag/credit xrefs, so the two can't
	 * drift apart. Everything goes through reconcileXrefItem() - nothing is wiped.
	 *
	 * @param array $pTrackFiles      scanTrackFiles()' own result
	 * @param array $pCommonTags      extractCommonTags()' xref item => value
	 * @param array $pPromotedTagKeys extractCommonTags()' promoted/ignored raw tag keys
	 * @return array<string,int>  summed reconcile counts
	 */
	private function reconcileAlbumXrefs( array $pTrackFiles, array $pCommonTags, array $pPromotedTagKeys ): array {
		$counts = [];
		$tally = function( array $pCounts ) use ( &$counts ) {
			foreach( $pCounts as $key => $n ) {
				$counts[$key] = ( $counts[$key] ?? 0 ) + $n;
			}
		};

		// A single-disc album showing "Disc: 1" identically on every single track row is just noise
		// (found live once the json-list view actually rendered per-track data for the first time) -
		// only worth including once there's genuinely more than one disc to distinguish.
		$isMultiDisc = count( array_unique( array_column( $pTrackFiles, 'disc' ) ) ) > 1;
		$wantedTracks = [];
		$wantedTrackArtists = [];
		foreach( $pTrackFiles as $track ) {
			// Flattened alongside title/disc rather than nested under its own 'tags' key - the
			// generic json-list xref template (view_json-list_item.tpl) just dumps every top-level
			// key as its own row, so nesting only bought a Smarty "Array" render instead of a usable
			// one. TITLE/DISC/TRACK themselves are excluded since they're already surfaced as the
			// clean 'title'/'disc'/'track' fields - anything identical across every track (real
			// MusicBrainz ids, label/catalog/barcode/country/genre, the credits) has already been
			// promoted to the album itself (see extractCommonTags()), so it isn't duplicated into
			// every single track's own data. A tag that happens to vary per track this time (a
			// various-artists compilation's own per-track ARTIST, a disc id that only applies within
			// one disc of a multi-disc set) stays here.
			$trackTagsForData = array_diff_key( $track['tags'], $pPromotedTagKeys, FISHEYEALBUM_TRACK_DATA_EXCLUDED_KEYS );
			$trackData = [ 'title' => $track['title'], 'track' => $track['track_num'] ];
			if( $isMultiDisc ) {
				$trackData['disc'] = $track['disc'];
			}
			$trackData['duration'] = $track['duration_ms'];
			$wantedTrack = [
				// Bare (or "CDxx/filename" for an album keeping its own CD-subfolder layer) - see
				// getImageStorageRoot()'s own docblock for why the album's folder itself is never
				// baked into this. Also the track's natural key for reconciling.
				'key'      => $track['relative'],
				'xkey_ext' => $track['relative'],
				'xorder'   => count( $wantedTracks ) + 1,
			];
			// The track's own artist(s) as contacts - read from the raw tags, since an id shared by
			// every track has been promoted out of the track data. The row's own xref/xkey take the
			// first credited artist (MusicBrainz orders the primary credit first); each further one
			// gets its own 'track_artist' row - same xorder and file, so it sits beside the track in
			// the Tracks grid and a contact's own xref search finds every track it's credited on.
			// Names come from ARTISTS, which Picard writes in the same order as the ids.
			$artistIds = array_values( array_filter( (array)( $track['tags']['MUSICBRAINZARTISTID'] ?? [] ) ) );
			if( $artistIds && ( $contact = self::contactForMusicBrainzId( $artistIds[0] ) ) ) {
				$wantedTrack['xref'] = $contact['content_id'];
				$wantedTrack['xkey'] = (string)$contact['external_id'];
			}
			$artistNames = array_values( (array)( $track['tags']['ARTISTS'] ?? [] ) );
			foreach( array_slice( $artistIds, 1, null, true ) as $i => $mbid ) {
				$wantedArtist = [
					'key'      => $track['relative'].'|'.$mbid,
					'xkey_ext' => $track['relative'],
					'xorder'   => $wantedTrack['xorder'],
					'data'     => [ 'mbid' => $mbid, 'name' => (string)( $artistNames[$i] ?? '' ) ],
				];
				if( $contact = self::contactForMusicBrainzId( $mbid ) ) {
					$wantedArtist['xref'] = $contact['content_id'];
					$wantedArtist['xkey'] = (string)$contact['external_id'];
				}
				$wantedTrackArtists[] = $wantedArtist;
			}
			$wantedTrack['data'] = array_merge( $trackData, $trackTagsForData );
			$wantedTracks[] = $wantedTrack;
		}
		$tally( $this->reconcileXrefItem( 'track', $wantedTracks, 'xkey_ext' ) );
		$tally( $this->reconcileXrefItem( 'track_artist', $wantedTrackArtists,
			fn( array $pRow ) => $pRow['xkey_ext'].'|'.( json_decode( (string)$pRow['data'], true )['mbid'] ?? '' ) ) );

		// Plain single-valued common tags - one the files no longer carry reconciles to an empty
		// wanted list, archiving its old row. The credit-source items are handled below instead.
		$commonItems = array_diff(
			array_unique( array_merge( array_values( FISHEYEALBUM_COMMON_TAG_MAP ), array_keys( FISHEYEALBUM_COMMON_TAG_ALTERNATES ) ) ),
			FISHEYEALBUM_CREDIT_SOURCE_ITEMS
		);
		foreach( $commonItems as $xrefItem ) {
			$wanted = isset( $pCommonTags[$xrefItem] ) ? [ [ 'key' => '', 'xorder' => 0, 'xkey_ext' => (string)self::tagString( $pCommonTags[$xrefItem] ) ] ] : [];
			$tally( $this->reconcileXrefItem( $xrefItem, $wanted, null ) );
		}

		// Credits - one row per person under their job, keyed by MusicBrainz id (or name, for a
		// credit with no id).
		$wantedByRole = array_fill_keys( FISHEYEALBUM_CREDIT_ITEMS, [] );
		foreach( self::buildCredits( $pTrackFiles ) as $credit ) {
			$wanted = [
				'key'      => $credit['mbid'] ?: 'name:'.mb_strtolower( (string)$credit['name'] ),
				'xkey_ext' => $credit['name'] ?? $credit['mbid'],
				'xorder'   => count( $wantedByRole[$credit['role']] ) + 1,
				'data'     => array_filter( [ 'mbid' => $credit['mbid'], 'source' => 'tags' ] ),
			];
			// Linked to the person's contact once one exists (contactwiki's people pass creates them
			// ahead of import): xref = its content_id (the authority), xkey = its best external id -
			// Wikidata qid ('Q...'), else Discogs artist id (digits). The name stays in xkey_ext as
			// display text / fallback for anyone not yet a contact.
			if( $credit['mbid'] && ( $contact = self::contactForMusicBrainzId( $credit['mbid'] ) ) ) {
				$wanted['xref'] = $contact['content_id'];
				$wanted['xkey'] = (string)$contact['external_id'];
			}
			$wantedByRole[$credit['role']][] = $wanted;
		}
		$creditKey = function( array $row ): string {
			$data = !empty( $row['data'] ) ? json_decode( $row['data'], true ) : null;
			return !empty( $data['mbid'] ) ? $data['mbid'] : 'name:'.mb_strtolower( (string)$row['xkey_ext'] );
		};
		foreach( $wantedByRole as $role => $wanted ) {
			$tally( $this->reconcileXrefItem( $role, $wanted, $creditKey, true ) );
		}

		// Items the credits replace - any old row still carrying one is archived.
		foreach( FISHEYEALBUM_RETIRED_ITEMS as $xrefItem ) {
			$tally( $this->reconcileXrefItem( $xrefItem, [], 'xkey_ext' ) );
		}
		return $counts;
	}

	/**
	 * Extract a track's own embedded cover art (FLAC METADATA_BLOCK_PICTURE, MP3 APIC, etc - the
	 * same "picture" stream ffprobe already reports as a video/mjpeg stream alongside the real
	 * audio one) into a temp file, for the common single-CD classical case where there's no
	 * standalone cover.jpg/folder.jpg sitting in the album folder at all.
	 *
	 * @param string $pAbsolutePath
	 * @return string|null  temp file path (caller's own to unlink), or null if there's no
	 *                       embedded picture / extraction failed
	 */
	private static function extractEmbeddedCoverArt( string $pAbsolutePath ): ?string {
		$tmpFile = tempnam( sys_get_temp_dir(), 'fisheye_embedded_cover_' );
		$cmd = 'ffmpeg -y -i '.escapeshellarg( $pAbsolutePath ).' -an -c:v copy -update 1 -f image2 '.escapeshellarg( $tmpFile ).' 2>/dev/null';
		shell_exec( $cmd );
		if( is_file( $tmpFile ) && filesize( $tmpFile ) > 0 ) {
			return $tmpFile;
		}
		@unlink( $tmpFile );
		return null;
	}

	/**
	 * Every distinct credited person across one artist/composer folder's albums, read straight from
	 * the files on disk (before anything is imported) - for contactwiki's people pass, which matches
	 * or creates a contact for each one first so an album import can link credits by contact rather
	 * than by name. Walks the folder the same way load_album.php does: an album folder directly
	 * under it, or one level down inside a discography category or box set. Each track contributes
	 * its own artist ids (MUSICBRAINZARTISTID, paired with ARTISTS) and the album-artist ids
	 * (MUSICBRAINZALBUMARTISTID, paired with ALBUMARTIST) - see pairCredits(). MusicBrainz's own
	 * "Various Artists" entity is skipped: a compilation marker, not a person.
	 *
	 * @param string $pAbsoluteArtistFolder  trailing slash
	 * @return array{albums:int, tracks:int, unreadable:list<string>, people:list<array{mbid:string, names:list<string>,
	 *               albums:int, tracks:int, album_artist:bool, track_artist:bool}>}
	 */
	public static function surveyArtistCredits( string $pAbsoluteArtistFolder ): array {
		$albumFolders = [];
		$collect = function( string $pDir, int $pDepth ) use ( &$collect, &$albumFolders ) {
			$entries = scandir( $pDir ) ?: [];
			natsort( $entries );
			foreach( $entries as $entry ) {
				if( str_starts_with( $entry, '.' ) || !is_dir( $pDir.$entry ) ) {
					continue;
				}
				if( self::folderHasTracks( $pDir.$entry.'/' ) ) {
					$albumFolders[] = $pDir.$entry.'/';
				} elseif( $pDepth < 1 ) {
					$collect( $pDir.$entry.'/', $pDepth + 1 );
				}
			}
		};
		$collect( $pAbsoluteArtistFolder, 0 );

		$people = [];
		$trackTotal = 0;
		$unreadable = [];
		foreach( $albumFolders as $albumIndex => $albumFolder ) {
			foreach( self::scanTrackFiles( $albumFolder, false ) as $track ) {
				$trackTotal++;
				// A file the web server can't read yields no tags at all - counted, not silently
				// treated as "credits nobody".
				if( !is_readable( $albumFolder.$track['relative'] ) ) {
					$unreadable[] = substr( $albumFolder, strlen( $pAbsoluteArtistFolder ) ).$track['relative'];
					continue;
				}
				$tags = $track['tags'];
				$credits = [
					'track_artist' => self::pairCredits( $tags['MUSICBRAINZARTISTID'] ?? null, $tags['ARTISTS'] ?? $tags['ARTIST'] ?? null ),
					'album_artist' => self::pairCredits( $tags['MUSICBRAINZALBUMARTISTID'] ?? null, $tags['ALBUMARTIST'] ?? null ),
				];
				$seenThisTrack = [];
				foreach( $credits as $as => $pairs ) {
					foreach( $pairs as $pair ) {
						$mbid = strtolower( $pair['mbid'] );
						if( $mbid === FISHEYEALBUM_MB_VARIOUS_ARTISTS ) {
							continue;
						}
						$people[$mbid] ??= [ 'mbid' => $mbid, 'names' => [], 'albums' => [], 'tracks' => 0, 'album_artist' => false, 'track_artist' => false ];
						if( $pair['name'] !== null && !in_array( $pair['name'], $people[$mbid]['names'], true ) ) {
							$people[$mbid]['names'][] = $pair['name'];
						}
						$people[$mbid]['albums'][$albumIndex] = true;
						$people[$mbid][$as] = true;
						if( !isset( $seenThisTrack[$mbid] ) ) {
							$people[$mbid]['tracks']++;
							$seenThisTrack[$mbid] = true;
						}
					}
				}
			}
		}
		foreach( $people as &$person ) {
			$person['albums'] = count( $person['albums'] );
		}
		unset( $person );
		usort( $people, fn( $a, $b ) => [ $b['tracks'], $a['names'][0] ?? $a['mbid'] ] <=> [ $a['tracks'], $b['names'][0] ?? $b['mbid'] ] );
		return [ 'albums' => count( $albumFolders ), 'tracks' => $trackTotal, 'unreadable' => $unreadable, 'people' => $people ];
	}

	/**
	 * Scan one album folder's track files, read each one's embedded tags, and sort into final
	 * (disc, track) order - shared by registerFromDisk() (a brand new album) and reloadTracks()
	 * (re-scanning an already-registered one, e.g. after re-tagging in Picard or a metadata-schema
	 * change like promoting a new common tag).
	 *
	 * @param string $pAbsoluteFolder
	 * @param bool $pWithDuration  also probe each file's real duration (one ffprobe per track) -
	 *                             skipped by surveyArtistCredits(), which only needs the tags
	 * @return array  each entry: 'relative' (path relative to $pAbsoluteFolder), 'disc', 'tags',
	 *                'track_num', 'title' (+ 'duration_ms') - empty if no track files found
	 */
	private static function scanTrackFiles( string $pAbsoluteFolder, bool $pWithDuration = true ): array {
		// Multi-disc sets (Black Sabbath-style CD1/CD2 subfolders) walked one level deep; a flat
		// album folder (Bob Marley/Classic Composers-style) has its track files directly inside -
		// both shapes scanned the same way, disc number just stays 1 for the flat case.
		$trackFiles = []; // [ 'relative' => path relative to $pAbsoluteFolder, 'disc' => int ]
		foreach( scandir( $pAbsoluteFolder ) as $entry ) {
			if( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$entryPath = $pAbsoluteFolder.$entry;
			if( is_dir( $entryPath ) ) {
				if( !preg_match( '/^CD\s*(\d+)/i', $entry, $discMatch ) ) {
					continue; // not a disc subfolder - e.g. artwork scans sitting alongside
				}
				foreach( scandir( $entryPath ) as $subEntry ) {
					$ext = strtolower( pathinfo( $subEntry, PATHINFO_EXTENSION ) );
					if( is_file( $entryPath.'/'.$subEntry ) && in_array( $ext, FISHEYEALBUM_TRACK_EXTENSIONS, true ) ) {
						$trackFiles[] = [ 'relative' => $entry.'/'.$subEntry, 'disc' => (int)$discMatch[1] ];
					}
				}
			} elseif( is_file( $entryPath ) ) {
				$ext = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
				if( in_array( $ext, FISHEYEALBUM_TRACK_EXTENSIONS, true ) ) {
					$trackFiles[] = [ 'relative' => $entry, 'disc' => 1 ];
				}
			}
		}
		if( empty( $trackFiles ) ) {
			return [];
		}

		// Read tags and sort by (disc, track-number-from-tag-or-filename) - embedded tags take
		// priority, since most tracks already carry their own real metadata; filename order is
		// only the fallback for untagged files (some releases have zero embedded tags at all).
		foreach( $trackFiles as &$track ) {
			$tags = self::readTrackTags( $pAbsoluteFolder.$track['relative'] );
			$track['tags'] = $tags;
			$trackNum = self::tagString( $tags['TRACK'] ?? $tags['TRACKNUMBER'] ?? null );
			if( $trackNum !== null ) {
				$track['track_num'] = (int)explode( '/', $trackNum )[0];
			} else {
				// Fallback: leading "NN " / "NN - " / "NN." in the filename, same convention
				// mpeg2_tidy's own TV-episode naming already relies on.
				preg_match( '/^(\d+)/', basename( $track['relative'] ), $m );
				$track['track_num'] = isset( $m[1] ) ? (int)$m[1] : 0;
			}
			if( !empty( $tags['DISC'] ) ) {
				$track['disc'] = (int)explode( '/', self::tagString( $tags['DISC'] ) )[0];
			}
			$track['title'] = self::tagString( $tags['TITLE'] ?? null ) ?? pathinfo( $track['relative'], PATHINFO_FILENAME );
			// From the file's own container, not the (culled, unreliable) embedded TLEN tag - same
			// source episodes/featurettes already use for their own duration.
			if( $pWithDuration ) {
				$track['duration_ms'] = \Bitweaver\Liberty\mime_film_get_duration_ms( $pAbsoluteFolder.$track['relative'] );
			}
		}
		unset( $track );
		usort( $trackFiles, fn( $a, $b ) => [ $a['disc'], $a['track_num'] ] <=> [ $b['disc'], $b['track_num'] ] );

		return $trackFiles;
	}

	/**
	 * Whether a folder under an artist/composer's own directory is a real album worth offering -
	 * used by load_album.php's own candidate scan to skip a same-level folder that isn't one at
	 * all (an "Artwork"/"Videos"/scans-style extras folder sitting alongside real albums), rather
	 * than listing it and only finding out via a failed import ("No track files found in ..."). No
	 * name-based denylist - genuinely checking for real track files handles any such folder by
	 * whatever it happens to be called, not just the ones already seen.
	 *
	 * Deliberately NOT scanTrackFiles() - that reads embedded tags and probes real duration (an
	 * ffprobe spawn each) for every track it finds, fine for actually registering one album but
	 * far too expensive just to answer "does this folder have anything in it at all", run once per
	 * candidate on every load_album.php page view. A 22-disc box set with ~20 tracks each turned a
	 * page load into ~900 ffprobe spawns before this - same file-extension check, stopping at the
	 * first match instead of reading every file found.
	 *
	 * @param string $pAbsoluteFolder
	 * @return bool
	 */
	public static function folderHasTracks( string $pAbsoluteFolder ): bool {
		foreach( scandir( $pAbsoluteFolder ) ?: [] as $entry ) {
			if( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$entryPath = $pAbsoluteFolder.$entry;
			if( is_dir( $entryPath ) ) {
				if( !preg_match( FISHEYEALBUM_DISC_FOLDER_PATTERN, $entry ) ) {
					continue;
				}
				foreach( scandir( $entryPath ) ?: [] as $subEntry ) {
					if( is_file( $entryPath.'/'.$subEntry )
						&& in_array( strtolower( pathinfo( $subEntry, PATHINFO_EXTENSION ) ), FISHEYEALBUM_TRACK_EXTENSIONS, true ) ) {
						return true;
					}
				}
			} elseif( is_file( $entryPath )
				&& in_array( strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) ), FISHEYEALBUM_TRACK_EXTENSIONS, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a folder is really a box set of distinct recordings rather than one multi-disc
	 * release - a real CDxx/Discxx subfolder still sitting directly inside it. Deliberately not a
	 * count threshold (">1 disc") - every genuine single-work multi-disc release already got its
	 * CD1/CD2 layer flattened away by hand this same session (tracks carrying their own real DISC
	 * tag need no folder-level grouping at all), so any CDxx folder still surviving now means it
	 * was kept on purpose, however many there are.
	 *
	 * @param string $pAbsoluteFolder
	 * @return bool
	 */
	public static function isBoxSetFolder( string $pAbsoluteFolder ): bool {
		foreach( scandir( $pAbsoluteFolder ) ?: [] as $entry ) {
			if( preg_match( FISHEYEALBUM_DISC_FOLDER_PATTERN, $entry ) && is_dir( $pAbsoluteFolder.$entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a folder directly inside an artist/composer folder is a group folder - one strip of
	 * the artist page, titled with the folder's own name (Compilation, Studio, Baroque, Modern...).
	 * Recognised by shape, not name: no tracks of its own, not a box set (its subfolders aren't
	 * CDxx/Vol-numbered discs), and holding at least one real album or box set. Artwork/Scans-style
	 * extras and the Videos folder hold no albums, so never qualify. Each album or box set inside
	 * one is a tile in that strip; anything sitting directly in the artist folder goes in the first,
	 * unlabelled strip (FisheyeMediaGallery::getCategorizedItems()).
	 *
	 * @param string $pAbsoluteFolder  the folder, trailing slash optional
	 * @return bool
	 */
	public static function isGroupFolder( string $pAbsoluteFolder ): bool {
		$folder = rtrim( $pAbsoluteFolder, '/' ).'/';
		$name = basename( $folder );
		if( str_starts_with( $name, '.' ) || $name === FISHEYEMEDIA_VIDEOS_GALLERY_TITLE || !is_dir( $folder ) ) {
			return false;
		}
		if( self::folderHasTracks( $folder ) || self::isBoxSetFolder( $folder ) ) {
			return false;
		}
		foreach( scandir( $folder ) ?: [] as $entry ) {
			if( !str_starts_with( $entry, '.' ) && is_dir( $folder.$entry )
				&& ( self::folderHasTracks( $folder.$entry.'/' ) || self::isBoxSetFolder( $folder.$entry.'/' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One disc's own title within a box set, distinct from the box's overall ALBUM tag (which
	 * FISHEYEALBUM_IGNORED_TAG_KEYS already drops as noise everywhere else, since it's normally
	 * identical to the album's own already-known title) - TSST (ID3v2) / DISCSUBTITLE (Vorbis,
	 * Picard's own equivalent naming) is where a disc's real content actually lives, confirmed
	 * live varying disc-to-disc on a real 22-disc release ("Ballets, Volume 1", "Concertos", ...)
	 * while ALBUM stayed fixed at the box's own title on every single track.
	 *
	 * @param string $pAbsoluteDiscFolder
	 * @return string|null
	 */
	public static function getDiscTitle( string $pAbsoluteDiscFolder ): ?string {
		foreach( scandir( $pAbsoluteDiscFolder ) ?: [] as $entry ) {
			$ext = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
			if( in_array( $ext, FISHEYEALBUM_TRACK_EXTENSIONS, true ) ) {
				$tags = self::readTrackTags( $pAbsoluteDiscFolder.$entry );
				return self::tagString( $tags['TSST'] ?? $tags['DISCSUBTITLE'] ?? null );
			}
		}
		return null;
	}

	/**
	 * Create (or find) a nested "container folder" gallery, linked into $pParentGalleryTitle (the
	 * artist/composer's own gallery - nesting a gallery inside another is a safe, already-
	 * anticipated case, see FisheyeGallery::addItem()'s own docblock). Shared by both of
	 * load_album.php's container shapes - a box set (isBoxSetFolder(), real CDxx/Volume-numbered
	 * discs of one work) and a discography category (isCategoryFolder(), Studio/Live/Compilation/
	 * Remaster/Single/Soundtrack) - both are just "this folder isn't an album itself, it holds real
	 * album folders underneath", the same nested-gallery treatment either way.
	 *
	 * Deliberately cheap - no track scanning at all, same one-off "create the gallery first, cheap/
	 * instant" step load_music.php's own top-level version already establishes for an artist/
	 * composer gallery. Populating it with real albums (or, for a box set, per-disc albums) is then
	 * just a normal load_album.php visit pointed at this new gallery - its own contents show up as
	 * ordinary candidates there, letting Lester pick a handful at a time rather than everything
	 * inside importing (and every track of it) in one single request.
	 *
	 * @param string $pRelativeFolderPath  the container's own folder, relative to
	 *                                     mime_film_get_storage_root() - same shape
	 *                                     registerFromDisk() takes
	 * @param int    $pParentContentId     content_id of the artist/composer gallery this container's
	 *                                     own nested gallery gets linked into
	 * @return array 'gallery_id'=>the container's own new/existing gallery, or 'error'=>string
	 */
	public static function createSubGallery( string $pRelativeFolderPath, int $pParentContentId ): array {
		$root = \Bitweaver\Liberty\mime_film_get_storage_root();
		if( empty( $root ) ) {
			return [ 'error' => 'fisheye_disk_storage_root is not configured.' ];
		}
		if( !is_dir( $root.rtrim( $pRelativeFolderPath, '/' ).'/' ) ) {
			return [ 'error' => 'Folder not found under the configured storage root: '.$pRelativeFolderPath ];
		}
		$containerTitle = basename( rtrim( $pRelativeFolderPath, '/' ) );

		// Passing the pagination style through to the initial store() call itself (not just the
		// storePreference() below) is what gets rows_per_page/cols_per_page force-set to 4*8 at
		// creation time - see findOrCreateNestedGallery()'s own docblock for why a freshly created
		// gallery without it kept a generic default row count instead. Created as a
		// FisheyeMediaGallery - music_gallery_icons_inc.tpl/the music_grid layout call its own
		// methods, which a plain FisheyeGallery doesn't have.
		$result = FisheyeMediaGallery::findOrCreateNestedGallery( $containerTitle, (int)$pParentContentId, FISHEYE_PAGINATION_MUSIC_GRID );
		if( empty( $result['error'] ) && empty( $result['already'] ) ) {
			// Music-grid pagination only makes sense freshly created, not re-applied to a gallery
			// that might already have its own preference set some other way. content_id (not
			// gallery_id, fisheye_gallery's own separate PK - see findOrCreateNestedGallery()'s own
			// docblock) is what the (null, $pContentId) constructor slot expects.
			$gallery = new FisheyeGallery( null, $result['content_id'] );
			$gallery->load();
			$gallery->storePreference( 'gallery_pagination', FISHEYE_PAGINATION_MUSIC_GRID );
		}
		return $result;
	}

	/**
	 * Re-scan an already-registered album's own folder and refresh its track/common-tag xrefs -
	 * for a re-tag in Picard after the fact, or a metadata-schema change here (a newly-promoted
	 * common tag, like this file's own compilation/release_status additions) that a plain edit
	 * page reload can't retroactively apply to already-registered albums. Cover art is untouched.
	 * 'track' and every currently-defined common-tag item are reconciled against the files, never
	 * wiped (see reconcileXrefItem()): unchanged rows keep their entry_date, changed values archive
	 * the old row and insert the new one, and a hand-corrected row is never overwritten.
	 *
	 * Folder resolution mirrors load_album.php's own: this album's title is expected to match a
	 * real folder directly under its parent gallery's own folder under Music/ - same one-level
	 * layout load_music.php's candidate scan uses.
	 *
	 * @return array 'tracks'=>count, or 'error'=>string on failure
	 */
	public function reloadTracks(): array {
		if( empty( $this->getParentGalleries() ) ) {
			return [ 'error' => 'This album is not linked into a collection gallery - cannot resolve its folder.' ];
		}
		$absoluteFolder = $this->getImageStorageRoot();
		if( empty( $absoluteFolder ) || !is_dir( $absoluteFolder ) ) {
			return [ 'error' => 'Folder not found under the configured storage root: '.$absoluteFolder ];
		}

		$trackFiles = self::scanTrackFiles( $absoluteFolder );
		if( empty( $trackFiles ) ) {
			return [ 'error' => 'No track files found in '.$absoluteFolder ];
		}
		[ $commonTags, $promotedTagKeys ] = self::extractCommonTags( $trackFiles );

		// 'image' xrefs (cover art) are deliberately left alone - this is a track/tag refresh only.
		$counts = $this->reconcileAlbumXrefs( $trackFiles, $commonTags, $promotedTagKeys );

		return [ 'tracks' => count( $trackFiles ), 'counts' => $counts ];
	}

	/**
	 * Register one album folder - every track file inside becomes a 'track' xref (disc/track
	 * number and title read from embedded tags when present, falling back to filename order and
	 * the bare filename otherwise), and a real cover.jpg/folder.jpg (FISHEYEALBUM_COVER_NAMES)
	 * sitting in the same folder gets attached as the thumbnail directly - no Plex round trip
	 * needed for the common case where the release already carries its own cover art.
	 *
	 * Idempotent the same way FisheyeSeason::registerFromDisk() is - re-running against an
	 * already-registered album just returns 'already' rather than creating a duplicate.
	 *
	 * @param string $pRelativeFolderPath  path relative to mime_film_get_storage_root(), e.g.
	 *                                     'Music Classical/Classic Composers/Vivaldi, Antonio
	 *                                     Lucio - VIVALDI Venetian Splendour (The Classic
	 *                                     Composers - Baroque 1)'
	 * @param string|null $pTitle          defaults to the folder's own basename - deliberately
	 *                                     never anything else, even when a nicer display name is
	 *                                     known (a box set disc's own TSST-derived title, say):
	 *                                     getImageStorageRoot() resolves this album's real folder
	 *                                     from its title, so the two must always match exactly. A
	 *                                     nicer name belongs in $pDescription instead.
	 * @param int $pGalleryContentId       collection gallery to link this album into (created
	 *                                     separately, same convention as FisheyeFilm) - a
	 *                                     content_id, not a title: a bare-title lookup here used to
	 *                                     be safe back when every gallery had a unique name, but
	 *                                     "Studio"/"Live"/"Compilation" etc. are now deliberately
	 *                                     shared names across different artists (see
	 *                                     FisheyeMediaGallery::findOrCreateNestedGallery()'s own
	 *                                     docblock), so it silently linked into whichever
	 *                                     same-titled gallery happened to exist first (found live:
	 *                                     every artist's newly-loaded albums were ending up in Bob
	 *                                     Marley's own Studio/Live/Compilation instead of their
	 *                                     own). The caller already has the exact gallery in
	 *                                     hand (it's the one load_album.php is browsing) - no lookup
	 *                                     needed at all once addressed by content_id.
	 * @param string|null $pDescription    shown on the album's own view page (same content_store
	 *                                     'edit'/description field every other content type uses) -
	 *                                     for a box set disc's own real content (its TSST/
	 *                                     DISCSUBTITLE tag), which the bare "CD01"-style folder
	 *                                     name the title is stuck with never conveys on its own
	 * @param bool $pFetchDiscogs           opt-in, same "slower, one round trip per album" tradeoff
	 *                                       as load_video.php's own Plex-image checkbox - only
	 *                                       attempted when a real MUSICBRAINZ_ALBUMID was actually
	 *                                       found among this album's tags, since fetchDiscogsLink()
	 *                                       needs one to look up (see its own docblock)
	 * @param string|null $pCategory        the discography-category folder (Studio/Live/
	 *                                      Compilation/...) this album's own folder sits directly
	 *                                      inside, if any - stored as a plain 'category' xref for
	 *                                      display-side grouping (Lester, 2026-09-24: deliberately
	 *                                      NOT a nested gallery per category - load_album.php just
	 *                                      flattens straight through into this artist's own gallery
	 *                                      instead, one xref per album rather than a whole gallery
	 *                                      row per artist per category). Distinct from the
	 *                                      MusicBrainz-derived 'release_type' common tag (see
	 *                                      FISHEYEALBUM_COMMON_TAG_ALTERNATES) since a folder
	 *                                      category like "Tribute"/"Other" isn't a real MB concept
	 *                                      and the two won't always agree.
	 * @return array 'already'=>content_id, or 'created'=>content_id plus 'tracks'/'cover'/'discogs'
	 *               summary info, or 'error'=>string on failure
	 */
	public static function registerFromDisk( string $pRelativeFolderPath, ?string $pTitle = null, int $pGalleryContentId = 0, ?string $pDescription = null, bool $pFetchDiscogs = false, ?string $pCategory = null ): array {
		global $gBitDb;

		$root = \Bitweaver\Liberty\mime_film_get_storage_root();
		if( empty( $root ) ) {
			return [ 'error' => 'fisheye_disk_storage_root is not configured.' ];
		}
		$folderPath = rtrim( $pRelativeFolderPath, '/' ).'/';
		$absoluteFolder = $root.$folderPath;
		if( !is_dir( $absoluteFolder ) ) {
			return [ 'error' => 'Folder not found under the configured storage root: '.$folderPath ];
		}

		$title = trim( (string)$pTitle ) ?: basename( rtrim( $pRelativeFolderPath, '/' ) );

		$existingContentId = $gBitDb->getOne(
			"SELECT content_id FROM liberty_content WHERE content_type_guid = 'fisheyealbum' AND title = ?",
			[ $title ]
		);
		if( $existingContentId ) {
			return [ 'already' => $existingContentId ];
		}

		$trackFiles = self::scanTrackFiles( $absoluteFolder );
		if( empty( $trackFiles ) ) {
			return [ 'error' => 'No track files found in '.$folderPath ];
		}

		$album = new FisheyeAlbum();
		[ $commonTags, $promotedTagKeys ] = self::extractCommonTags( $trackFiles );
		$storeHash = [ 'title' => $title ];
		if( $pDescription !== null && $pDescription !== '' ) {
			$storeHash['edit'] = $pDescription;
		}
		if( !$album->store( $storeHash ) ) {
			return [ 'error' => implode( '; ', $album->mErrors ) ];
		}
		$album->load();

		$linked = false;
		if( $pGalleryContentId ) {
			$gallery = new FisheyeGallery( null, $pGalleryContentId );
			$gallery->load();
			$linked = $gallery->addItem( $album->mContentId );
		}

		$album->reconcileAlbumXrefs( $trackFiles, $commonTags, $promotedTagKeys );
		if( $pCategory !== null && $pCategory !== '' ) {
			$categoryXrefHash = [ 'content_id' => $album->mContentId, 'item' => 'category', 'xkey_ext' => $pCategory ];
			$album->storeXref( $categoryXrefHash );
		}

		$discogsResult = null;
		if( $pFetchDiscogs && !empty( $commonTags['mbid'] ) ) {
			$discogsResult = $album->fetchDiscogsLink();
		}

		$coverAttached = $album->attachCoverFromDisk( $absoluteFolder, $trackFiles );

		return [
			'created' => $album->mContentId,
			'linked'  => $linked,
			'tracks'  => count( $trackFiles ),
			'cover'   => $coverAttached,
			'discogs' => $discogsResult,
		];
	}

	/**
	 * Look up this album's own MusicBrainz release (via its already-stored 'mbid' xref) for a
	 * linked Discogs release, and store it the same way FisheyeFilm stores 'imdb'/'tmdb' - a plain
	 * 'discogs' xref item whose xkey is the bare Discogs release id, rendered as a real link by
	 * view_album.php the same generic cross_ref_href way (see the 'liberty_xref_item' row Lester
	 * added for content_type_guid='fisheyealbum', matching fisheyefilm/fisheyeseason/fisheyeprogram's
	 * own imdb/tmdb rows exactly: x_group='external', template='href').
	 *
	 * MusicBrainz doesn't hand out Discogs ids directly - a release only has one if someone has
	 * already linked the two as a community edit, discoverable via the release's own url-rels
	 * (see https://musicbrainz.org/ws/2/release/<mbid>?inc=url-rels&fmt=json). No such link existing
	 * is a normal, common outcome, not an error - most of Lester's still-unmatched bootleg/demo
	 * material will never have one either way.
	 *
	 * @return array 'discogs_id'=>string plus 'url'=>string on a fresh find, 'already'=>true if a
	 *               discogs xref already exists (never re-fetched), 'none'=>true if MusicBrainz has
	 *               no linked Discogs release, or 'error'=>string
	 */
	public function fetchDiscogsLink(): array {
		if( !$this->mContentId ) {
			return [ 'error' => 'Album not loaded.' ];
		}
		$this->loadXrefInfo();
		$mbid = null;
		if( $this->mXrefInfo ) {
			foreach( $this->liveXrefs() as $xref ) {
				if( $xref['item'] === 'mbid' ) {
					$mbid = $xref['xkey_ext'];
				} elseif( $xref['item'] === 'discogs' ) {
					return [ 'already' => true ];
				}
			}
		}
		if( empty( $mbid ) ) {
			return [ 'error' => 'This album has no MusicBrainz Album Id stored - not MB-matched yet.' ];
		}

		global $gBitSystem;
		$contact = $gBitSystem->getConfig( 'fisheye_musicbrainz_contact', '' );
		$context = stream_context_create( [ 'http' => [
			'header'  => "User-Agent: fisheye-discogs-lookup/1.0".( !empty( $contact ) ? " ( $contact )" : '' )."\r\n",
			'timeout' => 15,
		] ] );
		$json = @file_get_contents( "https://musicbrainz.org/ws/2/release/$mbid?inc=url-rels&fmt=json", false, $context );
		if( $json === false ) {
			return [ 'error' => 'MusicBrainz lookup failed (network error or bad MBID).' ];
		}
		$data = json_decode( $json, true );
		$discogsId = null;
		foreach( $data['relations'] ?? [] as $relation ) {
			$url = $relation['url']['resource'] ?? '';
			if( str_contains( $url, 'discogs.com' ) && preg_match( '/(\d+)\/?$/', $url, $matches ) ) {
				$discogsId = $matches[1];
				break;
			}
		}
		if( $discogsId === null ) {
			return [ 'none' => true ];
		}

		$discogsXrefHash = [ 'content_id' => $this->mContentId, 'item' => 'discogs', 'xkey' => $discogsId ];
		$this->storeXref( $discogsXrefHash );

		return [ 'discogs_id' => $discogsId, 'url' => 'https://www.discogs.com/release/'.$discogsId ];
	}

}

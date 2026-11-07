<?php
/**
 * One video course at a time from the Local Tutorials folder: register the course, load its seasons, load the files that go with them. The
 * tutorial counterpart of FisheyeAuthorWorkbench - a front page lists every course folder and what is left, and each step is recomputed from the
 * database and the disk, so it can be repeated. Not a content type: a course is a FisheyeProgram and a section a FisheyeSeason, as for TV.
 *
 * The tree is Local Tutorials/<course>/Season NN - <section title>/ (see import/tutorials-tidy in webstack), under the Tutorials Storage Path.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeGallery;

class FisheyeTutorialWorkbench {
	/** Seasons loaded, or seasons whose files are loaded, per press. */
	public const BATCH = 10;

	/** The Local Tutorials folder with its closing slash, or '' when there is no root set up or no such folder. */
	public static function root(): string {
		$root = FisheyeProgram::getTutorialsRoot();
		return $root !== '' && is_dir( $root.FisheyeProgram::TUTORIALS_FOLDER ) ? $root.FisheyeProgram::TUTORIALS_FOLDER.'/' : '';
	}

	/** Whether the top-level Local Tutorials gallery exists - a course is filed in it when it is registered. */
	public static function hasTopGallery(): bool {
		return (bool)FisheyeGallery::getTopGalleryId( FisheyeProgram::TUTORIALS_FOLDER );
	}

	/**
	 * Make the top-level Local Tutorials gallery (a grid of courses, layout program_grid) when it is not there; a course is filed in it when registered.
	 *
	 * @return string|null  an error text, null when the gallery was made or already exists
	 */
	public static function createTopGallery(): ?string {
		if( self::hasTopGallery() ) {
			return null;
		}
		$gallery = new FisheyeGallery();
		$storeHash = [ 'title' => FisheyeProgram::TUTORIALS_FOLDER, 'gallery_pagination' => FISHEYE_PAGINATION_PROGRAM_GRID ];   // store() takes it by reference
		if( !$gallery->store( $storeHash ) ) {
			return implode( '; ', array_map( 'strval', array_values( $gallery->mErrors ) ) );
		}
		$gallery->storePreference( 'gallery_pagination', FISHEYE_PAGINATION_PROGRAM_GRID );
		return null;
	}

	/** The course folder names, alphabetically (case ignored; "2021 ..." before "60 ...", not 60 before 2021 as a natural sort would put them). */
	public static function courses(): array {
		$root = self::root();
		$names = $root === '' ? [] : array_filter( scandir( $root ) ?: [], fn( $d ) => $d[0] !== '.' && is_dir( $root.$d ) );
		$names = array_values( $names );
		sort( $names, SORT_STRING | SORT_FLAG_CASE );
		return $names;
	}

	/**
	 * Where a course stands: whether it is registered, its season folders (only those with a lesson video are seasons) with the season each
	 * became and how many files are on disk against registered, and what each step has left.
	 *
	 * @return array{name:string,error:?string,contentId:int,galleryId:int,url:?string,legacy:bool,seasons:list<array>,seasonsLoaded:int,seasonsTodo:int,filesTodo:int,done:bool}
	 */
	public static function status( string $pCourse ): array {
		global $gBitDb;
		$st = [ 'name' => $pCourse, 'error' => null, 'contentId' => 0, 'galleryId' => 0, 'url' => null, 'legacy' => false, 'courseFiles' => [ 'disk' => 0, 'have' => 0, 'todo' => false ], 'seasons' => [], 'seasonsLoaded' => 0, 'seasonsTodo' => 0, 'filesTodo' => 0, 'done' => false ];
		$dir = self::root().$pCourse.'/';
		if( $pCourse === '' || str_contains( $pCourse, '/' ) || !is_dir( $dir ) ) {
			$st['error'] = 'That course folder is not in the Local Tutorials folder.';
			return $st;
		}
		$program = $gBitDb->getRow( "SELECT lc.`content_id`, lc.`content_type_guid`, g.`gallery_id` FROM `".BIT_DB_PREFIX."liberty_content` lc JOIN `".BIT_DB_PREFIX."fisheye_gallery` g ON g.`content_id` = lc.`content_id`
			WHERE lc.`content_type_guid` IN ( 'fisheyecourse', 'fisheyeprogram' ) AND lc.`title` = ?", [ $pCourse ] );
		$st['contentId'] = (int)( $program['content_id'] ?? 0 );
		$st['galleryId'] = (int)( $program['gallery_id'] ?? 0 );
		$st['legacy'] = ( $program['content_type_guid'] ?? '' ) === 'fisheyeprogram';
		if( $st['galleryId'] ) {
			$urlHash = [ 'gallery_id' => $st['galleryId'] ];   // by reference: a variable, never a literal
			$st['url'] = FisheyeGallery::getDisplayUrlFromHash( $urlHash );
		}
		$have = [];
		if( $st['contentId'] ) {
			foreach( $gBitDb->getAll( "SELECT lc.`content_id`, lc.`title` FROM `".BIT_DB_PREFIX."liberty_content` lc JOIN `".BIT_DB_PREFIX."fisheye_gallery_image_map` m ON m.`item_content_id` = lc.`content_id`
				WHERE m.`gallery_content_id` = ? AND lc.`content_type_guid` IN ( 'fisheyeseason', 'fisheyesection' )", [ $st['contentId'] ] ) ?: [] as $row ) {
				$have[$row['title']] = (int)$row['content_id'];
			}
		}
		$registered = [];
		if( $have ) {
			$marks = implode( ',', array_fill( 0, count( $have ), '?' ) );
			foreach( $gBitDb->getAll( "SELECT `content_id`, COUNT(*) AS n FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `item` = 'extra' AND `end_date` IS NULL AND `content_id` IN ($marks) GROUP BY `content_id`", array_values( $have ) ) ?: [] as $row ) {
				$registered[(int)$row['content_id']] = (int)$row['n'];
			}
		}
		$folders = array_filter( scandir( $dir ) ?: [], fn( $d ) => $d[0] !== '.' && is_dir( $dir.$d ) );
		natcasesort( $folders );
		foreach( $folders as $folder ) {
			if( !FisheyeSection::folderHasLessons( $dir.$folder ) ) {
				continue;
			}
			$cid = $have[$pCourse.' - '.$folder] ?? 0;
			$disk = count( FisheyeSection::scanExtraFilesIn( $dir.$folder.'/' ) );
			$row = [ 'folder' => $folder, 'contentId' => $cid, 'filesDisk' => $disk, 'filesHave' => $registered[$cid] ?? 0 ];
			$row['filesTodo'] = $cid && $disk !== $row['filesHave'];
			$st['seasonsLoaded'] += $cid ? 1 : 0;
			$st['seasonsTodo'] += $cid ? 0 : 1;
			$st['filesTodo'] += $row['filesTodo'] ? 1 : 0;
			$st['seasons'][] = $row;
		}
		if( $st['contentId'] ) {
			// the files that belong to the whole course (see FisheyeCourse::scanFilesIn()): on disk against registered
			$st['courseFiles']['disk'] = count( FisheyeCourse::scanFilesIn( $dir ) );
			$st['courseFiles']['have'] = (int)$gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'extra' AND `end_date` IS NULL", [ $st['contentId'] ] );
			$st['courseFiles']['todo'] = $st['courseFiles']['disk'] !== $st['courseFiles']['have'];
		}
		$st['done'] = $st['contentId'] && !$st['seasonsTodo'] && !$st['filesTodo'] && !$st['courseFiles']['todo'];
		return $st;
	}

	/** Every course folder with what is left for it: [ name => status ]. */
	public static function overview(): array {
		$out = [];
		foreach( self::courses() as $course ) {
			$out[$course] = self::status( $course );
		}
		return $out;
	}

	/**
	 * A course registered before tutorials had their own types is a TV show and its sections TV seasons: change them to the tutorial types, keeping every
	 * row, thumbnail and file reference they have. Done in the application (not by hand in the database) so it is one button, repeatable, in one transaction.
	 *
	 * @return array{courses:int,sections:int,errors:list<string>}
	 */
	public static function convertCourse( string $pCourse ): array {
		global $gBitDb;
		$st = self::status( $pCourse );
		$ret = [ 'courses' => 0, 'sections' => 0, 'errors' => [] ];
		if( $st['error'] || !$st['contentId'] || !$st['legacy'] ) {
			$ret['errors'][] = $st['error'] ?? 'That course is not registered as a TV show.';
			return $ret;
		}
		$gBitDb->StartTrans();
		$gBitDb->query( "UPDATE `".BIT_DB_PREFIX."liberty_content` SET `content_type_guid` = 'fisheyecourse' WHERE `content_id` = ? AND `content_type_guid` = 'fisheyeprogram'", [ $st['contentId'] ] );
		$ret['courses'] = 1;
		foreach( array_column( $st['seasons'], 'contentId' ) as $cid ) {
			if( $cid ) {
				$gBitDb->query( "UPDATE `".BIT_DB_PREFIX."liberty_content` SET `content_type_guid` = 'fisheyesection' WHERE `content_id` = ? AND `content_type_guid` = 'fisheyeseason'", [ $cid ] );
				$ret['sections']++;
			}
		}
		$gBitDb->CompleteTrans();
		// getLibertyObject() looks a content item's type up through a query cached for an hour: without this flush a converted course keeps coming back as
		// its old type. (The cache is per host name the site is served on - a site served on more than one clears the others from /srv/tmp as usual.)
		if( !empty( $gBitDb->mDb ) && method_exists( $gBitDb->mDb, 'CacheFlush' ) ) {
			$gBitDb->mDb->CacheFlush();
		}
		return $ret;
	}

	/** Step 1: make the course (a FisheyeProgram, filed in the Local Tutorials gallery). */
	public static function registerCourse( string $pCourse ): array {
		$st = self::status( $pCourse );
		if( $st['error'] ) {
			return [ 'errors' => [ $st['error'] ] ];
		}
		$made = FisheyeProgram::registerFromDisk( $pCourse );
		return empty( $made['error'] ) ? [ 'errors' => [] ] : [ 'errors' => [ $made['error'] ] ];
	}

	/**
	 * Step 2: load up to BATCH seasons not yet loaded, then give the course a picture if it has none.
	 * @return array{loaded:int,episodes:int,errors:list<string>}
	 */
	public static function loadSeasons( string $pCourse ): array {
		$st = self::status( $pCourse );
		$ret = [ 'loaded' => 0, 'episodes' => 0, 'errors' => [] ];
		if( $st['error'] || !$st['contentId'] ) {
			$ret['errors'][] = $st['error'] ?? 'Register the course first.';
			return $ret;
		}
		foreach( array_slice( array_filter( $st['seasons'], fn( $s ) => !$s['contentId'] ), 0, self::BATCH ) as $season ) {
			$made = FisheyeSeason::registerFromDisk( $pCourse, $season['folder'], $st['contentId'] );
			if( !empty( $made['error'] ) ) {
				$ret['errors'][] = $season['folder'].': '.$made['error'];
			} else {
				$ret['loaded']++;
				$ret['episodes'] += is_countable( $made['episodes'] ?? null ) ? count( $made['episodes'] ) : 0;
			}
		}
		if( $ret['loaded'] ) {
			$program = FisheyeGallery::lookup( [ 'content_id' => $st['contentId'] ] );
			if( $program instanceof FisheyeProgram ) {
				$program->grabFrameIfNoImage();
			}
		}
		return $ret;
	}

	/**
	 * Step 3: load the files of up to BATCH seasons whose folder holds a different number of files than are registered.
	 * @return array{seasons:int,files:int,errors:list<string>}
	 */
	public static function loadFiles( string $pCourse ): array {
		$st = self::status( $pCourse );
		$ret = [ 'seasons' => 0, 'files' => 0, 'errors' => [] ];
		// the files of the whole course first (one registration, however many there are)
		if( $st['contentId'] && $st['courseFiles']['todo'] ) {
			$course = FisheyeGallery::lookup( [ 'content_id' => $st['contentId'] ] );
			if( $course instanceof FisheyeCourse ) {
				$done = $course->registerFilesFromDisk();
				$ret['files'] += $done['files'];
				foreach( $done['errors'] as $error ) {
					$ret['errors'][] = $error;
				}
				if( $done['errors'] ) {
					return $ret;
				}
			}
		}
		if( !FisheyeSection::extraItemDefined() ) {
			$ret['errors'][] = 'The media scheme has no "extra" item for seasons yet, so no file can be registered: apply the scheme from the Local Scheme page (admin menu), then press Load files again.';
			return $ret;
		}
		foreach( array_slice( array_filter( $st['seasons'], fn( $s ) => $s['filesTodo'] ), 0, self::BATCH ) as $season ) {
			$obj = \Bitweaver\Fisheye\FisheyeImage::lookup( [ 'content_id' => $season['contentId'] ] );
			if( !( $obj instanceof FisheyeSection ) || !$obj->isValid() ) {
				continue;
			}
			$done = $obj->registerFilesFromDisk();
			$ret['seasons']++;
			$ret['files'] += $done['files'];
			foreach( $done['errors'] as $error ) {
				$ret['errors'][] = $season['folder'].' - '.$error;
			}
		}
		return $ret;
	}
}

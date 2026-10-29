<?php
/**
 * Series written by several authors (the Dragonriders of Pern, the Brain & Brawn Ships), kept in the Library tree as
 * Library/Various Authors/<series>/<book>/ with a SeriesCatalogue.csv in the series folder listing every book of the series
 * whoever wrote it.
 *
 * The catalogue builds the database side: a "Various Authors" gallery under Library (as an author's gallery is made), the series
 * gallery under that, every book the catalogue names - found by ISBN, else title and author - added to it in catalogue order
 * (added, not moved: each stays in its own author's series), and each author's own gallery of the series filed under it - made when an author
 * with a gallery has none, and the author's books of the series that sit loose in their gallery moved into it only when asked. That
 * last link is what FisheyeMediaGallery::getFullSeries() looks for to put a Full Series tile on the author's page.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

class FisheyeVariousAuthors {
	public const CATALOGUE = 'SeriesCatalogue.csv';

	/** The series folders under Library/Various Authors that hold a catalogue: [ series name => csv path ]. */
	public static function catalogues(): array {
		$root = FisheyeBookFiles::libraryRoot();
		$found = [];
		if( $root === '' || !is_dir( $root.FisheyeMediaGallery::VARIOUS_AUTHORS ) ) {
			return $found;
		}
		foreach( scandir( $root.FisheyeMediaGallery::VARIOUS_AUTHORS ) as $entry ) {
			$file = $root.FisheyeMediaGallery::VARIOUS_AUTHORS.'/'.$entry.'/'.self::CATALOGUE;
			if( $entry[0] !== '.' && is_file( $file ) ) {
				$found[$entry] = $file;
			}
		}
		ksort( $found, SORT_NATURAL | SORT_FLAG_CASE );
		return $found;
	}

	/**
	 * What filing a series would do - read-only.
	 *
	 * @return array{series:string,error:?string,rows:list<array>,authorSeries:list<array>,seriesExists:bool}
	 */
	public static function plan( string $pSeries, string $pFile ): array {
		global $gBitDb;
		$plan = [ 'series' => $pSeries, 'error' => null, 'rows' => [], 'authorSeries' => [], 'seriesExists' => false ];
		$parsed = FisheyeBookCatalogue::readBooks( $pFile );
		if( isset( $parsed['error'] ) ) {
			$plan['error'] = $parsed['error'];
			return $plan;
		}
		$authors = [];
		$pPlan_rows = [];
		foreach( $parsed['rows'] as $row ) {
			$id = FisheyeBook::findContentId( $row ) ?? ( $row['isbn13'] !== '' ? FisheyeBook::findContentId( [ 'isbn13' => '' ] + $row ) : null );
			$credited = array_map( [ FisheyeBookCredits::class, 'canonicalName' ], $row['authors'] );
			$plan['rows'][] = [ 'title' => $row['title'], 'isbn' => $row['isbn13'], 'authors' => implode( ', ', $row['authors'] ), 'book' => $id, 'credited' => $credited ];
			$pPlan_rows[] = end( $plan['rows'] );
			foreach( $credited as $name ) {
				$authors[$name] = true;
			}
		}
		$vaId = FisheyeBookCredits::libraryGalleryFor( FisheyeMediaGallery::VARIOUS_AUTHORS );
		$seriesId = $vaId ? $gBitDb->getOne(
			"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
			 WHERE m.`gallery_content_id` = ? AND lc.`title` = ?", [ $vaId, $pSeries ] ) : null;
		$plan['seriesExists'] = (bool)$seriesId;
		foreach( array_keys( $authors ) as $name ) {
			$authorId = $name !== '' ? FisheyeBookCredits::libraryGalleryFor( $name ) : null;
			if( !$authorId ) {
				continue;
			}
			$own = $gBitDb->getOne(
				"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
				 WHERE m.`gallery_content_id` = ? AND lc.`title` = ?", [ $authorId, $pSeries ] );
			$linked = $own && $seriesId && $gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` WHERE `gallery_content_id` = ? AND `item_content_id` = ?", [ $seriesId, $own ] );
			// The catalogue's books this author is credited on that sit directly in the author's own gallery, outside any series: the ones
			// that would move into the author's series. Only moved when asked.
			$toMove = [];
			foreach( $pPlan_rows as $row ) {
				if( $row['book'] && in_array( $name, $row['credited'], true )
					&& $gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` WHERE `gallery_content_id` = ? AND `item_content_id` = ?", [ $authorId, $row['book'] ] ) ) {
					$toMove[] = [ 'id' => (int)$row['book'], 'title' => $row['title'] ];
				}
			}
			$plan['authorSeries'][] = [ 'author' => $name, 'author_id' => (int)$authorId, 'content_id' => $own ? (int)$own : null, 'linked' => (bool)$linked, 'toMove' => $toMove ];
		}
		return $plan;
	}

	/**
	 * File a series: make what is missing, add the books and the authors' own series galleries. Idempotent.
	 *
	 * @return array{added:int,missing:int,linked:int,made:int,moved:int,error:?string}
	 */
	public static function apply( array $pPlan, array $pMoveAuthors = [] ): array {
		$va = FisheyeBookCredits::findOrCreateLibraryGallery( FisheyeMediaGallery::VARIOUS_AUTHORS );
		if( $va['error'] ) {
			return [ 'added' => 0, 'missing' => 0, 'linked' => 0, 'made' => 0, 'moved' => 0, 'error' => $va['error'] ];
		}
		$into = FisheyeSeries::findOrCreateUnder( $pPlan['series'], (int)$va['content_id'] );
		if( !$into['series'] ) {
			return [ 'added' => 0, 'missing' => 0, 'linked' => 0, 'made' => 0, 'moved' => 0, 'error' => $into['error'] ];
		}
		$series = $into['series'];
		$ordered = [];
		$missing = 0;
		foreach( $pPlan['rows'] as $row ) {
			if( !$row['book'] ) {
				$missing++;
				continue;
			}
			$series->addItem( (int)$row['book'] );
			$ordered[] = (int)$row['book'];
		}
		$series->setOrder( $ordered );
		$linked = $made = $moved = 0;
		foreach( $pPlan['authorSeries'] as $own ) {
			// An author with a gallery gets a series gallery of this name when they have none yet, and it is linked under the combined series.
			if( !$own['content_id'] ) {
				$mine = FisheyeSeries::findOrCreateUnder( $pPlan['series'], $own['author_id'] );
				if( !$mine['series'] ) {
					continue;
				}
				$own['content_id'] = (int)$mine['series']->mContentId;
				$made += $mine['created'] ? 1 : 0;
			}
			if( !$own['linked'] ) {
				$series->addItem( $own['content_id'] );
				$linked++;
			}
			if( in_array( (string)$own['author_id'], array_map( 'strval', $pMoveAuthors ), true ) && $own['toMove'] ) {
				$authorSeries = new FisheyeSeries( null, $own['content_id'] );
				$authorSeries->load();
				$authorGallery = new FisheyeMediaGallery( null, $own['author_id'] );
				$authorGallery->load();
				foreach( $own['toMove'] as $book ) {
					$authorSeries->addItem( $book['id'] );
					if( $authorSeries->isInGallery( $authorSeries->mContentId, $book['id'] ) ) {
						$authorGallery->removeItem( $book['id'] );
						$moved++;
					}
				}
			}
		}
		return [ 'added' => count( $ordered ), 'missing' => $missing, 'linked' => $linked, 'made' => $made, 'moved' => $moved, 'error' => null ];
	}
}

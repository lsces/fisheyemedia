<?php
/**
 * Loads a group of catalogue rows as books - the work load_books.php does for one press, shared so the
 * authors pass (load_authors.php) can load a small author's books in the same step it creates the contact.
 *
 * A group is the shape FisheyeBookCatalogue::plan() gives: 'kind' author (a Library gallery named for the
 * author) or category (a gallery named for the Category), a 'name', and the 'rows'. A book already loaded is
 * recognised (FisheyeBook::findContentId()) and skipped, so running a group again is harmless.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;
use Bitweaver\Fisheye\FisheyeImage;

class FisheyeBookLoader {

	/** Seconds into a request after which the Open Library details of books just made are left for the Book Details page (the web server gives up at 150). */
	public const DETAILS_STOP_AFTER = 60;

	/** @var resource|false|null the press lock, held to the end of the request once taken */
	private static $pressLock = null;

	/**
	 * Take the press lock: one loading press at a time. A press that takes a few seconds gets pressed again, the browser
	 * abandons the first and sends another - and PHP carries on with every one of them, so three or four runs of the same
	 * press were each making the contact and the book. Held to the end of the request (taking it twice in one request is
	 * harmless), so a later run starts only after the earlier one has finished and finds what it made.
	 */
	public static function lockPress(): void {
		if( self::$pressLock === null ) {
			ignore_user_abort( true );
			self::$pressLock = @fopen( sys_get_temp_dir().'/fisheyemedia_press.lock', 'c' );
			if( self::$pressLock ) {
				flock( self::$pressLock, LOCK_EX );
			}
		}
	}

	/**
	 * Load up to $pLimit books of a group.
	 *
	 * @param array{kind:string,name:string,rows:array<int,array>} $pGroup
	 * @param ?list<int> $pPickedRows  the row keys to load; null for the group's rows in catalogue order
	 * @param bool $pUseSeries  file books into series Wikidata names (author groups only)
	 * @param ?list<string> $pSplitChosen  umbrella series kept split, null for their defaults
	 * @return array{created:int,already:int,errors:list<string>,group:string,details:list<array>,series?:array<string,int>}
	 */
	public static function loadGroup( array $pGroup, ?array $pPickedRows, bool $pUseSeries, ?array $pSplitChosen, int $pLimit ): array {
		self::lockPress();
		$result = [ 'created' => 0, 'already' => 0, 'errors' => [], 'group' => $pGroup['name'], 'details' => [] ];
		$gallery = FisheyeBookCredits::findOrCreateLibraryGallery( $pGroup['name'] );
		$galleryContentId = $gallery['content_id'];
		if( $galleryContentId ) {
			$shown = new FisheyeGallery( null, $galleryContentId );
			$shown->load();
			$result['group_url'] = $shown->getDisplayUrl();
		}
		if( $gallery['error'] ) {
			$result['errors'][] = $gallery['error'];
		}
		if( !$galleryContentId ) {
			return $result;
		}
		if( $pGroup['kind'] === 'author' && ( $authorContactId = FisheyeBookCredits::authorContactFor( $pGroup['name'] ) ) ) {
			FisheyeBookCredits::linkGalleryToContact( $galleryContentId, $authorContactId );
		}
		$newBooks = [];
		$seriesPlan = $pUseSeries && $pGroup['kind'] === 'author' ? FisheyeSeries::planForRows( $pGroup['rows'], FisheyeBookCredits::authorQid( $pGroup['name'] ), $pSplitChosen ) : null;
		$touchedSeries = [];
		$done = 0;
		foreach( $pGroup['rows'] as $rowIndex => $row ) {
			if( $done >= $pLimit ) {
				break;
			}
			if( $pPickedRows !== null && !in_array( $rowIndex, $pPickedRows, true ) ) {
				continue;
			}
			if( FisheyeBook::findContentId( $row ) ) {
				continue;
			}
			$one = FisheyeBook::registerFromRow( $row, $galleryContentId );
			if( !empty( $one['created'] ) ) {
				$result['created']++;
				$newBooks[] = (int)$one['created'];
				// Beside the primary author's gallery, in the galleries of any co-author that has one.
				FisheyeBookCredits::fileInAuthorGalleries( (int)$one['created'], (array)( $row['authors'] ?? [] ) );
				// Straight into its series when Wikidata names one - it leaves the author's own list.
				if( $seriesPlan && isset( $seriesPlan['assign'][$rowIndex] ) ) {
					$seriesName = $seriesPlan['assign'][$rowIndex];
					$into = FisheyeSeries::findOrCreateUnder( $seriesName, $galleryContentId );
					if( $into['series'] ) {
						$into['series']->addItem( (int)$one['created'] );
						if( $into['series']->isInGallery( $into['series']->mContentId, (int)$one['created'] ) ) {
							$authorOwn = new FisheyeGallery( null, $galleryContentId );
							$authorOwn->load();
							$authorOwn->removeItem( (int)$one['created'] );
							$touchedSeries[$seriesName] = $into['series'];
							// What Wikidata gave (the series, the book's number in it, the work's id) goes on the book itself.
							$filed = FisheyeImage::lookup( [ 'content_id' => (int)$one['created'] ] );
							if( $filed && $filed->isValid() && method_exists( $filed, 'recordWikidataSeries' ) ) {
								$filed->recordWikidataSeries( $seriesName, $seriesPlan['meta'][$rowIndex]['ordinal'] ?? null, (string)( $seriesPlan['meta'][$rowIndex]['work_q'] ?? '' ) );
							}
							$result['series'][$seriesName] = ( $result['series'][$seriesName] ?? 0 ) + 1;
						}
					} else {
						$result['errors'][] = $row['title'].' - '.$into['error'];
					}
				}
			} elseif( !empty( $one['already'] ) ) {
				$result['already']++;
			} else {
				$result['errors'][] = $row['title'].' - '.( $one['error'] ?? KernelTools::tra( 'not loaded' ) );
			}
			$done++;
		}
		// Each series touched is put in the catalogue's order again, counting books loaded on earlier presses.
		$groupRows = array_values( $pGroup['rows'] );
		foreach( $touchedSeries as $seriesName => $seriesGallery ) {
			foreach( $seriesPlan['series'] as $planned ) {
				if( $planned['name'] === $seriesName ) {
					$ids = [];
					foreach( $planned['rows'] as $i ) {
						if( $id = FisheyeBook::findContentId( $groupRows[$i] ) ) {
							$ids[] = (int)$id;
						}
					}
					$seriesGallery->setOrder( $ids );
				}
			}
		}
		// Details and the cover for the books just loaded, as the Book Details page does - so a press leaves
		// them complete rather than needing a second trip. Each is a trip to Open Library, and the web server gives up on a page at
		// 150 seconds, so once the request has run DETAILS_STOP_AFTER seconds the rest are left for the Book Details page (which finds
		// books still missing their details) instead of the whole press timing out after the books are already made.
		$result['details_left'] = 0;
		foreach( $newBooks as $bookContentId ) {
			if( microtime( true ) - (float)( $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) ) > self::DETAILS_STOP_AFTER ) {
				$result['details_left']++;
				continue;
			}
			$book = FisheyeImage::lookup( [ 'content_id' => $bookContentId ] );
			if( $book && $book->isValid() && method_exists( $book, 'reloadFromOpenLibrary' ) ) {
				$found = $book->reloadFromOpenLibrary();
				$result['details'][] = [ 'content_id' => $bookContentId, 'url' => $book->getDisplayUrl(), 'title' => $book->getTitle(), 'matched' => !empty( $found['matched'] ), 'items' => $found['items'] ?? [], 'error' => $found['error'] ?? '' ];
			}
		}
		// The author's catalogue in the Library folder takes up the ISBNs etc. this load established.
		if( $pGroup['kind'] === 'author' && ( $result['created'] || $result['already'] ) ) {
			$result['catalogue'] = FisheyeCatalogueMerge::updateAuthor( $pGroup['name'] );
		}
		return $result;
	}

	/**
	 * Where each of these book rows (FisheyeBookCatalogue::rowFromIsbn()) would be filed, read-only: the row's primary author as
	 * the Library gallery is titled, whether that gallery exists, and whether the author earns one (it exists already, or with
	 * this batch they have MIN_AUTHOR_BOOKS) - otherwise the book goes in the gallery for its Category.
	 *
	 * @param list<array> $pRows
	 * @return list<array{author:string,kind:string,name:string,gallery:bool,loaded:?int,leaves:list<string>}>  one per row, same order
	 */
	public static function filingFor( array $pRows ): array {
		$batch = [];
		foreach( $pRows as $row ) {
			$author = FisheyeBookCredits::canonicalName( FisheyeBookCatalogue::primaryAuthor( $row ) );
			if( !FisheyeBook::findContentId( $row ) ) {
				$batch[mb_strtolower( $author )] = ( $batch[mb_strtolower( $author )] ?? 0 ) + 1;
			}
		}
		$out = [];
		$known = [];
		foreach( $pRows as $row ) {
			$author = FisheyeBookCredits::canonicalName( FisheyeBookCatalogue::primaryAuthor( $row ) );
			$key = mb_strtolower( $author );
			$hasGallery = FisheyeBookCredits::libraryGalleryFor( $author ) !== null;
			$known[$key] ??= $hasGallery ? 0 : count( FisheyeBookCredits::booksOfAuthor( $author ) );
			$kind = $hasGallery || $known[$key] + ( $batch[$key] ?? 0 ) >= FisheyeBookCatalogue::MIN_AUTHOR_BOOKS ? 'author' : 'category';
			$category = (string)( $row['category'][0] ?? '' );
			$out[] = [ 'author' => $author, 'kind' => $kind, 'name' => $kind === 'author' ? $author : ( $category !== '' ? $category : \Bitweaver\KernelTools::tra( 'Uncategorised' ) ),
				'gallery' => $hasGallery, 'loaded' => FisheyeBook::findContentId( $row ),
				'joins' => $kind === 'author' && !$hasGallery ? $known[$key] : 0 ];
		}
		return $out;
	}

	/**
	 * Load book rows (rowFromIsbn()) one author's batch at a time: a Library gallery for an author who has or earns one, the
	 * Category gallery otherwise; books already loaded under that author are filed in a new gallery and taken out of their
	 * Category galleries; a series Wikidata names is made and put in order.
	 *
	 * @param list<array> $pRows
	 * @return list<array>  loadGroup()'s result per author batch, each with 'filed' (books added to a new author gallery) and 'moved'
	 */
	public static function addRows( array $pRows ): array {
		self::lockPress();
		$filing = self::filingFor( $pRows );
		$groups = [];
		foreach( $pRows as $i => $row ) {
			$groups[$filing[$i]['kind'].'|'.mb_strtolower( $filing[$i]['name'] )] ??= [ 'kind' => $filing[$i]['kind'], 'name' => $filing[$i]['name'], 'rows' => [] ];
			$groups[$filing[$i]['kind'].'|'.mb_strtolower( $filing[$i]['name'] )]['rows'][] = $row;
		}
		$results = [];
		foreach( $groups as $group ) {
			$result = self::loadGroup( $group, null, $group['kind'] === 'author', null, 20 );
			$result['filed'] = 0;
			$result['moved'] = 0;
			if( $group['kind'] === 'author' && ( $galleryContentId = FisheyeBookCredits::libraryGalleryFor( $group['name'] ) ) ) {
				$done = FisheyeBookCredits::fileBooksOfAuthor( $galleryContentId, $group['name'] );
				$result['filed'] = $done['added'];
				$result['moved'] = $done['moved'];
				foreach( array_keys( $result['series'] ?? [] ) as $seriesName ) {
					$into = FisheyeSeries::findOrCreateUnder( $seriesName, $galleryContentId );
					if( $into['series'] ) {
						$into['series']->orderByOrdinal();
					}
				}
			}
			$results[] = $result;
		}
		return $results;
	}
}

<?php
/**
 * The classic route for a book older than its ISBNs: the identifiers it is loaded from instead (a Wikidata work, a Project Gutenberg ebook, a
 * Google Books volume), how an identifier typed or pasted into the books table is told apart from an ISBN, and where to look for one -
 * Wikidata (the author's own works), Gutenberg's catalogue and Google Books. Nothing here writes to the database; the callers store what they
 * are given (FisheyeSeriesWorkbench::loadMissing(), FisheyeBook::fillDetails()).
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

class FisheyeBookClassics {
	/** A catalogue row published before this year is shown and loaded as a classic - by its work identifiers, not an ISBN. */
	public const BEFORE = 1970;
	/** Matches offered from each source. */
	public const SHOWN = 5;

	/**
	 * What kind of identifier a typed or pasted value is: a Wikidata work (Q-id or its address), a Gutenberg ebook (number, PG number or address),
	 * a Google Books volume (its 12 character id or address) or an ISBN. An ISBN always has ten or thirteen digits, a Gutenberg number never
	 * more than seven, so the two are not confused.
	 *
	 * @return array{kind:string,value:string}  kind: wikidata_work, gutenberg, google_books, isbn, or '' when it is none of them
	 */
	public static function classify( string $pValue ): array {
		$v = trim( htmlspecialchars_decode( $pValue, ENT_NOQUOTES ) );
		$none = [ 'kind' => '', 'value' => '' ];
		if( $v === '' ) {
			return $none;
		}
		if( preg_match( '/^(Q\d+)$/i', $v, $m ) || preg_match( '#wikidata\.org/(?:wiki|entity)/(Q\d+)#i', $v, $m ) ) {
			return [ 'kind' => 'wikidata_work', 'value' => strtoupper( $m[1] ) ];
		}
		if( preg_match( '#gutenberg\.org/(?:ebooks|files|cache/epub)/(\d+)#i', $v, $m ) || preg_match( '/^PG\s?(\d{1,7})$/i', $v, $m ) ) {
			return [ 'kind' => 'gutenberg', 'value' => $m[1] ];
		}
		if( preg_match( '#books\.google\.[a-z.]+/.*[?&]id=([A-Za-z0-9_-]{12})(?![A-Za-z0-9_-])#', $v, $m ) || preg_match( '#google\.[a-z.]+/books/edition/[^/]+/([A-Za-z0-9_-]{12})(?![A-Za-z0-9_-])#', $v, $m ) ) {
			return [ 'kind' => 'google_books', 'value' => $m[1] ];
		}
		if( preg_match( '/^\d{1,7}$/', $v ) ) {
			return [ 'kind' => 'gutenberg', 'value' => $v ];
		}
		$isbn = FisheyeBook::normaliseIsbn( $v );
		if( $isbn['isbn13'] !== '' ) {
			return [ 'kind' => 'isbn', 'value' => $isbn['isbn13'] ];
		}
		if( preg_match( '/^(?=.*[A-Za-z])[A-Za-z0-9_-]{12}$/', $v ) ) {
			return [ 'kind' => 'google_books', 'value' => $v ];
		}
		return $none;
	}

	/** A request, answered or null: a short timeout, an identifying agent, and the body whatever the status (the caller judges it). */
	private static function get( string $pUrl ): ?string {
		$context = stream_context_create( [ 'http' => [ 'timeout' => 15, 'ignore_errors' => true, 'header' => "Accept: */*\r\nUser-Agent: bitweaver-fisheyemedia\r\n" ] ] );
		$body = @file_get_contents( $pUrl, false, $context );
		return $body === false ? null : $body;
	}

	private static function titleKey( string $pTitle ): string {
		$t = preg_replace( '/[^a-z0-9 ]/', '', str_replace( '&', ' and ', mb_strtolower( $pTitle ) ) );
		return trim( preg_replace( '/\s+/', ' ', preg_replace( '/^(the|a|an) /', '', trim( $t ) ) ) );
	}

	/**
	 * The literary works Wikidata lists with this author (P50), earliest first. One request, kept a day.
	 *
	 * @return list<array{q:string,title:string,date:string,pg:string,ol:string}>|null  null when Wikidata cannot be asked
	 */
	public static function authorWorks( string $pAuthorQid ): ?array {
		$class = '\Bitweaver\Contactwiki\ContactWikiIndividual';
		if( !preg_match( '/^Q\d+$/', $pAuthorQid ) || !class_exists( $class ) ) {
			return null;
		}
		$cacheKey = 'fm_wd_authorworks_v1_'.$pAuthorQid;
		if( function_exists( 'apcu_fetch' ) && ( $cached = apcu_fetch( $cacheKey ) ) !== false ) {
			return $cached;
		}
		$bindings = $class::wikidataSparql(
			'SELECT ?w ?wLabel ?pub ?ol ?pg WHERE { VALUES ?type { wd:Q8261 wd:Q149537 wd:Q7725634 wd:Q25379 wd:Q49084 } ?w wdt:P50 wd:'.$pAuthorQid.' ; wdt:P31 ?type . '
			.'OPTIONAL { ?w wdt:P577 ?pub } OPTIONAL { ?w wdt:P648 ?ol } OPTIONAL { ?w wdt:P2034 ?pg } SERVICE wikibase:label { bd:serviceParam wikibase:language "en". } }' );
		if( $bindings === null ) {
			return null;
		}
		$works = [];
		foreach( $bindings as $row ) {
			$q = basename( (string)( $row['w']['value'] ?? '' ) );
			$title = (string)( $row['wLabel']['value'] ?? '' );
			if( !preg_match( '/^Q\d+$/', $q ) || $title === '' || preg_match( '/^Q\d+$/', $title ) ) {
				continue;
			}
			$date = preg_match( '/^\+?(\d{4})/', (string)( $row['pub']['value'] ?? '' ), $m ) ? $m[1] : '';
			$work = &$works[$q];
			$work ??= [ 'q' => $q, 'title' => $title, 'date' => '', 'pg' => '', 'ol' => '' ];
			$work['date'] = $date !== '' && ( $work['date'] === '' || $date < $work['date'] ) ? $date : $work['date'];
			$work['pg'] = $work['pg'] ?: (string)( $row['pg']['value'] ?? '' );
			$work['ol'] = $work['ol'] ?: (string)( $row['ol']['value'] ?? '' );
			unset( $work );
		}
		$works = array_values( $works );
		usort( $works, fn( $a, $b ) => [ $a['date'] ?: '9999', $a['title'] ] <=> [ $b['date'] ?: '9999', $b['title'] ] );
		if( function_exists( 'apcu_store' ) ) {
			apcu_store( $cacheKey, $works, 86400 );
		}
		return $works;
	}

	/**
	 * The Wikidata work for each catalogue row that has none, matched by title among the author's own works (read-only). A title that more than one
	 * item carries goes to the one with a Gutenberg or Open Library id (the work itself, not an adaptation or an edition), then the earliest.
	 *
	 * @param array<int,array> $pRows  catalogue rows keyed by their index
	 * @return array<int,array{q:string,title:string,date:string}>|null  row index => the work; null when Wikidata cannot be asked
	 */
	public static function findWikidataIds( array $pRows, string $pAuthorQid ): ?array {
		$works = self::authorWorks( $pAuthorQid );
		if( $works === null ) {
			return null;
		}
		$byTitle = [];
		foreach( $works as $work ) {
			$byTitle[self::titleKey( $work['title'] )][] = $work;
		}
		$found = [];
		foreach( $pRows as $i => $row ) {
			if( !empty( $row['external']['wikidata_work'] ) ) {
				continue;
			}
			$key = self::titleKey( (string)$row['title'] );
			$candidates = $byTitle[$key] ?? [];
			if( !$candidates ) {
				// Wikidata often labels a work with its subtitle ("Hard Times: For These Times"): the title followed by a colon, when that is the only such work.
				foreach( $works as $work ) {
					if( stripos( $work['title'], ':' ) !== false && self::titleKey( strstr( $work['title'], ':', true ) ) === $key ) {
						$candidates[] = $work;
					}
				}
				if( count( $candidates ) !== 1 ) {
					continue;
				}
			}
			usort( $candidates, fn( $a, $b ) => [ ( $a['pg'] !== '' || $a['ol'] !== '' ) ? 0 : 1, $a['date'] ?: '9999' ] <=> [ ( $b['pg'] !== '' || $b['ol'] !== '' ) ? 0 : 1, $b['date'] ?: '9999' ] );
			$found[$i] = [ 'q' => $candidates[0]['q'], 'title' => $candidates[0]['title'], 'date' => $candidates[0]['date'] ];
		}
		return $found;
	}

	/** The Wikidata work whose Gutenberg ebook number this is, or '' (none, or Wikidata cannot be asked). */
	public static function wikidataForGutenberg( string $pEbook ): string {
		$class = '\Bitweaver\Contactwiki\ContactWikiIndividual';
		if( !preg_match( '/^\d{1,7}$/', $pEbook ) || !class_exists( $class ) ) {
			return '';
		}
		$bindings = $class::wikidataSparql( 'SELECT ?w WHERE { ?w wdt:P2034 "'.$pEbook.'" } LIMIT 2' );
		return $bindings && count( $bindings ) === 1 ? basename( (string)( $bindings[0]['w']['value'] ?? '' ) ) : '';
	}

	/**
	 * Where this title might be found, from the three catalogues of classics, for the Suggest link: each match is { source, id, title, authors, year,
	 * note }, the id being what goes in the books table's identifier box. Wikidata is the author's own works when the author's Wikidata item is known,
	 * else a title search; Gutenberg is its catalogue's search page; Google Books needs the API key (without one Google gives the shared quota and
	 * refuses). A source that does not answer is named in 'notes'.
	 *
	 * @return array{matches:list<array<string,string>>,notes:list<string>}
	 */
	public static function suggest( string $pTitle, string $pAuthor, string $pAuthorQid ): array {
		global $gBitSystem;
		$out = [ 'matches' => [], 'notes' => [] ];
		$pTitle = trim( $pTitle );
		if( $pTitle === '' ) {
			return $out;
		}
		$words = array_filter( explode( ' ', self::titleKey( $pTitle ) ), fn( $w ) => strlen( $w ) > 2 );

		// Wikidata.
		$wd = [];
		if( $pAuthorQid !== '' && ( $works = self::authorWorks( $pAuthorQid ) ) !== null ) {
			foreach( $works as $work ) {
				$key = self::titleKey( $work['title'] );
				if( $words && !array_diff( $words, explode( ' ', $key ) ) ) {
					$wd[] = [ 'source' => 'Wikidata', 'id' => $work['q'], 'title' => $work['title'], 'authors' => $pAuthor, 'year' => $work['date'], 'note' => trim( ( $work['pg'] !== '' ? 'Gutenberg '.$work['pg'].' ' : '' ).( $work['ol'] !== '' ? 'Open Library '.$work['ol'] : '' ) ) ];
				}
			}
		} elseif( $body = self::get( 'https://www.wikidata.org/w/api.php?'.http_build_query( [ 'action' => 'wbsearchentities', 'search' => $pTitle, 'language' => 'en', 'type' => 'item', 'limit' => 8, 'format' => 'json' ] ) ) ) {
			foreach( (array)( json_decode( $body, true )['search'] ?? [] ) as $hit ) {
				if( preg_match( '/novel|novella|book|literary work|short story|story|play|poem/i', (string)( $hit['description'] ?? '' ) ) ) {
					$wd[] = [ 'source' => 'Wikidata', 'id' => (string)$hit['id'], 'title' => (string)( $hit['label'] ?? '' ), 'authors' => '', 'year' => '', 'note' => (string)( $hit['description'] ?? '' ) ];
				}
			}
		} else {
			$out['notes'][] = 'Wikidata did not answer.';
		}
		array_push( $out['matches'], ...array_slice( $wd, 0, self::SHOWN ) );

		// Project Gutenberg's catalogue search page.
		if( $body = self::get( 'https://www.gutenberg.org/ebooks/search/?'.http_build_query( [ 'query' => trim( $pTitle.' '.$pAuthor ), 'submit_search' => 'Go!' ] ) ) ) {
			$pg = [];
			if( preg_match_all( '#<li class="booklink">.*?href="/ebooks/(\d+)".*?<span class="title">(.*?)</span>.*?<span class="subtitle">(.*?)</span>#s', $body, $hits, PREG_SET_ORDER ) ) {
				foreach( $hits as $hit ) {
					$title = html_entity_decode( trim( strip_tags( $hit[2] ) ), ENT_QUOTES );
					$pg[] = [ 'source' => 'Gutenberg', 'id' => $hit[1], 'title' => $title, 'authors' => html_entity_decode( trim( strip_tags( $hit[3] ) ), ENT_QUOTES ), 'year' => '', 'note' => 'ebook '.$hit[1],
						'all' => $words && !array_diff( $words, explode( ' ', self::titleKey( $title ) ) ) ? 0 : 1 ];
				}
			}
			// Books whose title has every word asked for come first (the search itself ranks by popularity), whatever the order the page gave.
			usort( $pg, fn( $a, $b ) => $a['all'] <=> $b['all'] );
			foreach( array_slice( $pg, 0, self::SHOWN ) as $one ) {
				unset( $one['all'] );
				$out['matches'][] = $one;
			}
		} else {
			$out['notes'][] = 'Gutenberg did not answer.';
		}

		// Google Books.
		$key = trim( (string)$gBitSystem->getConfig( 'fisheyemedia_google_books_api_key' ) );
		if( $key === '' ) {
			$out['notes'][] = 'Google Books is not searched: no API key is set (Media Library Settings).';
		} elseif( $body = self::get( 'https://www.googleapis.com/books/v1/volumes?'.http_build_query( [ 'q' => 'intitle:"'.$pTitle.'"'.( $pAuthor !== '' ? ' inauthor:"'.$pAuthor.'"' : '' ), 'maxResults' => self::SHOWN, 'key' => $key ] ) ) ) {
			$json = json_decode( $body, true );
			if( isset( $json['error'] ) ) {
				$out['notes'][] = 'Google Books refused: '.preg_replace( '/\s+/', ' ', mb_substr( (string)( $json['error']['message'] ?? '' ), 0, 160 ) );
			}
			foreach( (array)( $json['items'] ?? [] ) as $item ) {
				$info = (array)( $item['volumeInfo'] ?? [] );
				$out['matches'][] = [ 'source' => 'Google Books', 'id' => (string)$item['id'], 'title' => (string)( $info['title'] ?? '' ), 'authors' => implode( ', ', (array)( $info['authors'] ?? [] ) ),
					'year' => substr( (string)( $info['publishedDate'] ?? '' ), 0, 4 ), 'note' => trim( ( isset( $info['pageCount'] ) ? $info['pageCount'].' pages ' : '' ).( !empty( $info['description'] ) ? 'with a description' : '' ) ) ];
			}
		} else {
			$out['notes'][] = 'Google Books did not answer.';
		}
		return $out;
	}
}

<?php
/**
 * TheTVDB (v4 API) as a second source of people for a show, used to fill the gaps Plex and TMDb leave - above all documentaries, where Plex
 * holds no cast at all but TheTVDB lists the presenters, and for some series each episode's writer, director and guests.
 *
 * Nothing here is written to the credit rows directly. A fetch (fetchShow(), run by a button or a script) saves what TheTVDB returned for a show
 * in one JSON file in the show's own storage branch; the episode builders (FisheyeSeason) then read that file - never the API - and fill an episode
 * only when Plex gave it no director, writer or star, so a later Reload from Plex keeps the people instead of wiping them.
 *
 * The API key is the fisheyemedia_tvdb_api_key setting (Media Library Settings); without it nothing in here calls out.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

class FisheyeTvdb {

	public const API = 'https://api4.thetvdb.com/v4/';
	public const CACHE_FILE = 'tvdb.json';
	/** TheTVDB genres that mean the people on screen are themselves, not characters ("Presenter", "Host", an expert's job). */
	private const SELF_STYLE_GENRES = [ 'documentary', 'reality', 'talk show', 'news', 'educational', 'informational', 'game show', 'travel', 'food', 'home and garden', 'science-fiction documentary', 'special interest' ];

	private static ?string $token = null;
	/** @var array<int,?array> */
	private static array $memo = [];
	public static ?string $lastError = null;

	public static function configured(): bool {
		global $gBitSystem;
		return trim( (string)$gBitSystem->getConfig( 'fisheyemedia_tvdb_api_key', '' ) ) !== '';
	}

	/** Log in (a token lasts a month - kept for the request, and in APCu for a day when there is one). */
	private static function token(): ?string {
		global $gBitSystem;
		if( self::$token !== null ) {
			return self::$token;
		}
		$key = trim( (string)$gBitSystem->getConfig( 'fisheyemedia_tvdb_api_key', '' ) );
		if( $key === '' ) {
			self::$lastError = 'No TheTVDB API key is set (Media Library Settings).';
			return null;
		}
		$cacheKey = 'fisheye_tvdb_token_'.substr( md5( $key ), 0, 12 );
		if( function_exists( 'apcu_fetch' ) && ( $cached = apcu_fetch( $cacheKey ) ) ) {
			return self::$token = $cached;
		}
		$login = self::http( 'login', [ 'apikey' => $key ], false );
		$token = $login['data']['token'] ?? null;
		if( !$token ) {
			self::$lastError = 'TheTVDB login failed'.( self::$lastError ? ': '.self::$lastError : '' );
			return null;
		}
		if( function_exists( 'apcu_store' ) ) {
			apcu_store( $cacheKey, $token, 86400 );
		}
		return self::$token = $token;
	}

	/** One API call; a 429 is waited out once. Returns the decoded JSON or null (the reason in $lastError). */
	private static function http( string $pPath, ?array $pPost = null, bool $pAuth = true ): ?array {
		$headers = [ 'User-Agent: rdmcloud-fisheyemedia/1.0', 'Accept: application/json' ];
		if( $pAuth ) {
			if( !( $token = self::token() ) ) {
				return null;
			}
			$headers[] = 'Authorization: Bearer '.$token;
		}
		for( $attempt = 0; $attempt < 2; $attempt++ ) {
			$http = [ 'header' => implode( "\r\n", $headers )."\r\n", 'timeout' => 25, 'ignore_errors' => true ];
			if( $pPost !== null ) {
				$http['method'] = 'POST';
				$http['content'] = json_encode( $pPost );
				$http['header'] .= "Content-Type: application/json\r\n";
			}
			$body = @file_get_contents( self::API.$pPath, false, stream_context_create( [ 'http' => $http ] ) );
			$status = 0;
			foreach( function_exists( 'http_get_last_response_headers' ) ? ( http_get_last_response_headers() ?? [] ) : ( $http_response_header ?? [] ) as $h ) {
				if( preg_match( '#^HTTP/\S+\s+(\d{3})#', $h, $m ) ) {
					$status = (int)$m[1];
				}
			}
			if( $status === 429 && $attempt === 0 ) {
				sleep( 3 );
				continue;
			}
			if( $status === 401 && $pAuth && $attempt === 0 ) {
				// token expired: drop the cached one and log in again once
				self::$token = null;
				global $gBitSystem;
				if( function_exists( 'apcu_delete' ) ) {
					apcu_delete( 'fisheye_tvdb_token_'.substr( md5( trim( (string)$gBitSystem->getConfig( 'fisheyemedia_tvdb_api_key', '' ) ) ), 0, 12 ) );
				}
				$headers = array_values( array_filter( $headers, fn( $h ) => !str_starts_with( $h, 'Authorization:' ) ) );
				if( !( $token = self::token() ) ) {
					return null;
				}
				$headers[] = 'Authorization: Bearer '.$token;
				continue;
			}
			if( $body === false || $status < 200 || $status >= 300 ) {
				self::$lastError = $status ? "HTTP $status" : 'no response from TheTVDB';
				return null;
			}
			return json_decode( $body, true ) ?: null;
		}
		return null;
	}

	// ---- finding the show on TheTVDB

	/** TheTVDB's id for a program: its own `tvdb` xref, else found through its IMDb or TMDb id, else an exact title match. */
	public static function findSeriesId( int $pProgramId, string $pTitle ): ?array {
		global $gBitDb;
		$have = trim( (string)$gBitDb->getOne( "SELECT `xkey` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'tvdb' AND `end_date` IS NULL", [ $pProgramId ] ) );
		if( ctype_digit( $have ) ) {
			return [ 'id' => (int)$have, 'via' => 'stored id' ];
		}
		$remote = [];
		if( $imdb = FisheyeCredits::imdbIdFor( $pProgramId ) ) {
			$remote['IMDb'] = $imdb;
		}
		if( $tmdb = FisheyeCredits::tmdbIdFor( $pProgramId ) ) {
			$remote['TMDb'] = (string)$tmdb;
		}
		foreach( $remote as $source => $id ) {
			$found = self::http( 'search/remoteid/'.rawurlencode( $id ) );
			foreach( (array)( $found['data'] ?? [] ) as $hit ) {
				if( !empty( $hit['series']['id'] ) ) {
					return [ 'id' => (int)$hit['series']['id'], 'via' => $source.' id' ];
				}
			}
		}
		// Last resort: the same title, exactly (case, punctuation and "(year)" ignored) - never a near match, a wrong show is worse than none.
		$norm = fn( string $t ) => trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( preg_replace( '/\s*\(\s*\d{4}\s*\)\s*$/', '', $t ) ) ) );
		$search = self::http( 'search?'.http_build_query( [ 'query' => $pTitle, 'type' => 'series' ], '', '&' ) );
		foreach( (array)( $search['data'] ?? [] ) as $hit ) {
			if( !empty( $hit['tvdb_id'] ) && $norm( (string)( $hit['name'] ?? '' ) ) === $norm( $pTitle ) ) {
				return [ 'id' => (int)$hit['tvdb_id'], 'via' => 'exact title' ];
			}
		}
		return null;
	}

	// ---- the per-show cache file

	public static function cachePath( int $pProgramId ): string {
		return STORAGE_PKG_PATH.\Bitweaver\Liberty\liberty_mime_get_storage_branch( [ 'attachment_id' => $pProgramId, 'create_dir' => false ] ).self::CACHE_FILE;
	}

	public static function readCache( int $pProgramId ): ?array {
		if( array_key_exists( $pProgramId, self::$memo ) ) {
			return self::$memo[$pProgramId];
		}
		$path = self::cachePath( $pProgramId );
		return self::$memo[$pProgramId] = is_file( $path ) ? ( json_decode( (string)file_get_contents( $path ), true ) ?: null ) : null;
	}

	private static function writeCache( int $pProgramId, array $pCache ): void {
		$path = self::cachePath( $pProgramId );
		\Bitweaver\KernelTools::mkdir_p( dirname( $path ).'/' );
		file_put_contents( $path, json_encode( $pCache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		self::$memo[$pProgramId] = $pCache;
		@chmod( $path, 0644 );
	}

	// ---- fetching

	/**
	 * Fetch what TheTVDB holds for a show into its cache file: the series' people, and (within the time budget, resumable) each wanted
	 * episode's own people. $pWantedEpisodes is a list of "SxE" keys (season x episode) the caller still has no people for.
	 *
	 * @param string[] $pWantedEpisodes
	 * @return array{ok:bool, error:?string, series_id:?int, via:?string, people:int, episodes_fetched:int, episodes_left:int, name:string}
	 */
	public static function fetchShow( int $pProgramId, string $pTitle, array $pWantedEpisodes, float $pBudget = 25.0 ): array {
		$started = microtime( true );
		$result = [ 'ok' => false, 'error' => null, 'series_id' => null, 'via' => null, 'people' => 0, 'episodes_fetched' => 0, 'episodes_left' => 0, 'name' => '' ];
		$cache = self::readCache( $pProgramId ) ?: [];
		if( empty( $cache['series_id'] ) ) {
			$found = self::findSeriesId( $pProgramId, $pTitle );
			if( !$found ) {
				$result['error'] = self::$lastError ?: 'no matching show on TheTVDB';
				return $result;
			}
			$cache = [ 'series_id' => $found['id'], 'via' => $found['via'] ];
		}
		$result['series_id'] = (int)$cache['series_id'];
		$result['via'] = $cache['via'] ?? null;
		if( !isset( $cache['series'] ) ) {
			$extended = self::http( 'series/'.$cache['series_id'].'/extended?short=false' );
			if( !$extended || empty( $extended['data'] ) ) {
				$result['error'] = self::$lastError ?: 'TheTVDB returned nothing for that show';
				return $result;
			}
			$d = $extended['data'];
			$genres = array_map( fn( $g ) => strtolower( (string)( $g['name'] ?? '' ) ), (array)( $d['genres'] ?? [] ) );
			$cache['name'] = (string)( $d['name'] ?? '' );
			$cache['genres'] = $genres;
			$cache['selfStyle'] = (bool)array_intersect( $genres, self::SELF_STYLE_GENRES );
			$cache['series'] = self::people( (array)( $d['characters'] ?? [] ) );
			// IMDb / TMDb / Wikidata ids of the series-level people (a few calls; the better-known have them) - used to pre-match them.
			foreach( $cache['series'] as &$person ) {
				if( !empty( $person['pid'] ) && microtime( true ) - $started < $pBudget ) {
					foreach( (array)( self::http( 'people/'.(int)$person['pid'].'/extended' )['data']['remoteIds'] ?? [] ) as $remote ) {
						$source = strtolower( (string)( $remote['sourceName'] ?? '' ) );
						if( str_starts_with( $source, 'imdb' ) ) {
							$person['imdb'] = $remote['id'];
						} elseif( str_starts_with( $source, 'themoviedb' ) ) {
							$person['tmdb'] = $remote['id'];
						} elseif( $source === 'wikidata' ) {
							$person['wikidata'] = $remote['id'];
						}
					}
				}
			}
			unset( $person );
			$cache['episodes'] = [];
			$cache['episodeIds'] = [];
		}
		$result['people'] = count( $cache['series'] );
		$result['name'] = (string)( $cache['name'] ?? '' );
		// The episode list (ids by season x number), once.
		if( empty( $cache['episodeIds'] ) && $pWantedEpisodes ) {
			for( $page = 0; $page < 12; $page++ ) {
				$list = self::http( 'series/'.$cache['series_id'].'/episodes/official?page='.$page );
				$episodes = $list['data']['episodes'] ?? [];
				foreach( $episodes as $episode ) {
					$cache['episodeIds'][(int)$episode['seasonNumber'].'x'.(int)$episode['number']] = (int)$episode['id'];
				}
				if( !$episodes || empty( $list['links']['next'] ) ) {
					break;
				}
			}
		}
		foreach( $pWantedEpisodes as $key ) {
			if( isset( $cache['episodes'][$key] ) || empty( $cache['episodeIds'][$key] ) ) {
				continue;
			}
			if( microtime( true ) - $started > $pBudget ) {
				$result['episodes_left']++;
				continue;
			}
			$episode = self::http( 'episodes/'.$cache['episodeIds'][$key].'/extended' );
			$cache['episodes'][$key] = self::people( (array)( $episode['data']['characters'] ?? [] ) );
			$result['episodes_fetched']++;
			usleep( 120000 );
		}
		$cache['fetched'] = time();
		self::writeCache( $pProgramId, $cache );
		$result['ok'] = true;
		return $result;
	}

	/** TheTVDB "characters" (people credits) reduced to what we use. */
	private static function people( array $pCharacters ): array {
		$ret = [];
		usort( $pCharacters, fn( $a, $b ) => ( (int)( $a['sort'] ?? 0 ) ) <=> ( (int)( $b['sort'] ?? 0 ) ) );
		foreach( $pCharacters as $c ) {
			$name = trim( preg_replace( '/\s+/u', ' ', (string)( $c['personName'] ?? '' ) ) );
			if( $name === '' ) {
				continue;
			}
			$ret[] = [ 'name' => $name, 'type' => (string)( $c['peopleType'] ?? '' ), 'role' => trim( (string)( $c['name'] ?? '' ) ),
				'featured' => !empty( $c['isFeatured'] ), 'pid' => (int)( $c['peopleId'] ?? 0 ) ];
		}
		return $ret;
	}

	// ---- using the cache (no API calls)

	/**
	 * The credits for one episode in the shape an episode's data packet holds: director / writer / star / narrator lists and a roles map
	 * (name => role text; "Self - Presenter" for the people of a documentary). The episode's own people plus the series-level hosts and cast,
	 * each once. Empty when there is no cache for the show.
	 *
	 * @return array{director?:string[], writer?:string[], star?:string[], narrator?:string[], roles?:array<string,string>}
	 */
	public static function creditsFor( int $pProgramId, ?int $pSeason, int $pEpisode ): array {
		$cache = self::readCache( $pProgramId );
		if( !$cache ) {
			return [];
		}
		$people = [];
		foreach( ( $pSeason !== null ? ( $cache['episodes'][$pSeason.'x'.$pEpisode] ?? [] ) : [] ) as $p ) {
			$people[] = $p;
		}
		foreach( (array)( $cache['series'] ?? [] ) as $p ) {
			$people[] = $p + [ 'series' => true ];
		}
		$self = !empty( $cache['selfStyle'] );
		$out = [ 'director' => [], 'writer' => [], 'star' => [], 'narrator' => [], 'roles' => [] ];
		$seen = [];
		foreach( $people as $p ) {
			$name = $p['name'];
			$type = strtolower( $p['type'] );
			$role = $p['role'];
			if( $type === 'director' ) {
				$list = 'director';
			} elseif( $type === 'writer' ) {
				$list = 'writer';
			} elseif( in_array( $type, [ 'actor', 'host', 'guest star', 'musical guest', 'narrator', 'presenter' ], true ) ) {
				$list = 'star';
			} else {
				continue;   // producers, creators and the like are not credits we hold
			}
			if( isset( $seen[$list][mb_strtolower( $name )] ) ) {
				continue;
			}
			$seen[$list][mb_strtolower( $name )] = true;
			$out[$list][] = $name;
			if( $list === 'star' ) {
				if( $type === 'narrator' || preg_match( '/narrat/i', $role ) ) {
					$out['narrator'][] = $name;
				}
				$label = $role;
				if( $self ) {
					// a documentary's "Actor as Presenter" is the person as themselves, in that function
					$label = ( $role === '' || strcasecmp( $role, 'Self' ) === 0 || strcasecmp( $role, $name ) === 0 ) ? ( $type === 'host' ? 'Host' : '' ) : $role;
					$label = $label === '' ? 'Self' : ( preg_match( '/^self\b/i', $label ) ? $label : 'Self - '.$label );
				}
				if( $label !== '' ) {
					$out['roles'][$name] = $label;
				}
			}
		}
		return array_filter( $out );
	}

	/** The IMDb / TMDb / Wikidata ids TheTVDB gave for a series-level person (by name), or []. */
	public static function externalIdsFor( int $pProgramId, string $pName ): array {
		foreach( (array)( self::readCache( $pProgramId )['series'] ?? [] ) as $p ) {
			if( mb_strtolower( $p['name'] ) === mb_strtolower( trim( $pName ) ) ) {
				return array_filter( [ 'imdb' => $p['imdb'] ?? null, 'tmdb' => $p['tmdb'] ?? null, 'wikidata' => $p['wikidata'] ?? null ] );
			}
		}
		return [];
	}
}

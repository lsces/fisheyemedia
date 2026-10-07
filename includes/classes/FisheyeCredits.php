<?php
/**
 * Plain-text credits (director/writer/star) on films, programs and seasons - the shared survey and
 * link writer a people-matching tool (contactwiki's film/TV people passes) works from, so every media
 * type is surveyed, linked and name-resolved the same way.
 *
 * A credit row is a liberty_xref row (item director/writer/star) whose `xkey_ext` is the credited name.
 * Once matched to a contact it also carries `xref` = the contact's content_id and `xkey` = its Wikidata
 * Q-id - the shape an album credit takes. A season's rows are a directory of the people credited on its
 * episodes: one row per person per role, the episode numbers they appear in under `data` ({"episodes":[...]}).
 * Everything here reads liberty_xref directly, never a content item's role-filtered mXrefInfo, so it sees
 * every row whoever runs it.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\Liberty\LibertyXref;

class FisheyeCredits {

	/** The xref items that credit a person. */
	public const ITEMS = [ 'director', 'writer', 'star' ];

	/** Every item that credits a person, for the survey/link/name queries: ITEMS plus the show-level 'creator' (a program's own rows only - a season has none). */
	public const ALL_ITEMS = [ 'director', 'writer', 'star', 'creator' ];

	/** The content types whose credit rows are surveyed and linked. */
	public const TYPES = [ 'fisheyefilm', 'fisheyeprogram', 'fisheyeseason' ];

	/**
	 * Every person credited on the given content types (optionally only on given content items), one
	 * entry per distinct name (case/spacing-insensitive).
	 *
	 * @param string[]   $pTypeGuids   content types (subset of TYPES)
	 * @param int[]|null $pContentIds  restrict to these content items, or null for all of the types
	 * @return array{items:int, credits:int, people:array<string,array>}  people keyed by lower-cased name,
	 *         most-credited first; each: name, names, roles (item=>rows), items (content_id=>title),
	 *         credits (rows), xref_ids, unlinked_ids, contacts, episodes (total episodes named on
	 *         season rows)
	 */
	public static function survey( array $pTypeGuids, ?array $pContentIds = null ): array {
		global $gBitDb;
		$pTypeGuids = array_values( array_intersect( $pTypeGuids, self::TYPES ) );
		if( !$pTypeGuids || ( $pContentIds !== null && !$pContentIds ) ) {
			return [ 'items' => 0, 'credits' => 0, 'people' => [] ];
		}
		$bind = $pTypeGuids;
		$sql = "SELECT x.`xref_id`, x.`content_id`, x.`item`, x.`xref`, x.`xkey_ext`, x.`data`, lc.`title`
			 FROM `".BIT_DB_PREFIX."liberty_xref` x
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = x.`content_id`
			 WHERE lc.`content_type_guid` IN ( ".implode( ',', array_fill( 0, count( $pTypeGuids ), '?' ) )." )
			 AND x.`end_date` IS NULL AND x.`item` IN ( 'director', 'writer', 'star', 'creator' ) AND x.`xkey_ext` IS NOT NULL";
		if( $pContentIds !== null ) {
			$sql .= " AND x.`content_id` IN ( ".implode( ',', array_fill( 0, count( $pContentIds ), '?' ) )." )";
			$bind = array_merge( $bind, array_map( 'intval', $pContentIds ) );
		}
		$rows = $gBitDb->getAll( $sql, $bind ) ?: [];
		$people = [];
		$items = [];
		foreach( $rows as $row ) {
			$name = trim( preg_replace( '/\s+/u', ' ', (string)$row['xkey_ext'] ) );
			if( $name === '' ) {
				continue;
			}
			$key = mb_strtolower( $name );
			$p = &$people[$key];
			$p ??= [ 'name' => $name, 'names' => [], 'roles' => [], 'items' => [], 'credits' => 0,
				'xref_ids' => [], 'unlinked_ids' => [], 'contacts' => [], 'episodes' => 0 ];
			$p['names'][$name] = ( $p['names'][$name] ?? 0 ) + 1;
			$p['roles'][$row['item']] = ( $p['roles'][$row['item']] ?? 0 ) + 1;
			$p['items'][(int)$row['content_id']] = $row['title'];
			$p['credits']++;
			$p['xref_ids'][] = (int)$row['xref_id'];
			if( empty( $row['xref'] ) ) {
				$p['unlinked_ids'][] = (int)$row['xref_id'];
			} else {
				$p['contacts'][(int)$row['xref']] = (int)$row['xref'];
			}
			if( !empty( $row['data'] ) && ( $d = json_decode( $row['data'], true ) ) && !empty( $d['episodes'] ) ) {
				$p['episodes'] += count( $d['episodes'] );
			}
			$items[(int)$row['content_id']] = true;
			unset( $p );
		}
		foreach( $people as &$person ) {
			arsort( $person['names'] );
			$person['name'] = (string)array_key_first( $person['names'] );
			$person['contacts'] = array_values( $person['contacts'] );
		}
		unset( $person );
		uasort( $people, fn( $a, $b ) => [ $b['credits'], $a['name'] ] <=> [ $a['credits'], $b['name'] ] );
		return [ 'items' => count( $items ), 'credits' => count( $rows ), 'people' => $people ];
	}

	/**
	 * Link credit rows to a contact: xref = the contact's content_id, xkey = its external id (a Wikidata
	 * Q-id when it has one). Only live credit rows on a surveyed content type with no link yet are
	 * written, so a link made by hand is never overwritten. The row keeps its own xorder/xkey_ext/data,
	 * and its last_update_date: linking is not a hand edit, so a later reload still updates the row
	 * (a season's episode list, say) and carries the link across (LibertyXref::reconcileItem()'s keep-links).
	 *
	 * @param int[]   $pXrefIds
	 * @return int  rows linked
	 */
	public static function linkRows( array $pXrefIds, int $pContactId, ?string $pXkey ): int {
		global $gBitDb;
		$pXrefIds = array_values( array_filter( array_map( 'intval', $pXrefIds ) ) );
		if( !$pXrefIds || $pContactId <= 0 ) {
			return 0;
		}
		$rows = $gBitDb->getAll(
			"SELECT x.`xref_id`, x.`last_update_date`, lc.`content_type_guid` FROM `".BIT_DB_PREFIX."liberty_xref` x
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = x.`content_id`
			 WHERE x.`xref_id` IN ( ".implode( ',', array_fill( 0, count( $pXrefIds ), '?' ) )." )
			 AND lc.`content_type_guid` IN ( '".implode( "','", self::TYPES )."' ) AND x.`end_date` IS NULL
			 AND x.`item` IN ( 'director', 'writer', 'star', 'creator' ) AND ( x.`xref` IS NULL OR x.`xref` = 0 )",
			$pXrefIds
		) ?: [];
		$linked = 0;
		foreach( $rows as $row ) {
			$xref = new LibertyXref();
			$xref->mContentTypeGuid = $row['content_type_guid'];
			$xref->load( (int)$row['xref_id'] );
			$hash = [ 'xref_id' => (int)$row['xref_id'], 'xref' => $pContactId, 'xkey' => (string)$pXkey,
				'last_update_date' => (int)$row['last_update_date'] ];
			if( $xref->store( $hash ) ) {
				$linked++;
			}
		}
		return $linked;
	}

	/**
	 * The contact each credited name is already linked to somewhere (a film, program or season), so a
	 * name resolved once is resolved everywhere. A name linked to more than one contact takes the one
	 * most rows use.
	 *
	 * @param string[] $pNames
	 * @return array<string, array{xref:int, xkey:string}>  keyed by lower-cased name
	 */
	public static function linkedContactsByName( array $pNames ): array {
		global $gBitDb;
		$ret = [];
		foreach( array_chunk( array_values( array_unique( array_map( fn( $n ) => mb_strtolower( trim( $n ) ), $pNames ) ) ), 200 ) as $chunk ) {
			$rows = $gBitDb->getAll(
				"SELECT LOWER( x.`xkey_ext` ) AS name, x.`xref`, x.`xkey`, COUNT(*) AS n
				 FROM `".BIT_DB_PREFIX."liberty_xref` x
				 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = x.`content_id`
				 WHERE lc.`content_type_guid` IN ( '".implode( "','", self::TYPES )."' ) AND x.`end_date` IS NULL
				 AND x.`item` IN ( 'director', 'writer', 'star', 'creator' ) AND x.`xref` IS NOT NULL AND x.`xref` <> 0
				 AND LOWER( x.`xkey_ext` ) IN ( ".implode( ',', array_fill( 0, count( $chunk ), '?' ) )." )
				 GROUP BY LOWER( x.`xkey_ext` ), x.`xref`, x.`xkey`",
				$chunk
			) ?: [];
			$best = [];
			foreach( $rows as $row ) {
				if( ( $best[$row['name']] ?? 0 ) < (int)$row['n'] ) {
					$best[$row['name']] = (int)$row['n'];
					$ret[$row['name']] = [ 'xref' => (int)$row['xref'], 'xkey' => (string)$row['xkey'] ];
				}
			}
		}
		return $ret;
	}

	/**
	 * Where each credited name links to: the page of the contact that name is already linked to on any film, program or
	 * season credit row (see linkedContactsByName()). Display pages use it to turn the plain names in an episode's or
	 * show's cast list into links - including a guest who was never promoted to a season row but is linked elsewhere.
	 * Same name = same person, the assumption the people tools make. Names with no link are simply absent.
	 *
	 * @param string[] $pNames
	 * @return array<string,string>  the name as given => url (index.php?content_id=, routed to the contact's own page)
	 */
	public static function urlsForNames( array $pNames ): array {
		$names = array_values( array_unique( array_filter( array_map( 'strval', $pNames ), fn( $n ) => trim( $n ) !== '' ) ) );
		if( !$names ) {
			return [];
		}
		$linked = self::linkedContactsByName( $names );
		$urls = [];
		foreach( $names as $name ) {
			if( isset( $linked[mb_strtolower( trim( $name ) )] ) ) {
				$urls[$name] = BIT_ROOT_URL.'index.php?content_id='.$linked[mb_strtolower( trim( $name ) )]['xref'];
			}
		}
		return $urls;
	}

	/**
	 * A show's credits rolled up from its seasons' credit directories: for each of director/writer/star, the distinct people with
	 * the episodes they appear in across all seasons and how many seasons, most episodes first (ties in billing order). A person's link comes from their
	 * season rows (xref) - the contact they were linked to. Empty for a show whose directories have not been built yet.
	 *
	 * @return array<string,list<array{name:string, url:?string, episodes:int, seasons:int}>>  role => people
	 */
	public static function programRollup( int $pProgramId ): array {
		global $gBitDb;
		$rows = $gBitDb->getAll(
			"SELECT x.`item`, x.`xkey_ext`, x.`xref`, x.`data`, x.`xorder` FROM `".BIT_DB_PREFIX."liberty_xref` x
			 JOIN `".BIT_DB_PREFIX."fisheye_gallery_image_map` m ON m.`item_content_id` = x.`content_id`
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = x.`content_id`
			 WHERE m.`gallery_content_id` = ? AND lc.`content_type_guid` = 'fisheyeseason' AND x.`end_date` IS NULL
			 AND x.`item` IN ( 'director', 'writer', 'star' ) AND x.`xkey_ext` IS NOT NULL",
			[ $pProgramId ]
		) ?: [];
		$byRole = [];
		foreach( $rows as $row ) {
			$name = trim( preg_replace( '/\s+/u', ' ', (string)$row['xkey_ext'] ) );
			if( $name === '' ) {
				continue;
			}
			$data = !empty( $row['data'] ) ? ( json_decode( $row['data'], true ) ?: [] ) : [];
			$entry = &$byRole[$row['item']][mb_strtolower( $name )];
			$entry ??= [ 'name' => $name, 'url' => null, 'episodes' => 0, 'seasons' => 0, 'rankSum' => 0 ];
			$entry['episodes'] += max( 1, count( (array)( $data['episodes'] ?? [] ) ) );
			$entry['seasons']++;
			// A season row's xorder is its place in that season's billing (episodes, then order of first appearance).
			$entry['rankSum'] += (int)$row['xorder'];
			if( !empty( $row['xref'] ) ) {
				$entry['url'] = BIT_ROOT_URL.'index.php?content_id='.(int)$row['xref'];
			}
			unset( $entry );
		}
		$ret = [];
		foreach( [ 'director', 'writer', 'star' ] as $role ) {
			$people = array_values( $byRole[$role] ?? [] );
			// Most episodes first; people tied on episodes in billing order (their average place across the seasons), then by name.
			usort( $people, fn( $a, $b ) => [ $b['episodes'], $a['rankSum'] / $a['seasons'], $a['name'] ] <=> [ $a['episodes'], $b['rankSum'] / $b['seasons'], $b['name'] ] );
			$ret[$role] = $people;
		}
		return $ret;
	}

	/**
	 * A show's creators: the people on its own `creator` rows (TMDb's created_by, written by the TV people tool's reload), each with the
	 * contact it is linked to. Empty until that reload has run.
	 *
	 * @return list<array{name:string, url:?string}>
	 */
	public static function programCreators( int $pProgramId ): array {
		global $gBitDb;
		$ret = [];
		foreach( $gBitDb->getAll(
			"SELECT x.`xkey_ext`, x.`xref` FROM `".BIT_DB_PREFIX."liberty_xref` x
			 WHERE x.`content_id` = ? AND x.`item` = 'creator' AND x.`end_date` IS NULL AND x.`xkey_ext` IS NOT NULL ORDER BY x.`xorder`, x.`xref_id`",
			[ $pProgramId ]
		) ?: [] as $row ) {
			$ret[] = [ 'name' => trim( (string)$row['xkey_ext'] ), 'url' => !empty( $row['xref'] ) ? BIT_ROOT_URL.'index.php?content_id='.(int)$row['xref'] : null ];
		}
		return $ret;
	}

	/**
	 * The content_ids of a program's seasons (its gallery's season items), in season order.
	 *
	 * @return int[]
	 */
	public static function seasonIdsForProgram( int $pProgramId ): array {
		global $gBitDb;
		return array_map( 'intval', $gBitDb->getCol(
			"SELECT m.`item_content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
			 WHERE m.`gallery_content_id` = ? AND lc.`content_type_guid` = 'fisheyeseason'
			 ORDER BY m.`item_position`, lc.`title`",
			[ $pProgramId ]
		) ?: [] );
	}

	/** The IMDb id (tt1234567) held in a content item's own live `imdb` xref, or null. */
	public static function imdbIdFor( int $pContentId ): ?string {
		global $gBitDb;
		$id = trim( (string)$gBitDb->getOne(
			"SELECT x.`xkey` FROM `".BIT_DB_PREFIX."liberty_xref` x WHERE x.`content_id` = ? AND x.`item` = 'imdb' AND x.`end_date` IS NULL",
			[ $pContentId ]
		) );
		return preg_match( '/^tt\d+$/', $id ) ? $id : null;
	}

	/** The TMDb id held in a content item's own live `tmdb` xref (a program's is its TMDb TV id). */
	public static function tmdbIdFor( int $pContentId ): ?int {
		global $gBitDb;
		$id = $gBitDb->getOne(
			"SELECT x.`xkey` FROM `".BIT_DB_PREFIX."liberty_xref` x WHERE x.`content_id` = ? AND x.`item` = 'tmdb' AND x.`end_date` IS NULL",
			[ $pContentId ]
		);
		return ctype_digit( (string)$id ) ? (int)$id : null;
	}

	/**
	 * Programs with how many seasons they have, how many of those hold a credits directory, and their credit rows
	 * (and how many are still unlinked) - what a show picker lists. Set-based: one grouped query per figure, not a
	 * correlated subquery per program (that took 22s over the whole library). With a program id only that program.
	 *
	 * @return list<array{content_id:int, title:string, seasons:int, built:int, unlinked:int, credits:int}>
	 */
	public static function programOverview( ?int $pProgramId = null ): array {
		global $gBitDb;
		$bind = [];
		$programSql = "SELECT p.`content_id`, p.`title` FROM `".BIT_DB_PREFIX."liberty_content` p WHERE p.`content_type_guid` = 'fisheyeprogram'";
		$mapWhere = '';
		if( $pProgramId !== null ) {
			$programSql .= " AND p.`content_id` = ?";
			$mapWhere = " AND m.`gallery_content_id` = ?";
			$bind = [ $pProgramId ];
		}
		$programs = $gBitDb->getAll( $programSql." ORDER BY p.`title`", $bind ) ?: [];
		$seasons = $gBitDb->getAssoc(
			"SELECT m.`gallery_content_id`, COUNT(*) FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
			 JOIN `".BIT_DB_PREFIX."liberty_content` s ON s.`content_id` = m.`item_content_id`
			 WHERE s.`content_type_guid` = 'fisheyeseason'".$mapWhere." GROUP BY m.`gallery_content_id`", $bind
		) ?: [];
		$credits = [];
		foreach( $gBitDb->getAll(
			"SELECT m.`gallery_content_id` AS pid, COUNT(DISTINCT x.`content_id`) AS built, COUNT(*) AS credits,
			        SUM( CASE WHEN x.`xref` IS NULL OR x.`xref` = 0 THEN 1 ELSE 0 END ) AS unlinked
			 FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
			 JOIN `".BIT_DB_PREFIX."liberty_xref` x ON x.`content_id` = m.`item_content_id`
			 WHERE x.`end_date` IS NULL AND x.`item` IN ( 'director', 'writer', 'star' )".$mapWhere."
			 GROUP BY m.`gallery_content_id`", $bind
		) ?: [] as $row ) {
			$credits[(int)$row['pid']] = $row;
		}
		return array_map( fn( $r ) => [
			'content_id' => (int)$r['content_id'], 'title' => $r['title'],
			'seasons'  => (int)( $seasons[$r['content_id']] ?? 0 ),
			'built'    => (int)( $credits[(int)$r['content_id']]['built'] ?? 0 ),
			'unlinked' => (int)( $credits[(int)$r['content_id']]['unlinked'] ?? 0 ),
			'credits'  => (int)( $credits[(int)$r['content_id']]['credits'] ?? 0 ),
		], $programs );
	}
}

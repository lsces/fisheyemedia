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
			 AND x.`end_date` IS NULL AND x.`item` IN ( 'director', 'writer', 'star' ) AND x.`xkey_ext` IS NOT NULL";
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
			 AND x.`item` IN ( 'director', 'writer', 'star' ) AND ( x.`xref` IS NULL OR x.`xref` = 0 )",
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
				 AND x.`item` IN ( 'director', 'writer', 'star' ) AND x.`xref` IS NOT NULL AND x.`xref` <> 0
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
	 * Every program with how many seasons it has, how many of them already hold a credits directory,
	 * and its credit rows still unlinked - what a show picker lists.
	 *
	 * @return list<array{content_id:int, title:string, seasons:int, built:int, unlinked:int, credits:int}>
	 */
	public static function programOverview(): array {
		global $gBitDb;
		$programs = $gBitDb->getAll(
			"SELECT p.`content_id`, p.`title`,
			   ( SELECT COUNT(*) FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m JOIN `".BIT_DB_PREFIX."liberty_content` s ON s.`content_id` = m.`item_content_id`
			     WHERE m.`gallery_content_id` = p.`content_id` AND s.`content_type_guid` = 'fisheyeseason' ) AS seasons,
			   ( SELECT COUNT(DISTINCT x.`content_id`) FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m JOIN `".BIT_DB_PREFIX."liberty_xref` x ON x.`content_id` = m.`item_content_id`
			     WHERE m.`gallery_content_id` = p.`content_id` AND x.`end_date` IS NULL AND x.`item` IN ( 'director', 'writer', 'star' ) ) AS built,
			   ( SELECT COUNT(*) FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m JOIN `".BIT_DB_PREFIX."liberty_xref` x ON x.`content_id` = m.`item_content_id`
			     WHERE m.`gallery_content_id` = p.`content_id` AND x.`end_date` IS NULL AND x.`item` IN ( 'director', 'writer', 'star' ) AND ( x.`xref` IS NULL OR x.`xref` = 0 ) ) AS unlinked,
			   ( SELECT COUNT(*) FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m JOIN `".BIT_DB_PREFIX."liberty_xref` x ON x.`content_id` = m.`item_content_id`
			     WHERE m.`gallery_content_id` = p.`content_id` AND x.`end_date` IS NULL AND x.`item` IN ( 'director', 'writer', 'star' ) ) AS credits
			 FROM `".BIT_DB_PREFIX."liberty_content` p WHERE p.`content_type_guid` = 'fisheyeprogram' ORDER BY p.`title`"
		) ?: [];
		return array_map( fn( $r ) => [ 'content_id' => (int)$r['content_id'], 'title' => $r['title'], 'seasons' => (int)$r['seasons'],
			'built' => (int)$r['built'], 'unlinked' => (int)$r['unlinked'], 'credits' => (int)$r['credits'] ], $programs );
	}
}

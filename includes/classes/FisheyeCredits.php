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
	public const ITEMS = [ 'director', 'writer', 'star', 'narrator' ];

	/** Every item that credits a person, for the survey/link/name queries: ITEMS plus the show-level 'creator' (a program's own rows only - a season has none). */
	public const ALL_ITEMS = [ 'director', 'writer', 'star', 'narrator', 'creator' ];

	/** The content types whose credit rows are surveyed and linked. */
	public const TYPES = [ 'fisheyefilm', 'fisheyeprogram', 'fisheyeseason' ];

	/** The item holding a cast member's character - not a person credit, so not in ITEMS (the people survey must not see it). */
	public const CHARACTER_ITEM = 'character';

	/**
	 * The wanted `character` rows for a film or season from plexCredits(): one per cast member whose role text Plex has, in billing order.
	 * The row's text is the role, its key the actor (a cast name is unique on an item, a role such as "Student" is not), and `data.actor`
	 * names the actor so the pair can be shown together; a later Wikidata pass sets the row's link (xref) and xkey to the character's
	 * contact and Wikidata item.
	 *
	 * @param array $pCredits  plexCredits() output
	 * @return list<array{key:string, xkey_ext:string, xorder:int, data:array}>
	 */
	public static function characterRows( array $pCredits ): array {
		$rows = [];
		foreach( $pCredits['star'] as $actor ) {
			if( !empty( $pCredits['roles'][$actor] ) ) {
				$rows[] = [ 'key' => $actor, 'xkey_ext' => $pCredits['roles'][$actor], 'xorder' => count( $rows ) + 1, 'data' => [ 'actor' => $actor ] ];
			}
		}
		return $rows;
	}

	/**
	 * The live `character` rows of these films, each with the Wikidata item of the actor playing it when that actor's cast row is linked to a
	 * contact (the cast row's xkey) - what a Wikidata cast-statement lookup needs to find the character.
	 *
	 * @param int[] $pFilmIds
	 * @return array<int,list<array{xref_id:int, role:string, actor:string, actor_qid:?string, linked:bool}>>  film => rows
	 */
	public static function characterRowsForFilms( array $pFilmIds ): array {
		global $gBitDb;
		$pFilmIds = array_values( array_unique( array_filter( array_map( 'intval', $pFilmIds ) ) ) );
		if( !$pFilmIds ) {
			return [];
		}
		$in = implode( ',', array_fill( 0, count( $pFilmIds ), '?' ) );
		$actorQ = [];
		foreach( $gBitDb->getAll( "SELECT `content_id`, `xkey_ext`, `xkey` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `item` = 'star' AND `end_date` IS NULL AND `xref` > 0 AND `xkey` LIKE 'Q%' AND `content_id` IN ( $in )", $pFilmIds ) ?: [] as $row ) {
			$actorQ[(int)$row['content_id']][(string)$row['xkey_ext']] = (string)$row['xkey'];
		}
		$ret = [];
		foreach( $gBitDb->getAll( "SELECT `xref_id`, `content_id`, `xkey_ext`, `xref`, `data` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `item` = ? AND `end_date` IS NULL AND `content_id` IN ( $in ) ORDER BY `content_id`, `xorder`", array_merge( [ self::CHARACTER_ITEM ], $pFilmIds ) ) ?: [] as $row ) {
			$actor = self::characterActor( $row );
			$ret[(int)$row['content_id']][] = [ 'xref_id' => (int)$row['xref_id'], 'role' => (string)$row['xkey_ext'], 'actor' => $actor,
				'actor_qid' => $actorQ[(int)$row['content_id']][$actor] ?? null, 'linked' => !empty( $row['xref'] ) ];
		}
		return $ret;
	}

	/**
	 * A show's recurring roles, from the `character` rows its seasons carry (the role text Plex gives each cast member): roles that
	 * appear in at least $pMinSeasons seasons, grouped so that variants of one role count together - "Sgt Don Brady" and "Sgt. Brady" when
	 * the same actor plays both. Each group lists the rows still to link; a group whose rows are all linked is left out. If some of a
	 * group's rows are already linked to one contact, that contact is the group's existing one (a new season's rows join it).
	 *
	 * @return list<array{key:string, role:string, variants:list<string>, seasons:int, actors:list<string>, multi:bool, xref_ids:int[], existing:?int, rows:int}>
	 *         most seasons first
	 */
	public static function recurringCharacters( int $pProgramId, int $pMinSeasons = 3 ): array {
		global $gBitDb;
		$seasonIds = self::seasonIdsForProgram( $pProgramId );
		if( !$seasonIds ) {
			return [];
		}
		$groups = [];
		foreach( $gBitDb->getAll(
			"SELECT `xref_id`, `content_id`, `xkey_ext`, `xref`, `data` FROM `".BIT_DB_PREFIX."liberty_xref`
			 WHERE `item` = ? AND `end_date` IS NULL AND `xkey_ext` IS NOT NULL AND `content_id` IN ( ".implode( ',', array_map( 'intval', $seasonIds ) )." )",
			[ self::CHARACTER_ITEM ]
		) ?: [] as $row ) {
			$role = trim( preg_replace( '/\s+/u', ' ', (string)$row['xkey_ext'] ) );
			$key = self::characterRoleKey( $role );
			if( $key === '' ) {
				continue;
			}
			$g = &$groups[$key];
			$g ??= [ 'variants' => [], 'seasons' => [], 'actors' => [], 'rows' => [] ];
			$g['variants'][$role] = ( $g['variants'][$role] ?? 0 ) + 1;
			$g['seasons'][(int)$row['content_id']] = true;
			if( ( $actor = self::characterActor( $row ) ) !== '' ) {
				$g['actors'][$actor] = true;
			}
			$g['rows'][] = [ 'xref_id' => (int)$row['xref_id'], 'linked' => (int)$row['xref'] ];
			unset( $g );
		}
		// Fold a shorter form into a longer one it is part of when the same actor plays both ("sgt brady" into "sgt don brady").
		uksort( $groups, fn( $a, $b ) => substr_count( $b, ' ' ) <=> substr_count( $a, ' ' ) ?: strcmp( $a, $b ) );
		foreach( array_keys( $groups ) as $short ) {
			if( !isset( $groups[$short] ) ) {
				continue;
			}
			$shortTokens = explode( ' ', $short );
			foreach( array_keys( $groups ) as $long ) {
				if( $long === $short || count( explode( ' ', $long ) ) <= count( $shortTokens ) || !isset( $groups[$long] ) ) {
					continue;
				}
				if( !array_diff( $shortTokens, explode( ' ', $long ) ) && array_intersect_key( $groups[$short]['actors'], $groups[$long]['actors'] ) ) {
					foreach( $groups[$short]['variants'] as $v => $n ) {
						$groups[$long]['variants'][$v] = ( $groups[$long]['variants'][$v] ?? 0 ) + $n;
					}
					$groups[$long]['seasons'] += $groups[$short]['seasons'];
					$groups[$long]['actors'] += $groups[$short]['actors'];
					$groups[$long]['rows'] = array_merge( $groups[$long]['rows'], $groups[$short]['rows'] );
					unset( $groups[$short] );
					break;
				}
			}
		}
		$ret = [];
		foreach( $groups as $key => $g ) {
			$pending = array_values( array_map( fn( $r ) => $r['xref_id'], array_filter( $g['rows'], fn( $r ) => !$r['linked'] ) ) );
			if( !$pending || count( $g['seasons'] ) < $pMinSeasons ) {
				continue;
			}
			arsort( $g['variants'] );
			// The name to use: the most used spelling, the longer one when tied.
			$variants = array_keys( $g['variants'] );
			usort( $variants, fn( $a, $b ) => [ $g['variants'][$b], strlen( $b ) ] <=> [ $g['variants'][$a], strlen( $a ) ] );
			$linkedTo = array_values( array_unique( array_filter( array_map( fn( $r ) => $r['linked'], $g['rows'] ) ) ) );
			$ret[] = [ 'key' => $key, 'role' => self::tidyRoleName( $variants[0] ), 'variants' => $variants, 'seasons' => count( $g['seasons'] ),
				'actors' => array_keys( $g['actors'] ), 'multi' => (bool)preg_match( '~\s/\s|\s&\s|\sand\s~i', $variants[0] ), 'xref_ids' => $pending, 'existing' => count( $linkedTo ) === 1 ? $linkedTo[0] : null, 'rows' => count( $g['rows'] ) ];
		}
		usort( $ret, fn( $a, $b ) => [ $b['seasons'], $a['role'] ] <=> [ $a['seasons'], $b['role'] ] );
		return $ret;
	}

	/**
	 * A role as displayed and stored: the full stops of rank abbreviations dropped - "Sgt. Hanlon" is "Sgt Hanlon", "D.C.I. Peters" is "DCI Peters",
	 * "Dr. Smith" is "Dr Smith". Only a short word ending in a stop (Sgt. Supt. Mrs. Jr.) or a leading initialism (D.C.I.) is touched, so a
	 * middle initial ("Jack T. Smith") and anything longer keep theirs. Idempotent.
	 */
	public static function tidyRoleName( string $pRole ): string {
		$words = preg_split( '/\s+/u', trim( $pRole ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		foreach( $words as $i => &$word ) {
			if( preg_match( '/^\p{L}{2,5}\.$/u', $word ) ) {
				$word = rtrim( $word, '.' );
			} elseif( $i === 0 && preg_match( '/^(\p{L}\.){1,3}\p{L}\.?$/u', $word ) ) {
				$word = str_replace( '.', '', $word );
			}
		}
		return implode( ' ', $words );
	}

	/** The character contact's URL for a role key: a row linked under that key, else under a longer role that contains it; null if none. */
	private static function characterContactUrl( string $pKey, array $pLinks ): ?string {
		$contactId = $pLinks[$pKey] ?? null;
		if( !$contactId ) {
			$tokens = explode( ' ', $pKey );
			foreach( $pLinks as $key => $id ) {
				if( count( explode( ' ', (string)$key ) ) > count( $tokens ) && !array_diff( $tokens, explode( ' ', (string)$key ) ) ) {
					$contactId = $id;
					break;
				}
			}
		}
		return $contactId ? BIT_ROOT_URL.'index.php?content_id='.$contactId : null;
	}

	/** A role's grouping key: lower case, no full stops or bracketed notes ("(voice)", "(uncredited)"), single spaces. */
	public static function characterRoleKey( string $pRole ): string {
		$role = preg_replace( '/\([^)]*\)/u', ' ', $pRole );
		// Full stops drop out without leaving a space ("D.C.I. Peters" is "dci peters"), commas become spaces.
		$role = str_replace( ',', ' ', str_replace( '.', '', mb_strtolower( $role ) ) );
		return trim( preg_replace( '/\s+/u', ' ', $role ) );
	}

	/**
	 * What a contact's character links show: as a character, who played it in what (the `character` rows linked to it); as a person, the
	 * characters they played (a cast row linked to them whose `character` row names them as the actor).
	 *
	 * @return array{playedBy:list<array{title:string,url:string,role:string,actor:string,actor_url:?string}>, played:list<array{title:string,url:string,role:string,character_url:?string}>}
	 */
	public static function characterLinksFor( int $pContactId ): array {
		global $gBitDb;
		$ret = [ 'playedBy' => [], 'played' => [] ];
		$url = fn( int $pContentId ) => BIT_ROOT_URL.'index.php?content_id='.$pContentId;
		$rows = $gBitDb->getAll(
			"SELECT x.`content_id`, x.`xkey_ext`, x.`data`, lc.`title` FROM `".BIT_DB_PREFIX."liberty_xref` x
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = x.`content_id`
			 WHERE x.`item` = ? AND x.`xref` = ? AND x.`end_date` IS NULL ORDER BY lc.`title`",
			[ self::CHARACTER_ITEM, $pContactId ]
		) ?: [];
		if( $rows ) {
			$actorLinks = [];
			foreach( $gBitDb->getAll( "SELECT `content_id`, `xkey_ext`, `xref` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `item` = 'star' AND `end_date` IS NULL AND `xref` > 0 AND `content_id` IN ( ".implode( ',', array_fill( 0, count( $rows ), '?' ) )." )", array_column( $rows, 'content_id' ) ) ?: [] as $star ) {
				$actorLinks[(int)$star['content_id']][(string)$star['xkey_ext']] = (int)$star['xref'];
			}
			foreach( $rows as $row ) {
				$actor = self::characterActor( $row );
				$ret['playedBy'][] = [ 'title' => (string)$row['title'], 'url' => $url( (int)$row['content_id'] ), 'role' => (string)$row['xkey_ext'], 'actor' => $actor,
					'actor_url' => isset( $actorLinks[(int)$row['content_id']][$actor] ) ? $url( $actorLinks[(int)$row['content_id']][$actor] ) : null ];
			}
		}
		$stars = $gBitDb->getAll( "SELECT `content_id`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `item` = 'star' AND `end_date` IS NULL AND `xref` = ?", [ $pContactId ] ) ?: [];
		if( $stars ) {
			$byContent = [];
			foreach( $stars as $star ) {
				$byContent[(int)$star['content_id']][(string)$star['xkey_ext']] = true;
			}
			foreach( $gBitDb->getAll(
				"SELECT x.`content_id`, x.`xkey_ext`, x.`xref`, x.`data`, lc.`title` FROM `".BIT_DB_PREFIX."liberty_xref` x
				 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = x.`content_id`
				 WHERE x.`item` = ? AND x.`end_date` IS NULL AND x.`content_id` IN ( ".implode( ',', array_fill( 0, count( $byContent ), '?' ) )." ) ORDER BY lc.`title`, x.`xorder`",
				array_merge( [ self::CHARACTER_ITEM ], array_keys( $byContent ) )
			) ?: [] as $row ) {
				if( isset( $byContent[(int)$row['content_id']][self::characterActor( $row )] ) ) {
					$ret['played'][] = [ 'title' => (string)$row['title'], 'url' => $url( (int)$row['content_id'] ), 'role' => (string)$row['xkey_ext'],
						'character_url' => !empty( $row['xref'] ) ? $url( (int)$row['xref'] ) : null ];
				}
			}
		}
		return $ret;
	}

	/**
	 * Make each linked credit's key (xkey) agree with its contact's current Wikidata id. The key is what matches a credit to Wikidata (the
	 * characters pass finds a cast row's actor through it), but it was written when the link was made: a Wikidata item merged since, or a
	 * contact whose id was corrected, leaves it stale. Only rows whose contact has a Wikidata id are touched, and linking is not a hand edit,
	 * so the row's last-update stamp is kept.
	 *
	 * @return int  rows corrected
	 */
	public static function syncLinkKeys( int $pLimit = 300 ): int {
		global $gBitDb;
		$pLimit = max( 1, $pLimit );
		$rows = $gBitDb->getAll(
			"SELECT FIRST $pLimit x.`xref_id`, x.`last_update_date`, lc.`content_type_guid`, w.`xkey_ext` AS qid
			 FROM `".BIT_DB_PREFIX."liberty_xref` x
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = x.`content_id`
			 JOIN `".BIT_DB_PREFIX."liberty_xref` w ON w.`content_id` = x.`xref` AND w.`item` = 'wikidata' AND w.`end_date` IS NULL
			 WHERE x.`end_date` IS NULL AND x.`xref` > 0 AND lc.`content_type_guid` IN ( '".implode( "','", self::TYPES )."' )
			 AND x.`item` IN ( ".self::allItemsSql().", '".self::CHARACTER_ITEM."' ) AND ( x.`xkey` IS NULL OR x.`xkey` <> w.`xkey_ext` )"
		) ?: [];
		$fixed = 0;
		foreach( $rows as $row ) {
			$xref = new LibertyXref();
			$xref->mContentTypeGuid = $row['content_type_guid'];
			$xref->load( (int)$row['xref_id'] );
			$hash = [ 'xref_id' => (int)$row['xref_id'], 'xkey' => (string)$row['qid'], 'last_update_date' => (int)$row['last_update_date'] ];
			if( $xref->store( $hash ) ) {
				$fixed++;
			}
		}
		return $fixed;
	}

	/** reconcileItem()'s key for a live `character` row: the actor on a film (one role each), "actor|role" on a season (data.k). */
	public static function characterKey( array $pRow ): string {
		$data = json_decode( (string)( $pRow['data'] ?? '' ), true ) ?: [];
		$key = (string)( $data['k'] ?? $data['actor'] ?? '' );
		// The role part is compared tidied, so a row stored as "David Jason|Sgt. Hanlon" still matches the "…|Sgt Hanlon" a reload now produces and keeps its link.
		if( strpos( $key, '|' ) !== false ) {
			[ $actor, $role ] = explode( '|', $key, 2 );
			$key = $actor.'|'.self::tidyRoleName( $role );
		}
		return $key;
	}

	/** The actor a live `character` row names. */
	public static function characterActor( array $pRow ): string {
		return (string)( ( json_decode( (string)( $pRow['data'] ?? '' ), true ) ?: [] )['actor'] ?? '' );
	}

	/** ALL_ITEMS as a quoted SQL list. */
	private static function allItemsSql(): string {
		return "'".implode( "', '", self::ALL_ITEMS )."'";
	}

	/** ITEMS as a quoted SQL list. */
	private static function itemsSql(): string {
		return "'".implode( "', '", self::ITEMS )."'";
	}

	/**
	 * A Plex item's credits by role, in billing order, each name once: director/writer/star from the director/writer/actor taggings, plus
	 * narrator for an actor tagging whose role text says narrator ("Narrator", "Narrator (voice)", "Self - Narrator"), not a trailer's narrator. A narrator stays in the
	 * cast too: Plex lists them there, and in fiction "Narrator" can be a character (Fight Club) who is really one of the stars.
	 *
	 * Also 'roles': each actor's role text as Plex holds it ('Tristan Thorn'), by name - the character played. Plex has it for 99% of cast.
	 *
	 * @return array{director:string[], writer:string[], star:string[], narrator:string[], roles:array<string,string>}
	 */
	public static function plexCredits( \PDO $pPlexDb, int $pMetadataItemId ): array {
		$stmt = $pPlexDb->prepare(
			"SELECT t.tag, t.tag_type, tg.text FROM taggings tg JOIN tags t ON t.id = tg.tag_id
			 WHERE tg.metadata_item_id = ? AND t.tag_type IN ( 4, 5, 6 ) ORDER BY t.tag_type, tg.\"index\""
		);
		$stmt->execute( [ $pMetadataItemId ] );
		$ret = [ 'director' => [], 'writer' => [], 'star' => [], 'narrator' => [] ];
		$roles = [];
		foreach( $stmt->fetchAll( \PDO::FETCH_ASSOC ) as $row ) {
			$role = [ 4 => 'director', 5 => 'writer', 6 => 'star' ][(int)$row['tag_type']];
			$ret[$role][$row['tag']] = $row['tag'];
			if( $role === 'star' && !isset( $roles[$row['tag']] ) && ( $text = trim( preg_replace( '/\s+/u', ' ', (string)$row['text'] ) ) ) !== '' ) {
				$roles[$row['tag']] = self::tidyRoleName( $text );
			}
			if( $role === 'star' && preg_match( '/\bnarrat/i', (string)$row['text'] ) && !preg_match( '/trailer|promo|teaser/i', (string)$row['text'] ) ) {
				$ret['narrator'][$row['tag']] = $row['tag'];
			}
		}
		return array_map( 'array_values', $ret ) + [ 'roles' => $roles ];
	}

	/**
	 * Every person credited on the given content types (optionally only on given content items), one
	 * entry per distinct name (case/spacing-insensitive).
	 *
	 * @param string[]   $pTypeGuids   content types (subset of TYPES)
	 * @param int[]|null $pContentIds  restrict to these content items, or null for all of the types
	 * @param int|null   $pStarDepth   a star billed lower than this on every row the person has (and no other role) makes them 'minor' - credited, but not worth a contact of their own; null = nobody is minor
	 * @return array{items:int, credits:int, people:array<string,array>}  people keyed by lower-cased name,
	 *         most-credited first; each: name, names, roles (item=>rows), items (content_id=>title),
	 *         credits (rows), xref_ids, unlinked_ids, unlinked_by_item (content_id => unlinked xref ids), contacts, episodes (total episodes named on
	 *         season rows)
	 */
	public static function survey( array $pTypeGuids, ?array $pContentIds = null, ?int $pStarDepth = null ): array {
		global $gBitDb;
		$pTypeGuids = array_values( array_intersect( $pTypeGuids, self::TYPES ) );
		if( !$pTypeGuids || ( $pContentIds !== null && !$pContentIds ) ) {
			return [ 'items' => 0, 'credits' => 0, 'people' => [] ];
		}
		$bind = $pTypeGuids;
		$sql = "SELECT x.`xref_id`, x.`content_id`, x.`item`, x.`xorder`, x.`xref`, x.`xkey_ext`, x.`data`, lc.`title`
			 FROM `".BIT_DB_PREFIX."liberty_xref` x
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = x.`content_id`
			 WHERE lc.`content_type_guid` IN ( ".implode( ',', array_fill( 0, count( $pTypeGuids ), '?' ) )." )
			 AND x.`end_date` IS NULL AND x.`item` IN ( ".self::allItemsSql()." ) AND x.`xkey_ext` IS NOT NULL";
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
				'xref_ids' => [], 'unlinked_ids' => [], 'unlinked_by_item' => [], 'contacts' => [], 'episodes' => 0, 'minor' => $pStarDepth !== null ];
			$p['names'][$name] = ( $p['names'][$name] ?? 0 ) + 1;
			$p['roles'][$row['item']] = ( $p['roles'][$row['item']] ?? 0 ) + 1;
			$p['items'][(int)$row['content_id']] = $row['title'];
			$p['credits']++;
			if( $pStarDepth === null || $row['item'] !== 'star' || (int)$row['xorder'] <= $pStarDepth ) {
				$p['minor'] = false;
			}
			$p['xref_ids'][] = (int)$row['xref_id'];
			if( empty( $row['xref'] ) ) {
				$p['unlinked_ids'][] = (int)$row['xref_id'];
				$p['unlinked_by_item'][(int)$row['content_id']][] = (int)$row['xref_id'];
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
	public static function linkRows( array $pXrefIds, int $pContactId, ?string $pXkey, ?array $pItems = null ): int {
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
			 AND x.`item` IN ( ".( $pItems ? "'".implode( "', '", array_map( fn( $i ) => str_replace( "'", '', $i ), $pItems ) )."'" : self::allItemsSql() )." ) AND ( x.`xref` IS NULL OR x.`xref` = 0 )",
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
				 AND x.`item` IN ( ".self::allItemsSql()." ) AND x.`xref` IS NOT NULL AND x.`xref` <> 0
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
	 * @return array<string,list<array{name:string, url:?string, episodes:int, seasons:int, roles:string, roleList:list<array{name:string,url:?string}>, rolesMore:int}>>  role => people (roles: the characters a star played as text, roleList the same with each character's contact link)
	 */
	public static function programRollup( int $pProgramId ): array {
		global $gBitDb;
		$rows = $gBitDb->getAll(
			"SELECT x.`item`, x.`xkey_ext`, x.`xref`, x.`data`, x.`xorder` FROM `".BIT_DB_PREFIX."liberty_xref` x
			 JOIN `".BIT_DB_PREFIX."fisheye_gallery_image_map` m ON m.`item_content_id` = x.`content_id`
			 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = x.`content_id`
			 WHERE m.`gallery_content_id` = ? AND lc.`content_type_guid` = 'fisheyeseason' AND x.`end_date` IS NULL
			 AND x.`item` IN ( ".self::itemsSql()." ) AND x.`xkey_ext` IS NOT NULL",
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
			$entry ??= [ 'name' => $name, 'url' => null, 'episodes' => 0, 'seasons' => 0, 'rankSum' => 0, 'roleSeasons' => [] ];
			$entry['episodes'] += max( 1, count( (array)( $data['episodes'] ?? [] ) ) );
			// The characters a star row carries (the season's own list, see deriveCreditDirectory()) - counted per season so the usual ones come first.
			foreach( (array)( $data['roles'] ?? [] ) as $character ) {
				if( is_string( $character ) && trim( $character ) !== '' ) {
					$entry['roleSeasons'][trim( $character )] = ( $entry['roleSeasons'][trim( $character )] ?? 0 ) + 1;
				}
			}
			$entry['seasons']++;
			// A season row's xorder is its place in that season's billing (episodes, then order of first appearance).
			$entry['rankSum'] += (int)$row['xorder'];
			if( !empty( $row['xref'] ) ) {
				$entry['url'] = BIT_ROOT_URL.'index.php?content_id='.(int)$row['xref'];
			}
			unset( $entry );
		}
		// The character contacts this show's rows are linked to, by role key, so a star's characters can link to them.
		$characterLinks = [];
		foreach( $gBitDb->getAll(
			"SELECT x.`xkey_ext`, x.`xref` FROM `".BIT_DB_PREFIX."liberty_xref` x
			 JOIN `".BIT_DB_PREFIX."fisheye_gallery_image_map` m ON m.`item_content_id` = x.`content_id`
			 WHERE m.`gallery_content_id` = ? AND x.`item` = ? AND x.`end_date` IS NULL AND x.`xref` > 0 AND x.`xkey_ext` IS NOT NULL",
			[ $pProgramId, self::CHARACTER_ITEM ]
		) ?: [] as $linkRow ) {
			$characterLinks[self::characterRoleKey( (string)$linkRow['xkey_ext'] )] ??= (int)$linkRow['xref'];
		}
		$ret = [];
		foreach( self::ITEMS as $role ) {
			$people = array_values( $byRole[$role] ?? [] );
			// Most episodes first; people tied on episodes in billing order (their average place across the seasons), then by name.
			usort( $people, fn( $a, $b ) => [ $b['episodes'], $a['rankSum'] / $a['seasons'], $a['name'] ] <=> [ $a['episodes'], $b['rankSum'] / $b['seasons'], $b['name'] ] );
			foreach( $people as &$person ) {
				arsort( $person['roleSeasons'] );   // stable: ties keep first-seen order
				// One entry per role: "Sgt. Hanlon" and "Sgt Hanlon" are the same, and so is a shorter form of a role listed in full
				// ("Sgt. Brady" beside "Sgt Don Brady"). The usual spelling leads, shown without the full stops.
				$byKey = [];
				foreach( $person['roleSeasons'] as $character => $n ) {
					$key = self::characterRoleKey( $character );
					if( $key !== '' ) {
						$byKey[$key] ??= [ 'name' => self::tidyRoleName( $character ), 'n' => 0 ];
						$byKey[$key]['n'] += $n;
					}
				}
				foreach( array_keys( $byKey ) as $short ) {
					$shortTokens = explode( ' ', $short );
					foreach( array_keys( $byKey ) as $long ) {
						if( $long !== $short && isset( $byKey[$long], $byKey[$short] ) && count( explode( ' ', $long ) ) > count( $shortTokens ) && !array_diff( $shortTokens, explode( ' ', $long ) ) ) {
							$byKey[$long]['n'] += $byKey[$short]['n'];
							unset( $byKey[$short] );
							break;
						}
					}
				}
				uasort( $byKey, fn( $a, $b ) => $b['n'] <=> $a['n'] );
				// "as The Doctor, Romana" - the usual characters, at most three, each linked to its character contact where it has one, then "+N".
				$person['roleList'] = [];
				foreach( array_slice( $byKey, 0, 3, true ) as $key => $entry ) {
					$person['roleList'][] = [ 'name' => $entry['name'], 'url' => self::characterContactUrl( $key, $characterLinks ) ];
				}
				$person['rolesMore'] = max( 0, count( $byKey ) - 3 );
				$person['roles'] = implode( ', ', array_column( $person['roleList'], 'name' ) ).( $person['rolesMore'] ? ' +'.$person['rolesMore'] : '' );
				unset( $person['roleSeasons'] );
			}
			unset( $person );
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
			 WHERE x.`end_date` IS NULL AND x.`item` IN ( ".self::itemsSql()." )".$mapWhere."
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

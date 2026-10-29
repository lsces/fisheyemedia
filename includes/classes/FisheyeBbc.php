<?php
/**
 * BBC programme ids for the programs in the library. A BBC episode's summary (from Plex, or the file's own tags) ends with
 * 'PLAY: .../iplayer/episode/<pid>' and 'INFO: .../programmes/<pid>'; the programme record behind that pid names its parent (series, then brand), so
 * walking up from any one episode reaches the id of the whole programme - the one the 'iplayer' item on a program holds and the programmes page
 * (which stays on the BBC site after the episodes leave iPlayer) is linked from.
 *
 * Nothing here writes: programsToLink() lists what has no id yet, programmeTop() asks the BBC. The load_program_links.php page does the saving.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

class FisheyeBbc {

	public const API = 'https://www.bbc.co.uk/programmes/';

	/** One BBC programme record as an array, or null when the BBC does not answer or does not know the pid. */
	public static function programme( string $pPid ): ?array {
		if( !preg_match( '/^[0-9a-z]{8}$/', $pPid ) ) {
			return null;
		}
		$body = @file_get_contents( self::API.$pPid.'.json', false, stream_context_create( [ 'http' => [ 'header' => "User-Agent: rdmcloud-fisheyemedia/1.0\r\nAccept: application/json\r\n", 'timeout' => 12, 'ignore_errors' => false ] ] ) );
		$data = $body ? json_decode( $body, true ) : null;
		return is_array( $data['programme'] ?? null ) ? $data['programme'] : null;
	}

	/** The top of the tree above an episode (or series) pid: [ 'pid', 'title', 'type' ], or null when a lookup fails on the way up. */
	public static function programmeTop( string $pPid ): ?array {
		for( $depth = 0; $depth < 5; $depth++ ) {
			if( !( $programme = self::programme( $pPid ) ) ) {
				return null;
			}
			$parent = $programme['parent']['programme']['pid'] ?? null;
			if( !$parent ) {
				return [ 'pid' => (string)$programme['pid'], 'title' => (string)( $programme['title'] ?? '' ), 'type' => (string)( $programme['type'] ?? '' ) ];
			}
			$pPid = (string)$parent;
		}
		return null;
	}

	/** The BBC episode pid in a stored episode row, in either the escaped or the plain form of the PLAY address. */
	public static function episodePidIn( string $pData ): ?string {
		return preg_match( '#iplayer\\\\?/episode\\\\?/([0-9a-z]{8})#', $pData, $m ) ? $m[1] : null;
	}

	private static function fromWhere(): string {
		return "FROM `".BIT_DB_PREFIX."liberty_content` g
			WHERE g.`content_type_guid` = 'fisheyeprogram'
			AND NOT EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` i WHERE i.`content_id` = g.`content_id` AND i.`item` = 'iplayer' AND i.`end_date` IS NULL )
			AND EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
				JOIN `".BIT_DB_PREFIX."liberty_xref` x ON x.`content_id` = m.`item_content_id` AND x.`item` = 'episode' AND x.`data` LIKE '%iplayer%episode%'
				WHERE m.`gallery_content_id` = g.`content_id` )";
	}

	/** How many programs have BBC episodes but no 'iplayer' id yet. */
	public static function countToLink(): int {
		global $gBitDb;
		return (int)$gBitDb->getOne( "SELECT COUNT(*) ".self::fromWhere() );
	}

	/** The next programs after content_id $pAfter, each with the pid of one of its BBC episodes: [ [ 'content_id', 'title', 'episode_pid' ], ... ]. */
	public static function programsToLink( int $pAfter, int $pLimit ): array {
		global $gBitDb;
		$out = [];
		foreach( $gBitDb->getAll( "SELECT FIRST ".(int)$pLimit." g.`content_id`, g.`title` ".self::fromWhere()." AND g.`content_id` > ? ORDER BY g.`content_id`", [ $pAfter ] ) ?: [] as $row ) {
			$data = (string)$gBitDb->getOne(
				"SELECT x.`data` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
				 JOIN `".BIT_DB_PREFIX."liberty_xref` x ON x.`content_id` = m.`item_content_id` AND x.`item` = 'episode' AND x.`data` LIKE '%iplayer%episode%'
				 WHERE m.`gallery_content_id` = ?", [ (int)$row['content_id'] ] );
			if( $pid = self::episodePidIn( $data ) ) {
				$out[] = [ 'content_id' => (int)$row['content_id'], 'title' => (string)$row['title'], 'episode_pid' => $pid ];
			}
		}
		return $out;
	}

	/** Titles compared without case, punctuation or a bracketed year. */
	public static function sameTitle( string $pA, string $pB ): bool {
		$key = fn( string $s ) => preg_replace( '/[^a-z0-9]/', '', strtolower( preg_replace( '/\(.*?\)/', '', $s ) ) );
		return $key( $pA ) === $key( $pB );
	}
}

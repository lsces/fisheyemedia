<?php
/**
 * The descriptions of films, programmes, seasons and albums, held as HTML like a book's or a contact's (the content format is
 * 'bithtml' for all of them). Older rows were plain text with newlines; toHtml() makes them paragraphs, escaping the text, and turns
 * the BBC "PLAY: <address>" / "INFO: <address>" lines a download carries into links. convert_descriptions.php runs it over the rows.
 *
 * @package fisheyemedia
 * @subpackage classes
 */

namespace Bitweaver\Fisheyemedia;

class FisheyeDescriptions {
	/** content type => class, the descriptions that began as plain text */
	public const TYPES = [
		'fisheyefilm'    => FisheyeFilm::class,
		'fisheyeprogram' => FisheyeProgram::class,
		'fisheyeseason'  => FisheyeSeason::class,
		'fisheyealbum'   => FisheyeAlbum::class,
	];

	/** Plain text (or HTML already, which is left as it is) to HTML paragraphs, BBC lines as links. Safe to apply twice. */
	public static function toHtml( string $pText ): string {
		$html = FisheyeBook::textToHtml( $pText );
		return preg_replace( '#\b(PLAY|INFO):[ \t]*(https?://[^\s<]+)#', '<a href="$2">$1</a>', $html );
	}

	/** Whether a stored description is still plain text (non-empty, and toHtml() would change it). */
	public static function isPlain( string $pText ): bool {
		$trimmed = trim( str_replace( [ "\r\n", "\r" ], "\n", $pText ) );
		return $trimmed !== '' && self::toHtml( $pText ) !== $trimmed;
	}

	/**
	 * The plain-text rows after $pAfter, in content id order.
	 * @return list<array{content_id:int,content_type_guid:string,title:string,data:string}>
	 */
	public static function plainRows( int $pAfter = 0, int $pLimit = 0 ): array {
		global $gBitDb;
		$types = array_keys( self::TYPES );
		$rows = $gBitDb->getAll(
			"SELECT `content_id`, `content_type_guid`, `title`, `data` FROM `".BIT_DB_PREFIX."liberty_content`
			 WHERE `content_type_guid` IN (".implode( ',', array_fill( 0, count( $types ), '?' ) ).") AND `content_id` > ? AND `data` IS NOT NULL
			 ORDER BY `content_id`", array_merge( $types, [ $pAfter ] )
		) ?: [];
		$out = [];
		foreach( $rows as $row ) {
			if( self::isPlain( (string)$row['data'] ) ) {
				$out[] = [ 'content_id' => (int)$row['content_id'], 'content_type_guid' => $row['content_type_guid'], 'title' => (string)$row['title'], 'data' => (string)$row['data'] ];
				if( $pLimit && count( $out ) >= $pLimit ) {
					break;
				}
			}
		}
		return $out;
	}

	/** Store one row's description as HTML through its own class (history and modified date written as for any edit). */
	public static function convert( array $pRow ): ?string {
		$class = self::TYPES[$pRow['content_type_guid']] ?? null;
		if( !$class ) {
			return 'not a type that is converted';
		}
		$object = new $class( null, $pRow['content_id'] );
		$object->load();
		$hash = [ 'content_id' => $pRow['content_id'], 'title' => $pRow['title'], 'edit' => self::toHtml( $pRow['data'] ) ];
		return $object->store( $hash ) ? null : implode( '; ', array_map( 'strval', array_values( (array)$object->mErrors ) ) );
	}
}

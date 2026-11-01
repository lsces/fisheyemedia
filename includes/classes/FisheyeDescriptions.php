<?php
/**
 * The descriptions of films, programmes, seasons and albums are held as HTML like a book's or a contact's (the content format is
 * 'bithtml' for all of them). toHtml() makes plain text into that - paragraphs, the text escaped - and turns the BBC
 * "PLAY: <address>" / "INFO: <address>" lines a download carries into links. Every writer of a description uses it.
 *
 * @package fisheyemedia
 * @subpackage classes
 */

namespace Bitweaver\Fisheyemedia;

class FisheyeDescriptions {
	/** Plain text (or HTML already, which is left as it is) to HTML paragraphs, BBC lines as links. Safe to apply twice. */
	public static function toHtml( string $pText ): string {
		$html = FisheyeBook::textToHtml( $pText );
		return preg_replace( '#\b(PLAY|INFO):[ \t]*(https?://[^\s<]+)#', '<a href="$2">$1</a>', $html );
	}
}

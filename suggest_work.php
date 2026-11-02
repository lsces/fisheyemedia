<?php
/**
 * Where a classic might be found, as JSON for the Suggest link on a classic's row in the books table (title and author in, matches from Wikidata, Project
 * Gutenberg and Google Books out - see FisheyeBookClassics::suggest()). Read-only; admin only like the pages that call it.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

$author = trim( htmlspecialchars_decode( (string)( $_REQUEST['author'] ?? '' ), ENT_NOQUOTES ) );
header( 'Content-Type: application/json; charset=utf-8' );
echo json_encode( FisheyeBookClassics::suggest( htmlspecialchars_decode( (string)( $_REQUEST['title'] ?? '' ), ENT_NOQUOTES ), $author, $author !== '' ? FisheyeBookCredits::authorQid( FisheyeBookCredits::canonicalName( $author ) ) : '' ) );
die;

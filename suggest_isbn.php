<?php
/**
 * Open Library matches for one book with no ISBN, as JSON for the Suggest link on the Add books from a sheet review lists (title and author in,
 * the first few editions with an ISBN-13 out - see FisheyeBookCatalogue::suggestEditions()). Read-only; admin only like the pages that call it.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

header( 'Content-Type: application/json; charset=utf-8' );
echo json_encode( [ 'matches' => FisheyeBookCatalogue::suggestEditions( (string)( $_REQUEST['title'] ?? '' ), (string)( $_REQUEST['author'] ?? '' ) ) ] );
die;

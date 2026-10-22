<?php
/**
 * One-off: tag every contact already credited as an author on a book or series with the Book Author type (WP14).
 * The authors pass tags WP14 from now on; this catches contacts it tagged WP11 before that existed.
 *
 * Dry run by default - lists who would be tagged. Add ?apply=1 to write. Additive only and safe to re-run.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\Contactwiki\ContactWikiIndividual as Wiki;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitDb;

$gBitSystem->verifyPackage( 'contactwiki' );
$gBitSystem->verifyPermission( 'p_contact_update' );

$apply = !empty( $_REQUEST['apply'] );
header( 'Content-Type: text/plain; charset=utf-8' );

$ids = $gBitDb->getCol(
	"SELECT DISTINCT x.`xref` FROM `".BIT_DB_PREFIX."liberty_xref` x
	 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = x.`content_id`
	 WHERE x.`item` = 'author' AND x.`end_date` IS NULL AND x.`xref` > 0
	 AND lc.`content_type_guid` IN ( 'fisheyebook', 'fisheyeseries' )"
) ?: [];

$tagged = 0;
foreach( $ids as $id ) {
	$contact = new Wiki( null, (int)$id );
	$contact->load();
	if( !$contact->isValid() || $contact->mContentTypeGuid !== 'contactwikiindi' ) {
		continue;
	}
	if( in_array( 'WP14', $contact->getSetTypeItems(), true ) ) {
		continue;
	}
	echo ( $apply ? 'tagged  ' : 'would tag  ' ).$contact->getTitle()."\n";
	if( $apply ) {
		$contact->markAsBookAuthor();
	}
	$tagged++;
}
echo "\n".$tagged.( $apply ? ' tagged' : ' would be tagged' ).' of '.count( $ids )." credited authors\n";

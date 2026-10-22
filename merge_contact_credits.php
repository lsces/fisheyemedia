<?php
/**
 * One-off helper: move what the books hold against one contact onto another - for the case where the
 * authors pass made an individual for a name that is really an organisation (Reader's Digest as
 * "Trusted Media Brands"), and the right organisation contact was made afterwards.
 *
 *   merge_contact_credits.php?from=<old content_id>&to=<new content_id>[&apply=1]
 *   merge_contact_credits.php?to=<content_id>[&apply=1]     (no old contact: only link the unlinked book credits
 *                                                            written under the contact's own name and other names)
 *
 * Dry run by default: lists what would change. With apply=1 it
 *   - adds the old contact's other names (pen names, and its own name if different) to the new one,
 *   - links the book credits (author, editor, ...) written under any of those names, and not yet linked
 *     to anyone, to the new contact,
 *   - copies the old contact's image to the new one when that has none, and makes it the thumbnail,
 *   - points any Library gallery that names the old contact as its author at the new one.
 * It never deletes the old contact (do that from its edit page once nothing needs it) and never touches a
 * credit already linked to someone. Run as a logged-in admin: xref writes are role-gated.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\Contactwiki\ContactWikiGroup;
use Bitweaver\Contactwiki\ContactWikiIndividual;
use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitDb;

$gBitSystem->verifyPackage( 'contactwiki' );
$gBitSystem->verifyPermission( 'p_contact_update' );
class_exists( FisheyeBook::class );

$apply  = !empty( $_REQUEST['apply'] );
$fromId = (int)( $_REQUEST['from'] ?? 0 );
$toId   = (int)( $_REQUEST['to'] ?? 0 );
header( 'Content-Type: text/plain; charset=utf-8' );

$load = function( int $pId ) use ( $gBitDb ) {
	$guid = $gBitDb->getOne( "SELECT `content_type_guid` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ?", [ $pId ] );
	$class = $guid === 'contactwikigroup' ? ContactWikiGroup::class : ( $guid === 'contactwikiindi' ? ContactWikiIndividual::class : null );
	if( !$class ) {
		return null;
	}
	$contact = new $class( null, $pId );
	$contact->load();
	return $contact->isValid() ? $contact : null;
};
$from = $fromId && $fromId !== $toId ? $load( $fromId ) : null;
$to   = $toId ? $load( $toId ) : null;
if( !$to || ( $fromId && !$from ) ) {
	echo "Give the new wiki contact, and optionally a different old one: ?to=<content_id>[&from=<old content_id>]\n";
	die;
}
$nameOf = fn( int $pId ) => FisheyeBookCredits::contactName( $pId );
echo ( $apply ? 'APPLY' : 'DRY RUN' ).": ".( $from ? $nameOf( $fromId )." ($fromId) -> " : '' ).$nameOf( $toId )." ($toId)\n\n";

// The names the contacts answer to: the old contact's (when there is one) and the new contact's own and other names - a
// credit written under any of them is the new contact's. Other names typed on the edit page sit in xkey, those from Wikidata
// or a credit in xkey_ext.
$names = [ $nameOf( $toId ) ];
foreach( array_filter( [ $fromId, $toId ] ) as $contactId ) {
	if( $contactId === $fromId ) {
		$names[] = $nameOf( $fromId );
	}
	foreach( $gBitDb->getCol( "SELECT COALESCE( NULLIF( `xkey_ext`, '' ), `xkey` ) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` IN ( 'penname', 'birthname' ) AND `end_date` IS NULL", [ $contactId ] ) ?: [] as $other ) {
		if( (string)$other !== '' ) {
			$names[] = (string)$other;
		}
	}
}
$keys = array_map( [ FisheyeBookCredits::class, 'nameKey' ], $names );

// Other names for the new contact.
$ownKeys = array_map( fn( $form ) => str_replace( ' ', '', $form ), ContactWikiIndividual::nameForms( $nameOf( $toId ) ) );
foreach( $from ? array_unique( $names ) : [] as $name ) {
	if( in_array( FisheyeBookCredits::nameKey( $name ), $ownKeys, true ) ) {
		continue;
	}
	echo "other name: $name\n";
	if( $apply ) {
		$to->addOtherName( $name );
	}
}

// Book credits written under those names and linked to nobody.
$rows = $gBitDb->getAll(
	"SELECT x.`xref_id`, x.`xkey_ext`, x.`xref`, lc.`title` FROM `".BIT_DB_PREFIX."liberty_xref` x
	 JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = x.`content_id`
	 WHERE lc.`content_type_guid` = 'fisheyebook' AND x.`end_date` IS NULL AND x.`xkey_ext` IS NOT NULL
	 AND x.`item` IN ( '".implode( "', '", FISHEYEBOOK_CREDIT_ITEMS )."' )"
) ?: [];
$unlinked = [];
$leftAlone = 0;
foreach( $rows as $row ) {
	if( !in_array( FisheyeBookCredits::nameKey( (string)$row['xkey_ext'] ), $keys, true ) ) {
		continue;
	}
	if( !empty( $row['xref'] ) ) {
		$leftAlone++;
		continue;
	}
	$unlinked[] = (int)$row['xref_id'];
	echo "credit: ".$row['title']." (".$row['xkey_ext'].")\n";
}
echo count( $unlinked )." credits to link".( $leftAlone ? ", $leftAlone already linked to someone and left alone" : '' )."\n";
if( $apply && $unlinked ) {
	$linked = FisheyeCredits::linkRows( $unlinked, $toId, FisheyeBookCredits::contactKey( $toId ), null, [ 'fisheyebook' ] );
	echo "linked $linked\n";
}

// The image (only when there is an old contact to take it from).
$hasImage = (int)$gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'image' AND `end_date` IS NULL", [ $toId ] );
$image = $gBitDb->getOne( "SELECT `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'image' AND `end_date` IS NULL ORDER BY `xorder`", [ $fromId ] );
if( !$from ) {
	// no old contact: nothing to copy
} elseif( $hasImage ) {
	echo "image: the new contact already has one\n";
} elseif( !$image || basename( (string)$image ) !== $image || !is_file( $from->getExtraImagePath( $image ) ) ) {
	echo "image: nothing to copy from the old contact\n";
} else {
	echo "image: copy $image\n";
	if( $apply ) {
		KernelTools::mkdir_p( $to->getExtraImagePath( '' ) );
		if( copy( $from->getExtraImagePath( $image ), $to->getExtraImagePath( $image ) ) ) {
			@chmod( $to->getExtraImagePath( $image ), 0644 );
			$to->upsertXref( $toId, 'image', [ 'xkey_ext' => $image ] );
			echo $to->promoteImageToThumbnail( $image ) ? "image: copied, thumbnail set\n" : "image: copied, thumbnail NOT built\n";
		} else {
			echo "image: copy FAILED\n";
		}
	}
}

// Galleries that name the old contact as their author.
foreach( $from ? $gBitDb->getCol( "SELECT `content_id` FROM `".BIT_DB_PREFIX."liberty_content_prefs` WHERE `pref_name` = 'author_contact_id' AND `pref_value` = ?", [ (string)$fromId ] ) : [] as $galleryId ) {
	echo "gallery: ".$gBitDb->getOne( "SELECT `title` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ?", [ (int)$galleryId ] )." ($galleryId)\n";
	if( $apply ) {
		FisheyeBookCredits::linkGalleryToContact( (int)$galleryId, $toId );
	}
}

echo "\n".( $apply ? ( $from ? "Done. The old contact is untouched - delete it from its edit page when ready (until then both answer to the same other names).\n" : "Done.\n" ) : "Nothing changed. Add &apply=1 to do it.\n" );

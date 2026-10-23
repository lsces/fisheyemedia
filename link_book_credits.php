<?php
/**
 * One-off helper: link the book credits that name a person who has an author contact. Books loaded before their author's
 * contact existed (or by a loader that did not link them) hold the credit as a bare name; this gives each such credit its
 * contact, by the same name rules the authors press uses (FisheyeBookCredits::authorContactFor() - name, pen name, birth
 * name, and only a contact that is a book author, an Open Library author or an organisation tagged as publisher).
 *
 *   link_book_credits.php[?apply=1][&gallery_id=<content_id>]
 *
 * Dry run by default: lists, per contact, the credits that would be linked. A name that is ambiguous (two contacts answer to
 * it) or has no contact is left, and counted. A credit already linked, by hand or otherwise, is never touched. At most
 * LINK_BOOK_CREDITS_MAX credits are linked per press - run it again for the rest. Run as a logged-in admin: xref writes are role-gated.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitDb;

$gBitSystem->verifyPackage( 'contactwiki' );
$gBitSystem->verifyPermission( 'p_contact_update' );
class_exists( FisheyeBook::class );

const LINK_BOOK_CREDITS_MAX = 200;

$apply = !empty( $_REQUEST['apply'] );
$galleryContentId = (int)( $_REQUEST['gallery_id'] ?? 0 ) ?: null;
header( 'Content-Type: text/plain; charset=utf-8' );
echo ( $apply ? 'APPLY' : 'DRY RUN' ).( $galleryContentId ? ", books in gallery $galleryContentId" : ', all books' )."\n\n";

FisheyeBookCredits::resetCache();
$byContact = [];
$noContact = 0;
$leftNames = [];
foreach( FisheyeBookCredits::unlinkedPeople( $galleryContentId ) as $person ) {
	$contactId = FisheyeBookCredits::authorContactFor( $person['name'] );
	if( !$contactId ) {
		$noContact += count( $person['xref_ids'] );
		$leftNames[] = $person['name'].' ('.count( $person['xref_ids'] ).')';
		continue;
	}
	$byContact[$contactId]['name'] = $person['name'];
	$byContact[$contactId]['xref_ids'] = array_merge( $byContact[$contactId]['xref_ids'] ?? [], $person['xref_ids'] );
	$byContact[$contactId]['books'] = array_merge( $byContact[$contactId]['books'] ?? [], array_values( $person['books'] ) );
}

$total = $linked = 0;
foreach( $byContact as $contactId => $group ) {
	$books = array_values( array_unique( $group['books'] ) );
	echo FisheyeBookCredits::contactName( $contactId )." ($contactId) <- credited as \"".$group['name']."\": ".count( $books )." books, ".implode( '; ', array_slice( $books, 0, 3 ) ).( count( $books ) > 3 ? '...' : '' )."\n";
	$total += count( $group['xref_ids'] );
	if( $apply && $linked < LINK_BOOK_CREDITS_MAX ) {
		$linked += FisheyeBookCredits::linkBookRows( array_slice( $group['xref_ids'], 0, LINK_BOOK_CREDITS_MAX - $linked ), $contactId, FisheyeBookCredits::contactKey( $contactId ) );
	}
}
echo "\n".$total." credits for ".count( $byContact )." contacts".( $apply ? ", linked $linked" : ' to link' ).";"
	." $noContact left (no single author contact for the name)\n";
if( $leftNames ) {
	echo "Left: ".implode( '; ', $leftNames )."\n";
}
if( $apply && $total > $linked ) {
	echo "More remain - run again.\n";
}

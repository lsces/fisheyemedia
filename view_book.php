<?php
/**
 * Dedicated view page for a book (FisheyeBook) - same shape as view_album.php, bucketing this book's
 * own xref data into template-friendly arrays rather than hardcoding which x_group an item lives under.
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeImage;

require_once '../kernel/includes/setup_inc.php';
global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'fisheye' );

$gContent = FisheyeImage::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No book exists with the given ID' ), 'error.tpl' );
}
$gContent->verifyViewPermission();
$gContent->addHit();

$gContent->loadXrefInfo();
$isbn = null;
$credits = [];
$values = [];
$lists = [];
$externalLinks = [];
if( $gContent->mXrefInfo ) {
	foreach( $gContent->liveXrefs() as $xref ) {
		$item = $xref['item'];
		$data = !empty( $xref['data'] ) ? json_decode( $xref['data'], true ) : [];

		// External ids (ol_work, wikidata_work ...) - identified by having a cross_ref_href, the same
		// convention the album and film links use, so a new item in the scheme shows up here for free.
		$linkValue = $xref['xkey'] ?: ( $xref['xkey_ext'] ?? null );
		if( !empty( $xref['cross_ref_href'] ) && !empty( $linkValue ) && $item !== 'isbn' ) {
			$externalLinks[] = [ 'title' => $xref['xref_title'] ?? strtoupper( $item ), 'url' => $xref['cross_ref_href'].$linkValue ];
		}

		if( $item === 'isbn' ) {
			$isbn = [ 'isbn13' => (string)$xref['xkey'], 'isbn10' => (string)$xref['xkey_ext'], 'entered' => $data['entered'] ?? '', 'note' => $data['note'] ?? '' ];
		} elseif( in_array( $item, [ ...FISHEYEBOOK_CREDIT_ITEMS, 'publisher' ], true ) ) {
			// Linked to the person's contact once there is one - the root index.php?content_id=
			// dispatcher routes to whatever the contact's own page is.
			$credits[$item][] = [
				'name'   => $xref['xkey_ext'],
				'xorder' => (int)$xref['xorder'],
				'url'    => !empty( $xref['xref'] ) ? BIT_ROOT_URL.'index.php?content_id='.(int)$xref['xref'] : null,
			];
		} elseif( in_array( $item, FISHEYEBOOK_TEXT_ITEMS, true ) ) {
			$values[$item] = (string)$xref['xkey_ext'];
		} elseif( in_array( $item, FISHEYEBOOK_LIST_ITEMS, true ) ) {
			$lists[$item][] = [ 'text' => (string)$xref['xkey_ext'], 'xorder' => (int)$xref['xorder'] ];
		}
	}
}

// Credits in job order, each job's people in credit order.
$creditGroups = [];
foreach( [ ...FISHEYEBOOK_CREDIT_ITEMS, 'publisher' ] as $role ) {
	if( !empty( $credits[$role] ) ) {
		usort( $credits[$role], fn( $a, $b ) => $a['xorder'] <=> $b['xorder'] );
		$creditGroups[] = [ 'role' => $role, 'people' => $credits[$role] ];
	}
}
foreach( $lists as &$list ) {
	usort( $list, fn( $a, $b ) => $a['xorder'] <=> $b['xorder'] );
}
unset( $list );

// What is shown as label/value lines, in this order. The price paid is private - only an editor sees them.
$detailLabels = [
	'published' => 'Published', 'edition' => 'Edition', 'format' => 'Format', 'imprint' => 'Imprint',
	'pages' => 'Pages', 'language' => 'Language', 'copies' => 'Copies',
];
if( $gContent->hasUpdatePermission() ) {
	$detailLabels += [ 'paid' => 'Paid (GBP)' ];
}
$details = [];
foreach( $detailLabels as $item => $label ) {
	if( ( $values[$item] ?? '' ) !== '' ) {
		$details[] = [ 'label' => KernelTools::tra( $label ), 'value' => $values[$item] ];
	}
}
foreach( [ 'category' => 'Category', 'subject' => 'Subject', 'note' => 'Note' ] as $item => $label ) {
	if( !empty( $lists[$item] ) ) {
		$details[] = [ 'label' => KernelTools::tra( $label ), 'value' => implode( '; ', array_column( $lists[$item], 'text' ) ) ];
	}
}

$gBitSmarty->assign( 'isbn', $isbn );
$gBitSmarty->assign( 'creditGroups', $creditGroups );
$gBitSmarty->assign( 'details', $details );
$gBitSmarty->assign( 'externalLinks', $externalLinks );
$gBitSmarty->assign( 'gContent', $gContent );

$gBitSystem->setCanonicalLink( $gContent->getDisplayUrl() );
$gBitSystem->setBrowserTitle( $gContent->getTitle() );
$gBitSystem->display( 'bitpackage:fisheyemedia/view_book.tpl', null, [ 'display_mode' => 'display' ] );

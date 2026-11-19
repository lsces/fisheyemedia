<?php
/**
 * A book's details as the pages show them - credits, ISBN, label/value lines and external links - bucketed from the book's own xref rows. One place for it,
 * so the book's info page (view_book.php) and the file viewer (view_extra_file.php) show the same data the same way. Read only: changing anything is the
 * edit page's job.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;

class FisheyeBookDetails {

	/**
	 * @return array{isbn:?array,isbns:list<array>,creditGroups:list<array>,details:list<array>,externalLinks:list<array>}
	 */
	public static function collect( FisheyeBook $pBook ): array {
		$pBook->loadXrefInfo();
		$isbn = null;
		$isbns = [];   // every edition the book carries, in the order they are kept
		$credits = [];
		$values = [];
		$lists = [];
		$externalLinks = [];
		if( $pBook->mXrefInfo ) {
			foreach( $pBook->liveXrefs() as $xref ) {
				$item = $xref['item'];

				// External ids (ol_work, wikidata_work ...) - identified by having a cross_ref_href, the same
				// convention the album and film links use, so a new item in the scheme shows up here for free.
				$linkValue = $xref['xkey'] ?: ( $xref['xkey_ext'] ?? null );
				if( !empty( $xref['cross_ref_href'] ) && !empty( $linkValue ) && $item !== 'isbn' ) {
					$externalLinks[] = [ 'title' => $xref['xref_title'] ?? strtoupper( $item ), 'url' => $xref['cross_ref_href'].$linkValue ];
				} elseif( ( $xref['template'] ?? '' ) === 'href' && empty( $xref['cross_ref_href'] ) && preg_match( '#^https?://#i', (string)( $xref['xkey_ext'] ?? '' ) ) ) {
					// an item with no prefix holds the whole web address (the project Book's Extra Information): its link text, when given, stands for the title
					$externalLinks[] = [ 'title' => $xref['xkey'] ?: ( $xref['xref_title'] ?? strtoupper( $item ) ), 'url' => $xref['xkey_ext'] ];
				}

				if( $item === 'isbn' ) {
					$isbns[] = [ 'isbn13' => (string)$xref['xkey'], 'isbn10' => (string)$xref['xkey_ext'], 'note' => trim( (string)( $xref['data'] ?? '' ) ) ];
					$isbn ??= $isbns[0];
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
				} elseif( $item === 'genre' || in_array( $item, FISHEYEBOOK_LIST_ITEMS, true ) ) {
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

		// What is shown as label/value lines, in this order. The price paid is private - only an editor sees it.
		$detailLabels = [
			'published' => 'Published', 'edition_date' => 'Edition published', 'edition' => 'Edition', 'format' => 'Format', 'imprint' => 'Imprint',
			'pages' => 'Pages', 'language' => 'Language', 'copies' => 'Copies',
		];
		if( $pBook->hasUpdatePermission() ) {
			$detailLabels += [ 'paid' => 'Paid (GBP)' ];
		}
		$details = [];
		foreach( $detailLabels as $item => $label ) {
			if( ( $values[$item] ?? '' ) !== '' ) {
				$details[] = [ 'label' => KernelTools::tra( $label ), 'value' => $values[$item] ];
			}
		}
		foreach( [ 'category' => 'Category', 'genre' => 'Genre', 'subject' => 'Subject', 'note' => 'Note' ] as $item => $label ) {
			if( !empty( $lists[$item] ) ) {
				$details[] = [ 'label' => KernelTools::tra( $label ), 'value' => implode( '; ', array_column( $lists[$item], 'text' ) ) ];
			}
		}

		// Bookshop links come from the ISBNs and are not stored: Amazon's id for a book is its ISBN-10, AbeBooks searches by ISBN-13. A book with several editions
		// gets a link for each, named by the number, as a shop may know only one of them.
		foreach( $isbns as $edition ) {
			$tag = count( $isbns ) > 1 ? ' ('.( $edition['isbn13'] ?: $edition['isbn10'] ).')' : '';
			if( !empty( $edition['isbn10'] ) ) {
				$externalLinks[] = [ 'title' => 'Amazon'.$tag, 'url' => 'https://www.amazon.co.uk/dp/'.$edition['isbn10'] ];
			}
			if( !empty( $edition['isbn13'] ) ) {
				$externalLinks[] = [ 'title' => 'AbeBooks'.$tag, 'url' => 'https://www.abebooks.co.uk/servlet/SearchResults?isbn='.$edition['isbn13'] ];
			}
		}
		return [ 'isbn' => $isbn, 'isbns' => $isbns, 'creditGroups' => $creditGroups, 'details' => $details, 'externalLinks' => $externalLinks ];
	}
}

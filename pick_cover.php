<?php
/**
 * Cover picker for one book. Lists the covers the book's ISBN finds (Open Library, Google Books, Amazon) with their
 * sizes, and takes one from an address pasted in (the "copy image address" of a picture found on Amazon, AbeBooks or
 * anywhere else) or from a scan uploaded from this machine. Nothing is stored until a picture is chosen; the chosen one
 * becomes the book's front cover and tile (FisheyeBook::replaceCover(), at most FISHEYEBOOK_COVER_MAX px).
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeImage;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'fisheye' );

$gContent = FisheyeImage::lookup( $_REQUEST );
if( !$gContent || !$gContent->isValid() || !method_exists( $gContent, 'replaceCover' ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No book exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$gContent->verifyUpdatePermission();

// detoxify() HTML-escapes every $_REQUEST value; undone for the address.
$decode = fn( $pValue ) => htmlspecialchars_decode( trim( (string)$pValue ), ENT_NOQUOTES );

$message = '';
$errors = [];

$hasIsbnRow = fn() => (bool)$gContent->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'isbn' AND `end_date` IS NULL", [ $gContent->mContentId ] );

// A book with no ISBN can be given one here: FisheyeBook::storeXref() checks the check digit and keeps ISBN-13 and ISBN-10.
if( !empty( $_REQUEST['add_isbn'] ) && !$hasIsbnRow() ) {
	$hash = [ 'content_id' => $gContent->mContentId, 'item' => 'isbn', 'isbn' => $decode( $_REQUEST['isbn'] ?? '' ) ];
	if( $gContent->storeXref( $hash ) ) {
		$message = KernelTools::tra( 'The ISBN is added to the book.' );
	} else {
		$errors = array_merge( $errors, array_values( $gContent->mErrors ) );
	}
}

// The edit page's Reload from Open Library: fills what is blank (format, genre, pages, description ...) and a cover only if the book has none.
if( !empty( $_REQUEST['reload_ol'] ) && $hasIsbnRow() ) {
	$olResult = $gContent->reloadFromOpenLibrary();
	if( !empty( $olResult['error'] ) ) {
		$errors[] = $olResult['error'];
	} elseif( $olResult['items'] ) {
		$message = KernelTools::tra( 'Added from Open Library' ).': '.implode( ', ', $olResult['items'] );
	} else {
		$message = $olResult['matched'] ? KernelTools::tra( 'Open Library has this edition, but nothing it holds is missing here.' ) : KernelTools::tra( 'Open Library has no edition with this ISBN.' );
	}
}

$isbn13 = (string)$gContent->mDb->getOne( "SELECT `xkey` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'isbn' AND `end_date` IS NULL", [ $gContent->mContentId ] );
$isbn10 = (string)$gContent->mDb->getOne( "SELECT `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'isbn' AND `end_date` IS NULL", [ $gContent->mContentId ] );

$pasted = $decode( $_REQUEST['address'] ?? '' );

/** Store picture bytes as the cover; true on success. */
$useBytes = function( string $pBytes, string $pSource ) use ( $gContent, &$errors ): bool {
	$tmp = tempnam( sys_get_temp_dir(), 'pickcover_' );
	file_put_contents( $tmp, $pBytes );
	$stored = $gContent->replaceCover( $tmp, $pSource );
	@unlink( $tmp );
	if( $stored === null ) {
		$errors[] = KernelTools::tra( 'The picture could not be stored as the cover.' );
	}
	return $stored !== null;
};

$candidates = FisheyeBookCovers::candidates( $isbn13, $isbn10 );

if( !empty( $_REQUEST['use'] ) ) {
	$key = $decode( $_REQUEST['use'] );
	if( $key === 'address' ) {
		$bytes = FisheyeBookCovers::fetchAddress( $pasted, $why );
		if( $bytes === null ) {
			$errors[] = KernelTools::tra( (string)$why );
		} elseif( $useBytes( $bytes, 'picked:'.$pasted ) ) {
			$message = KernelTools::tra( 'The picture from that address is now the cover.' );
		}
	} else {
		foreach( $candidates as $candidate ) {
			if( $candidate['key'] === $key ) {
				$bytes = FisheyeBookCovers::fetchMany( [ $key => $candidate['url'] ] )[$key] ?? null;
				if( $bytes === null ) {
					$errors[] = KernelTools::tra( 'That site no longer gives a picture.' );
				} elseif( $useBytes( $bytes, 'picked:'.$candidate['label'] ) ) {
					$message = KernelTools::tra( 'The picture from' ).' '.$candidate['label'].' '.KernelTools::tra( 'is now the cover.' );
				}
				break;
			}
		}
	}
} elseif( !empty( $_REQUEST['use_scan'] ) ) {
	$file = $_FILES['scan'] ?? null;
	$bytes = $file && $file['error'] === UPLOAD_ERR_OK ? (string)file_get_contents( $file['tmp_name'] ) : '';
	if( !FisheyeBookCovers::isPicture( $bytes ) ) {
		$errors[] = KernelTools::tra( 'Choose a picture file to upload.' );
	} elseif( $useBytes( $bytes, 'scan:'.basename( (string)$file['name'] ) ) ) {
		$message = KernelTools::tra( 'The uploaded scan is now the cover.' );
	}
}

// Look at the candidates: fetched together, listed with size; the pasted address is shown beside them for a look before it is used.
$urls = [];
foreach( $candidates as $candidate ) {
	$urls[$candidate['key']] = $candidate['url'];
}
$pictures = $urls ? FisheyeBookCovers::fetchMany( $urls ) : [];
$shown = [];
$seen = [];
foreach( $candidates as $candidate ) {
	$bytes = $pictures[$candidate['key']] ?? null;
	// The same picture under both ISBNs is listed once, and a site's grey "no cover" stand-in not at all.
	if( $bytes === null || isset( $seen[md5( $bytes )] ) || FisheyeBook::isBlankCover( $bytes ) ) {
		continue;
	}
	$seen[md5( $bytes )] = true;
	$shown[] = $candidate + FisheyeBookCovers::describe( $bytes );
}
$customs = [];      // pictures found at the pasted address (a picture, or the pictures on a bookseller's page)
if( $pasted !== '' && empty( $_REQUEST['use'] ) && empty( $_REQUEST['add_isbn'] ) ) {
	foreach( FisheyeBookCovers::picturesAt( $pasted, $why ) as $found ) {
		$customs[] = [ 'url' => $found['url'] ] + FisheyeBookCovers::describe( $found['bytes'] );
	}
	if( !$customs ) {
		$errors[] = KernelTools::tra( (string)$why );
	}
}

$gContent->load();
$gBitSmarty->assign( 'gContent', $gContent );
$gBitSmarty->assign( 'message', $message );
$gBitSmarty->assign( 'errors', $errors );
$gBitSmarty->assign( 'shown', $shown );
$gBitSmarty->assign( 'customs', $customs );
$gBitSmarty->assign( 'pasted', $pasted );
$gBitSmarty->assign( 'hasIsbn', $isbn13 !== '' || $isbn10 !== '' );
$gBitSmarty->assign( 'searchTerm', rawurlencode( $isbn13 ?: $isbn10 ?: $gContent->getTitle() ) );
$gBitSmarty->assign( 'titleTerm', rawurlencode( $gContent->getTitle() ) );
$gBitSmarty->assign( 'isbn13', $isbn13 );
$gBitSmarty->assign( 'isbn10', $isbn10 );
$gBitSystem->display( 'bitpackage:fisheyemedia/pick_cover.tpl', KernelTools::tra( 'Find a Cover: ' ).$gContent->getTitle(), [ 'display_mode' => 'edit' ] );

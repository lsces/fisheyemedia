<?php
/**
 * Brings book covers in from another bitweaver site's fisheye gallery whose images are titled with the book's
 * ISBN-13 (hyphens allowed, e.g. 978-0711031517). The gallery's pages are read over HTTP, each image is matched to
 * the Library book with that ISBN, and the ones ticked have the book's front cover and tile replaced by the
 * picture, fetched at original size (liberty's download route) and stored at no more than 400px, the size the Library
 * shows (a smaller one is kept as it is). Spine, back and other images a book holds stay.
 *
 * Nothing is written until Replace is pressed; the list shows the current cover beside the new one, with sizes, so
 * a smaller picture can be unticked. A cover brought in this way is marked, and the list shows it as done.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeImage;
use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPackage( 'contactwiki' );
$gBitSystem->verifyPermission( 'p_fisheye_admin' );

// Covers replaced per press - each is a download, a resize and a thumbnail rebuild.
const LOAD_BOOK_COVERS_BATCH = 10;
// Gallery pages read at most (twelve images each) - a stop for a site that never runs out.
const LOAD_BOOK_COVERS_MAX_PAGES = 20;

$site = rtrim( trim( (string)( $_REQUEST['site'] ?? 'https://medw.uk' ) ), '/' );
if( !preg_match( '~^https?://[A-Za-z0-9.-]+(:\d+)?$~', $site ) ) {
	$site = 'https://medw.uk';
}
$galleryId = max( 1, (int)( $_REQUEST['gallery_id'] ?? 108 ) );
$marker = 'bookcovers:'.parse_url( $site, PHP_URL_HOST ).':';

/** Body of a URL, null when it does not answer with 200 in time. */
function load_book_covers_fetch( string $pUrl ): ?string {
	$ch = curl_init( $pUrl );
	curl_setopt_array( $ch, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'bitweaver-fisheyemedia' ] );
	$body = curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	return ( $body !== false && $code === 200 ) ? (string)$body : null;
}

/**
 * The gallery's images: attachment id (as the thumbnail's own path gives it), title, thumbnail URL.
 *
 * @return array{items:list<array{id:int,title:string,thumb:string}>,error:string}
 */
function load_book_covers_list( string $pSite, int $pGalleryId ): array {
	$items = [];
	$seen = [];
	for( $page = 1; $page <= LOAD_BOOK_COVERS_MAX_PAGES; $page++ ) {
		$html = load_book_covers_fetch( $pSite.'/fisheye/view.php?gallery_id='.$pGalleryId.'&page='.$page );
		if( $html === null ) {
			return [ 'items' => $items, 'error' => $page === 1 ? 'the gallery did not answer' : '' ];
		}
		$added = 0;
		if( preg_match_all( '~<img[^>]*src="([^"]*/attachments/\d+/(\d+)/thumbs/[^"]*)"[^>]*alt="([^"]*)"~', $html, $m, PREG_SET_ORDER ) ) {
			foreach( $m as $hit ) {
				$id = (int)$hit[2];
				if( isset( $seen[$id] ) ) {
					continue;
				}
				$seen[$id] = true;
				$added++;
				$items[] = [ 'id' => $id, 'title' => html_entity_decode( $hit[3] ), 'thumb' => $hit[1] ];
			}
		}
		if( !$added ) {
			break;
		}
	}
	return [ 'items' => $items, 'error' => $items ? '' : 'no images found in that gallery' ];
}

$listing = load_book_covers_list( $site, $galleryId );

// Pair each image with its book.
$rows = [];
foreach( $listing['items'] as $item ) {
	$isbn = str_replace( [ '-', ' ' ], '', $item['title'] );
	$fixed = '';
	if( preg_match( '/^978\d{10}$/', $isbn ) && !FisheyeBook::isbn13IsValid( $isbn ) ) {
		// A 978 stuck in front of an ISBN-10 without recomputing the check digit: keep the nine digits, rebuild the check.
		$fixed = FisheyeBook::isbn10To13( substr( $isbn, 3, 9 ).'0' );
	}
	$row = $item + [ 'isbn' => $fixed !== '' ? $fixed : $isbn, 'fixed' => $fixed, 'valid' => $fixed !== '' || FisheyeBook::isbn13IsValid( $isbn ), 'book' => null, 'done' => false, 'old' => null ];
	$contentId = $row['valid'] ? FisheyeBook::findContentId( [ 'isbn13' => $row['isbn'] ] ) : null;
	if( $contentId ) {
		$book = FisheyeImage::lookup( [ 'content_id' => $contentId ] );
		if( $book && $book->isValid() ) {
			$row['book'] = [ 'content_id' => $contentId, 'title' => $book->getTitle(), 'url' => $book->getDisplayUrl() ];
			$old = $gBitDb->getAll( "SELECT `xkey_ext`, `data` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'image' AND `end_date` IS NULL ORDER BY `xorder`", [ $contentId ] ) ?: [];
			foreach( $old as $o ) {
				if( str_contains( (string)$o['data'], $marker.$item['id'] ) ) {
					$row['done'] = true;
				}
			}
			if( $old ) {
				$file = $book->getExtraImagePath( '' ).$old[0]['xkey_ext'];
				$size = is_file( $file ) ? @getimagesize( $file ) : false;
				$row['old'] = [ 'thumb' => $book->getThumbnailUrl( 'avatar' ), 'size' => $size ? $size[0].'x'.$size[1] : '' ];
			}
			if( !$row['done'] ) {
				// Only the matched ones are fetched here, to show the size of what would arrive beside what is there.
				$bytes = load_book_covers_fetch( $site.'/liberty/download/file/'.$item['id'].'?size=original' );
				$info = $bytes !== null ? @getimagesizefromstring( $bytes ) : false;
				$row['newSize'] = $info ? $info[0].'x'.$info[1] : '';
				if( $info && max( $info[0], $info[1] ) > FISHEYEBOOK_COVER_MAX ) {
					$scale = FISHEYEBOOK_COVER_MAX / max( $info[0], $info[1] );
					$row['newSize'] .= ' → '.round( $info[0] * $scale ).'x'.round( $info[1] * $scale );
				}
			}
			$row['bookObj'] = $book;
		}
	}
	$rows[] = $row;
}

// Replace the ticked covers, one batch at a time.
$results = null;
if( !empty( $_REQUEST['replace'] ) && !empty( $_REQUEST['pick'] ) && is_array( $_REQUEST['pick'] ) ) {
	$results = [];
	$picked = array_map( 'intval', $_REQUEST['pick'] );
	foreach( $rows as &$row ) {
		if( !in_array( $row['id'], $picked, true ) || empty( $row['book'] ) || $row['done'] ) {
			continue;
		}
		if( count( $results ) >= LOAD_BOOK_COVERS_BATCH ) {
			break;
		}
		$bytes = load_book_covers_fetch( $site.'/liberty/download/file/'.$row['id'].'?size=original' );
		$line = [ 'title' => $row['book']['title'], 'url' => $row['book']['url'], 'isbn' => $row['isbn'], 'error' => '', 'size' => '' ];
		if( $bytes === null || !( $info = @getimagesizefromstring( $bytes ) ) ) {
			$line['error'] = 'the picture could not be fetched';
		} else {
			$tmp = tempnam( sys_get_temp_dir(), 'bookcover_' );
			file_put_contents( $tmp, $bytes );
			$name = $row['bookObj']->replaceCover( $tmp, $marker.$row['id'] );
			@unlink( $tmp );
			if( $name === null ) {
				$line['error'] = 'the picture could not be stored';
			} else {
				$line['size'] = $info[0].'x'.$info[1];
				$row['done'] = true;
			}
		}
		$results[] = $line;
	}
	unset( $row );
}

$matched = $waiting = 0;
foreach( $rows as &$row ) {
	unset( $row['bookObj'] );
	if( $row['book'] && !$row['done'] ) {
		$waiting++;
	}
	if( $row['book'] ) {
		$matched++;
	}
}
unset( $row );

$gBitSmarty->assign( 'rows', $rows );
$gBitSmarty->assign( 'matched', $matched );
$gBitSmarty->assign( 'waiting', $waiting );
$gBitSmarty->assign( 'site', $site );
$gBitSmarty->assign( 'galleryId', $galleryId );
$gBitSmarty->assign( 'listError', $listing['error'] );
$gBitSmarty->assign( 'results', $results );
$gBitSmarty->assign( 'batchSize', LOAD_BOOK_COVERS_BATCH );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_book_covers.tpl', KernelTools::tra( 'Book Covers from a Gallery' ), [ 'display_mode' => 'edit' ] );

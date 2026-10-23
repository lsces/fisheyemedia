<?php
/**
 * One-off helper: bring the front, spine and back pictures of books in from another bitweaver site's fisheye gallery (images
 * named for the book and the part: "BritishGoodsWagons fc", "DouglasToPeelSpine") and file them in ordinary galleries beside
 * the Library:  Images / Books / <book title> / Front, Spine, Back  - plain fisheyegallery folders holding plain fisheyeimage
 * content, at the size the source site holds. Each image is matched to an existing Library book by its title.
 *
 *   load_book_images.php[?site=https://medw.uk][&gallery_id=112][&apply=1][&book[<name>]=<content_id>]
 *
 * Dry run by default: lists each book found in the gallery, the Library book it matches (or the candidates, for one that
 * does not match) and which parts are already filed. A wrong or missing match is set with book[<name>]=<content_id> using the
 * name shown. With apply=1 the first LOAD_BOOK_IMAGES_BATCH books are filed; a part already in the book's folder is left alone,
 * so it can be pressed again for the rest. The book's folder carries a 'book_content_id' preference, the way an author's
 * Library gallery carries 'author_contact_id'. Run as a logged-in admin.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeGallery;

require_once '../kernel/includes/setup_inc.php';
require_once FISHEYE_PKG_INCLUDE_PATH.'upload_inc.php';

global $gBitSystem, $gBitDb;

$gBitSystem->verifyPackage( 'contactwiki' );
$gBitSystem->verifyPermission( 'p_fisheye_admin' );

const LOAD_BOOK_IMAGES_BATCH = 5;
const LOAD_BOOK_IMAGES_PARTS = [
	'Front' => [ 'front', 'fc', 'cover' ],
	'Spine' => [ 'spine', 'sp' ],
	'Back'  => [ 'back', 'bc' ],
];

$apply = !empty( $_REQUEST['apply'] );
$site = rtrim( trim( (string)( $_REQUEST['site'] ?? 'https://medw.uk' ) ), '/' );
if( !preg_match( '~^https?://[A-Za-z0-9.-]+(:\d+)?$~', $site ) ) {
	$site = 'https://medw.uk';
}
$galleryId = max( 1, (int)( $_REQUEST['gallery_id'] ?? 112 ) );
$chosen = array_map( 'intval', (array)( $_REQUEST['book'] ?? [] ) );
header( 'Content-Type: text/plain; charset=utf-8' );

function load_book_images_fetch( string $pUrl ): ?string {
	$ch = curl_init( $pUrl );
	curl_setopt_array( $ch, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'bitweaver-fisheyemedia' ] );
	$body = curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	return ( $body !== false && $code === 200 ) ? (string)$body : null;
}

/** A title reduced to what two spellings share: lower case letters and digits, "Volume Two" and "Vol. 2" alike. */
function load_book_images_squash( string $pTitle ): string {
	$words = [ 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10 ];
	$title = preg_replace_callback( '/\b(?:volume|vol)\b\.?\s*(one|two|three|four|five|six|seven|eight|nine|ten|\d+)/i',
		fn( $m ) => ' v'.( $words[strtolower( $m[1] )] ?? $m[1] ).' ', $pTitle );
	return preg_replace( '/[^a-z0-9]/', '', strtolower( (string)$title ) );
}

/** [ book name, part ] for an image title ending in a part word, else null. A two-letter word counts only after a separator. */
function load_book_images_split( string $pTitle ): ?array {
	$title = trim( $pTitle );
	foreach( LOAD_BOOK_IMAGES_PARTS as $part => $words ) {
		foreach( $words as $word ) {
			$separator = strlen( $word ) <= 2 ? '[\s_\-]+' : '[\s_\-]*';
			if( preg_match( '/^(.+?)'.$separator.preg_quote( $word, '/' ).'$/i', $title, $m ) && trim( $m[1] ) !== '' ) {
				return [ trim( $m[1] ), $part ];
			}
		}
	}
	return null;
}

/** The gallery's images (attachment id, title), read from its pages over HTTP. */
function load_book_images_list( string $pSite, int $pGalleryId ): array {
	$items = [];
	$seen = [];
	for( $page = 1; $page <= 20; $page++ ) {
		$html = load_book_images_fetch( $pSite.'/fisheye/view.php?gallery_id='.$pGalleryId.'&page='.$page );
		$added = 0;
		if( $html !== null && preg_match_all( '~<img[^>]*src="[^"]*/attachments/\d+/(\d+)/thumbs/[^"]*"[^>]*alt="([^"]*)"~', $html, $m, PREG_SET_ORDER ) ) {
			foreach( $m as $hit ) {
				if( !isset( $seen[(int)$hit[1]] ) ) {
					$seen[(int)$hit[1]] = true;
					$added++;
					$items[] = [ 'id' => (int)$hit[1], 'title' => html_entity_decode( $hit[2] ) ];
				}
			}
		}
		if( !$added ) {
			break;
		}
	}
	return $items;
}

/** A plain gallery with this title under a parent (null = top level), made when it is not there. Returns [ content_id, gallery_id ]. */
function load_book_images_gallery( string $pTitle, ?int $pParentContentId ): array {
	global $gBitDb;
	if( $pParentContentId ) {
		$contentId = $gBitDb->getOne( "SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m
			JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id`
			WHERE m.`gallery_content_id` = ? AND lc.`title` = ? AND lc.`content_type_guid` = 'fisheyegallery'", [ $pParentContentId, $pTitle ] );
	} else {
		$topId = FisheyeGallery::getTopGalleryId( $pTitle );
		$contentId = $topId ? $gBitDb->getOne( "SELECT `content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery` WHERE `gallery_id` = ?", [ $topId ] ) : null;
	}
	if( !$contentId ) {
		$gallery = new FisheyeGallery();
		$storeHash = [ 'title' => $pTitle ];
		if( !$gallery->store( $storeHash ) ) {
			return [ null, null ];
		}
		if( $pParentContentId ) {
			$gallery->addToGalleries( [ (int)$gBitDb->getOne( "SELECT `gallery_id` FROM `".BIT_DB_PREFIX."fisheye_gallery` WHERE `content_id` = ?", [ $pParentContentId ] ) ] );
		}
		$contentId = $gallery->mContentId;
	}
	return [ (int)$contentId, (int)$gBitDb->getOne( "SELECT `gallery_id` FROM `".BIT_DB_PREFIX."fisheye_gallery` WHERE `content_id` = ?", [ (int)$contentId ] ) ];
}

// Group the source images by book.
$groups = [];
foreach( load_book_images_list( $site, $galleryId ) as $item ) {
	$split = load_book_images_split( $item['title'] );
	$name = $split ? $split[0] : $item['title'];
	$groups[$name] ??= [ 'parts' => [], 'other' => [] ];
	if( $split && !isset( $groups[$name]['parts'][$split[1]] ) ) {
		$groups[$name]['parts'][$split[1]] = $item;
	} else {
		$groups[$name]['other'][] = $item['title'];
	}
}
echo ( $apply ? 'APPLY' : 'DRY RUN' ).": $site gallery $galleryId, ".count( $groups )." books\n\n";

// The Library books to match against, squashed once.
$library = [];
foreach( $gBitDb->getAll( "SELECT `content_id`, `title` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_type_guid` = 'fisheyebook'" ) ?: [] as $row ) {
	$library[(int)$row['content_id']] = [ 'title' => (string)$row['title'], 'key' => load_book_images_squash( (string)$row['title'] ) ];
}

$done = 0;
foreach( $groups as $name => $group ) {
	$key = load_book_images_squash( $name );
	$parts = implode( '/', array_keys( $group['parts'] ) );
	if( !empty( $chosen[$name] ) && isset( $library[$chosen[$name]] ) ) {
		$bookId = $chosen[$name];
	} else {
		// The book whose squashed title is the name, else the only one that starts with it; names under eight characters match exactly only.
		$exact = array_keys( array_filter( $library, fn( $b ) => $b['key'] === $key ) );
		$starts = strlen( $key ) >= 8 ? array_keys( array_filter( $library, fn( $b ) => str_starts_with( $b['key'], $key ) ) ) : [];
		$bookId = count( $exact ) === 1 ? $exact[0] : ( !$exact && count( $starts ) === 1 ? $starts[0] : null );
	}
	if( !$bookId ) {
		$near = array_keys( array_filter( $library, fn( $b ) => strlen( $key ) >= 8 && ( str_contains( $b['key'], $key ) || ( strlen( $b['key'] ) >= 10 && str_contains( $key, $b['key'] ) ) ) ) );
		echo "NO MATCH  $name ($parts)".( $near ? ' - candidates: '.implode( '; ', array_map( fn( $id ) => "$id ".$library[$id]['title'], array_slice( $near, 0, 4 ) ) ) : '' )
			." - set with &book[".$name."]=<content_id>\n";
		continue;
	}
	$folder = null;
	$existing = [];
	if( $row = $gBitDb->getRow( "SELECT p.`content_id` FROM `".BIT_DB_PREFIX."liberty_content_prefs` p WHERE p.`pref_name` = 'book_content_id' AND p.`pref_value` = ?", [ (string)$bookId ] ) ) {
		$folder = (int)$row['content_id'];
		foreach( $gBitDb->getCol( "SELECT lc.`title` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` m JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = m.`item_content_id` WHERE m.`gallery_content_id` = ?", [ $folder ] ) ?: [] as $t ) {
			$existing[$t] = true;
		}
	}
	$missing = array_diff_key( $group['parts'], $existing );
	echo ( $missing ? 'FILE      ' : 'DONE      ' ).$name." -> $bookId ".$library[$bookId]['title']." ($parts)".( $missing ? ', to bring in: '.implode( '/', array_keys( $missing ) ) : '' )
		.( $group['other'] ? ' [not a part, left: '.implode( ', ', $group['other'] ).']' : '' )."\n";
	if( !$apply || !$missing || $done >= LOAD_BOOK_IMAGES_BATCH ) {
		continue;
	}
	$done++;
	[ $topId ] = load_book_images_gallery( 'Images', null );
	[ $booksId ] = $topId ? load_book_images_gallery( 'Books', $topId ) : [ null ];
	if( !$topId || !$booksId ) {
		echo "          could not make the Images / Books galleries\n";
		continue;
	}
	if( !$folder ) {
		[ $folder, $folderGalleryId ] = load_book_images_gallery( $library[$bookId]['title'], $booksId );
		if( $folder ) {
			$gallery = new FisheyeGallery( null, $folder );
			$gallery->load();
			$gallery->storePreference( 'book_content_id', (string)$bookId );
		}
	} else {
		$folderGalleryId = (int)$gBitDb->getOne( "SELECT `gallery_id` FROM `".BIT_DB_PREFIX."fisheye_gallery` WHERE `content_id` = ?", [ $folder ] );
	}
	if( !$folder ) {
		echo "          could not make the book's folder\n";
		continue;
	}
	foreach( $missing as $part => $item ) {
		$bytes = load_book_images_fetch( $site.'/liberty/download/file/'.$item['id'] );
		if( $bytes === null || !@getimagesizefromstring( $bytes ) ) {
			echo "          $part: could not be fetched\n";
			continue;
		}
		$tmp = tempnam( sys_get_temp_dir(), 'bookimg_' );
		file_put_contents( $tmp, $bytes );
		$fileHash = [ 'name' => preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name.'-'.$part ).'.jpg', 'tmp_name' => $tmp, 'size' => strlen( $bytes ), 'error' => 0 ];
		$errors = fisheye_store_upload( $fileHash, [ 'title' => $part, 'gallery_additions' => [ $folderGalleryId ] ], false );
		echo "          $part: ".( $errors ? 'FAILED '.implode( '; ', $errors ) : 'filed ('.strlen( $bytes ).' bytes)' )."\n";
	}
}
if( $apply && $done >= LOAD_BOOK_IMAGES_BATCH ) {
	echo "\nThe first ".LOAD_BOOK_IMAGES_BATCH." books were filed - run again for the rest.\n";
}

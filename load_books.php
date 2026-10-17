<?php
/**
 * Loads books from an uploaded catalogue spreadsheet (see FisheyeBookCatalogue for the sheet shape)
 * into the Library gallery, one group at a time - the books counterpart of load_music.php.
 *
 * Filing: an author with two or more books in the catalogue gets a gallery of their own, directly under
 * the top-level Library gallery - named for their contact once the authors pass has made one (so a pen
 * name's books and the real name's land together, each credit still reading as published), else as
 * written in the sheet. A book whose author has only
 * the one is filed into a gallery named for its Category instead (Workshop, Steam, Fiction ...) - a
 * gallery apiece for hundreds of one-book authors would bury the real ones. The author is still a
 * credit on the book itself, so a one-book author turning into a two-book author later only means
 * promoting them to a gallery of their own; nothing about the book changes.
 *
 * A group is loaded a few books per submit (LOAD_BOOKS_BATCH) and the page comes straight back, so a
 * long author never runs one request long; running it again carries on, and a book already loaded
 * is recognised (FisheyeBook::findContentId()) and skipped, so repeating it is harmless.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

const LOAD_BOOKS_BATCH = 10;
const LOAD_BOOKS_GROUPS_SHOWN = 30;
// An author needs at least this many books in the catalogue to get a gallery of their own.
const LOAD_BOOKS_MIN_AUTHOR_BOOKS = 2;

/**
 * Split the catalogue rows into the groups they are filed under - 'a|<author>' galleries for
 * authors with enough books, 'c|<category>' galleries for the rest.
 *
 * @return array<string,array{kind:string,name:string,rows:list<array>}>
 */
function load_books_plan( array $pRows ): array {
	$perAuthor = [];
	foreach( $pRows as $row ) {
		$author = FisheyeBookCredits::canonicalName( FisheyeBookCatalogue::primaryAuthor( $row ) );
		if( $author !== '' ) {
			$perAuthor[mb_strtolower( $author )] = ( $perAuthor[mb_strtolower( $author )] ?? 0 ) + 1;
		}
	}
	$groups = [];
	foreach( $pRows as $row ) {
		$author = FisheyeBookCredits::canonicalName( FisheyeBookCatalogue::primaryAuthor( $row ) );
		if( $author !== '' && $perAuthor[mb_strtolower( $author )] >= LOAD_BOOKS_MIN_AUTHOR_BOOKS ) {
			$key = 'a|'.mb_strtolower( $author );
			$groups[$key] ??= [ 'kind' => 'author', 'name' => $author, 'rows' => [] ];
		} else {
			$category = $row['category'][0] ?? '';
			$name = $category !== '' ? $category : KernelTools::tra( 'Uncategorised' );
			$key = 'c|'.mb_strtolower( $name );
			$groups[$key] ??= [ 'kind' => 'category', 'name' => $name, 'rows' => [] ];
		}
		$groups[$key]['rows'][] = $row;
	}
	uasort( $groups, fn( $a, $b ) => [ $a['kind'], mb_strtolower( $a['name'] ) ] <=> [ $b['kind'], mb_strtolower( $b['name'] ) ] );
	return $groups;
}

/**
 * The gallery with this title directly under the top-level Library gallery, if there is one.
 */
function load_books_gallery_content_id( string $pTitle, int $pTopContentId ): ?int {
	global $gBitDb;
	$id = $gBitDb->getOne(
		"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map` fgim
		 INNER JOIN `".BIT_DB_PREFIX."liberty_content` lc ON lc.`content_id` = fgim.`item_content_id`
		 WHERE fgim.`gallery_content_id` = ? AND lc.`title` = ? AND lc.`content_type_guid` IN ( 'fisheyemediagal', 'fisheyegallery' )",
		[ $pTopContentId, $pTitle ]
	);
	return $id ? (int)$id : null;
}

$topGalleryId = FisheyeGallery::getTopGalleryId( 'Library' );
$topContentId = $topGalleryId ? (int)$gBitDb->getOne( "SELECT `content_id` FROM `".BIT_DB_PREFIX."fisheye_gallery` WHERE `gallery_id` = ?", [ $topGalleryId ] ) : 0;

$import = null;
$result = null;

if( !empty( $_FILES['catalogue_file']['tmp_name'] ) ) {
	if( !is_uploaded_file( $_FILES['catalogue_file']['tmp_name'] ) || strtolower( pathinfo( (string)$_FILES['catalogue_file']['name'], PATHINFO_EXTENSION ) ) !== 'ods' ) {
		$import = [ 'rows' => 0, 'error' => KernelTools::tra( 'Please choose an .ods spreadsheet.' ) ];
	} else {
		$import = FisheyeBookCatalogue::importFile( $_FILES['catalogue_file']['tmp_name'] );
	}
}

$catalogue = FisheyeBookCatalogue::cached();
$groups = load_books_plan( $catalogue['rows'] );

if( !empty( $_REQUEST['process_group'] ) ) {
	$result = [ 'created' => 0, 'already' => 0, 'errors' => [], 'group' => '' ];
	// detoxify() HTML-escapes every $_REQUEST value - undone here, as load_music.php does, since a
	// name can hold &, < or >.
	$groupKey = htmlspecialchars_decode( trim( (string)$_REQUEST['process_group'] ), ENT_NOQUOTES );
	if( !$topContentId ) {
		$result['errors'][] = KernelTools::tra( 'There is no top-level Library gallery to file books under.' );
	} elseif( !isset( $groups[$groupKey] ) ) {
		$result['errors'][] = KernelTools::tra( 'That group is not in the loaded catalogue.' );
	} else {
		$group = $groups[$groupKey];
		$result['group'] = $group['name'];
		$galleryContentId = load_books_gallery_content_id( $group['name'], $topContentId );
		if( !$galleryContentId ) {
			// The library layout: strips per category on an author's page.
			$gallery = new FisheyeMediaGallery();
			$storeHash = [ 'title' => $group['name'], 'gallery_pagination' => FISHEYE_PAGINATION_LIBRARY_GRID ];
			if( $gallery->store( $storeHash ) ) {
				$gallery->storePreference( 'gallery_pagination', FISHEYE_PAGINATION_LIBRARY_GRID );
				$gallery->addToGalleries( [ $topGalleryId ] );
				$galleryContentId = (int)$gallery->mContentId;
			} else {
				$result['errors'][] = $group['name'].' - '.implode( '; ', $gallery->mErrors );
			}
		}
		if( $galleryContentId && $group['kind'] === 'author' && ( $authorContactId = FisheyeBookCredits::authorContactFor( $group['name'] ) ) ) {
			FisheyeBookCredits::linkGalleryToContact( $galleryContentId, $authorContactId );
		}
		if( $galleryContentId ) {
			$done = 0;
			foreach( $group['rows'] as $row ) {
				if( $done >= LOAD_BOOKS_BATCH ) {
					break;
				}
				if( FisheyeBook::findContentId( $row ) ) {
					continue;
				}
				$one = FisheyeBook::registerFromRow( $row, $galleryContentId );
				if( !empty( $one['created'] ) ) {
					$result['created']++;
				} elseif( !empty( $one['already'] ) ) {
					$result['already']++;
				} else {
					$result['errors'][] = $row['title'].' - '.( $one['error'] ?? KernelTools::tra( 'not loaded' ) );
				}
				$done++;
			}
		}
	}
}

// What is still to do per group - recounted after any processing above.
$pending = [];
foreach( $groups as $key => $group ) {
	$loaded = 0;
	foreach( $group['rows'] as $row ) {
		if( FisheyeBook::findContentId( $row ) ) {
			$loaded++;
		}
	}
	if( $loaded < count( $group['rows'] ) ) {
		$pending[] = [ 'key' => $key, 'kind' => $group['kind'], 'name' => $group['name'], 'total' => count( $group['rows'] ), 'loaded' => $loaded ];
	}
}

$topGalleryUrlHash = [ 'gallery_id' => $topGalleryId ];
$gBitSmarty->assign( 'topGalleryUrl', $topGalleryId ? FisheyeGallery::getDisplayUrlFromHash( $topGalleryUrlHash ) : null );
$gBitSmarty->assign( 'catalogueRows', count( $catalogue['rows'] ) );
$gBitSmarty->assign( 'catalogueImported', $catalogue['imported'] );
$gBitSmarty->assign( 'catalogueFile', $catalogue['file'] );
$gBitSmarty->assign( 'groupCount', count( $groups ) );
$gBitSmarty->assign( 'pending', array_slice( $pending, 0, LOAD_BOOKS_GROUPS_SHOWN ) );
$gBitSmarty->assign( 'pendingTotal', count( $pending ) );
$gBitSmarty->assign( 'groupsShown', LOAD_BOOKS_GROUPS_SHOWN );
$gBitSmarty->assign( 'batchSize', LOAD_BOOKS_BATCH );
$gBitSmarty->assign( 'minAuthorBooks', LOAD_BOOKS_MIN_AUTHOR_BOOKS );
$gBitSmarty->assign( 'hasTopGallery', (bool)$topContentId );
$gBitSmarty->assign( 'import', $import );
$gBitSmarty->assign( 'result', $result );

$gBitSystem->display( 'bitpackage:fisheyemedia/load_books.tpl', KernelTools::tra( 'Load Books' ), [ 'display_mode' => 'edit' ] );

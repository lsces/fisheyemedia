<?php
/**
 * Author workbench: one author's books from the author's gallery (see FisheyeAuthorWorkbench) - load the books the author's AuthorCatalogue.csv
 * names that are not in the database, sort them into series (from the folders, or from Wikidata and Open Library), load their files. Each step is its
 * own button and is recomputed from the database and the Library tree afterwards, so it can be repeated; nothing is written until a button is pressed.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

use Bitweaver\KernelTools;
use Bitweaver\Fisheye\FisheyeGallery;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPermission( 'p_fisheye_admin' );

$gContent = FisheyeGallery::lookup( [ 'gallery_id' => $_REQUEST['gallery_id'] ?? 0 ] );
if( !$gContent || !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No gallery exists with the given ID' ) );
}
$contentId = (int)$gContent->mContentId;
$messages = [];
$errors = [];

$st = FisheyeAuthorWorkbench::status( $contentId );
// Wikidata is only asked when the page is asked to (the lookup and the proposal button carry wd=1 on).
$wd = !empty( $_REQUEST['wd'] );
$split = isset( $_REQUEST['ws_form'] ) ? array_map( 'strval', array_keys( (array)( $_REQUEST['split'] ?? [] ) ) ) : null;

if( !$st['error'] ) {
	$filed = FisheyeAuthorWorkbench::fileCredited( $st );
	if( $filed ) {
		$messages[] = $filed.' '.KernelTools::tra( 'loaded books that credit this author as a co-author were filed in this gallery.' );
	}
	$report = function( array $pResult ) use ( &$messages, &$errors ) {
		foreach( $pResult['created'] as $made ) {
			$messages[] = $made['name'].': '.$made['moved'].' '.KernelTools::tra( 'books moved in' ).( $made['nested'] ? ' ('.KernelTools::tra( 'inside its umbrella series' ).')' : '' ).( $made['merged'] ? ' ('.KernelTools::tra( 'added to the series already there' ).')' : '' );
		}
		$errors = array_merge( $errors, $pResult['errors'] );
	};
	if( !empty( $_REQUEST['load_missing'] ) ) {
		foreach( FisheyeSeriesWorkbench::loadMissing( $st, array_map( 'intval', (array)( $_REQUEST['book'] ?? [] ) ), (array)( $_REQUEST['isbn'] ?? [] ) ) as $one ) {
			if( $one['group'] !== '' ) {
				$messages[] = $one['group'].': '.$one['created'].' '.KernelTools::tra( 'books created' ).( $one['already'] ? ', '.$one['already'].' '.KernelTools::tra( 'already there' ) : '' ).'.';
			}
			$errors = array_merge( $errors, $one['errors'] );
		}
	} elseif( !empty( $_REQUEST['update_catalogue'] ) && $st['folder'] !== null ) {
		$messages[] = FisheyeCatalogueMerge::updateAuthor( $st['folder'] ) ?? KernelTools::tra( 'The catalogue file had nothing to change.' );
	} elseif( !empty( $_REQUEST['use_file_years'] ) && $st['folder'] !== null ) {
		$n = FisheyeCatalogueMerge::useFileYears( [ 'kind' => 'author', 'name' => $st['folder'], 'path' => $st['abs'].FisheyeAuthorWorkbench::CATALOGUE, 'exists' => true ], array_map( 'intval', array_keys( (array)( $_REQUEST['year_book'] ?? [] ) ) ) );
		$messages[] = $n.' '.KernelTools::tra( 'books now have the catalogue file\'s year.' );
	} elseif( !empty( $_REQUEST['move_folders'] ) ) {
		$moved = FisheyeAuthorWorkbench::applyMoves( $st, array_map( 'intval', array_keys( (array)( $_REQUEST['move_book'] ?? [] ) ) ) );
		$messages[] = $moved['moved'].' '.KernelTools::tra( 'folders moved to match their series.' );
		$errors = array_merge( $errors, $moved['errors'] );
	} elseif( !empty( $_REQUEST['create_folder_series'] ) ) {
		$report( FisheyeAuthorWorkbench::createFromFolders( $st, $_REQUEST ) );
		$line = $st['folder'] !== null ? FisheyeCatalogueMerge::updateAuthor( $st['folder'] ) : null;
		if( $line ) {
			$messages[] = $line;
		}
	} elseif( !empty( $_REQUEST['lookup_series'] ) ) {
		$wd = true;
		$lookup = FisheyeAuthorWorkbench::lookupSeriesText( $contentId );
		$messages[] = KernelTools::tra( 'Asked Open Library about' ).' '.$lookup['done'].' '.KernelTools::tra( 'books; books with series text now' ).': '.$lookup['with'].'.';
		$errors = array_merge( $errors, $lookup['errors'] );
	} elseif( !empty( $_REQUEST['create_source_series'] ) ) {
		$wd = true;
		$report( FisheyeAuthorWorkbench::createFromSources( $st, $_REQUEST, $split ) );
		$line = $st['folder'] !== null ? FisheyeCatalogueMerge::updateAuthor( $st['folder'] ) : null;
		if( $line ) {
			$messages[] = $line;
		}
	}
	$st = FisheyeAuthorWorkbench::status( $contentId );
	// Loading files is quick, so it needs no button: any book whose folder holds files not yet loaded is loaded now (at most a batch per view).
	if( !$st['files']['blocked'] && $st['files']['todo'] ) {
		$todo = array_column( array_filter( $st['files']['books'], fn( $b ) => $b['todo'] ), 'id' );
		$done = FisheyeSeriesWorkbench::registerFiles( $todo );
		$messages[] = $done['books'].' '.KernelTools::tra( 'books had their files loaded,' ).' '.$done['files'].' '.KernelTools::tra( 'files.' );
		$errors = array_merge( $errors, $done['errors'] );
		$st = FisheyeAuthorWorkbench::status( $contentId );
	}
	$gBitSmarty->assign( 'sources', $wd ? FisheyeAuthorWorkbench::sourcesProposal( $st, $split ) : null );
}

$gBitSmarty->assign( 'st', $st );
$gBitSmarty->assign( 'wd', $wd );
$gBitSmarty->assign( 'messages', $messages );
$gBitSmarty->assign( 'errors', $errors );
$gBitSmarty->assign( 'batch', FisheyeSeriesWorkbench::BATCH );
$gBitSmarty->assign( 'folderPreTick', FisheyeAuthorWorkbench::FOLDER_PRETICK_MIN );
$gBitSmarty->assign( 'sourcesPreTick', FisheyeAuthorWorkbench::SOURCES_PRETICK_MIN );
$gBitSystem->display( 'bitpackage:fisheyemedia/author_workbench.tpl', KernelTools::tra( 'Author workbench' ), [ 'display_mode' => 'edit' ] );

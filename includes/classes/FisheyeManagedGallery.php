<?php
/**
 * Galleries whose place in the tree is managed by a loader rather than by their Edit page - the Library's author and series galleries (library_grid)
 * and the Documentation galleries (docs_grid). fisheye's own Edit page cannot be used for them: it rebuilds a gallery's parents from a list that does
 * not reliably contain them, so a save detached the gallery. edit_gallery.php (this package) edits their title and description and moves them: each
 * current parent can be replaced by another gallery of the same tree, never by one outside it, never by the gallery itself or anything below it.
 *
 * @package fisheyemedia
 */
namespace Bitweaver\Fisheyemedia;

use Bitweaver\Fisheye\FisheyeGallery;

class FisheyeManagedGallery {
	/** The layouts that make a gallery managed. */
	public static function layouts(): array {
		return [ FISHEYE_PAGINATION_LIBRARY_GRID, FISHEYE_PAGINATION_DOCS_GRID ];
	}

	public static function isManaged( FisheyeGallery $pGallery ): bool {
		return $pGallery->isValid() && in_array( $pGallery->getLayout(), self::layouts(), true );
	}

	/**
	 * Every gallery with its place: content_id => [ 'title', 'parents' => [content_id], 'children' => [content_id], 'gallery_id' ].
	 *
	 * @return array<int,array{title:string,gallery_id:int,parents:list<int>,children:list<int>}>
	 */
	private static function tree(): array {
		global $gBitDb;
		$nodes = [];
		foreach( $gBitDb->getAll(
			"SELECT lc.`content_id`, lc.`title`, g.`gallery_id` FROM `".BIT_DB_PREFIX."liberty_content` lc
			 JOIN `".BIT_DB_PREFIX."fisheye_gallery` g ON g.`content_id` = lc.`content_id`" ) ?: [] as $row ) {
			$nodes[(int)$row['content_id']] = [ 'title' => (string)$row['title'], 'gallery_id' => (int)$row['gallery_id'], 'parents' => [], 'children' => [] ];
		}
		foreach( $gBitDb->getAll( "SELECT `gallery_content_id` AS p, `item_content_id` AS c FROM `".BIT_DB_PREFIX."fisheye_gallery_image_map`" ) ?: [] as $edge ) {
			$p = (int)$edge['p'];
			$c = (int)$edge['c'];
			if( isset( $nodes[$p], $nodes[$c] ) ) {
				$nodes[$p]['children'][] = $c;
				$nodes[$c]['parents'][] = $p;
			}
		}
		return $nodes;
	}

	/** @return list<int> the content ids of everything below a gallery (its sub-galleries, their sub-galleries ...) */
	private static function below( array $pNodes, int $pContentId ): array {
		$seen = [];
		$queue = $pNodes[$pContentId]['children'] ?? [];
		while( $queue ) {
			$id = array_shift( $queue );
			if( !isset( $seen[$id] ) ) {
				$seen[$id] = true;
				array_push( $queue, ...( $pNodes[$id]['children'] ?? [] ) );
			}
		}
		return array_keys( $seen );
	}

	/**
	 * Every gallery in the same tree(s) as a gallery: the top-level gallery above each of its parents (Documentation, Library ...) and everything below
	 * it, the gallery itself included. What Image Order's Copy/Move-to-gallery picker offers for a managed gallery.
	 *
	 * @return list<int> content ids
	 */
	public static function treeIds( FisheyeGallery $pGallery ): array {
		$nodes = self::tree();
		$me = (int)$pGallery->mContentId;
		if( !isset( $nodes[$me] ) ) {
			return [];
		}
		$ids = [ $me => true ];
		foreach( $nodes[$me]['parents'] ?: [ $me ] as $parent ) {
			$top = $parent;
			for( $guard = 0; ( $nodes[$top]['parents'][0] ?? null ) !== null && $guard < 20; $guard++ ) {
				$top = $nodes[$top]['parents'][0];
			}
			foreach( array_merge( [ $top ], self::below( $nodes, $top ) ) as $id ) {
				$ids[$id] = true;
			}
		}
		return array_keys( $ids );
	}

	/**
	 * The gallery's current parents, and for each the galleries it could be moved under: the top-level gallery of that parent's tree and everything
	 * below it, except the gallery itself and what is below it.
	 *
	 * @return list<array{content_id:int,title:string,options:array<int,string>}>  options: content_id => "Top / Parent / Gallery" path label
	 */
	public static function placements( FisheyeGallery $pGallery ): array {
		$nodes = self::tree();
		$me = (int)$pGallery->mContentId;
		if( !isset( $nodes[$me] ) ) {
			return [];
		}
		$excluded = array_flip( array_merge( [ $me ], self::below( $nodes, $me ) ) );
		$label = function( int $pId ) use ( $nodes ): string {
			$parts = [];
			for( $guard = 0; $pId && $guard < 20; $guard++ ) {
				array_unshift( $parts, $nodes[$pId]['title'] );
				$pId = $nodes[$pId]['parents'][0] ?? 0;
			}
			return implode( ' / ', $parts );
		};
		$rows = [];
		foreach( $nodes[$me]['parents'] as $parent ) {
			// the top of this parent's tree (the first parent up the chain, until there is none)
			$top = $parent;
			for( $guard = 0; ( $nodes[$top]['parents'][0] ?? null ) !== null && $guard < 20; $guard++ ) {
				$top = $nodes[$top]['parents'][0];
			}
			$options = [];
			foreach( array_merge( [ $top ], self::below( $nodes, $top ) ) as $id ) {
				if( !isset( $excluded[$id] ) ) {
					$options[$id] = $label( $id );
				}
			}
			asort( $options, SORT_STRING | SORT_FLAG_CASE );
			$rows[] = [ 'content_id' => $parent, 'title' => $label( $parent ), 'options' => $options ];
		}
		return $rows;
	}

	/**
	 * The moves asked for ([current parent content_id => new parent content_id]) that change something, or an error when one is not a place
	 * placements() offered for that parent. Nothing is written.
	 *
	 * @param array<int|string,int|string> $pMoves
	 * @return array{moves:array<int,int>,errors:list<string>}
	 */
	public static function checkMoves( FisheyeGallery $pGallery, array $pMoves ): array {
		$out = [ 'moves' => [], 'errors' => [] ];
		$offered = [];
		foreach( self::placements( $pGallery ) as $row ) {
			$offered[$row['content_id']] = $row['options'];
		}
		foreach( $pMoves as $old => $new ) {
			$old = (int)$old;
			$new = (int)$new;
			if( $old === $new || !isset( $offered[$old] ) ) {
				continue;
			}
			if( !isset( $offered[$old][$new] ) ) {
				$out['errors'][] = 'That gallery is not somewhere this one can be moved to.';
				continue;
			}
			$out['moves'][$old] = $new;
		}
		return $out;
	}

	/**
	 * Apply moves that checkMoves() passed: the new place is added before the old one is left.
	 *
	 * @param array<int,int> $pMoves current parent content_id => new parent content_id
	 * @return list<string> error texts
	 */
	public static function move( FisheyeGallery $pGallery, array $pMoves ): array {
		$errors = [];
		foreach( $pMoves as $old => $new ) {
			$from = FisheyeGallery::lookup( [ 'content_id' => $old ] );
			$to = FisheyeGallery::lookup( [ 'content_id' => $new ] );
			if( !$from || !$to || !$from->isValid() || !$to->isValid() ) {
				$errors[] = 'A gallery in the move could not be found.';
				continue;
			}
			$to->load();
			if( !$to->addItem( $pGallery->mContentId ) && !$pGallery->isInGallery( $to->mContentId, $pGallery->mContentId ) ) {
				$errors[] = 'The move to '.$to->getTitle().' could not be made.';
				continue;
			}
			$from->removeItem( $pGallery->mContentId );
		}
		return $errors;
	}
}

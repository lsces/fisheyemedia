<?php
/**
 * What a tutorial course and a tutorial section share: their files (exercise archives, notes, handouts) as 'extra' rows holding only a path under the
 * tutorials root, counted on the Extras button, listed on the Extras strip ahead of the pictures, and written by storeExtraFileRows() when the item's
 * folder is read. The files are served from where they are by view_season_file.php - never copied into storage. Used by FisheyeSection (the files of a
 * section's folder) and FisheyeCourse (the files that belong to the whole course).
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

trait FisheyeTutorialFilesTrait {

	/** The Extras button counts the item's files along with its pictures. */
	public function getExtrasCounts(): array {
		$counts = parent::getExtrasCounts();
		$vis = $this->getVisibleXrefSql( 'x' );
		$counts['extras'] += (int)$this->mDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref` x WHERE x.`content_id` = ? AND x.`item` = 'extra' AND x.`end_date` IS NULL".$vis['where_sql'], [ $this->mContentId, ...$vis['bind_vars'] ] );
		return $counts;
	}

	/** The item's files lead the plain Extras strip, ahead of its pictures. */
	public function getExtrasGroups(): array {
		$extras = parent::getExtrasGroups();
		if( $files = $this->getExtraFileItems() ) {
			foreach( $extras['groups'] as &$group ) {
				if( $group['id'] === 'pictures' ) {
					$group['items'] = array_merge( $files, $group['items'] );
					unset( $group );
					return $extras;
				}
			}
			unset( $group );
			$extras['groups'][] = [ 'id' => 'pictures', 'kind' => 'Extras', 'items' => $files, 'links' => [] ];
		}
		return $extras;
	}

	/** The item's files (exercise archives, notes) as Extras items: the file type's own icon, the file name, and the download link. */
	protected function getExtraFileItems(): array {
		$vis = $this->getVisibleXrefSql( 'x' );
		$rows = $this->mDb->getAll( "SELECT x.`xref_id`, x.`xkey_ext`, x.`data` FROM `".BIT_DB_PREFIX."liberty_xref` x WHERE x.`content_id` = ? AND x.`item` = 'extra' AND x.`end_date` IS NULL".$vis['where_sql']." ORDER BY x.`xorder`", [ $this->mContentId, ...$vis['bind_vars'] ] ) ?: [];
		$items = [];
		foreach( $rows as $row ) {
			$data = json_decode( (string)$row['data'], true ) ?: [];
			$name = basename( (string)$row['xkey_ext'] );
			$items[] = [ 'thumb' => $this->getFileThumbUrl( (int)$row['xref_id'], $name ), 'url' => FISHEYEMEDIA_PKG_URL.'view_season_file.php?xref_id='.(int)$row['xref_id'], 'title' => $name, 'showTitle' => true,
				'label' => strtoupper( (string)( $data['format'] ?? '' ) ).( !empty( $data['size'] ) ? ' '.$data['size'] : '' ) ];
		}
		return $items;
	}

	/** Whether the site's media scheme has the 'extra' item for a content type - without it strict xref refuses every file row (apply the scheme from the Local Scheme page). */
	public static function extraItemDefinedFor( string $pContentTypeGuid ): bool {
		global $gBitDb;
		return (bool)$gBitDb->getOne( "SELECT COUNT(*) FROM `".BIT_DB_PREFIX."liberty_xref_item` WHERE `content_type_guid` = ? AND `item` = 'extra'", [ $pContentTypeGuid ] );
	}

	/**
	 * Rebuild this item's 'extra' rows: one per file, xkey_ext the path under the tutorials root ($pBaseRelative, no closing slash, plus the file's own
	 * path), served by view_season_file.php by xref_id only. The old rows go first, so a repeat leaves exactly what the folder holds.
	 *
	 * @param string $pBaseRelative  the item's folder under the tutorials root, e.g. "Local Tutorials/<course>"
	 * @param list<array{rel:string,ext:string,bytes:int}> $pFiles
	 * @return array{files:int,errors:list<string>}
	 */
	protected function storeExtraFileRows( string $pBaseRelative, array $pFiles ): array {
		$summary = [ 'files' => 0, 'errors' => [] ];
		\Bitweaver\Liberty\LibertyContent::deleteXrefByItem( (int)$this->mContentId, [ 'extra' ] );
		foreach( array_values( $pFiles ) as $order => $file ) {
			$size = $file['bytes'] >= 1048576 ? round( $file['bytes'] / 1048576, 1 ).' MB' : max( 1, (int)round( $file['bytes'] / 1024 ) ).' KB';
			$hash = [ 'content_id' => $this->mContentId, 'item' => 'extra', 'xkey_ext' => $pBaseRelative.'/'.$file['rel'],
				'edit' => json_encode( [ 'format' => $file['ext'], 'size' => $size, 'note' => '' ] ), 'xorder' => $order + 1 ];   // by reference: a variable
			if( $this->storeXref( $hash ) ) {
				$summary['files']++;
			} else {
				$summary['errors'][] = $file['rel'].': '.implode( '; ', array_map( 'strval', array_values( $this->mErrors ) ) );
			}
		}
		return $summary;
	}
}

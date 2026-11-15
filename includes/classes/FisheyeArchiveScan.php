<?php
/**
 * Reads a folder archive from its raw file structure - no catalogue - and says what it would make: a gallery for each folder that holds other folders, a
 * book for each folder that holds a document (pdf, epub ...), an Extras gallery for a folder called Extras, a picture gallery for a folder of images.
 * It is the scan half of the archive loader and writes nothing; the base folder is whatever the caller picks, not a fixed name.
 *
 * What a folder is:
 *  - 'extras'   a folder called Extras: every file below it is an extra file of its parent (nothing is made per file).
 *  - 'pictures' no folders of its own that hold anything, and more images than documents: a gallery of pictures; documents and text beside them are its extras.
 *  - 'book'     no folders of its own that hold anything, and at least one document: one book named for the folder, whose other files are extras.
 *               Several documents in one folder are editions of the book.
 *  - 'gallery'  anything else: a gallery. A document sitting loose in it (no folder of its own) is a 'loose' book named for the file.
 * Names are taken as the files have them; a book's identity is its path under the base, which is what the loader will key on.
 *
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

class FisheyeArchiveScan {

	/** Documents that make a book. */
	public const BOOK = [ 'pdf', 'epub', 'mobi', 'azw', 'azw3', 'prc', 'lit', 'fb2', 'djvu', 'doc', 'docx', 'rtf' ];
	/** Pictures. */
	public const PICTURE = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif', 'tif', 'tiff', 'heic' ];
	/** Files that are never loaded. */
	private const SKIP = [ 'thumbs.db', 'desktop.ini', '.ds_store', 'metadata.opf' ];

	/**
	 * The folders that could be an archive: the sub-folders of the folder that holds the media trees (the one holding Library/, else the given one),
	 * with the file count of each and whether the media library already uses it.
	 *
	 * @return list<array{name:string,abs:string}>
	 */
	public static function candidates( string $pParent ): array {
		$out = [];
		foreach( array_diff( scandir( $pParent ) ?: [], [ '.', '..' ] ) as $name ) {
			if( $name[0] !== '.' && is_dir( rtrim( $pParent, '/' ).'/'.$name ) ) {
				$out[] = [ 'name' => $name, 'abs' => rtrim( $pParent, '/' ).'/'.$name ];
			}
		}
		usort( $out, fn( $a, $b ) => strnatcasecmp( $a['name'], $b['name'] ) );
		return $out;
	}

	private static function skipName( string $pName ): bool {
		return $pName[0] === '.' || in_array( strtolower( $pName ), self::SKIP, true );
	}

	/** The files and folders directly in a folder, sorted naturally. @return array{dirs:list<string>,files:list<string>} */
	private static function entries( string $pAbs ): array {
		$dirs = [];
		$files = [];
		foreach( array_diff( scandir( $pAbs ) ?: [], [ '.', '..' ] ) as $name ) {
			if( self::skipName( $name ) ) {
				continue;
			}
			is_dir( $pAbs.'/'.$name ) ? $dirs[] = $name : $files[] = $name;
		}
		natcasesort( $dirs );
		natcasesort( $files );
		return [ 'dirs' => array_values( $dirs ), 'files' => array_values( $files ) ];
	}

	private static function ext( string $pName ): string {
		return strtolower( pathinfo( $pName, PATHINFO_EXTENSION ) );
	}

	/** Every file below a folder. */
	private static function countFiles( string $pAbs ): int {
		$n = 0;
		foreach( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $pAbs, \FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			if( $f->isFile() && !self::skipName( $f->getFilename() ) ) {
				$n++;
			}
		}
		return $n;
	}

	/** Whether a folder, or anything below it, holds a document or a picture. */
	private static function hasContent( string $pAbs ): bool {
		foreach( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $pAbs, \FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			if( $f->isFile() && ( in_array( self::ext( $f->getFilename() ), self::BOOK, true ) || in_array( self::ext( $f->getFilename() ), self::PICTURE, true ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One folder and everything below it.
	 *
	 * @return array{name:string,rel:string,kind:string,docs:int,pictures:int,extras:int,children:list<array>,loose:list<array>,warnings:list<string>}
	 */
	public static function node( string $pAbs, string $pRel = '' ): array {
		$name = $pRel === '' ? basename( $pAbs ) : basename( $pRel );
		$node = [ 'name' => $name, 'rel' => $pRel, 'kind' => 'gallery', 'docs' => 0, 'pictures' => 0, 'extras' => 0, 'children' => [], 'loose' => [], 'warnings' => [] ];
		if( $pRel !== '' && $name === 'Extras' ) {
			$node['kind'] = 'extras';
			$node['extras'] = self::countFiles( $pAbs );
			return $node;
		}
		$e = self::entries( $pAbs );
		$docs = array_values( array_filter( $e['files'], fn( $f ) => in_array( self::ext( $f ), self::BOOK, true ) ) );
		$pics = array_values( array_filter( $e['files'], fn( $f ) => in_array( self::ext( $f ), self::PICTURE, true ) ) );
		$other = count( $e['files'] ) - count( $docs ) - count( $pics );
		$withContent = array_values( array_filter( $e['dirs'], fn( $d ) => self::hasContent( $pAbs.'/'.$d ) ) );
		$plainDirs = array_diff( $e['dirs'], $withContent );   // folders with nothing loadable (readmes, covers...): extras of this folder
		if( !$withContent && $docs && count( $docs ) >= count( $pics ) ) {
			$node['kind'] = 'book';
			$node['docs'] = count( $docs );
			$node['extras'] = $other + count( $pics ) + array_sum( array_map( fn( $d ) => self::countFiles( $pAbs.'/'.$d ), $plainDirs ) );
			return $node;
		}
		if( !$withContent && $pics ) {
			$node['kind'] = 'pictures';
			$node['pictures'] = count( $pics );
			$node['docs'] = count( $docs );   // pdf maps beside the images
			$node['extras'] = $other;
			return $node;
		}
		foreach( $withContent as $dir ) {
			$child = self::node( $pAbs.'/'.$dir, ltrim( $pRel.'/'.$dir, '/' ) );
			$node['children'][] = $child;
			foreach( [ 'docs', 'pictures', 'extras' ] as $k ) {
				$node[$k] += $child[$k];
			}
		}
		foreach( $docs as $f ) {   // a document with no folder of its own: a book named for the file
			$node['loose'][] = [ 'name' => pathinfo( $f, PATHINFO_FILENAME ), 'rel' => ltrim( $pRel.'/'.$f, '/' ), 'ext' => self::ext( $f ) ];
			$node['docs']++;
		}
		$node['pictures'] += count( $pics );
		$node['extras'] += $other;
		// two children with one name in one gallery would be one book/gallery to the loader
		$seen = [];
		foreach( array_merge( array_column( $node['children'], 'name' ), array_column( $node['loose'], 'name' ) ) as $n ) {
			$key = mb_strtolower( $n );
			if( isset( $seen[$key] ) ) {
				$node['warnings'][] = 'two entries named "'.$n.'" in one gallery';
			}
			$seen[$key] = true;
		}
		return $node;
	}

	/** Totals for a scanned tree: galleries, books (a folder or a loose document), extras galleries, picture galleries, pictures, extra files, warnings. */
	public static function totals( array $pNode, bool $pRoot = true ): array {
		$t = [ 'galleries' => 0, 'books' => 0, 'loose' => 0, 'extras_galleries' => 0, 'picture_galleries' => 0, 'pictures' => 0, 'extra_files' => 0, 'documents' => 0, 'warnings' => [] ];
		switch( $pNode['kind'] ) {
			case 'gallery': $t['galleries'] = $pRoot ? 0 : 1; break;
			case 'book': $t['books'] = 1; $t['documents'] = $pNode['docs']; $t['extra_files'] = $pNode['extras']; break;
			case 'extras': $t['extras_galleries'] = 1; $t['extra_files'] = $pNode['extras']; break;
			case 'pictures': $t['picture_galleries'] = 1; $t['pictures'] = $pNode['pictures']; $t['documents'] = $pNode['docs']; $t['extra_files'] = $pNode['extras']; break;
		}
		foreach( $pNode['warnings'] as $w ) {
			$t['warnings'][] = $pNode['rel'].': '.$w;
		}
		if( $pNode['kind'] === 'gallery' ) {
			$t['loose'] = count( $pNode['loose'] );
			$t['books'] += $t['loose'];
			$t['documents'] += $t['loose'];
			$t['pictures'] += $pNode['pictures'] - array_sum( array_column( $pNode['children'], 'pictures' ) );   // pictures lying loose in the gallery
		}
		foreach( $pNode['children'] as $child ) {
			$c = self::totals( $child, false );
			foreach( $t as $k => $v ) {
				$t[$k] = $k === 'warnings' ? array_merge( $v, $c[$k] ) : $v + $c[$k];
			}
		}
		return $t;
	}

	/** Scan a whole archive: [ 'root' => abs, 'tree' => node, 'totals' => totals ]. */
	public static function scan( string $pAbs ): array {
		$pAbs = rtrim( $pAbs, '/' );
		$tree = self::node( $pAbs );
		return [ 'root' => $pAbs, 'tree' => $tree, 'totals' => self::totals( $tree ) ];
	}
}

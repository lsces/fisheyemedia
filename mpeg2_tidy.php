<?php
/**
 * One-off library tidy: find video files still encoded as mpeg2video (old broadcast/DVD-era
 * rips - bulky and, along with everything else that doesn't play inline, browsers reject even a
 * remux of .mkv itself, not just the codec inside - see DEVELOPER.md's Video playback section) and
 * re-encode them to h264/aac in an .mp4 container, in place, ahead of ever loading them into
 * fisheye at all. Deliberately NOT a live/runtime transcode-on-demand feature - a real transcode is
 * genuinely slow (minutes per file), and every file only needs doing once, so a batch pass ahead
 * of import is the right shape, not caching a second copy behind every page load.
 *
 * Stateless by design, same "N per run via cron" cadence as thumbnailer.php but without that
 * script's liberty_process_queue plumbing - that table tracks jobs against an already-registered
 * content_id, which most of these files don't have yet (that's the whole point: tidy before
 * loading). Every run just re-scans the storage roots live for whatever's still mpeg2video;
 * ffprobe reads only container headers (fast - a few hundred ms/file even at this library's
 * scale), so re-scanning each run costs nothing next to the transcode time itself.
 *
 * Originals are archived, never deleted - moved into a `.mpeg2_originals/` folder alongside the file being
 * replaced, so a bad re-encode is trivially reversible and nothing about the folder structure
 * itself changes.
 *
 * Skips any file already referenced by a real 'episode' xref or a FisheyeFilm's own attachment -
 * this tool is scoped to pre-load tidying, not touching something already live (that's a
 * separate, not-yet-built concern - see DEVELOPER.md).
 *
 * Not invoked directly - like fisheye-thumbnailer.php (/etc/webstack/site-config/common/), this
 * package file's own __FILE__/dirname(__FILE__) resolves through the _bw5 symlink chain back to
 * the real dev checkout regardless of which site's path it was invoked through, and bare CLI
 * never sets $_SERVER['DOCUMENT_ROOT'] the way a real web request does - which is what
 * setup_inc.php actually keys off to resolve a specific site's config/database. The matching
 * site-agnostic wrapper (fisheye-mpeg2-tidy.php, same directory) takes the site root as an
 * explicit argument and sets DOCUMENT_ROOT before requiring this file directly.
 *
 * Usage (via the wrapper):
 *   php fisheye-mpeg2-tidy.php <site-root> --scan   dry run: list candidates, size, no changes
 *   php fisheye-mpeg2-tidy.php <site-root> <N>      transcode up to N candidates this run
 *                                                    (suggested cron: every few minutes, N=1-2 -
 *                                                    a real transcode is minutes long, this is
 *                                                    not a "process 20 at once" tool)
 *
 * @package fisheyemedia
 * @subpackage functions
 */

namespace Bitweaver\Fisheyemedia;

if( !empty( $argc ) ) {
	define( 'BIT_PHP_ERROR_REPORTING', E_ERROR | E_PARSE );
}
require_once $_SERVER['DOCUMENT_ROOT'].'/kernel/includes/setup_inc.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/liberty/plugins/mime.film.php';

global $gBitSystem, $gBitDb, $gBitUser;

if( empty( $argc ) && !$gBitUser->isAdmin() ) {
	die( "You cannot run this script.\n" );
}

const MPEG2_TIDY_EXTENSIONS = [ 'mkv', 'mp4', 'm4v', 'avi' ];

/**
 * Every real video file under every storage root this install knows about, as
 * [ 'root' => absolute root path, 'relative' => path relative to that root ].
 *
 * $pScope filters by top-level folder name within each root ('films' => only under 'Films/',
 * 'tvshows' => only under 'TV Shows/', null/empty => everything) rather than by which
 * configured root is being walked - some installs have fisheye_disk_storage_root and both
 * fisheye_tvshow_storage_root_* configs all happen to resolve, via symlinks, to the very same
 * real directory, walking exactly one physical tree covering both Films/ and TV Shows/ at once, so
 * root identity alone can't distinguish them. Built to split the work across multiple machines -
 * one machine handling films, another handling TV shows - without either duplicating the other's
 * work; a shared-root install's own Films/ turned out to have real candidates too, once the
 * codec-detection bugs below were fixed.
 *
 * @param string|null $pScope  'films', 'tvshows', or null for no filtering
 * @param string|null $pShow   further narrows to one show/film's own folder within that scope
 *                             (requires $pScope) - see the walkDir note below for why
 * @return array
 */
function mpeg2_tidy_scan_roots( ?string $pScope = null, ?string $pShow = null ): array {
	global $gBitSystem;
	$found = [];
	$roots = [ \Bitweaver\Liberty\mime_film_get_storage_root() ];
	foreach( [ 'fisheye_tvshow_storage_root_am', 'fisheye_tvshow_storage_root_nz' ] as $configKey ) {
		$root = $gBitSystem->getConfig( $configKey, '' );
		if( !empty( $root ) ) {
			$roots[] = rtrim( $root, '/' ).'/';
		}
	}
	$scopePrefix = match( $pScope ) {
		'films' => 'Films/',
		'tvshows' => 'TV Shows/',
		default => null,
	};
	$seenRoots = [];
	foreach( $roots as $root ) {
		$realRoot = realpath( $root );
		if( empty( $realRoot ) || isset( $seenRoots[$realRoot] ) ) {
			continue;
		}
		$seenRoots[$realRoot] = true;
		$realRoot = rtrim( $realRoot, '/' ).'/';
		// When scoped, walk only the matching subfolder directly - not the whole root then
		// filter - so an unrelated top-level folder (Music/Maps/Library/... on a shared-root
		// install) never gets walked at all, not just skipped per-file. Same reasoning one level
		// deeper for $pShow - walking the whole scope then discarding everything but one show is
		// cheap on local disk but genuinely slow over a network-mounted storage root, where every
		// directory entry costs a round trip.
		$walkDir = $scopePrefix !== null ? $realRoot.$scopePrefix : $realRoot;
		if( !empty( $pShow ) && $scopePrefix !== null ) {
			$walkDir .= $pShow.'/';
		}
		if( !is_dir( $walkDir ) ) {
			continue;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $walkDir, \FilesystemIterator::SKIP_DOTS ) );
		foreach( $iterator as $fileInfo ) {
			if( !$fileInfo->isFile() ) {
				continue;
			}
			// never descend into our own archive folders
			if( str_contains( $fileInfo->getPathname(), '/.mpeg2_originals/' ) ) {
				continue;
			}
			$ext = strtolower( $fileInfo->getExtension() );
			if( !in_array( $ext, MPEG2_TIDY_EXTENSIONS, true ) ) {
				continue;
			}
			$found[] = [ 'root' => $realRoot, 'relative' => substr( $fileInfo->getPathname(), strlen( $realRoot ) ) ];
		}
	}
	return $found;
}

/**
 * The video stream's codec_name via ffprobe, or null if it can't be determined. Single-file - used
 * only for the one-off post-transcode verification below, never for the bulk scan (see
 * mpeg2_tidy_video_codecs_parallel() for that - a sequential shell_exec() per file here was timed
 * at ~15 minutes for a few thousand files on a smaller library, unusable at this scale or as a
 * "runs every few minutes via cron" cadence).
 *
 * `-of csv=p=0` on some files (a stream carrying extra disposition/side-data, seen live on real
 * mpeg2video files - e.g. "mpeg2video," rather than a bare "mpeg2video") appends a trailing empty
 * CSV field - found live, after this silently made every such file invisible to the exact-match
 * check both here and in the scan below (dozens of real mpeg2video films alone, missed entirely,
 * a scan wrongly reporting zero candidates as a result). Always take just the first comma-
 * separated field, never compare the raw ffprobe line directly.
 */
function mpeg2_tidy_video_codec( string $pFile ): ?string {
	$cmd = 'ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of csv=p=0 '.escapeshellarg( $pFile );
	$codec = explode( ',', trim( shell_exec( $cmd ) ?? '' ), 2 )[0];
	return $codec !== '' ? $codec : null;
}

/**
 * Video codec_name for many files at once, via a single `xargs -P8` pipeline (same pattern
 * proven earlier scanning this same library for the original mkv-codec survey - ~4300 files in a
 * few minutes, versus the ~15-minute sequential equivalent above). Files with spaces/anything
 * shell-unsafe are handled via NUL-separated xargs -0, not naive newline splitting.
 *
 * @param string[] $pFiles  absolute paths
 * @return array<string,string>  path => codec_name, only for files ffprobe could read
 */
function mpeg2_tidy_video_codecs_parallel( array $pFiles ): array {
	if( empty( $pFiles ) ) {
		return [];
	}
	$listFile = tempnam( sys_get_temp_dir(), 'mpeg2_tidy_list_' );
	file_put_contents( $listFile, implode( "\0", $pFiles ) );
	// -v error keeps ffprobe's own error output out of the codec line; a null-separated
	// "codec\tpath" line lets a path containing '|' (this library has real examples) round-trip
	// safely, unlike the '|'-delimited format the rest of this codebase's own probe scripts use.
	// printf, not echo "...\t..." - plain sh's echo does NOT interpret backslash escapes by
	// default, so that form previously emitted the two literal characters '\' 't' rather than a
	// real tab byte - found live 2026-09-03 (confirmed via `cat -A` on the raw output), the
	// actual root cause of every scan on this file reporting zero candidates: PHP's own
	// explode("\t", ...) below (a *real* tab, since PHP double-quoted strings do interpret it)
	// never found a delimiter in a line that never had one, silently corrupting every row.
	$cmd = 'xargs -0 -P8 -I{} sh -c \'printf "%s\t%s\n" "$(ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of csv=p=0 "$1" 2>/dev/null)" "$1"\' _ {} < '
		.escapeshellarg( $listFile );
	$output = shell_exec( $cmd ) ?? '';
	@unlink( $listFile );
	$ret = [];
	foreach( explode( "\n", $output ) as $line ) {
		if( $line === '' ) {
			continue;
		}
		[ $codec, $path ] = explode( "\t", $line, 2 );
		// See mpeg2_tidy_video_codec()'s own docblock - some files' csv output carries a
		// trailing empty field (e.g. "mpeg2video,"), always take just the first one.
		$codec = explode( ',', $codec, 2 )[0];
		if( $codec !== '' ) {
			$ret[$path] = $codec;
		}
	}
	return $ret;
}

/**
 * Whether this exact relative path is already live - referenced by a real 'episode' xref, or a
 * FisheyeFilm's own attachment file_name - so mpeg2_tidy.php never touches something already
 * loaded and playable, only pre-load candidates.
 */
function mpeg2_tidy_is_already_loaded( string $pRelativePath ): bool {
	global $gBitDb;
	if( $gBitDb->getOne( "SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `item`='episode' AND `xkey_ext`=?", [ $pRelativePath ] ) ) {
		return true;
	}
	if( $gBitDb->getOne( "SELECT 1 FROM `".BIT_DB_PREFIX."liberty_files` WHERE `file_name`=?", [ $pRelativePath ] ) ) {
		return true;
	}
	return false;
}

$scope = null;
$show = null;
foreach( $argv ?? [] as $arg ) {
	if( str_starts_with( $arg, '--scope=' ) ) {
		$scope = substr( $arg, strlen( '--scope=' ) );
	} elseif( str_starts_with( $arg, '--show=' ) ) {
		$show = substr( $arg, strlen( '--show=' ) );
	}
}

// --show=<name> cherry-picks a single show/film by its top-level folder name - lets one machine
// be pointed at a specific title (e.g. splitting the backlog across several machines by hand)
// without walking, probing, or re-transcoding the rest of the library.
$allEntries = mpeg2_tidy_scan_roots( $scope, $show );
$allPaths = array_map( fn( $e ) => $e['root'].$e['relative'], $allEntries );
$codecs = mpeg2_tidy_video_codecs_parallel( $allPaths );

$candidates = [];
foreach( $allEntries as $entry ) {
	$fullPath = $entry['root'].$entry['relative'];
	if( ( $codecs[$fullPath] ?? null ) !== 'mpeg2video' ) {
		continue;
	}
	if( mpeg2_tidy_is_already_loaded( $entry['relative'] ) ) {
		continue;
	}
	$candidates[] = [ 'root' => $entry['root'], 'relative' => $entry['relative'], 'full' => $fullPath, 'size' => filesize( $fullPath ) ];
}

if( in_array( '--scan', $argv ?? [], true ) ) {
	$totalSize = array_sum( array_column( $candidates, 'size' ) );
	foreach( $candidates as $c ) {
		printf( "%10s  %s\n", number_format( $c['size'] / 1048576, 1 ).'MB', $c['relative'] );
	}
	printf( "\n%d candidates, %.1f GB total (mpeg2video, not yet loaded, .mkv/.mp4/.m4v/.avi under the configured storage roots)\n",
		count( $candidates ), $totalSize / 1073741824 );
	exit;
}

$batchSize = (int)( $argv[1] ?? 1 );
$batch = array_slice( $candidates, 0, max( 1, $batchSize ) );

foreach( $batch as $c ) {
	$begin = time();
	$tmpOut = $c['full'].'.tidy_tmp.mp4';
	@unlink( $tmpOut );
	// nice (never compete with interactive foreground work) + a capped thread count (never
	// saturate every core) - unconstrained libx264 pegged every thread on a real machine at
	// ~980% CPU, real heat while the machine was actively in interactive use. Half the
	// machine's own thread count, not a fixed number, so this scales sanely across machines
	// with a different core count too.
	//
	// A plain global `-threads N` (before -i) mostly governs the *decoder*'s thread count, not
	// libx264's own encoder thread pool - confirmed live: it made no measurable difference to
	// either %CPU or system load. `-x264-params threads=N` talks to x264 directly and is the
	// combination that actually constrains it.
	$threads = max( 1, (int)( shell_exec( 'nproc' ) ?: 4 ) );
	$halfThreads = max( 1, intdiv( $threads, 2 ) );
	// nice alone only deprioritises CPU scheduling - a multi-hour transcode reading a multi-GB
	// source file competes for real disk I/O bandwidth too, which nice does nothing about.
	// ionice -c3 (idle class - only get disk I/O when nothing else wants it) found needed live:
	// a transcode noticeably slowed down loading a film's own video stream on the same machine
	// at the same time, even with CPU nice'd down already.
	$cmd = 'ionice -c3 nice -n 19 timeout 3600 ffmpeg -y -i '.escapeshellarg( $c['full'] )
		.' -map 0:v:0 -map 0:a:0? -c:v libx264 -preset medium -crf 20 -x264-params threads='.$halfThreads
		.' -c:a aac -b:a 192k -movflags +faststart '
		.escapeshellarg( $tmpOut ).' 2>&1';
	shell_exec( $cmd );

	if( !is_file( $tmpOut ) || filesize( $tmpOut ) < 1024 || mpeg2_tidy_video_codec( $tmpOut ) !== 'h264' ) {
		@unlink( $tmpOut );
		printf( "FAILED  %s (%ds)\n", $c['relative'], time() - $begin );
		continue;
	}

	// Films are duplicated across every machine that holds a full copy, so the original mpeg2
	// file is deleted outright once the re-encode is verified good, not archived. TV Shows are
	// not duplicated the same way - only some shows exist on any one machine, so a given copy
	// may be the only one - archived into .mpeg2_originals/ instead, same reasoning as the
	// unscoped default (every non-'films' scope, including no scope at all, keeps this safer
	// default).
	//
	// A source that's already .mp4 (mpeg2video does turn up in that extension too - see the
	// codec breakdown) needs care: $newPath then equals $c['full'] exactly, so there's no room
	// for a separate "remove the original, then move the new file in" pair of steps without a
	// real window where neither file exists. Handled by never explicitly removing/renaming the
	// original away in that case - archiving becomes a copy() taken *before* anything else so
	// the original survives right up until the rename() below, which overwrites it atomically
	// in a single filesystem operation; for films (no archive wanted) that same atomic
	// overwrite alone is both the "replace" and the "remove" step, so there's nothing further
	// to do at all.
	$newPath = preg_replace( '/\.[^.\/]+$/', '', $c['full'] ).'.mp4';
	$samePath = ( $newPath === $c['full'] );
	$preserved = true;
	if( $scope !== 'films' ) {
		$archiveDir = dirname( $c['full'] ).'/.mpeg2_originals/';
		\Bitweaver\KernelTools::mkdir_p( $archiveDir );
		$preserved = $samePath
			? copy( $c['full'], $archiveDir.basename( $c['full'] ) )
			: rename( $c['full'], $archiveDir.basename( $c['full'] ) );
	} elseif( !$samePath ) {
		$preserved = unlink( $c['full'] );
	}
	if( !$preserved || !rename( $tmpOut, $newPath ) ) {
		printf( "FAILED  %s (move step failed - check %s manually)\n", $c['relative'], $tmpOut );
		continue;
	}
	@chmod( $newPath, 0644 );

	$newSize = filesize( $newPath );
	printf( "OK  %s -> %s  (%s -> %s, %ds)\n",
		$c['relative'], basename( $newPath ),
		number_format( $c['size'] / 1048576, 1 ).'MB', number_format( $newSize / 1048576, 1 ).'MB',
		time() - $begin );
}

if( empty( $batch ) ) {
	echo "Nothing to do - no mpeg2video candidates found.\n";
}

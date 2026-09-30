<?php
/**
 * @package fisheyemedia
 */

namespace Bitweaver\Fisheyemedia;

/**
 * Shared engine used by both media inheritance branches - FisheyeMediaGallery (FisheyeProgram, a
 * show with no video file of its own) and FisheyeMediaImage (FisheyeFilm/FisheyeSeason/
 * FisheyeAlbum, each a real attached file) - hence a trait rather than living on either single
 * intermediate class. Relies only on methods/properties FisheyeBase already provides on both
 * branches (getExtraImagePath(), resizeImageFile(), storeXref(), mDb, mContentId, getTitle()).
 *
 * @package fisheyemedia
 */
trait FisheyeMediaTrait {

	/**
	 * Every live xref row on this content object, across all groups except liberty's synthetic
	 * 'history' group (rows with an end_date - archived by a reload's reconcile, or by hand).
	 * liberty's own LibertyXrefContent::allXrefs() deliberately includes history, since the
	 * generic xref grid shows it on its own History tab; the media view/edit code here wants
	 * current data only, so it reads through this instead. Empty if xref info isn't loaded.
	 *
	 * @return array
	 */
	public function liveXrefs(): array {
		$ret = [];
		foreach( $this->mXrefInfo->mGroups ?? [] as $groupName => $group ) {
			if( $groupName === 'history' ) {
				continue;
			}
			foreach( $group->mXrefs as $xref ) {
				$ret[] = $xref;
			}
		}
		return $ret;
	}

	/**
	 * Grab a frame from a given video file and store it as a new 'image' xref on THIS content
	 * object - the shared engine behind FisheyeSeason::grabVideoFrameImage() (source: its own
	 * seed episode) and FisheyeProgram::grabVideoFrameImage() (source: its first season's own
	 * seed episode, since a show has no video file of its own). Reuses
	 * mime_film_grab_video_frame() - the same ffmpegthumbnailer/ffmpeg chain a plain film's own
	 * attachment thumbnail already falls back to.
	 *
	 * @param string $pVideoFile  absolute path to a real video file to grab a frame from
	 * @return string|null  the new xref row's xkey_ext, or null if this content type has no
	 *                       image storage location, the file doesn't exist, or the grab/resize/
	 *                       store itself failed
	 */
	protected function grabVideoFrameIntoImageXref( string $pVideoFile ): ?string {
		if( !is_file( $pVideoFile ) ) {
			return null;
		}
		// Routed through getExtraImagePath() (empty relative path -> the directory itself) rather
		// than hardcoding getImageStorageRoot().'images/' here, so this automatically picks up
		// whichever storage location the calling subclass actually overrides it with (storage/
		// attachments/<branch>/ for every current fisheye content type - see that method's own
		// docblock).
		$imagesDir = $this->getExtraImagePath( '' );
		if( empty( $imagesDir ) ) {
			return null;
		}
		\Bitweaver\KernelTools::mkdir_p( $imagesDir );
		$tmpFile = tempnam( sys_get_temp_dir(), 'fisheye_frame_' );
		$relativePath = null;
		if( \Bitweaver\Liberty\mime_film_grab_video_frame( $pVideoFile, $tmpFile ) ) {
			$baseName = $this->getTitle();
			$n = 1;
			do {
				$fileName = "$baseName-frame-$n.jpg";
				$n++;
			} while( is_file( $imagesDir.$fileName ) );
			if( self::resizeImageFile( $tmpFile, $imagesDir.$fileName, 400 ) ) {
				$relativePath = $fileName;
				$nextXorder = 1 + (int)$this->mDb->getOne(
					"SELECT COALESCE(MAX(`xorder`),0) FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id`=? AND `item`='image'",
					[ $this->mContentId ]
				);
				$xrefParamHash = [ 'content_id' => $this->mContentId, 'item' => 'image', 'xkey_ext' => $relativePath, 'xorder' => $nextXorder ];
				$this->storeXref( $xrefParamHash );
			}
		}
		@unlink( $tmpFile );
		return $relativePath;
	}

	/**
	 * Shared engine behind FisheyeFilm::registerFeaturettesFromDisk() and
	 * FisheyeSeason::registerFeaturettesFromDisk() (previously two near-identical copies of this
	 * same scan-and-register logic) - scans a "Featurettes/" subfolder inside the given
	 * containing directory and registers each real video file as a 'featurette' xref on this
	 * content object. Rebuild-not-diff, same as
	 * every other reload* method here. Each subclass still owns resolving its own containing
	 * directory (a film's own folder via `dirname($pRelativePath)`; a season's via
	 * `resolveSeasonDirectoryFromDisk()`) - genuinely different logic per type, not worth forcing
	 * into a shared shape - only the scan-and-register half was actually duplicated.
	 *
	 * @param string $pContainingDirAbsolute  absolute path to the film/season's own folder,
	 *                                         trailing slash
	 * @param string $pContainingDirRelative  same folder, relative to the storage root (no
	 *                                         trailing slash) - used to build each featurette's
	 *                                         xkey_ext
	 * @return array{items:array}
	 */
	protected function registerFeaturettesFromFolder( string $pContainingDirAbsolute, string $pContainingDirRelative ): array {
		$summary = [ 'items' => [] ];
		$featurettesDir = $pContainingDirAbsolute.'Featurettes/';
		if( !is_dir( $featurettesDir ) ) {
			return $summary;
		}

		self::deleteXrefByItem( $this->mContentId, [ 'featurette' ] );

		// Same per-item thumbnail grab as FisheyeSeason::registerEpisodesFromFilesystem()'s own
		// no-Plex fallback - a featurette has no Plex metadata to source a thumb from at all
		// (Plex doesn't catalogue bonus content), so a local frame grab is the only option.
		$imagesDir = $this->getExtraImagePath( '' );
		\Bitweaver\KernelTools::mkdir_p( $imagesDir );

		$files = scandir( $featurettesDir );
		natsort( $files );
		$xorder = 0;
		foreach( $files as $file ) {
			if( !is_file( $featurettesDir.$file ) ) {
				continue;
			}
			if( !in_array( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ), [ 'mkv', 'mp4', 'm4v', 'avi' ], true ) ) {
				continue;
			}
			$xorder++;
			$title = pathinfo( $file, PATHINFO_FILENAME );
			$featuretteData = [ 'title' => $title ];
			// Plex never catalogues bonus content, so there's no metadata source for duration,
			// resolution or audio layout here at all - straight from the file's own container via
			// ffprobe instead.
			$durationMs = \Bitweaver\Liberty\mime_film_get_duration_ms( $featurettesDir.$file );
			if( $durationMs !== null ) {
				$featuretteData['duration'] = $durationMs;
			}
			$qualityInfo = \Bitweaver\Liberty\mime_film_get_quality_info( $featurettesDir.$file );
			if( $qualityInfo['resolution'] !== null ) {
				$featuretteData['resolution'] = $qualityInfo['resolution'];
			}
			if( $qualityInfo['audio'] !== null ) {
				$featuretteData['audio'] = $qualityInfo['audio'];
			}
			// Named after the featurette's own source file (unique within this folder), not its
			// xorder position - reload is rebuild-not-diff (every xref row deleted and re-created
			// above), so a position-based name would both mis-attach an old thumb to the wrong
			// file the moment a new featurette shifts the ordering, and force an expensive
			// re-grab of every thumbnail on every reload even when nothing about that file
			// changed. Keying by the file's own name means an already-grabbed thumbnail is
			// simply reused - only genuinely new featurettes pay the ffmpeg cost.
			$fileName = $title.'.jpg';
			if( is_file( $imagesDir.$fileName ) ) {
				$featuretteData['thumb'] = $fileName;
			} else {
				$tmpFile = tempnam( sys_get_temp_dir(), 'fisheye_featurette_thumb_' );
				if( \Bitweaver\Liberty\mime_film_grab_video_frame( $featurettesDir.$file, $tmpFile ) ) {
					if( self::resizeImageFile( $tmpFile, $imagesDir.$fileName, 400 ) ) {
						$featuretteData['thumb'] = $fileName;
					}
				}
				@unlink( $tmpFile );
			}
			$xrefHash = [
				'content_id' => $this->mContentId,
				'item'       => 'featurette',
				'xkey_ext'   => $pContainingDirRelative.'/Featurettes/'.$file,
				'edit'       => json_encode( $featuretteData ),
				'xorder'     => $xorder,
			];
			$this->storeXref( $xrefHash );
			$summary['items'][] = $title;
		}

		return $summary;
	}
}

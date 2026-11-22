{strip}
<div class="admin fisheye">
	<div class="header">
		<h1>{tr}Episode Thumbnail{/tr}: {$gContent->getTitle()|escape} - {$episodeTitle|escape}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}

		<p>
			<img src="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_extra_image.php?xref_id={$episode.xref_id}" alt="{$episodeTitle|escape}" style="max-width:320px; max-height:200px;" />
		</p>

		{form id="episodeThumbGrabForm"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
			<input type="hidden" name="xref_id" value="{$episode.xref_id}" />
			{legend legend="Take a frame from the video"}
				<div class="form-group">
					{formlabel label="Time (seconds)" for="seconds"}
					{forminput}
						<input type="number" class="form-control" name="seconds" id="seconds" min="0" {if $durationSeconds}max="{$durationSeconds}" {/if}value="{$defaultSeconds}" />
						{formhelp note="Where in the episode to take the picture from{if $durationSeconds} (it runs {$durationSeconds} seconds){/if}. Try another time if the first frame is a title card or black."}
					{/forminput}
				</div>
				<div class="form-group submit">
					<input type="submit" class="btn btn-primary" name="fGrab" value="{tr}Use this frame{/tr}" />
				</div>
			{/legend}
		{/form}

		{form id="episodeThumbUploadForm" enctype="multipart/form-data"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
			<input type="hidden" name="xref_id" value="{$episode.xref_id}" />
			{legend legend="Or upload a picture"}
				<div class="form-group">
					{formlabel label="Picture" for="episode_thumb_file"}
					{forminput}
						<input type="file" name="episode_thumb_file" id="episode_thumb_file" accept="image/*" />
						{formhelp note="Any picture; it is resized to fit 400 pixels."}
					{/forminput}
				</div>
				<div class="form-group submit">
					<input type="submit" class="btn btn-primary" name="fUpload" value="{tr}Upload{/tr}" />
				</div>
			{/legend}
		{/form}

		{form id="episodeThumbCancelForm"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
			<input type="hidden" name="xref_id" value="{$episode.xref_id}" />
			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="fCancel" value="{tr}Back to the season{/tr}" />
			</div>
		{/form}
	</div><!-- end .body -->
</div><!-- end .fisheye -->
{/strip}

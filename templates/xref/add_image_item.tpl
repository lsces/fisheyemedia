{strip}
<div class="edit liberty">
	<div class="header">
		<h1>{tr}Add Image{/tr}: {$gContent->getTitle()|escape}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}

		{form id="addImageXrefForm" enctype="multipart/form-data"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />

			<div class="form-group">
				{formlabel label="Image" for="image_file"}
				{forminput}
					<input type="file" name="image_file" id="image_file" accept="image/*" />
					{formhelp note="Choose an image to add - stored as a new entry on the Images tab."}
				{/forminput}
			</div>

			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="fCancel" value="{tr}Cancel{/tr}" />
				<input type="submit" class="btn btn-primary" name="fAddImage" value="{tr}Add{/tr}" />
			</div>
		{/form}
	</div><!-- end .body -->
</div><!-- end .liberty -->
{/strip}

{* Group-tab override for the 'images' xref group's own add form (liberty_xref_group.template
   'images' for fisheyefilm/fisheyeseason/fisheyeprogram/fisheyealbum) - the generic
   add_xref.tpl has no file upload at all, so this replaces it with a real file field, posting
   back to the same add_xref.php (fAddXref) rather than a separate script - add_xref.php's own
   fAddXref handler already checks for an uploaded file via addImageXrefFile() when the content
   class supports it. *}
{strip}
<div class="edit liberty">
	<div class="header">
		<h1>{tr}Add Image{/tr}: {$gContent->getTitle()|escape}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}

		{form id="addXrefForm" enctype="multipart/form-data"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
			<input type="hidden" name="group" value="{$group}" />
			<input type="hidden" name="item" value="image" />

			<div class="form-group">
				{formlabel label="Image" for="image_file"}
				{forminput}
					<input type="file" name="image_file" id="image_file" accept="image/*" />
					{formhelp note="Choose an image to add - stored as a new entry on this tab."}
				{/forminput}
			</div>

			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="fCancel" value="{tr}Cancel{/tr}" />
				<input type="submit" class="btn btn-primary" name="fAddXref" value="{tr}Save{/tr}" />
			</div>
		{/form}
	</div><!-- end .body -->
</div><!-- end .liberty -->
{/strip}

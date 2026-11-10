{* Add a gallery inside a managed gallery - see add_gallery.php and FisheyeManagedGallery::create() *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin fisheye">
	<div class="header">
		<h1>{foreach from=$gContent->getBreadcrumbTrail() item=crumb}<a href="{$crumb.url|escape}">{$crumb.title|escape}</a> - {/foreach}<a href="{$gContent->getDisplayUrl()|escape}">{$gContent->getTitle()|escape}</a> - {tr}Add gallery{/tr}</h1>
	</div>

	<div class="body">
		{formfeedback hash=$feedback}
		{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`add_gallery.php"}
			<input type="hidden" name="gallery_id" value="{$gContent->mGalleryId}" />

			<div class="form-group">
				{formlabel label="Title" for="gallery-title"}
				{forminput}
					<input type="text" class="form-control" name="title" id="gallery-title" size="50" maxlength="160" value="{$formTitle|escape}" />
					{formhelp note="The new gallery is made inside this one and takes its layout."}
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Description" for="gallery-desc"}
				{forminput}
					<textarea class="form-control" name="edit" id="gallery-desc" rows="4" cols="50">{$formEdit|escape}</textarea>
				{/forminput}
			</div>

			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="cancelgallery" value="{tr}Cancel{/tr}" />
				<input type="submit" class="btn btn-primary" name="savegallery" value="{tr}Create{/tr}" />
			</div>
		{/form}
	</div>
</div>
{/strip}

{* Edit page of a gallery whose place is managed by a loader - see edit_gallery.php and FisheyeManagedGallery *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin fisheye">
	<div class="header">
		<h1>{foreach from=$gContent->getBreadcrumbTrail() item=crumb}<a href="{$crumb.url|escape}">{$crumb.title|escape}</a> - {/foreach}<a href="{$gContent->getDisplayUrl()|escape}">{$gContent->getTitle()|escape}</a> - {tr}Edit{/tr}</h1>
	</div>

	<div class="body">
		{formfeedback hash=$feedback}
		{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`edit_gallery.php"}
			<input type="hidden" name="gallery_id" value="{$gContent->mGalleryId}" />

			<div class="form-group">
				{formlabel label="Title" for="gallery-title"}
				{forminput}
					<input type="text" class="form-control" name="title" id="gallery-title" size="50" maxlength="160" value="{$formTitle|escape}" />
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Description" for="gallery-desc"}
				{forminput}
					<textarea class="form-control" name="edit" id="gallery-desc" rows="4" cols="50">{$formEdit|escape}</textarea>
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Located in"}
				{forminput}
					{if $placements}
						{foreach from=$placements item=place}
							<select class="form-control" name="parent[{$place.content_id}]">
								{foreach from=$place.options key=optionId item=optionLabel}
									<option value="{$optionId}"{if $optionId == $place.content_id} selected="selected"{/if}>{$optionLabel|escape}</option>
								{/foreach}
							</select>
						{/foreach}
						{formhelp note="Choose another gallery to move this one there. Only galleries of the same tree are offered, never this gallery or anything inside it."}
					{else}
						<p class="help-block">{tr}This is a top-level gallery, so it is not located in another one.{/tr}</p>
					{/if}
				{/forminput}
			</div>

			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="cancelgallery" value="{tr}Cancel{/tr}" />
				<input type="submit" class="btn btn-primary" name="savegallery" value="{tr}Save{/tr}" />
			</div>
		{/form}
	</div>
</div>
{/strip}

{* A season's json-list item fields: the shared fields, plus - for an episode already saved - its thumbnail and the way to change it by hand
   (episode_thumb.php: upload a picture, or take a frame from the episode's own video). *}
{include file="bitpackage:liberty/xref/edit_json-list_fields.tpl"}
{if $xrefInfo.item == 'episode' && $xrefInfo.xref_id}
	<div class="form-group">
		{formlabel label="Thumbnail"}
		{forminput}
			<img src="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_extra_image.php?xref_id={$xrefInfo.xref_id|escape}" alt="" style="max-width:200px; max-height:120px;" />
			<p><a class="btn btn-secondary" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}episode_thumb.php?content_id={$xrefInfo.content_id|escape}&amp;xref_id={$xrefInfo.xref_id|escape}">{tr}Change thumbnail{/tr}</a></p>
			{formhelp note="Upload a picture, or take a frame from this episode's video. Save any changes above first."}
		{/forminput}
	</div>
{/if}

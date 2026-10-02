{* Alternate-image grid shared by every Images tab (view_season.tpl, view_program_single_season.tpl,
   view_film.tpl). Params: $images (array of {xref_id}), $imagesAltText (alt text for each
   thumbnail, defaults to "Images"). *}
{if $images|@count}
	<div class="row">
		{foreach from=$images item=stripImage name=stripImages}
			<div class="col-md-1 col-sm-4 col-xs-6">
				<div class="gallery-box">
					<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_extra_image.php?xref_id={$stripImage.xref_id}&amp;name=image.jpg" target="_blank" rel="noopener">{* name= only so FancyZoom picks the link up *}
						<div class="gallery-img">
							<img class="img-responsive thumb" src="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_extra_image.php?xref_id={$stripImage.xref_id}" alt="{$imagesAltText|default:"Images"|escape}" />
						</div>
					</a>
				</div>
			</div>
			{if $smarty.foreach.stripImages.iteration % 2 == 0}<div class="clearfix visible-xs-block"></div>{/if}
			{if $smarty.foreach.stripImages.iteration % 3 == 0}<div class="clearfix visible-sm-block"></div>{/if}
			{if $smarty.foreach.stripImages.iteration % 12 == 0}<div class="clearfix visible-md-block visible-lg-block"></div>{/if}
		{/foreach}
	</div>
{/if}

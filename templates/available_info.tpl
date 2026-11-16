{* Available information for one Book - see available_info.php's own docblock. *}
{strip}
<div class="admin fisheye">
	<div class="header">
		<h1>{tr}Available information{/tr}: <a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}look_up_details.php?content_id={$gContent->mContentId}">{$gContent->getTitle()|escape}</a></h1>
	</div>

	<div class="body">
		{if $applied && $applied.error}<div class="alert alert-danger"><p>{$applied.error|escape}</p></div>{/if}

		{if $result}
			{if $result.error}<div class="alert alert-warning"><p>{$result.error|escape}</p></div>
			{elseif !$result.olFound}<div class="alert alert-info"><p>{tr}Open Library has no edition with this ISBN.{/tr}</p></div>{/if}
			{if $result.wdQid eq ''}
				<div class="alert alert-info"><p>{tr}Wikidata is not compared: this book has no Wikidata item yet. Find it on the Look up details page and it appears here.{/tr}</p></div>
			{elseif !$result.wdFound}
				<div class="alert alert-warning"><p>{tr}Wikidata did not answer for {/tr}{$result.wdQid|escape}.</p></div>
			{/if}

			{form id="availableForm"}
				<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
				<input type="hidden" name="payload" value="{$result.payload|escape}" />
				{foreach from=$result.covers key=src item=cover}{if $src ne 'now'}<input type="hidden" name="cover_uri[{$src|escape}]" value="{$cover.uri|escape}" />{/if}{/foreach}

				{if $result.covers}
					{legend legend="Cover"}
						<div class="row">
							{foreach from=$result.covers key=src item=cover}
								<div class="col-xs-6 col-md-3 text-center">
									<label>
										<img src="{$cover.uri}" alt="" style="max-height:220px; max-width:100%" /><br />
										<input type="radio" name="pick_cover" value="{$src|escape}"{if $src eq $result.coverDefault} checked="checked"{/if} />
										{if $src eq 'now'}{tr}Keep this one{/tr}{else}{tr}Use this one{/tr}{/if}<br />
										<small>{$cover.label|escape}{if $cover.size} - {$cover.size|escape}{/if}</small>
									</label>
								</div>
							{/foreach}
						</div>
					{/legend}
				{/if}

				{legend legend="Details"}
					<table class="table data">
						<tr><th></th><th>{tr}Stored here{/tr}</th>{if $result.wdFound}<th>{tr}Wikidata{/tr}</th>{/if}<th>{tr}Open Library{/tr}</th></tr>
						{foreach from=$result.fields key=item item=row}
							{if $row.values}
								<tr>
									<td>{$row.label|escape}</td>
									{foreach from=$columns item=src}
										<td>{if isset($row.values.$src)}<label><input type="radio" name="pick_field[{$item|escape}]" value="{$src}"{if $src eq $row.default} checked="checked"{/if} /> {$row.values.$src|escape}</label>{elseif $src eq 'now'}<label><input type="radio" name="pick_field[{$item|escape}]" value="now"{if $row.default eq 'now'} checked="checked"{/if} /> <em>{tr}none{/tr}</em></label>{/if}</td>
									{/foreach}
								</tr>
							{/if}
						{/foreach}
						{if $result.genres}
							<tr><td>{tr}Genres{/tr}</td>
								{foreach from=$columns item=src}
									<td>{if isset($result.genres.$src)}<label><input type="radio" name="pick_genres" value="{$src}"{if $src eq $result.genresDefault} checked="checked"{/if} /> {$result.genres.$src|escape}</label>{elseif $src eq 'now'}<label><input type="radio" name="pick_genres" value="now"{if $result.genresDefault eq 'now'} checked="checked"{/if} /> <em>{tr}none{/tr}</em></label>{/if}</td>
								{/foreach}
							</tr>
						{/if}
						{if $result.ids}
							<tr><td>{tr}Links to other services{/tr}</td>
								{foreach from=$columns item=src}
									<td>{if isset($result.ids.$src)}<label><input type="radio" name="pick_ids" value="{$src}"{if $src eq $result.idsDefault} checked="checked"{/if} /> {$result.ids.$src|escape}</label>{elseif $src eq 'now'}<label><input type="radio" name="pick_ids" value="now"{if $result.idsDefault eq 'now'} checked="checked"{/if} /> <em>{tr}none{/tr}</em></label>{/if}</td>
								{/foreach}
							</tr>
						{/if}
					</table>
				{/legend}

				{if $result.description}
					{legend legend="Description"}
						<div class="row">
							{foreach from=['now','ol'] item=src}
								{if isset($result.description.$src) || $src eq 'now'}
									<div class="col-md-6">
										<label><input type="radio" name="pick_description" value="{$src}"{if $src eq $result.descriptionDefault} checked="checked"{/if} /> <strong>{if $src eq 'now' && !isset($result.description.now)}{tr}No description{/tr}{else}{$sourceLabels.$src|escape}{/if}</strong></label>
										{if isset($result.description.$src)}<blockquote>{$result.description.$src|escape|nl2br}</blockquote>{/if}
									</div>
								{/if}
							{/foreach}
						</div>
					{/legend}
				{/if}

				<p class="help-block">{tr}A value you choose from another source replaces the stored one; the old value stays in the History tab. Anything left on Stored here is not touched.{/tr}</p>
				<input type="submit" class="btn btn-primary" name="apply" value="{tr}Apply my choices{/tr}" />&nbsp;
				<a class="btn btn-default" href="{$gContent->getEditUrl()|escape}">{tr}Cancel{/tr}</a>
			{/form}
		{/if}
	</div>
</div>
{/strip}

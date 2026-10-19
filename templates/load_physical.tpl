{* Review page for physical copies (LPs, singles, CDs, DVD sets) - see load_physical.php's own docblock.
   Nothing here writes until "Store ticked copies" is pressed. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Physical Copies{/tr}</h1>
	</div>

	<div class="body">

		{if $import}
			{if $import.error}
				<div class="alert alert-danger"><p>{$import.error|escape}</p></div>
			{else}
				<div class="alert alert-success"><p>{tr}Read{/tr} {$import.rows} {tr}rows from{/tr} {$import.file|escape}.</p></div>
			{/if}
		{/if}

		{if $applied}
			<div class="alert alert-info">
				<ul>
					{foreach from=$applied item=made}
						<li>{$made.text|escape} ({$made.format|escape}): {if $made.ok}{$made.message|escape}{else}<strong>{$made.message|escape}</strong>{/if}</li>
					{/foreach}
				</ul>
			</div>
		{/if}

		{form legend="Catalogue spreadsheet" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_physical.php" enctype="multipart/form-data"}
			<div class="form-group">
				{formlabel label="Spreadsheet (.ods)" for="catalogue_file"}
				{forminput}
					<input type="file" name="catalogue_file" id="catalogue_file" />
					{formhelp note="The Physical sheet: Kind, Format, Owner, Location, Box, Artist, Title, Discs, Note, Ref, Publisher, Year, Source. Each upload is kept, and the newest one is used."}
				{/forminput}
			</div>
			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="upload" value="{tr}Upload{/tr}" />
			</div>
		{/form}

		{if !$catalogueFile}
			<div class="alert alert-warning"><p>{tr}No catalogue has been uploaded yet.{/tr}</p></div>
		{else}
			<p>{$catalogueFile|escape}: {$counts.applied} {tr}stored{/tr}, {$counts.matched} {tr}match something in the library{/tr}, {$counts.stub} {tr}new albums{/tr}, {$counts.newartist} {tr}new albums with a new artist{/tr}, {$counts.ondisk} {tr}waiting for their folder to be loaded{/tr}, {$counts.torip} {tr}albums to rip{/tr}, {$counts.none} {tr}films and shows not in the library{/tr}.</p>

			<p>
				{tr}View{/tr}:
				<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_physical.php?view=todo&amp;kind={$kind|escape}">{tr}to do{/tr}</a> |
				<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_physical.php?view=unmatched&amp;kind={$kind|escape}">{tr}unmatched{/tr} ({$counts.none + $counts.ondisk + $counts.torip})</a> |
				<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_physical.php?view=all&amp;kind={$kind|escape}">{tr}all not yet stored{/tr}</a> |
					<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_physical.php?view={$view|escape}&amp;kind={$kind|escape}&amp;csv=1">{tr}download this view as CSV{/tr}</a>
				&nbsp;&nbsp;{tr}Show{/tr}:
				<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_physical.php?view={$view|escape}">{tr}everything{/tr}</a> |
				<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_physical.php?view={$view|escape}&amp;kind=album">{tr}albums{/tr}</a> |
				<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_physical.php?view={$view|escape}&amp;kind=film">{tr}films{/tr}</a> |
				<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_physical.php?view={$view|escape}&amp;kind=tv">{tr}TV{/tr}</a>
			</p>

			{if !$rows}
				<div class="alert alert-success"><p>{if $view eq 'unmatched'}{tr}Nothing unmatched.{/tr}{else}{tr}Nothing left to store here.{/tr}{/if}</p></div>
			{else}
				{form legend="Copies to store" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_physical.php"}
					<input type="hidden" name="kind" value="{$kind|escape}" />
					<input type="hidden" name="view" value="{$view|escape}" />
					<input type="hidden" name="start" value="{$start|escape}" />
					<div class="form-group">
						{formlabel label="Default location" for="location"}
						{forminput}
							<input type="text" class="form-control" name="location" id="location" value="{$defaultLocation|escape}" size="30" />
							{formhelp note="Used for any row with a box code but no Location of its own - 'Bookcase' for a box on the bookcase. Leave it blank if the boxes are elsewhere."}
						{/forminput}
					</div>
					<p>{$pendingCount} {tr}rows still to do. Tick the ones to store - up to{/tr} {$batchSize} {tr}at a time. In To do, a row left unticked is set aside and moves to the Unmatched view. A matched row adds a copy to what is already there; one with no match can be linked by hand, then ticked; a new album is created as a stub with no tracks, and loading its folder later fills it in.{/tr}</p>
					<table class="table table-condensed">
						<thead>
							<tr><th>&nbsp;</th><th>{tr}Format{/tr}</th><th>{tr}Box{/tr}</th><th>{tr}Owner{/tr}</th><th>{tr}Artist{/tr}</th><th>{tr}Title{/tr}</th><th>{tr}What it will do{/tr}</th></tr>
						</thead>
						<tbody>
							{foreach from=$rows item=row}
								<tr>
									<td>
										<input type="hidden" name="shown[]" value="{$row.key|escape}" />
										{if ( $row.status eq 'ondisk' or $row.status eq 'torip' ) and !$row.candidates}
											&nbsp;
										{elseif $row.status eq 'ondisk' or $row.status eq 'torip' or $row.skipped}
											<input type="checkbox" name="pick[]" value="{$row.key|escape}" />
										{elseif $row.status eq 'none' or strpos($row.label, 'with this name') !== false}
											<input type="checkbox" name="pick[]" value="{$row.key|escape}" />
										{else}
											<input type="checkbox" name="pick[]" value="{$row.key|escape}" checked="checked" />
										{/if}
									</td>
									<td>{$row.format|escape}</td>
									<td>{$row.box|escape}</td>
									<td>{$row.owner|escape}</td>
									<td>{$row.artist|escape}</td>
									<td>{$row.title|escape}{if $row.note} <span class="help-block">{$row.note|escape}</span>{/if}</td>
									<td>
										{if $row.status eq 'none'}{tr}no match - choose what it belongs to:{/tr}
										{else}{$row.label|escape}{/if}
										{if $row.skipped} <em>({tr}set aside{/tr})</em>{/if}
										{if $row.candidates || $row.status eq 'none'}
											<br />
											<select name="link[{$row.key|escape}]">
												<option value="">{if $row.status eq 'none'}{tr}choose{/tr}{else}{tr}as matched{/tr}{/if}</option>
												{foreach from=$row.candidates item=candidate}
													<option value="{$candidate.id|escape}">{$candidate.title|escape} ({$candidate.kind|escape})</option>
												{/foreach}
											</select>
											{tr}or content id{/tr} <input type="text" name="linkid[{$row.key|escape}]" size="8" />
										{/if}
									</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
					<div class="form-group submit">
						<input type="submit" class="btn btn-primary" name="apply" value="{tr}Store ticked copies{/tr}" />
						<input type="submit" class="btn btn-default" name="show" value="{tr}Refresh the list{/tr}" />
						{if $view ne 'todo'}<input type="submit" class="btn btn-default" name="restore" value="{tr}Put ticked rows back in To do{/tr}" />{/if}
					</div>
				{/form}
				<p>
					{if $prevStart !== null}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_physical.php?view={$view|escape}&amp;kind={$kind|escape}&amp;start={$prevStart}">{tr}Previous{/tr}</a>{/if}
					{if $prevStart !== null and $nextStart !== null} | {/if}
					{if $nextStart !== null}<a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_physical.php?view={$view|escape}&amp;kind={$kind|escape}&amp;start={$nextStart}">{tr}Next{/tr}</a>{/if}
				</p>
			{/if}
		{/if}

	</div>
</div>
{/strip}

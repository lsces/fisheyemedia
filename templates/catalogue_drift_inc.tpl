{* The catalogue file against the database - needs $catalogue (status.catalogue), $actionUrl, $hidden (name => value). Shown only when there is something to say. *}
{strip}
{if $catalogue.changes || $catalogue.conflicts}
	<div class="alert alert-info">
		{if $catalogue.changes}
			<p>{tr}The catalogue file is behind the database{/tr}: {$catalogue.filled} {tr}empty cells can be filled (ISBN, publisher, year) from books already loaded{/tr}{if $catalogue.added}, {tr}and these books are in the database but not in the file{/tr}: {foreach from=$catalogue.added item=title name=add}{$title|escape}{if !$smarty.foreach.add.last}, {/if}{/foreach}{/if}. {tr}A cell that already has a value is never changed.{/tr}</p>
			{form legend="" action=$actionUrl}
				{foreach from=$hidden key=hname item=hvalue}<input type="hidden" name="{$hname|escape}" value="{$hvalue|escape}" />{/foreach}
				<div class="form-group submit">
					<input type="submit" class="btn btn-default" name="update_catalogue" value="{tr}Update the catalogue file from the database{/tr}" />
				</div>
			{/form}
		{/if}
		{if $catalogue.conflicts}
			<p>{tr}The file and the database disagree on{/tr}:</p>
			{form legend="" action=$actionUrl}
				{foreach from=$hidden key=hname item=hvalue}<input type="hidden" name="{$hname|escape}" value="{$hvalue|escape}" />{/foreach}
				<ul class="list-unstyled">
					{foreach from=$catalogue.conflicts item=c}
						<li>
							{if $c.1 == 'Year'}<input type="checkbox" name="year_book[{$c.4}]" value="1" /> {/if}
							<a href="{$smarty.const.BIT_ROOT_URL}index.php?content_id={$c.4}">{$c.0|escape}</a>: {$c.1|escape} - {tr}file{/tr} {$c.2|escape}, {tr}database{/tr} {$c.3|escape}
						</li>
					{/foreach}
				</ul>
				<p class="help-block">{tr}Tick the books whose year the file has right to put it in the database (the published row and the date that orders the book in its series). A year the database has right is changed in the file itself.{/tr}</p>
				<div class="form-group submit">
					<input type="submit" class="btn btn-default" name="use_file_years" value="{tr}Use the file's year for the ticked books{/tr}" />
				</div>
			{/form}
		{/if}
	</div>
{/if}
{/strip}

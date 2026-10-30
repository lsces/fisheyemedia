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
							<a href="{$smarty.const.BIT_ROOT_URL}index.php?content_id={$c.4}">{$c.0|escape}</a>: {$c.1|escape} - {tr}file{/tr} {$c.2|escape}, {tr}database{/tr} {$c.3|escape}
							<label class="checkbox-inline"><input type="checkbox" name="db_value[{$c.4}_{$c.1|escape}]" value="1" /> {tr}database is right{/tr}</label>
							{if $c.1 == 'Year'}<label class="checkbox-inline"><input type="checkbox" name="year_book[{$c.4}]" value="1" /> {tr}file is right{/tr}</label>{/if}
						</li>
					{/foreach}
				</ul>
				<p class="help-block">{tr}Tick where the database is right to put its value into the catalogue file (an ISBN-13 brings its ISBN-10 with it), or, for a year, where the file is right to put it in the database (the published row and the date that orders the book in its series).{/tr}</p>
				<div class="form-group submit">
					<input type="submit" class="btn btn-default" name="use_database_values" value="{tr}Put the database's value in the file{/tr}" />
					<input type="submit" class="btn btn-default" name="use_file_years" value="{tr}Use the file's year in the database{/tr}" />
				</div>
			{/form}
		{/if}
	</div>
{/if}
{/strip}

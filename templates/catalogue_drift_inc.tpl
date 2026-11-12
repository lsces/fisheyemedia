{* The catalogue file against the database - needs $catalogue (status.catalogue), $actionUrl, $hidden (name => value). Shown only when there is something to say. *}
{strip}
{if $catalogue.changes || $catalogue.conflicts || $catalogue.attach || $catalogue.isbn || $catalogue.epubIsbns}
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
		{if $catalogue.attach}
			<p>{tr}The file gives these books work identifiers (Wikidata, Gutenberg, Open Library work) that the books do not have yet{/tr}: {foreach from=$catalogue.attach item=a name=att}{$a.0|escape} ({$a.1|escape} {$a.2|escape}){if !$smarty.foreach.att.last}, {/if}{/foreach}. {tr}An identifier a book already has is never replaced.{/tr}</p>
			{form legend="" action=$actionUrl}
				{foreach from=$hidden key=hname item=hvalue}<input type="hidden" name="{$hname|escape}" value="{$hvalue|escape}" />{/foreach}
				<div class="form-group submit">
					<input type="submit" class="btn btn-default" name="attach_work_ids" value="{tr}Attach the file's work identifiers to the books{/tr}" />
				</div>
			{/form}
		{/if}
		{if $catalogue.epubIsbns}
			<p>{tr}These books have no ISBN in the catalogue file, but their own epubs carry one{/tr}. {tr}Where an epub names more than one (the print and the ebook edition), choose the one to keep; the ISBN-10 goes with it.{/tr}</p>
			{form legend="" action=$actionUrl}
				{foreach from=$hidden key=hname item=hvalue}<input type="hidden" name="{$hname|escape}" value="{$hvalue|escape}" />{/foreach}
				<table class="table table-condensed">
					<tbody>
						{foreach from=$catalogue.epubIsbns item=e}
							<tr>
								<td>{$e.title|escape} <small class="text-muted">{$e.file|escape}</small></td>
								<td>
									{foreach from=$e.isbns item=isbn name=isbnloop}
										<label class="radio-inline"><input type="radio" name="epub_isbn[{$e.key}]" value="{$isbn|escape}"{if $smarty.foreach.isbnloop.first} checked="checked"{/if} /> {$isbn|escape}</label>
									{/foreach}
								</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
				<div class="form-group submit">
					<input type="submit" class="btn btn-default" name="fill_isbns_from_epubs" value="{tr}Write these ISBNs in the catalogue file{/tr}" />
				</div>
			{/form}
		{/if}
		{if $catalogue.isbn}
			<p>{tr}The file gives these books an ISBN that the books do not have yet{/tr}: {foreach from=$catalogue.isbn item=i name=isbnbooks}{$i.0|escape} ({$i.1|escape}){if !$smarty.foreach.isbnbooks.last}, {/if}{/foreach}. {tr}Once they have it the Book Details jobs can look their details and covers up by it.{/tr}</p>
			{form legend="" action=$actionUrl}
				{foreach from=$hidden key=hname item=hvalue}<input type="hidden" name="{$hname|escape}" value="{$hvalue|escape}" />{/foreach}
				<div class="form-group submit">
					<input type="submit" class="btn btn-default" name="attach_isbns" value="{tr}Attach the file's ISBNs to the books{/tr}" />
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
				<p class="help-block">{tr}Tick where the database is right to put its value into the catalogue file (an ISBN-13 brings its ISBN-10 with it), or, for a year, where the file is right to put it in the database (the published row and the date that orders the book in its series; the year it replaces is kept as the book's edition date).{/tr}</p>
				<div class="form-group submit">
					<input type="submit" class="btn btn-default" name="use_database_values" value="{tr}Put the database's value in the file{/tr}" />
					<input type="submit" class="btn btn-default" name="use_file_years" value="{tr}Use the file's year in the database{/tr}" />
				</div>
			{/form}
		{/if}
	</div>
{/if}
{/strip}

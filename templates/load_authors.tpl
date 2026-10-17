{* Review page for the authors pass - see load_authors.php's own docblock. Nothing here writes
   until "Create selected" is pressed. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Authors from Open Library{/tr}{if $galleryId} - <a href="{$galleryUrl|escape}">{$galleryTitle|escape}</a>{/if}</h1>
	</div>

	<div class="body">

		{if $result}
			{if $result.errors}
				<div class="alert alert-danger">
					<p>{tr}Failed{/tr}:</p>
					<ul>{foreach from=$result.errors item=error}<li>{$error|escape}</li>{/foreach}</ul>
				</div>
			{/if}
			{if $result.done}
				<div class="alert alert-success">
					<ul>{foreach from=$result.done item=made}<li>{$made.name|escape} - <a href="{$made.url|escape}">{$made.contact|escape}</a>: {$made.linked} {tr}of{/tr} {$made.books} {tr}books linked{/tr}</li>{/foreach}</ul>
				</div>
			{/if}
		{/if}

		{if !$unlinkedCount}
			<div class="alert alert-success"><p>{if $galleryId}{tr}Every name credited on this gallery's books is linked to a contact.{/tr}{elseif $fromSheet}{tr}Every author on the catalogue spreadsheet has a contact.{/tr}{else}{tr}Every credited name on the books is linked to a contact.{/tr}{/if}</p></div>
		{elseif !$batch && !$result}
			{form legend="Authors" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_authors.php"}
				<input type="hidden" name="start" value="{$start|escape}" />{if $galleryId}<input type="hidden" name="gallery_id" value="{$galleryId|escape}" />{/if}
				<p>{if $fromSheet}{$unlinkedCount} {tr}authors on the catalogue spreadsheet{/tr} ({$sheetFile|escape}) {tr}have no contact yet.{/tr}{else}{$unlinkedCount} {tr}credited names on the books are not yet linked to a contact.{/tr}{/if} {tr}Each press looks up the next{/tr} {$batchSize}, {tr}most books first - Open Library is asked about each person's own books, so it takes a little while.{/tr}</p>
				<div class="form-group submit">
					<input type="submit" class="btn btn-primary" name="scan" value="{tr}Look up the next batch{/tr}" />
				</div>
			{/form}
		{else}
			{form legend="Proposed contacts" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_authors.php"}
				<input type="hidden" name="start" value="{$start|escape}" />{if $galleryId}<input type="hidden" name="gallery_id" value="{$galleryId|escape}" />{/if}
				<p>{tr}Each person was looked up from their own books where there was an ISBN to go on - an author found that way is already chosen. Where Open Library names nobody, a contact already called that is offered, or a name-only writer to be filled in later.{/tr}</p>
				{foreach from=$batch item=person name=people}
					{assign var=i value=$smarty.foreach.people.index}
					<div class="form-group">
						<input type="hidden" name="person[{$i}]" value="{$person.name|escape}" />
						<h3>{$person.name|escape}</h3>
						<p class="help-block">{$person.bookCount} {tr}books{/tr}: {foreach from=$person.bookTitles item=title name=titles}{$title|escape}{if !$smarty.foreach.titles.last}, {/if}{/foreach}{if $person.bookCount > 6}, ...{/if}</p>
						{foreach from=$person.candidates item=candidate}
							<div class="radio"><label>
								<input type="radio" name="choice[{$i}]" value="ol:{$candidate.olid|escape}"{if $person.default eq "ol:`$candidate.olid`"} checked="checked"{/if} />
								<strong>{$candidate.name|escape}</strong>{if $candidate.born} ({$candidate.born|escape}{if $candidate.died} - {$candidate.died|escape}{/if}){/if}
								- <a href="https://openlibrary.org/authors/{$candidate.olid|escape}" target="_blank" rel="noopener">{$candidate.olid|escape}</a>{if $candidate.qid}, <a href="https://www.wikidata.org/wiki/{$candidate.qid|escape}" target="_blank" rel="noopener">{$candidate.qid|escape}</a>{/if}
								- {if $candidate.via eq 'isbn'}{tr}found from the ISBN of{/tr}{else}{tr}found by name, known for{/tr}{/if} {$candidate.book|escape}{if $candidate.held} - {tr}already a contact:{/tr} {$candidate.held|escape}{/if}
							</label></div>
						{/foreach}
						{foreach from=$person.contacts item=contact}
							<div class="radio"><label>
								<input type="radio" name="choice[{$i}]" value="contact:{$contact.content_id}"{if $person.default eq "contact:`$contact.content_id`"} checked="checked"{/if} />
								{tr}Existing contact{/tr}: <a href="{$smarty.const.BIT_ROOT_URL}index.php?content_id={$contact.content_id}" target="_blank" rel="noopener">{$contact.title|escape}</a>{if $contact.alias} ({tr}credited here under a pen name or birth name of theirs{/tr}){/if}{if $contact.qid} - {$contact.qid|escape}{/if}
							</label></div>
						{/foreach}
						<div class="radio"><label>
							<input type="radio" name="choice[{$i}]" value="new"{if $person.default eq 'new'} checked="checked"{/if} />
							{tr}Create a name-only writer{/tr}
						</label></div>
						<div class="radio"><label>
							<input type="radio" name="choice[{$i}]" value="skip"{if $person.default eq 'skip'} checked="checked"{/if} />
							{tr}Leave for now{/tr}
						</label></div>
					</div>
				{/foreach}
				<div class="form-group submit">
					<input type="submit" class="btn btn-primary" name="create_authors" value="{tr}Create selected{/tr}" />
				</div>
			{/form}
		{/if}

		{if $result && !$batch && $unlinkedCount}
			{form legend="Next" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_authors.php"}
				<input type="hidden" name="start" value="{$start|escape}" />{if $galleryId}<input type="hidden" name="gallery_id" value="{$galleryId|escape}" />{/if}
				<div class="form-group submit">
					<input type="submit" class="btn btn-primary" name="scan" value="{tr}Look up the next batch{/tr}" />
				</div>
			{/form}
		{/if}

	</div>
</div>
{/strip}

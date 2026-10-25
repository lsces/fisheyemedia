{* Batch fill of the Library's books from Open Library - see load_book_details.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Book Details from Open Library{/tr}</h1>
	</div>

	<div class="body">

		{if $results}
			<div class="alert alert-info">
				<ul>
					{foreach from=$results item=made}
						<li><a href="{$made.url|escape}">{$made.title|escape}</a>:
							{if $made.error}{$made.error|escape}
							{elseif $made.items}{foreach from=$made.items item=line name=lines}{$line|escape}{if !$smarty.foreach.lines.last}; {/if}{/foreach}
							{elseif $made.matched}{tr}nothing missing that Open Library holds{/tr}
							{else}{tr}not on Open Library{/tr}{/if}
						</li>
					{/foreach}
				</ul>
			</div>
		{/if}

		{if $replaced !== null}
			<div class="alert alert-info">
				<p>{$replaced|@count} {tr}placeholder covers removed and looked for again{/tr}:</p>
				<ul>
					{foreach from=$replaced item=made}
						<li><a href="{$made.url|escape}">{$made.title|escape}</a>:
							{if $made.error}{$made.error|escape}
							{elseif $made.items}{foreach from=$made.items item=line name=lines}{$line|escape}{if !$smarty.foreach.lines.last}; {/if}{/foreach}
							{else}{tr}no real cover found - left without one{/tr}{/if}
						</li>
					{/foreach}
				</ul>
			</div>
		{/if}

		{if $blankCount}
			{form legend="Placeholder covers" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_details.php"}
				<p>{$blankCount} {tr}books have a Google Books cover that is only the grey "image not available" picture. This removes the next{/tr} {$batchSize} {tr}of them (image, file and thumbnail) and looks for a real cover - Open Library first, then Google at a smaller size. A book with no real cover anywhere is left without one, ready for your own scan.{/tr}</p>
				<div class="form-group submit">
					<input type="submit" class="btn btn-default" name="replace_blank" value="{tr}Replace placeholder covers{/tr}" />
				</div>
			{/form}
		{/if}

		{if $quota}
			<div class="alert alert-danger"><p>{$quota|escape}. {tr}The run has stopped; the books not reached are not marked and will be asked about next time.{/tr}</p></div>
		{/if}
		{if $busy}
			<div class="alert alert-warning"><p>{tr}A run is already going - started by an earlier press. It carries on by itself and this page asks again in a few seconds; there is no need to press the button again.{/tr}</p></div>
		{/if}
		{if $pressBooks}
			<p><strong>{tr}This batch{/tr}:</strong> {$pressBooks} {tr}books in{/tr} {$pressSeconds} {tr}seconds{/tr} ({($pressSeconds/$pressBooks)|string_format:"%.1f"} {tr}s a book{/tr}).
				<strong>{tr}So far{/tr}:</strong> {$doneCount} {tr}books, {/tr}{$changedCount} {tr}gained something, in{/tr} {$totalSeconds|string_format:"%d"} {tr}seconds{/tr}{if $pendingCount} - {$pendingCount} {tr}to go{/tr}{/if}.</p>
		{elseif $doneCount}
			<p>{$doneCount} {tr}books asked so far;{/tr} {$changedCount} {tr}gained something.{/tr}</p>
		{/if}

		{form legend="Job" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_details.php" id="bookDetailsForm"}
			<input type="hidden" name="after" value="{$after|escape}" />
			<input type="hidden" name="done" value="{$doneCount|escape}" />
			<input type="hidden" name="changed" value="{$changedCount|escape}" />
			<input type="hidden" name="secs" value="{$totalSeconds|escape}" />
			<input type="hidden" name="fetch" value="1" />
			{if $more && $again}<input type="hidden" name="again" value="1" />{/if}
			{if $more}
				<input type="hidden" name="job" value="{$job|escape}" />
			{else}
				<div class="form-group">
					<label><input type="radio" name="job" value="details"{if $job eq 'details'} checked="checked"{/if} /> {tr}Load Missing Details{/tr} - {$counts.details} {tr}to do{/tr}</label>
					<span class="help-block">{tr}Every book with an ISBN, with a cover or without: whatever Open Library holds that is still blank - its edition id, a description, genres, links to Wikipedia, Goodreads and the like. Only the blanks are filled in; nothing entered is replaced. Covers are not looked for here - that is the next job.{/tr}</span>
					<label><input type="radio" name="job" value="covers"{if $job eq 'covers'} checked="checked"{/if} /> {tr}Find Missing Covers{/tr} - {$counts.covers} {tr}to do{/tr}</label>
					<span class="help-block">{tr}The books with no image: Open Library is asked for a cover (then Google Books), and the rest of what it holds is filled in too. A book no source has a cover for is passed over, ready for your own scan.{/tr}</span>
					{if $googleKey}
						<label><input type="radio" name="job" value="google"{if $job eq 'google'} checked="checked"{/if} /> {tr}Fill Gaps from Google Books{/tr} - {$counts.google} {tr}to do{/tr}</label>
						<span class="help-block">{tr}For what Open Library left blank - the description, genres, pages, language and publisher. Best run after Load Missing Details. Uses the Google Books key (Media Library Settings), about 1,000 lookups a day; it stops by itself when Google says the day's quota is used.{/tr}</span>
					{/if}
				</div>
			{/if}
			{if !$more}
				<div class="form-group">
					<label><input type="checkbox" name="again" value="1"{if $again} checked="checked"{/if} /> {tr}Ask again about books already checked{/tr}</label>
					<span class="help-block">{tr}Each book is asked about once, and then marked - a book Open Library has no description, genre or cover for does not come back every run. Tick this to go over them all again, for example after Open Library has been added to.{/tr}</span>
				</div>
			{/if}
			{if $pendingCount || !$more}
				<p>{if $more}{tr}Running - the page sends itself again until the list is done.{/tr}{/if}</p>
				<div class="form-group submit">
					<input type="submit" class="btn btn-primary" id="bookDetailsGo" value="{if $doneCount}{tr}Carry on{/tr}{else}{tr}Start{/tr}{/if}" />
					{if $more}<a class="btn btn-default" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_details.php">{tr}Stop{/tr}</a>{/if}
				</div>
			{else}
				<div class="alert alert-success"><p>{if $job eq 'covers'}{tr}Finished - every book that can have a cover from Open Library or Google Books has one.{/tr}{elseif $job eq 'google'}{tr}Finished - every book with a gap has been looked up on Google Books.{/tr}{else}{tr}Finished - every book with an ISBN has been looked at.{/tr}{/if}</p></div>
			{/if}
		{/form}
		<script>/* <![CDATA[ */
			( function() {
				var f = document.getElementById( 'bookDetailsForm' );
				if( !f ) { return; }
				var submitted = false;
				/* One press at a time: the button goes grey and says it is working, and a second submit is ignored. */
				f.onsubmit = function() {
					if( submitted ) { return false; }
					submitted = true;
					var b = document.getElementById( 'bookDetailsGo' );
					if( b ) { b.disabled = true; b.value = '{tr}Working - a batch takes about 40 seconds...{/tr}'; }
					return true;
				};
				{if $more || $busy}
					setTimeout( function() { if( !submitted ) { f.onsubmit(); f.submit(); } }, {if $busy}8000{else}1500{/if} );
				{/if}
			} )();
		/* ]]> */</script>

		{if $noIsbn}
			<p class="help-block">{$noIsbn} {tr}books have no ISBN, so Open Library cannot be asked about them by ISBN - they need their details entered by hand, or an ISBN added first.{/tr}</p>
		{/if}

		{if $noCoverCount}
			{if $showNoCover}
			<h3>{tr}Books without a cover{/tr} ({$noCoverCount})</h3>
			<p class="help-block">{tr}No image at all - add your own photograph or scan from the book's edit page; the first image added becomes its tile.{/tr}{if $noCoverCount > $noCoverShown} {tr}Showing the first{/tr} {$noCoverShown}.{/if}</p>
			<table class="table table-condensed">
				<thead><tr><th>{tr}Title{/tr}</th><th>{tr}Author{/tr}</th><th>{tr}Year{/tr}</th><th>{tr}ISBN{/tr}</th><th></th></tr></thead>
				<tbody>
					{foreach from=$noCover item=book}
						<tr>
							<td><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}view_book.php?content_id={$book.content_id}">{$book.title|escape}</a></td>
							<td>{foreach from=$book.authors item=author name=au}{$author|escape}{if !$smarty.foreach.au.last}, {/if}{/foreach}</td>
							<td>{$book.year|escape}</td>
							<td>{$book.isbn|escape}</td>
							<td><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}edit_book.php?content_id={$book.content_id}">{tr}Edit{/tr}</a></td>
						</tr>
					{/foreach}
				</tbody>
			</table>

			{else}
				<p class="help-block">{$noCoverCount} {tr}books have no cover - the Covers job above looks for one; any it cannot find wait for your own photograph or scan.{/tr} <a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}load_book_details.php?nocover=1">{tr}List them{/tr}</a></p>
			{/if}
		{/if}

	</div>
</div>
{/strip}

{* Look up details for one Book - see look_up_details.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin fisheye">
	<div class="header">
		<h1>{tr}Look up details{/tr}: <a href="{$gContent->getEditUrl()|escape}">{$gContent->getTitle()|escape}</a></h1>
	</div>

	<div class="body">
		{if $message}<div class="alert alert-success"><p>{$message|escape}</p></div>{/if}
		{formfeedback error=$errors}

		{if $isbnHint}
			<div class="alert alert-warning">{$isbnHint|escape} {tr}Use Available information below to compare the details with the edition it names.{/tr}</div>
		{/if}

		{legend legend="Find the edition"}
			{form method="get"}
				<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
				<div class="form-group">
					{formlabel label="Add to the search" for="extra"}
					{forminput}
						<input type="text" class="form-control" name="extra" id="extra" value="{$extra|escape}" placeholder="{tr}more words, e.g. D&amp;D 4th, or an author{/tr}" />
					{/forminput}
				</div>
				<input type="submit" class="btn btn-default" value="{tr}Update the search{/tr}" />
			{/form}
			<p>
				<a href="https://www.abebooks.co.uk/servlet/SearchResults?tn={$titleTerm}{if $extraTerm}&amp;kn={$extraTerm}{/if}" title="{tr}Lists every edition with its ISBN{/tr}" target="_blank" rel="noopener">AbeBooks</a> |
				<a href="https://openlibrary.org/search?q={$searchTerm}" target="_blank" rel="noopener">Open Library</a> |
				<a href="https://www.wikidata.org/w/index.php?search={$searchTerm}" title="{tr}Wikidata has an item for many sourcebooks and modules, with publisher and date; it rarely holds the ISBN{/tr}" target="_blank" rel="noopener">Wikidata</a> |
				<a href="https://www.google.com/search?q={$searchTerm}+isbn" target="_blank" rel="noopener">Google</a>
			</p>
		{/legend}

		{legend legend="ISBN"}
			{if $isbn13 ne '' || $isbn10 ne ''}
				<p>{if $isbn13 ne ''}{$isbn13|escape}{/if}{if $isbn10 ne ''} ({tr}ISBN-10{/tr} {$isbn10|escape}){/if}</p>
			{else}
				<p>{tr}This book has no ISBN. Search a bookseller for the edition (AbeBooks lists every edition with its ISBN), then put the number here.{/tr}</p>
			{/if}
			{form}
				<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
				<input type="hidden" name="extra" value="{$extra|escape}" />
				<div class="input-group">
					<input type="text" class="form-control" name="isbn" id="isbn" value="{if $isbn13 ne ''}{$isbn13|escape}{/if}" placeholder="{tr}ISBN-10 or ISBN-13{/tr}" />
					<span class="input-group-btn"><input type="submit" class="btn btn-primary" name="save_isbn" value="{if $isbn13 ne '' || $isbn10 ne ''}{tr}Change ISBN{/tr}{else}{tr}Add ISBN{/tr}{/if}" /></span>
				</div>
				{if $isbn13 ne '' || $isbn10 ne ''}<p class="help-block">{tr}Changing it to another edition changes what the lookup below finds; you choose what to take before anything is replaced.{/tr}</p>{/if}
			{/form}
		{/legend}

		{legend legend="Wikidata"}
			{if $wikidata ne ''}<p><a href="https://www.wikidata.org/wiki/{$wikidata|escape:'url'}" target="_blank" rel="noopener">{$wikidata|escape}</a></p>{/if}
			{form}
				<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
				<input type="hidden" name="extra" value="{$extra|escape}" />
				<div class="input-group">
					<input type="text" class="form-control" name="wikidata" id="wikidata" value="{$wikidata|escape}" placeholder="{tr}the item's number (Q...) or its address{/tr}" />
					<span class="input-group-btn"><input type="submit" class="btn btn-primary" name="save_wikidata" value="{if $wikidata ne ''}{tr}Change{/tr}{else}{tr}Add{/tr}{/if}" /></span>
				</div>
				<p class="help-block">{tr}Found it with the Wikidata search above? Paste its address or its Q number here; it is kept as the book's Wikidata link.{/tr}</p>
			{/form}
		{/legend}

		<p>
			{if $isbn13 ne '' || $isbn10 ne ''}<a class="btn btn-primary" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}available_info.php?content_id={$gContent->mContentId}">{tr}Available information{/tr}</a>&nbsp;{/if}
			<a class="btn btn-default" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}pick_cover.php?content_id={$gContent->mContentId}">{tr}Find a Cover{/tr}</a>
		</p>
	</div>
</div>
{/strip}

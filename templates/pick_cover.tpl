{* Cover picker for one book - see pick_cover.php's own docblock. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin fisheye">
	<div class="header">
		<h1>{tr}Find a Cover{/tr}: <a href="{$gContent->getEditUrl()|escape}">{$gContent->getTitle()|escape}</a></h1>
	</div>

	<div class="body">
		{if $message}<div class="alert alert-success"><p>{$message|escape} <a href="{$gContent->getDisplayUrl()|escape}">{tr}View the book{/tr}</a></p></div>{/if}
		{formfeedback error=$errors}

		{legend legend="Covers found for this book"}
			{if !$hasIsbn}
				<p>{tr}This book has no ISBN, so there is nothing to look up. Add one (a bookseller's page such as AbeBooks usually gives it) and the covers are looked for, or paste an address or upload a scan below.{/tr}</p>
				{form}
					<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
					<div class="form-group">
						{formlabel label="ISBN" for="isbn"}
						{forminput}
							<input type="text" class="form-control" name="isbn" id="isbn" value="" placeholder="ISBN-10 or ISBN-13" />
						{/forminput}
					</div>
					<div class="form-group submit">
						<input type="submit" class="btn btn-primary" name="add_isbn" value="{tr}Add ISBN{/tr}" />
					</div>
				{/form}
			{elseif !$shown}
				<p>{tr}Open Library, Google Books and Amazon have no cover for this ISBN.{/tr}</p>
			{else}
				{form}
					<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
					<div class="media-strip">
						{foreach from=$shown item=one}
							<div class="media-grid-item">
								<img src="{$one.preview}" alt="{$one.label|escape}" /><br />
								<strong>{$one.label|escape}</strong><br />
								{$one.width} x {$one.height} px, {$one.kb} KB<br />
								<button type="submit" class="btn btn-primary" name="use" value="{$one.key|escape}">{tr}Use as cover{/tr}</button>
							</div>
						{/foreach}
					</div>
				{/form}
			{/if}
		{/legend}

		{if $hasIsbn}
			{form}
				<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
				<div class="form-group submit">
					<input type="submit" class="btn btn-secondary" name="reload_ol" value="{tr}Reload from Open Library{/tr}" />
					<small>{tr}Fills the format, genre, pages and description where they are blank. A cover is added only if the book has none.{/tr}</small>
				</div>
			{/form}
		{/if}

		{legend legend="A picture from a web address"}
			<p>{tr}Paste the address of a bookseller's page (or of the picture itself) and the pictures on it are listed.{/tr}</p>
			<p>
				<a href="https://www.amazon.co.uk/s?k={$searchTerm}" target="_blank" rel="noopener">Amazon</a> |
				<a href="https://www.abebooks.co.uk/servlet/SearchResults?{if $isbn13}isbn={$isbn13|escape:'url'}{else}tn={$titleTerm}{/if}" target="_blank" rel="noopener">AbeBooks</a> |
				<a href="https://www.google.com/search?tbm=isch&amp;q={$searchTerm}+book+cover" target="_blank" rel="noopener">Google Images</a> |
				<a href="https://openlibrary.org/search?q={$searchTerm}" target="_blank" rel="noopener">Open Library</a>
			</p>
			{form}
				<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
				<div class="form-group">
					{forminput}
						<input type="text" class="form-control" name="address" value="{$pasted|escape}" placeholder="https://..." />
					{/forminput}
				</div>
				<div class="form-group submit">
					<input type="submit" class="btn btn-secondary" name="look" value="{tr}Look{/tr}" />
				</div>
			{/form}
			{if $customs}
				<div class="media-strip">
					{foreach from=$customs item=one}
						{form}
							<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
							<input type="hidden" name="address" value="{$one.url|escape}" />
							<div class="media-grid-item">
								<img src="{$one.preview}" alt="" /><br />
								{$one.width} x {$one.height} px, {$one.kb} KB<br />
								<button type="submit" class="btn btn-primary" name="use" value="address">{tr}Use as cover{/tr}</button>
							</div>
						{/form}
					{/foreach}
				</div>
			{/if}
		{/legend}

		{legend legend="A scan or photograph from this computer"}
			<form method="post" enctype="multipart/form-data" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}pick_cover.php">
				<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
				<div class="form-group">
					<input type="file" name="scan" accept="image/*" />
				</div>
				<div class="form-group submit">
					<input type="submit" class="btn btn-primary" name="use_scan" value="{tr}Use as cover{/tr}" />
				</div>
			</form>
		{/legend}
		<p><small>{tr}A cover is kept at no more than 400 px, the size the Library shows. The book's other pictures are not touched.{/tr}</small></p>
	</div><!-- end .body -->
</div><!-- end .fisheye -->
{/strip}

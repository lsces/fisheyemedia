{* ISBN tags and file details for a folder archive - see archive_isbn.php, FisheyeArchiveIsbn and FisheyeArchiveMeta. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{$topTitle|escape} - {tr}ISBN tags and file details{/tr}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}
		{foreach from=$messages item=message}
			<div class="alert alert-success"><p>{$message|escape}</p></div>
		{/foreach}

		<p class="help-block"><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}docs_workbench.php?kind={$kind}">{tr}Back to the workbench{/tr}</a></p>

		{if $isMagazines}
			<h2>{tr}Issue number, volume and date from the file names{/tr}</h2>
			<p>{$issue.loaded} {tr}loaded issues{/tr}: {$issue.read} {tr}read{/tr}, {$issue.filled} {tr}given details{/tr}, <strong>{$issue.todo} {tr}still to read{/tr}</strong>.</p>
			<p class="help-block">{tr}A scan of a magazine carries no details of its own, but its name does: the issue number, the volume, the cover date. Each loaded issue's name (and the year or volume folder it is in) is read and the number, volume and date are added to the issue, which also sets the date it is sorted by. Nothing an issue already holds is replaced. Index volumes and extras are marked as such and given no number.{/tr}</p>
			{if $issue.todo}
				{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`archive_isbn.php" id="issueForm"}
					<input type="hidden" name="kind" value="{$kind}" />
					<label><input type="checkbox" name="keep_going" value="1"{if $keepGoingChecked} checked="checked"{/if} /> {tr}Keep going{/tr}</label>
					<input type="submit" class="btn btn-primary" name="tag_issue" value="{tr}Read and add details{/tr} ({$issue.todo} {tr}left{/tr})" />
					{if $issueContinue}<a class="btn btn-default" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}archive_isbn.php?kind={$kind}">{tr}Stop{/tr}</a>{/if}
				{/form}
				{if $issueContinue}
					<p class="help-block"><em>{tr}Reading the next press...{/tr}</em></p>
					<script>window.setTimeout( function() { var f = document.getElementById( 'issueForm' ); if( f ) { var b = document.createElement( 'input' ); b.type = 'hidden'; b.name = 'tag_issue'; b.value = '1'; f.appendChild( b ); f.submit(); } }, 400 );</script>
				{/if}
			{else}
				<p><strong>{tr}Every loaded issue has been read.{/tr}</strong></p>
			{/if}
			{if $index.titles}
				<h2>{tr}Index sheets{/tr}</h2>
				<p>{foreach from=$index.titles key=title item=t name=ix}{$title|escape}: {$t.sheet} {tr}issues in the sheet{/tr}, {$t.issues} {tr}loaded{/tr}{if !$smarty.foreach.ix.last}; {/if}{/foreach}. <strong>{$index.todo} {tr}still to apply{/tr}</strong> ({$index.filled} {tr}given details{/tr}).</p>
				<p class="help-block">{tr}A title's folder may hold an IssueIndex.csv (columns Issue, Volume, Published, Publisher, Price, Frequency, Title as printed): the facts a file name cannot give. Each issue is matched to its row by issue number and only what it lacks is added. An issue that is not in the sheet is marked and left.{/tr}</p>
				{if $index.todo}
					{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`archive_isbn.php" id="indexForm"}
						<input type="hidden" name="kind" value="{$kind}" />
						<label><input type="checkbox" name="keep_going" value="1"{if $keepGoingChecked} checked="checked"{/if} /> {tr}Keep going{/tr}</label>
						<input type="submit" class="btn btn-primary" name="apply_index" value="{tr}Apply the index sheets{/tr} ({$index.todo} {tr}left{/tr})" />
						{if $indexContinue}<a class="btn btn-default" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}archive_isbn.php?kind={$kind}">{tr}Stop{/tr}</a>{/if}
					{/form}
					{if $indexContinue}
						<p class="help-block"><em>{tr}Reading the next press...{/tr}</em></p>
						<script>window.setTimeout( function() { var f = document.getElementById( 'indexForm' ); if( f ) { var b = document.createElement( 'input' ); b.type = 'hidden'; b.name = 'apply_index'; b.value = '1'; f.appendChild( b ); f.submit(); } }, 400 );</script>
					{/if}
				{else}
					<p><strong>{tr}Every issue with a sheet has been applied.{/tr}</strong></p>
				{/if}
			{/if}
		{else}
		{if $plainBooks}<div class="alert alert-warning"><p>{$plainBooks} {tr}loaded PDFs and epubs are still plain Images and are not read here. Make them Books on the workbench front page first.{/tr}</p></div>{/if}
		<p>{$st.loaded} {tr}loaded books{/tr}: {$st.read} {tr}read{/tr}, {$st.tagged} {tr}with an ISBN{/tr}, <strong>{$st.todo} {tr}still to read{/tr}</strong>.</p>
		<p class="help-block">{tr}Each PDF and epub is read where it is kept and the first valid ISBN it gives is added to the item as its ISBN row, for looking up the book's details later. A file that already has an ISBN keeps it; a file is only read once. A press reads at most{/tr} {$batch} {tr}files; Keep going works for about{/tr} {$keepSeconds} {tr}seconds, then sends the next press by itself until none are left.{/tr}</p>
		{if $st.todo}
			{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`archive_isbn.php" id="isbnForm"}
				<input type="hidden" name="kind" value="{$kind}" />
				<label><input type="checkbox" name="keep_going" value="1"{if $keepGoingChecked} checked="checked"{/if} /> {tr}Keep going{/tr}</label>
				<input type="submit" class="btn btn-primary" name="tag_isbn" value="{tr}Read and tag{/tr} ({$st.todo} {tr}left{/tr})" />
				{if $continue}<a class="btn btn-default" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}archive_isbn.php?kind={$kind}">{tr}Stop{/tr}</a>{/if}
			{/form}
			{if $continue}
				<p class="help-block"><em>{tr}Reading the next press...{/tr}</em></p>
				<script>window.setTimeout( function() { var f = document.getElementById( 'isbnForm' ); if( f ) { var b = document.createElement( 'input' ); b.type = 'hidden'; b.name = 'tag_isbn'; b.value = '1'; f.appendChild( b ); f.submit(); } }, 800 );</script>
			{/if}
		{else}
			<p><strong>{tr}Every loaded file has been read.{/tr}</strong></p>
		{/if}

		<h2>{tr}People, publisher and date from the files{/tr}</h2>
		<p>{$meta.loaded} {tr}loaded books{/tr}: {$meta.read} {tr}read{/tr}, {$meta.filled} {tr}given details they lacked{/tr}, <strong>{$meta.todo} {tr}still to read{/tr}</strong>.</p>
		<p class="help-block">{tr}Each PDF and epub is read where it is kept. The authors (and editors, illustrators, translators and narrators an epub names), the publisher and the date of the copy are added to the book, names exactly as written; placeholders such as "unknown" are skipped. Nothing the book already holds is replaced, and a book that has people, a publisher or a date keeps them.{/tr}</p>
		{if $meta.todo}
			{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`archive_isbn.php" id="metaForm"}
				<input type="hidden" name="kind" value="{$kind}" />
				<label><input type="checkbox" name="keep_going" value="1"{if $keepGoingChecked} checked="checked"{/if} /> {tr}Keep going{/tr}</label>
				<input type="submit" class="btn btn-primary" name="tag_meta" value="{tr}Read and add details{/tr} ({$meta.todo} {tr}left{/tr})" />
				{if $metaContinue}<a class="btn btn-default" href="{$smarty.const.FISHEYEMEDIA_PKG_URL}archive_isbn.php?kind={$kind}">{tr}Stop{/tr}</a>{/if}
			{/form}
			{if $metaContinue}
				<p class="help-block"><em>{tr}Reading the next press...{/tr}</em></p>
				<script>window.setTimeout( function() { var f = document.getElementById( 'metaForm' ); if( f ) { var b = document.createElement( 'input' ); b.type = 'hidden'; b.name = 'tag_meta'; b.value = '1'; f.appendChild( b ); f.submit(); } }, 400 );</script>
			{/if}
		{else}
			<p><strong>{tr}Every loaded file has been read.{/tr}</strong></p>
		{/if}
		{/if}
	</div>
</div>
{/strip}

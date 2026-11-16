{* ISBN tags for a folder archive - see archive_isbn.php and FisheyeArchiveIsbn. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{$topTitle|escape} - {tr}ISBN tags{/tr}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}
		{foreach from=$messages item=message}
			<div class="alert alert-success"><p>{$message|escape}</p></div>
		{/foreach}

		<p class="help-block"><a href="{$smarty.const.FISHEYEMEDIA_PKG_URL}docs_workbench.php?kind={$kind}">{tr}Back to the workbench{/tr}</a></p>

		<p>{$st.loaded} {tr}loaded files{/tr}: {$st.read} {tr}read{/tr}, {$st.tagged} {tr}with an ISBN{/tr}, <strong>{$st.todo} {tr}still to read{/tr}</strong>.</p>
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
	</div>
</div>
{/strip}

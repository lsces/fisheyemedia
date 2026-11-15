{* The view of an epub kept in place (mimeextepub), as liberty/mime/pdf/view.tpl is for a PDF: the site's epub.js reader (themes/js/epubjs), fed the
   file through liberty's download_file.php, which does the permission check and serves it from where it is. A search term (highlight) is found in it. *}
{strip}
{assign var=epubAttachmentId value=$gContent->getField('attachment_id')}
{if $epubAttachmentId}
	<iframe id="epub-viewer" src="{$smarty.const.THEMES_PKG_URL}js/epubjs-0.3.93/reader.html?file={"`$smarty.const.LIBERTY_PKG_URL`download_file.php?attachment_id=`$epubAttachmentId`"|escape:'url'}{if !empty($highlight)}&amp;search={$highlight|escape:'url'}{/if}" width="100%" style="height:85vh; border:0"></iframe>
	{include file="bitpackage:liberty/mime_meta_inc.tpl"}
{else}
	{include file=$gLibertySystem->getMimeTemplate('view', $smarty.const.LIBERTY_DEFAULT_MIME_HANDLER)}
{/if}
{/strip}

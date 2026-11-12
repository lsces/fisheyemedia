{* Apply a gallery's access to everything inside it - see apply_access.php. *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Gallery access{/tr}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}
		{foreach from=$messages item=message}
			<div class="alert alert-success"><p>{$message|escape}</p></div>
		{/foreach}

		<p>{tr}Restricting a gallery to a role (its Edit page) hides only the gallery's own page: the galleries and items inside keep their own access, so their pages and the search results stay open. This gives everything inside a gallery the gallery's own roles. New items loaded into a restricted gallery by the workbenches, and items uploaded into it, get its roles automatically; this is for what was there before.{/tr}</p>

		{form legend="" action="`$smarty.const.FISHEYEMEDIA_PKG_URL`apply_access.php"}
			<table class="table table-condensed">
				<thead><tr><th>{tr}Top-level gallery{/tr}</th><th>{tr}Restricted to{/tr}</th><th>{tr}Inside{/tr}</th><th>{tr}With different access{/tr}</th><th></th></tr></thead>
				<tbody>
					{foreach from=$rows item=row}
						<tr>
							<td>{$row.title|escape}</td>
							<td>{if $row.roles}{$row.roles|escape}{else}<span class="text-muted">{tr}everyone who can view it{/tr}</span>{/if}</td>
							<td>{$row.inside}</td>
							<td>{if $row.differ}<strong>{$row.differ}</strong>{else}<span class="text-muted">0</span>{/if}</td>
							<td>{if $row.differ}<input type="submit" class="btn btn-default btn-sm" name="apply_access[{$row.contentId}]" value="{tr}Apply to everything inside{/tr}" />{/if}</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
		{/form}
	</div>
</div>
{/strip}

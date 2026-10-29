{* The catalogue spreadsheet upload, shared by the Load Books and Authors pages - both read the newest archived sheet. *}
{if $import}
	{if $import.error}
		<div class="alert alert-danger"><p>{$import.error|escape}</p></div>
	{else}
		<div class="alert alert-success"><p>{tr}Catalogue read{/tr}: {$import.rows} {tr}books{/tr}.</p></div>
	{/if}
{/if}

{form legend="Catalogue spreadsheet" enctype="multipart/form-data"}
		<div class="form-group">
			{formlabel label="Spreadsheet (.ods) or .csv" for="catalogue_file"}
			{forminput}
				<input type="file" name="catalogue_file" id="catalogue_file" accept=".ods,.csv" />
				{formhelp note="A 'Books' sheet (or a .csv with a header row) with one row per book - Title is the only required column. Each upload is archived and the newest is the one loaded from; books already loaded are recognised and not loaded twice."}
			{/forminput}
		</div>
		<div class="form-group submit">
			<input type="submit" class="btn btn-default" name="upload" value="{tr}Read Catalogue{/tr}" />
		</div>
{/form}

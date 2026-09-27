{* Admin: fisheyemedia's own storage-path/Plex settings - moved off fisheye's General Settings tab *}
{strip}
<div class="floaticon">{bithelp}</div>

<div class="admin liberty">
	<div class="header">
		<h1>{tr}Media Library Settings{/tr}</h1>
	</div>

	<div class="body">

		{form legend="" action="{$smarty.const.FISHEYEMEDIA_PKG_URL}admin/admin_fisheyemedia_settings.php"}
			{foreach from=$formFisheyeMediaGeneral key=item item=output}
				<div class="form-group">
					{formlabel label=$output.label for=$item}
					{forminput}
						<input type="text" class="form-control" name="{$item}" id="{$item}" value="{$gBitSystem->getConfig($item)}" />
						{formhelp note=$output.note}
					{/forminput}
				</div>
			{/foreach}
			<input type="submit" class="btn btn-primary" name="fisheyemediaGeneralSubmit" value="{tr}Change Preferences{/tr}" />
		{/form}

	</div>
</div>
{/strip}

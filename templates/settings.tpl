{**
 * templates/settings.tpl
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Plugin settings form.
 *}
<script>
    $(function() {ldelim}
    // Attach the form handler.
    $('#fileIntegritySettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
    {rdelim});
</script>

{* Tombol Pemindaian Manual *}
<div class="pkp_form" id="manualScan">
    {fbvFormSection}
    <p>{translate key="plugins.generic.fileIntegrity.settings.scan.description"}</p>
    <a href="{$scanUrl}" class="pkp_button">{translate key="plugins.generic.fileIntegrity.settings.scan.button"}</a>
    {/fbvFormSection}
</div>

<hr>

{* Form Pengaturan *}
<form class="pkp_form" id="fileIntegritySettingsForm" method="post"
    action="{url op="manage" verb="settings" category="generic" plugin=$pluginName}">
    {csrf}

    {include file="common/formErrors.tpl"}

    <p>{translate key="plugins.generic.fileIntegrity.settings.introduction"}</p>

    {fbvFormSection}
    {fbvElement
			type="textarea"
			id="excludedPaths"
			label="plugins.generic.fileIntegrity.settings.excludedPaths"
			value=$excludedPaths
			rich=false
			rows=10
			description="plugins.generic.fileIntegrity.settings.excludedPaths.description"
		}
    {/fbvFormSection}

    {fbvFormButtons submitText="common.save"}
</form>
{**
 * @file plugins/generic/ashFileIntegrity/templates/settings.tpl
 *
 * Copyright (c) 2025 AshVisualTheme
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Pengaturan untuk File Integrity Plugin
 *}

<script>
    $(function() {ldelim}
    $('#ashFileIntegritySettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
    {rdelim});
</script>

<form class="pkp_form" id="ashFileIntegritySettings" method="POST"
    action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
    {csrf}

    <p>{translate key="plugins.generic.fileIntegrity.settings.description"}</p>

    {fbvFormSection label="plugins.generic.fileIntegrity.settings.manualExcludeLabel"}
    <p>{translate key="plugins.generic.fileIntegrity.settings.manualExcludeInfo"}</p>
    {fbvElement type="textarea" id="manualExcludeValue" value=$manualExcludeValue|escape rich=false}
    {/fbvFormSection}

    {fbvFormButtons submitText="common.save"}
</form>
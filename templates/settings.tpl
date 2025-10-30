{**
 * templates/settings.tpl
 *
 * Copyright (c) 2025 AshVisualTheme
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Settings form for the pluginTemplate plugin.
 *}

<script>
    $(function() {ldelim}
    $('#ashFileIntegritySettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
    {rdelim});
</script>

<form class="pkp_form" id="ashFileIntegritySettings" method="POST"
    action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
    {csrf}
    <div class="section">
        <p>{translate key="plugins.generic.fileIntegrity.settings.description"}</p>
    </div>

    {fbvFormSection title="plugins.generic.fileIntegrity.settings.additionalEmails"}
    <p>{translate key="plugins.generic.fileIntegrity.settings.additionalEmailsInfo"}</p>
    {fbvElement type="textarea" id="additionalEmails" value=$additionalEmails|escape rich=false label="plugins.generic.fileIntegrity.settings.additionalEmails.description"}
    {/fbvFormSection}

    {fbvFormSection title="plugins.generic.fileIntegrity.settings.manualExcludes"}
    <p>{translate key="plugins.generic.fileIntegrity.settings.manualExcludeInfo"}</p>
    {fbvElement type="textarea" id="manualExcludes" value=$manualExcludes|escape rich=false label="plugins.generic.fileIntegrity.settings.manualExcludes.description"}
    {/fbvFormSection}

    {fbvFormButtons submitText="common.save"}
</form>
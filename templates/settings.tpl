{**
 * templates/settings.tpl
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
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

    {fbvFormSection title="plugins.generic.fileIntegrity.settings.manualExcludes"}
    {fbvElement type="textarea" id="manualExcludes" value=$manualExcludes|escape rich=false label="plugins.generic.fileIntegrity.settings.manualExcludes" description="plugins.generic.fileIntegrity.settings.manualExcludes.description"}
    {/fbvFormSection}

    {fbvFormButtons submitText="common.save"}
</form>
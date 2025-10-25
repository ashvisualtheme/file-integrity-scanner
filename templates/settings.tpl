{**
 * @file plugins/generic/ashFileIntegrity/templates/settings.tpl
 *}
<script>
    $(function() {ldelim}
    $('#fileIntegritySettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
    {rdelim});
</script>

<form class="pkp_form" id="fileIntegritySettingsForm" method="post" action="{plugin_url op="manage" save=true}">
    {csrf}

    {fbvFormArea id="excludedPathsArea"}
    {fbvFormSection
			title="plugins.generic.fileIntegrity.settings.excludedPaths.title"
			description="plugins.generic.fileIntegrity.settings.excludedPaths.description"
		}
    {fbvElement type="textarea" id="excludedPaths" value=$excludedPaths rich=false rows=10}
    {/fbvFormSection}
    {/fbvFormArea}

    {fbvFormButtons}
</form>
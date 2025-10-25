<script>
    $(function() {
        // Attach the form handler.
        $('#fileIntegritySettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
    });
</script>

<form class="pkp_form" id="fileIntegritySettingsForm" method="post" action="{plugin_url path="settings"}">
    {csrf}

    <p>{translate key="plugins.generic.fileIntegrity.settings.description"}</p>

    {fbvFormArea id="excludedPaths"}
    {fbvFormSection title="plugins.generic.fileIntegrity.settings.excludedPaths.title" for="excludedPaths" description="plugins.generic.fileIntegrity.settings.excludedPaths.description"}
    {fbvTextarea id="excludedPaths" value=$excludedPaths rows=10}
    {/fbvFormSection}
    {/fbvFormArea}

    <p>
        <a href="{$scanUrl}" class="pkp_button"
            id="manualScanButton">{translate key="plugins.generic.fileIntegrity.scan.run"}</a>
    </p>

    {fbvFormButtons hideCancel=true}
</form>
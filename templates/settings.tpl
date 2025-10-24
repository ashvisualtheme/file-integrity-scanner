{**
 * @file plugins/generic/ashFileIntegrity/templates/settings.tpl
 *}
<script>
    $(function() {
        // Handle baseline creation
        $('#createBaselineBtn').pkpHandler('$.pkp.controllers.linkAction.LinkActionHandler', {
            actionRequest: new $.pkp.classes.linkAction.request.AjaxRequest(
                '{$createBaselineUrl|escape:"javascript"}',
                {
                    method: 'POST',
                    success: function(junk, result) {
                        alert(result.content);
                        // Reload the modal to show the new 'last created' date
                        $('.pkp_modal_close').click();
                        $('a[id="settings-plugins-generic-fileIntegrity-index-php"]').click();
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert('An error occurred: ' + textStatus);
                    }
                }
            )
        });

        // Handle manual scan
        $('#runScanBtn').pkpHandler('$.pkp.controllers.linkAction.LinkActionHandler', {
            actionRequest: new $.pkp.classes.linkAction.request.AjaxRequest(
                '{$runScanUrl|escape:"javascript"}',
                {
                    method: 'POST',
                    success: function(junk, result) {
                        alert(result.content);
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert('An error occurred: ' + textStatus);
                    }
                }
            )
        });
    });
</script>

<div id="fileIntegritySettings">
    <p>{translate key="plugins.generic.fileIntegrity.settings.introduction"}</p>

    <h3>{translate key="plugins.generic.fileIntegrity.baseline.title"}</h3>
    <p>
        {if $lastCreated}
            {translate key="plugins.generic.fileIntegrity.baseline.lastCreated" lastCreated=$lastCreated}
        {else}
            {translate key="plugins.generic.fileIntegrity.baseline.notCreated"}
        {/if}
    </p>
    <p>{translate key="plugins.generic.fileIntegrity.baseline.create.description"}</p>
    {load_url_in_div id="baselineCreatorContainer"}
    {button id="createBaselineBtn" label="{translate key='plugins.generic.fileIntegrity.baseline.create'}"}
    {/load_url_in_div}

    <br /><br />

    <h3>{translate key="plugins.generic.fileIntegrity.scan.title"}</h3>
    <p>{translate key="plugins.generic.fileIntegrity.scan.run.description"}</p>
    {load_url_in_div id="scanRunnerContainer"}
    {button id="runScanBtn" label="{translate key='plugins.generic.fileIntegrity.scan.run'}"}
    {/load_url_in_div}
</div>
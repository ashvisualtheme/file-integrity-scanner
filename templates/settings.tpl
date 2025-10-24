{**
 * @file plugins/generic/ashFileIntegrity/templates/settings.tpl
 *}
<script>
    $(function() {
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
    <p>This tool compares your OJS installation against the official file repository to detect unauthorized changes. The
        results will be sent to the site administrator's email.</p>

    <br />

    <h3>{translate key="plugins.generic.fileIntegrity.scan.title"}</h3>
    <p>{translate key="plugins.generic.fileIntegrity.scan.run.description"}</p>
    {load_url_in_div id="scanRunnerContainer"}
    {button id="runScanBtn" label="{translate key='plugins.generic.fileIntegrity.scan.run'}"}
    {/load_url_in_div}
</div>
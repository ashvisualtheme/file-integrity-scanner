<script>
    $(function() {
        // Fungsi untuk menginisialisasi handler tombol
        function initializeScanButtonHandler() {
            var $btn = $('#runScanBtn');
            // Hanya inisialisasi jika belum pernah dilakukan
            if ($btn.data('pkp-handler-initialized')) {
                return;
            }
            $btn.pkpHandler('$.pkp.controllers.linkAction.LinkActionHandler', {
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
            $btn.data('pkp-handler-initialized', true);
        }

        // Panggil fungsi inisialisasi saat konten modal dimuat
        $('#fileIntegritySettings').on('pkp-contents-loaded', initializeScanButtonHandler);

        // Panggil juga untuk berjaga-jaga jika event tidak ter-trigger
        initializeScanButtonHandler();
    });
</script>

<div id="fileIntegritySettings">
    <p>{translate key="plugins.generic.fileIntegrity.settings.introduction"}</p>
    <p>This tool compares your OJS installation against the official file repository to detect unauthorized changes. The
        results will be sent to the site administrator's email.</p>

    <br />

    <h3>{translate key="plugins.generic.fileIntegrity.scan.title"}</h3>
    <p>{translate key="plugins.generic.fileIntegrity.scan.run.description"}</p>

    <button id="runScanBtn" class="pkp_button">{translate key='plugins.generic.fileIntegrity.scan.run'}</button>

</div>
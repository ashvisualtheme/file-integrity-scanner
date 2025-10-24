<?php

import('classes.handler.Handler');
// --- PERBAIKAN DI SINI: Mengimpor kelas-kelas yang diperlukan untuk otorisasi ---
import('lib.pkp.classes.security.authorization.PolicySet');
import('lib.pkp.classes.security.authorization.RoleBasedHandlerOperationPolicy');

class FileIntegrityHandler extends Handler
{
    /**
     * @copydoc PKPHandler::authorize()
     * Metode ini akan dipanggil oleh OJS sebelum method lain di handler ini.
     * Kita akan memeriksa apakah pengguna adalah seorang Manager di sini.
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        // Membuat kebijakan yang hanya mengizinkan peran MANAGER
        $this->addPolicy(new RoleBasedHandlerOperationPolicy($request, ROLE_ID_MANAGER, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * Menjalankan pemindaian file.
     * @param array $args
     * @param PKPRequest $request
     * @return JSONMessage
     */
    public function runScan($args, $request)
    {
        error_log('FileIntegrityHandler: runScan() method EXECUTED successfully.'); // DEBUG

        $plugin = PluginRegistry::getPlugin('generic', 'ashfileintegrity');
        $plugin->import('classes.FileIntegrityScanScheduledTask');

        $task = new FileIntegrityScanScheduledTask();
        $task->executeActions();

        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification($request->getUser()->getId(), NOTIFICATION_TYPE_SUCCESS, ['contents' => __('plugins.generic.fileIntegrity.scan.success')]);

        return new JSONMessage(true);
    }
}

<?php

/**
 * @file plugins/generic/ashFileIntegrity/FileIntegrityHandler.inc.php
 */

import('classes.handler.Handler');

class FileIntegrityHandler extends Handler
{
    public function __construct()
    {
        parent::__construct();
        // Mendaftarkan 'runScan' sebagai operasi yang diizinkan untuk Manager
        $this->addRoleAssignment([ROLE_ID_MANAGER], ['runScan']);
    }

    /**
     * Menjalankan pemindaian dan mengembalikan notifikasi.
     * @param array $args
     * @param PKPRequest $request
     * @return JSONMessage
     */
    public function runScan($args, $request)
    {
        // Import dan jalankan tugas pemindaian
        $plugin = PluginRegistry::getPlugin('generic', 'ashfileintegrity');
        $plugin->import('classes.FileIntegrityScanScheduledTask');
        $task = new FileIntegrityScanScheduledTask();
        $task->executeActions();

        // Kirim notifikasi sukses kembali ke browser
        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification($request->getUser()->getId(), NOTIFICATION_TYPE_SUCCESS, ['contents' => __('plugins.generic.fileIntegrity.scan.success')]);

        // Kembalikan JSON yang menandakan oeprasi selesai
        return new JSONMessage(true);
    }
}

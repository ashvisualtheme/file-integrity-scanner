<?php

import('classes.handler.Handler');

class FileIntegrityHandler extends Handler
{
    public function __construct()
    {
        parent::__construct();
        $this->addRoleAssignment([ROLE_ID_MANAGER], ['runScan']);
    }

    public function runScan($args, $request)
    {
        // Dapatkan objek plugin saat ini
        $plugin = PluginRegistry::getPlugin('generic', 'ashfileintegrityplugin'); // Sesuaikan dengan nama direktori plugin
        $plugin->import('classes.FileIntegrityScanScheduledTask');

        $task = new FileIntegrityScanScheduledTask();
        $task->executeActions();

        // Kirim notifikasi sukses ke browser
        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification($request->getUser()->getId(), NOTIFICATION_TYPE_SUCCESS, ['contents' => __('plugins.generic.fileIntegrity.scan.success')]);

        return new JSONMessage(true);
    }
}

<?php

import('classes.handler.Handler');

class FileIntegrityHandler extends Handler
{
    public function __construct()
    {
        parent::__construct();
    }

    public function runScan($args, $request)
    {
        if (!Validation::isSiteAdmin()) {
            return new JSONMessage(false, 'Authorization failed: User is not a Site Administrator.');
        }

        import('plugins.generic.ashFileIntegrity.classes.FileIntegrityScanScheduledTask');
        $task = new FileIntegrityScanScheduledTask();
        $task->executeActions();

        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification($request->getUser()->getId(), NOTIFICATION_TYPE_SUCCESS, ['contents' => __('plugins.generic.fileIntegrity.scan.success')]);

        return new JSONMessage(true);
    }
}

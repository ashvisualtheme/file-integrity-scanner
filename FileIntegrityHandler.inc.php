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
        error_log('FileIntegrityHandler: runScan() method ENTERED.'); // DEBUG

        // --- PEMERIKSAAN OTORISASI MANUAL ---
        $user = $request->getUser();
        if (!$user) {
            error_log('FileIntegrityHandler: Authorization FAILED - No user logged in.'); // DEBUG
            return new JSONMessage(false, 'Authorization failed: User not logged in.');
        }

        $userGroupDao = DAORegistry::getDAO('UserGroupDAO');
        $userGroups = $userGroupDao->getByUserId($user->getId());

        $isManager = false;
        while ($userGroup = $userGroups->next()) {
            if ($userGroup->getRoleId() == ROLE_ID_MANAGER) {
                $isManager = true;
                break;
            }
        }

        if (!$isManager) {
            error_log('FileIntegrityHandler: Authorization FAILED - User is not a Manager.'); // DEBUG
            return new JSONMessage(false, 'Authorization failed: User is not a Manager.');
        }

        error_log('FileIntegrityHandler: Authorization SUCCESS.'); // DEBUG
        // --- AKHIR PEMERIKSAAN OTORISASI ---

        import('plugins.generic.ashFileIntegrity.classes.FileIntegrityScanScheduledTask');
        error_log('FileIntegrityHandler: FileIntegrityScanScheduledTask class imported.'); // DEBUG

        $task = new FileIntegrityScanScheduledTask();
        error_log('FileIntegrityHandler: Task object created. Starting executeActions().'); // DEBUG

        // --- PERUBAHAN DI SINI ---
        // true menandakan pemindaian manual, yang akan memaksa cache di-refresh.
        $task->executeActions(true);
        // --- AKHIR PERUBAHAN ---

        error_log('FileIntegrityHandler: executeActions() finished.'); // DEBUG

        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification($request->getUser()->getId(), NOTIFICATION_TYPE_SUCCESS, ['contents' => __('plugins.generic.fileIntegrity.scan.success')]);
        error_log('FileIntegrityHandler: Success notification created.'); // DEBUG

        return new JSONMessage(true);
    }
}

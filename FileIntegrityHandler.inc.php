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
        $user = $request->getUser();
        if (!$user) {
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
            return new JSONMessage(false, 'Authorization failed: User is not a Manager.');
        }

        import('plugins.generic.ashFileIntegrity.classes.FileIntegrityScanScheduledTask');
        $task = new FileIntegrityScanScheduledTask();
        $task->executeActions(true);
        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification($request->getUser()->getId(), NOTIFICATION_TYPE_SUCCESS, ['contents' => __('plugins.generic.fileIntegrity.scan.success')]);

        return new JSONMessage(true);
    }
}

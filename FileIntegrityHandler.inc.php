<?php

import('classes.handler.Handler');

class FileIntegrityHandler extends Handler
{
    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $user = $request->getUser();
        if (!$user) {
            return false;
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
            return false;
        }

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
        // --- PERBAIKAN FINAL DI SINI ---
        // Menggunakan import() global dengan path lengkap ke kelas.
        // Ini adalah cara yang paling andal dan tidak bergantung pada PluginRegistry.
        import('plugins.generic.ashFileIntegrity.classes.FileIntegrityScanScheduledTask');

        $task = new FileIntegrityScanScheduledTask();
        $task->executeActions();

        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification($request->getUser()->getId(), NOTIFICATION_TYPE_SUCCESS, ['contents' => __('plugins.generic.fileIntegrity.scan.success')]);

        return new JSONMessage(true);
    }
}

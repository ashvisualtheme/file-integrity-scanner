<?php

import('classes.handler.Handler');

class FileIntegrityHandler extends Handler
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Menjalankan pemindaian integritas file.
     */
    public function runScan($args, $request)
    {
        if (!$this->_isUserAdmin($request)) {
            return new JSONMessage(false, 'Authorization failed.');
        }

        import('plugins.generic.ashFileIntegrity.classes.FileIntegrityScanScheduledTask');
        $task = new FileIntegrityScanScheduledTask();
        $task->executeActions();

        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification($request->getUser()->getId(), NOTIFICATION_TYPE_SUCCESS, ['contents' => __('plugins.generic.fileIntegrity.scan.success')]);

        return new JSONMessage(true);
    }

    /**
     * Membersihkan cache JSON milik plugin ini. (FUNGSI BARU)
     */
    public function clearCache($args, $request)
    {
        if (!$this->_isUserAdmin($request)) {
            return new JSONMessage(false, 'Authorization failed.');
        }

        $cacheDir = Core::getBaseDir() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'integrityFilesScan';

        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . DIRECTORY_SEPARATOR . '*.json');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        $notificationManager = new NotificationManager();
        $notificationManager->createTrivialNotification($request->getUser()->getId(), NOTIFICATION_TYPE_SUCCESS, ['contents' => __('plugins.generic.fileIntegrity.cache.clear.success')]);

        return new JSONMessage(true);
    }

    /**
     * Memeriksa apakah pengguna adalah seorang admin/manager.
     * @return bool
     */
    private function _isUserAdmin($request)
    {
        $user = $request->getUser();
        if (!$user) {
            return false;
        }

        $userGroupDao = DAORegistry::getDAO('UserGroupDAO');
        $userGroups = $userGroupDao->getByUserId($user->getId());

        while ($userGroup = $userGroups->next()) {
            if (in_array($userGroup->getRoleId(), [ROLE_ID_MANAGER, ROLE_ID_SITE_ADMIN])) {
                return true;
            }
        }

        return false;
    }
}

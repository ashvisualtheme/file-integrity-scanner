<?php

/**
 * @file plugins/generic/ashFileIntegrity/AshFileIntegrityHandler.inc.php
 *
 * Copyright (c) 2025 AshVisualTheme
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AshFileIntegrityHandler
 * @ingroup plugins_generic_ashFileIntegrity
 *
 * @brief Handler for file integrity operations accessed via URL.
 */

import('classes.handler.Handler');
import('plugins.generic.ashFileIntegrity.classes.AshFileIntegrityScanScheduledTask');

class AshFileIntegrityHandler extends Handler
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Executes the file integrity scan.
     * Calls the scheduled task to initiate the scan and sends a success notification if the user is an admin/manager.
     *
     * @param array $args Arguments passed to the handler.
     * @param Request $request The request object.
     * @return JSONMessage
     */
    public function runScan($args, $request)
    {
        // Ensures the token CSRF is valid
        if ($request->checkCSRF() === false) {
            error_log('[AshFileIntegrityPlugin] CSRF check failed.');
            return new JSONMessage(false, 'Invalid CSRF token.');
        }

        // Ensures the user is an admin/manager before running the scan.
        if (!$this->_isUserAdmin($request)) {
            error_log('[AshFileIntegrityPlugin] Authorization failed. User is not an admin.');
            return new JSONMessage(false, 'Authorization failed.');
        }

        error_log('[AshFileIntegrityPlugin] User authorized. Creating scheduled task object.');
        $task = new AshFileIntegrityScanScheduledTask();
        $success = $task->executeActions(true);
        error_log('[AshFileIntegrityPlugin] executeActions completed. Success: ' . ($success ? 'true' : 'false'));

        $notificationManager = new NotificationManager();
        if ($success) {
            $notificationManager->createTrivialNotification(
                $request->getUser()->getId(),
                NOTIFICATION_TYPE_SUCCESS,
                ['contents' => __('plugins.generic.fileIntegrity.scan.success')]
            );
        } else {
            $notificationManager->createTrivialNotification(
                $request->getUser()->getId(),
                NOTIFICATION_TYPE_ERROR,
                ['contents' => __('plugins.generic.fileIntegrity.scan.error')]
            );
        }

        return new JSONMessage(true);
    }

    /**
     * Clears the plugin's JSON cache.
     * Deletes all .json files in the plugin's integrityFilesScan cache directory.
     *
     * @param array $args Arguments passed to the handler.
     * @param Request $request The request object.
     * @return JSONMessage
     */
    public function clearCache($args, $request)
    {
        // Ensures the token CSRF is valid
        if ($request->checkCSRF() === false) {
            return new JSONMessage(false, 'Invalid CSRF token.');
        }

        // Ensures the user is an admin/manager before clearing the cache.
        if (!$this->_isUserAdmin($request)) {
            return new JSONMessage(false, 'Authorization failed.');
        }

        // Defines the location of the cache directory.
        $cacheDir = Core::getBaseDir() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'integrityFilesScan';

        if (is_dir($cacheDir)) {
            // Finds all JSON files within the cache directory and deletes them.
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
     * Checks if the user is an admin/manager (ROLE_ID_MANAGER or ROLE_ID_SITE_ADMIN).
     *
     * @param Request $request The request object.
     * @return bool True if the user has an admin/manager role, false otherwise.
     */
    private function _isUserAdmin($request)
    {
        $user = $request->getUser();
        if (!$user) {
            return false;
        }

        // Retrieves the user's group list and checks if any group has the Manager or Site Admin role.
        $userGroupDao = DAORegistry::getDAO('UserGroupDAO');
        $userGroups = $userGroupDao->getByUserId($user->getId());

        while ($userGroup = $userGroups->next()) {
            // ROLE_ID_MANAGER is for Journal Manager, ROLE_ID_SITE_ADMIN is for Site Administrator.
            if (in_array($userGroup->getRoleId(), [ROLE_ID_MANAGER, ROLE_ID_SITE_ADMIN])) {
                return true;
            }
        }

        return false;
    }
}

<?php

/**
 * @file plugins/generic/ashFileIntegrity/AshFileIntegrityHandler.php
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

namespace APP\plugins\generic\ashFileIntegrity;

use APP\plugins\generic\ashFileIntegrity\classes\AshFileIntegrityScanScheduledTask;
use APP\handler\Handler;
use APP\notification\NotificationManager;
use PKP\core\Core;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\security\Role;
use PKP\security\Validation;

class AshFileIntegrityHandler extends Handler
{
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
        $authResult = $this->_authorizeRequest($request);
        if ($authResult !== true) {
            return $authResult;
        }

        $task = new AshFileIntegrityScanScheduledTask();
        $success = $task->executeActions(true);

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
        $authResult = $this->_authorizeRequest($request);
        if ($authResult !== true) {
            return $authResult;
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
     * Authorizes the request by checking CSRF and user roles.
     *
     * @param Request $request
     * @return bool|JSONMessage True on success, JSONMessage on failure.
     */
    private function _authorizeRequest($request)
    {
        // Validate the CSRF token
        if (!$request->checkCSRF()) {
            return new JSONMessage(false, __('form.csrfInvalid'));
        }

        // Ensures the user is a site administrator. The context is site-level (0).
        if (!Validation::isAuthorized(Role::ROLE_ID_SITE_ADMIN, PKPApplication::CONTEXT_SITE)) {
            return new JSONMessage(false, 'Authorization failed.');
        }
        return true;
    }
}

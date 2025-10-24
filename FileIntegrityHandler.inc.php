<?php

/**
 * @file plugins/generic/ashFileIntegrity/FileIntegrityHandler.inc.php
 */

import('classes.handler.Handler');
import('plugins.generic.fileIntegrity.classes.FileIntegrityScanScheduledTask');

class FileIntegrityHandler extends Handler
{

    public function __construct()
    {
        parent::__construct();
        $this->addRoleAssignment(
            [ROLE_ID_MANAGER],
            ['createBaseline', 'runScan']
        );
    }

    public function createBaseline($args, $request)
    {
        $task = new FileIntegrityScanScheduledTask();
        $fileCount = $task->createBaseline();

        return new JSONMessage(true, __('plugins.generic.fileIntegrity.baseline.success', ['fileCount' => $fileCount]));
    }

    public function runScan($args, $request)
    {
        $plugin = PluginRegistry::getPlugin('generic', 'fileIntegrity');
        $contextId = $request->getContext()->getId();

        if (!$plugin->getSetting($contextId, 'baselineHashes')) {
            return new JSONMessage(false, __('plugins.generic.fileIntegrity.scan.noBaseline'));
        }

        $task = new FileIntegrityScanScheduledTask();
        $task->executeActions();

        return new JSONMessage(true, __('plugins.generic.fileIntegrity.scan.success'));
    }
}

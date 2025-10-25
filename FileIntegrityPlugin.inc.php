<?php

/**
 * @file plugins/generic/ashFileIntegrity/FileIntegrityPlugin.inc.php
 */

import('lib.pkp.classes.plugins.GenericPlugin');
import('lib.pkp.classes.linkAction.LinkAction');
import('lib.pkp.classes.linkAction.request.RemoteActionConfirmationModal');

class FileIntegrityPlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled()) {
            HookRegistry::register('AcronPlugin::parseCronTab', array($this, 'callbackParseCronTab'));
            HookRegistry::register('LoadHandler', array($this, 'callbackLoadHandler'));
        }
        return $success;
    }

    public function getDisplayName()
    {
        return __('plugins.generic.fileIntegrity.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.fileIntegrity.description');
    }

    public function getActions($request, $verb)
    {
        $actions = parent::getActions($request, $verb);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $dispatcher = $request->getDispatcher();
        $scanUrl = $dispatcher->url($request, ROUTE_PAGE, null, 'integrity', 'runScan');
        $actions[] = new LinkAction(
            'runScan',
            new RemoteActionConfirmationModal(
                $request->getSession(),
                __('plugins.generic.fileIntegrity.scan.run.description'),
                __('plugins.generic.fileIntegrity.scan.run'),
                $scanUrl,
                'modal_confirm'
            ),
            __('plugins.generic.fileIntegrity.scan.run'),
            null
        );

        $clearCacheUrl = $dispatcher->url($request, ROUTE_PAGE, null, 'integrity', 'clearCache');
        $actions[] = new LinkAction(
            'clearCache',
            new RemoteActionConfirmationModal(
                $request->getSession(),
                __('plugins.generic.fileIntegrity.cache.clear.description'),
                __('plugins.generic.fileIntegrity.cache.clear'),
                $clearCacheUrl,
                'modal_confirm'
            ),
            __('plugins.generic.fileIntegrity.cache.clear'),
            null
        );

        return $actions;
    }

    public function callbackLoadHandler($hookName, $args)
    {
        $page = &$args[0];
        $op = &$args[1];

        if ($page === 'integrity' && in_array($op, ['runScan', 'clearCache'])) {
            define('HANDLER_CLASS', 'FileIntegrityHandler');
            $this->import('FileIntegrityHandler');
            return true;
        }
        return false;
    }

    public function manage($args, $request)
    {
        return parent::manage($args, $request);
    }

    public function callbackParseCronTab($hookName, $args)
    {
        $tasks = &$args[0];
        $tasks[] = $this->getPluginPath() . '/scheduledTasks.xml';
        return false;
    }
}

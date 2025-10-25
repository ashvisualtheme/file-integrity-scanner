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
        error_log('FileIntegrityPlugin: Registering plugin.'); // DEBUG
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
        error_log('FileIntegrityPlugin: getActions() method called.'); // DEBUG
        $dispatcher = $request->getDispatcher();

        $scanUrl = $dispatcher->url(
            $request,
            ROUTE_PAGE,
            null,
            'integrity',
            'runScan'
        );

        error_log('FileIntegrityPlugin: Generated Scan URL: ' . $scanUrl); // DEBUG

        $modal = new RemoteActionConfirmationModal(
            $request->getSession(),
            __('plugins.generic.fileIntegrity.scan.run.description'),
            __('plugins.generic.fileIntegrity.scan.run'),
            $scanUrl,
            'modal_confirm'
        );

        $action = new LinkAction(
            'runScan',
            $modal,
            __('plugins.generic.fileIntegrity.scan.run'),
            null
        );

        return array_merge(
            $this->getEnabled() ? array($action) : array(),
            parent::getActions($request, $verb)
        );
    }

    public function callbackLoadHandler($hookName, $args)
    {
        $page = &$args[0];
        $op = &$args[1];

        error_log("FileIntegrityPlugin: LoadHandler hook triggered. Page: [{$page}], Op: [{$op}]"); // DEBUG

        if ($page === 'integrity' && $op === 'runScan') {
            error_log('FileIntegrityPlugin: Matched route! Loading FileIntegrityHandler.'); // DEBUG
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

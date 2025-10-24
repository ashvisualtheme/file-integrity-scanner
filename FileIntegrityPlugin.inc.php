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
            // Daftarkan handler untuk menerima panggilan AJAX
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

    /**
     * @copydoc Plugin::getActions
     */
    public function getActions($request, $verb)
    {
        $router = $request->getRouter();

        // --- PERBAIKAN FINAL DI SINI ---
        // Membuat URL yang akan ditangkap oleh LoadHandler kita.
        // Ini adalah cara paling andal untuk plugin.
        $scanUrl = $router->url(
            $request,
            null,
            'integrity', // Ini akan menjadi 'page'
            'runScan',   // Ini akan menjadi 'op'
            null,
            null
        );

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

    /**
     * Mendaftarkan handler untuk URL yang kita buat.
     */
    public function callbackLoadHandler($hookName, $args)
    {
        $page = &$args[0];
        $op = &$args[1];

        // Menangkap URL .../index.php/journal/integrity/runScan
        if ($page === 'integrity' && $op === 'runScan') {
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
        $tasks[] = $this->getPluginPath() . '/classes/FileIntegrityScanScheduledTask.inc.php';
        return false;
    }
}

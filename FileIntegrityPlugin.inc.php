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

            // --- AWAL PERUBAHAN ---
            // Daftarkan hook untuk membersihkan cache saat admin melakukannya
            HookRegistry::register('CacheManager::clearDataCache', array($this, 'callbackClearDataCache'));
            // --- AKHIR PERUBAHAN ---
        }
        return $success;
    }

    // ... (Fungsi getDisplayName dan getDescription tidak berubah) ...
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
        $dispatcher = $request->getDispatcher();
        $scanUrl = $dispatcher->url(
            $request,
            ROUTE_PAGE,
            null,
            'integrity',
            'runScan'
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

    public function callbackLoadHandler($hookName, $args)
    {
        $page = &$args[0];
        $op = &$args[1];

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
        $tasks[] = $this->getPluginPath() . '/scheduledTasks.xml';
        return false;
    }

    /**
     * Callback untuk membersihkan cache file integritas.
     * Dipanggil saat administrator membersihkan data cache OJS.
     */
    public function callbackClearDataCache($hookName, $args)
    {
        $cacheDir = Core::getBaseDir() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'fileIntegrityScanner';

        if (!is_dir($cacheDir)) {
            return false;
        }

        $files = glob($cacheDir . DIRECTORY_SEPARATOR . '*.json');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        error_log('FileIntegrityPlugin: Cleared integrity cache files.');
        return false;
    }
}

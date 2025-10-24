<?php

/**
 * @file plugins/generic/ashFileIntegrity/FileIntegrityPlugin.inc.php
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class FileIntegrityPlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled()) {
            // Mendaftarkan scheduled task untuk pemindaian otomatis
            HookRegistry::register('AcronPlugin::parseCronTab', array($this, 'callbackParseCronTab'));
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
        // Import class yang diperlukan untuk membuat LinkAction
        import('lib.pkp.classes.linkAction.request.ConfirmationModal');
        import('lib.pkp.classes.linkAction.request.AjaxAction');

        $router = $request->getRouter();

        // Buat URL yang akan dieksekusi oleh AJAX
        $scanUrl = $router->url(
            $request,
            null,
            null,
            'runScan', // Ini adalah nama operasi di handler kita
            null,
            null
        );

        // Buat LinkAction dengan konfirmasi
        $action = new LinkAction(
            'runScan',
            new ConfirmationModal(
                __('plugins.generic.fileIntegrity.scan.run.description'), // Teks konfirmasi
                __('plugins.generic.fileIntegrity.scan.run'), // Judul modal
                null, // Icon
                __('plugins.generic.fileIntegrity.scan.run'), // Teks tombol OK
                null, // Teks tombol Cancel
                true
            ),
            __('plugins.generic.fileIntegrity.scan.run'), // Teks tautan/tombol di daftar plugin
            null
        );

        // Atur agar setelah konfirmasi, AJAX dijalankan
        $action->getActionRequest()->setRemoteAction($scanUrl);


        return array_merge(
            $this->getEnabled() ? array($action) : array(),
            parent::getActions($request, $verb)
        );
    }

    /**
     * Karena tidak ada halaman pengaturan, fungsi manage() tidak lagi diperlukan.
     */
    public function manage($args, $request)
    {
        return parent::manage($args, $request);
    }

    /**
     * Mendaftarkan scheduled task
     */
    public function callbackParseCronTab($hookName, $args)
    {
        $tasks = &$args[0];
        // Pastikan path ini benar sesuai struktur Anda
        $tasks[] = $this->getPluginPath() . '/classes/FileIntegrityScanScheduledTask.inc.php';
        return false;
    }
}

<?php

/**
 * @file plugins/generic/ashFileIntegrity/FileIntegrityPlugin.inc.php
 */

import('lib.pkp.classes.plugins.GenericPlugin');
import('lib.pkp.classes.linkAction.LinkAction');
import('lib.pkp.classes.linkAction.request.RemoteActionConfirmationModal');

class FileIntegrityPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled()) {
            // Mendaftarkan file XML untuk tugas terjadwal (AcronPlugin)
            HookRegistry::register('AcronPlugin::parseCronTab', array($this, 'callbackParseCronTab'));

            // Mendaftarkan handler untuk menangani permintaan AJAX dari tombol
            HookRegistry::register('LoadHandler', array($this, 'callbackLoadHandler'));
        }
        return $success;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.fileIntegrity.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.fileIntegrity.description');
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb)
    {
        $dispatcher = $request->getDispatcher();

        // Membuat URL yang akan ditangkap oleh LoadHandler kita menggunakan ROUTE_PAGE.
        // Ini adalah cara yang paling stabil dan andal untuk plugin generik.
        $scanUrl = $dispatcher->url(
            $request,
            ROUTE_PAGE,  // Secara eksplisit menggunakan Page Router
            null,        // context
            'integrity', // Ini akan menjadi 'page' yang kita tangkap di LoadHandler
            'runScan'    // Ini akan menjadi 'op' yang kita tangkap di LoadHandler
        );

        // Membuat modal konfirmasi yang akan memanggil URL di atas via AJAX.
        $modal = new RemoteActionConfirmationModal(
            $request->getSession(),
            __('plugins.generic.fileIntegrity.scan.run.description'), // Teks konfirmasi
            __('plugins.generic.fileIntegrity.scan.run'),             // Judul modal
            $scanUrl,
            'modal_confirm'
        );

        // Membuat LinkAction (tombol) dengan modal sebagai request-nya.
        $action = new LinkAction(
            'runScan',
            $modal,
            __('plugins.generic.fileIntegrity.scan.run'), // Teks pada tombol
            null
        );

        return array_merge(
            $this->getEnabled() ? array($action) : array(),
            parent::getActions($request, $verb)
        );
    }

    /**
     * Mendaftarkan handler untuk URL yang telah kita buat.
     */
    public function callbackLoadHandler($hookName, $args)
    {
        $page = &$args[0];
        $op = &$args[1];

        // Menangkap URL yang cocok: .../index.php/journal/integrity/runScan
        if ($page === 'integrity' && $op === 'runScan') {
            define('HANDLER_CLASS', 'FileIntegrityHandler');
            $this->import('FileIntegrityHandler');
            return true; // Memberitahu OJS untuk menyerahkan kendali ke handler kita
        }
        return false; // Lanjutkan proses normal jika URL tidak cocok
    }

    /**
     * @copydoc Plugin::manage()
     * Tidak diperlukan karena kita tidak memiliki halaman pengaturan.
     */
    public function manage($args, $request)
    {
        return parent::manage($args, $request);
    }

    /**
     * Mendaftarkan file XML untuk tugas terjadwal.
     */
    public function callbackParseCronTab($hookName, $args)
    {
        $tasks = &$args[0];
        // Mengarahkan ke file XML, bukan file PHP
        $tasks[] = $this->getPluginPath() . '/scheduledTasks.xml';
        return false;
    }
}

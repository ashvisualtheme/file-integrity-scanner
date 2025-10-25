<?php

/**
 * @file plugins/generic/ashFileIntegrity/FileIntegrityPlugin.inc.php
 */

import('lib.pkp.classes.plugins.GenericPlugin');
import('lib.pkp.classes.linkAction.LinkAction');
import('lib.pkp.classes.linkAction.request.RemoteActionConfirmationModal');
import('lib.pkp.classes.form.Form');

class FileIntegrityPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled()) {
            HookRegistry::register('AcronPlugin::parseCronTab', array($this, 'callbackParseCronTab'));
            HookRegistry::register('LoadHandler', array($this, 'callbackLoadHandler'));
        }
        return $success;
    }

    /**
     * @copydoc PKPPlugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.fileIntegrity.displayName');
    }

    /**
     * @copydoc PKPPlugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.fileIntegrity.description');
    }

    /**
     * @copydoc Plugin::isSitePlugin()
     */
    public function isSitePlugin()
    {
        return true;
    }

    /**
     * @copydoc Plugin::getActions
     */
    public function getActions($request, $verb)
    {
        $actions = parent::getActions($request, $verb);

        // Hanya tampilkan tombol jika pengguna adalah Site Admin
        if ($this->getEnabled() && Validation::isSiteAdmin()) {
            $dispatcher = $request->getDispatcher();

            // Tombol Manual Scan
            $scanUrl = $dispatcher->url($request, ROUTE_PAGE, null, 'integrity', 'runScan');
            $modal = new RemoteActionConfirmationModal(
                $request->getSession(),
                __('plugins.generic.fileIntegrity.scan.run.description'),
                __('plugins.generic.fileIntegrity.scan.run'),
                $scanUrl,
                'modal_confirm'
            );
            $scanAction = new LinkAction('runScan', $modal, __('plugins.generic.fileIntegrity.scan.run'), null);

            // Tombol Settings
            import('lib.pkp.classes.linkAction.request.RedirectAction');
            $settingsUrl = $dispatcher->url(
                $request,
                ROUTE_COMPONENT,
                null,
                'grid.settings.plugins.SettingsPluginGridHandler',
                'manage',
                null,
                ['plugin' => $this->getName(), 'category' => 'generic']
            );
            $settingsAction = new LinkAction('settings', new RedirectAction($settingsUrl), __('common.settings'), null);

            array_unshift($actions, $scanAction);
            array_unshift($actions, $settingsAction);
        }

        return $actions;
    }

    /**
     * @copydoc Plugin::manage
     */
    public function manage($args, $request)
    {
        // Blokir akses ke halaman settings jika bukan Site Admin
        if (!Validation::isSiteAdmin()) {
            $request->getDispatcher()->handle403();
            return;
        }

        $templateManager = TemplateManager::getManager($request);
        $templateManager->registerPlugin('function', 'plugin_url', array($this, 'smartyPluginUrl'));

        $this->import('FileIntegritySettingsForm');
        $form = new FileIntegritySettingsForm($this, CONTEXT_SITE);

        if ($request->getUserVar('save')) {
            $form->readInputData();
            if ($form->validate()) {
                $form->execute();
                import('classes.notification.NotificationManager');
                $notificationManager = new NotificationManager();
                $notificationManager->createTrivialNotification($request->getUser()->getId(), NOTIFICATION_TYPE_SUCCESS, array('contents' => __('common.changesSaved')));
                return new JSONMessage(true);
            }
        }

        $templateManager->assign('form', $form);
        return $templateManager->display($this->getTemplateResource('settings.tpl'));
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

    public function callbackParseCronTab($hookName, $args)
    {
        $tasks = &$args[0];
        $tasks[] = $this->getPluginPath() . '/scheduledTasks.xml';
        return false;
    }
}

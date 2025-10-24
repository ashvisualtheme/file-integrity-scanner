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
            // Register the scheduled task
            HookRegistry::register('AcronPlugin::parseCronTab', array($this, 'callbackParseCronTab'));

            // Register the handler for settings page actions
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
        import('lib.pkp.classes.linkAction.request.AjaxModal');
        return array_merge(
            $this->getEnabled() ? array(
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, array('verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic')),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                ),
            ) : array(),
            parent::getActions($request, $verb)
        );
    }

    /**
     * @copydoc Plugin::manage
     */
    public function manage($args, $request)
    {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON, LOCALE_COMPONENT_PKP_MANAGER);
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->registerPlugin('function', 'plugin_url', array($this, 'smartyPluginUrl'));

                $this->import('FileIntegritySettingsForm');
                $form = new FileIntegritySettingsForm($this);

                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        return new JSONMessage(true);
                    }
                } else {
                    $form->initData();
                }

                return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }

    /**
     * Register scheduled task for daily scans
     */
    public function callbackParseCronTab($hookName, $args)
    {
        $tasks = &$args[0];
        $tasks[] = 'plugins/generic/fileIntegrity/classes/FileIntegrityScanScheduledTask.inc.php';
        return false;
    }

    /**
     * Register the handler for AJAX actions from the settings page
     */
    public function callbackLoadHandler($hookName, $args)
    {
        $page = $args[0];
        $op = $args[1];
        if ($page === 'integrity' && in_array($op, ['createBaseline', 'runScan'])) {
            define('HANDLER_CLASS', 'FileIntegrityHandler');
            define('FILE_INTEGRITY_PLUGIN_DIR', $this->getPluginPath());
            $this->import('FileIntegrityHandler');
            return true;
        }
        return false;
    }
}

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

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb)
    {
        $router = $request->getRouter();
        import('lib.pkp.classes.linkAction.request.AjaxModal');
        import('lib.pkp.classes.linkAction.LinkAction');
        $action = new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, ['plugin' => $this->getName(), 'category' => 'generic']),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        );
        return array_merge(
            $this->getEnabled() ? [$action] : [],
            parent::getActions($request, $verb)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $this->import('FileIntegritySettingsForm');
                $form = new FileIntegritySettingsForm($this);
                if ($request->isPost()) {
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

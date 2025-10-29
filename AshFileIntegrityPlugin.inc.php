<?php

/**
 * @file plugins/generic/ashFileIntegrity/AshFileIntegrityPlugin.inc.php
 *
 * Copyright (c) 2025 AshVisualTheme
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AshFileIntegrityPlugin
 * @ingroup plugins_generic_ashFileIntegrity
 *
 * @brief Generic plugin to perform file integrity scanning (comparing local hashes with a baseline hash on GitHub).
 */

import('lib.pkp.classes.plugins.GenericPlugin');
import('lib.pkp.classes.linkAction.LinkAction');
import('lib.pkp.classes.linkAction.request.RemoteActionConfirmationModal');
import('lib.pkp.classes.linkAction.request.AjaxModal');

class AshFileIntegrityPlugin extends GenericPlugin
{
    /**
     * Registers the plugin with the system.
     *
     * @param string $category Plugin category.
     * @param string $path Plugin path.
     * @param int|null $mainContextId Main context ID.
     * @return bool
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path);
        // Registers hooks only if the plugin is successfully registered and enabled.
        if ($success && $this->getEnabled()) {
            HookRegistry::register('AcronPlugin::parseCronTab', array($this, 'callbackParseCronTab'));
            HookRegistry::register('LoadHandler', array($this, 'callbackLoadHandler'));
        }
        return $success;
    }

    /**
     * Gets the display name of the plugin.
     *
     * @return string
     */
    public function getDisplayName()
    {
        return __('plugins.generic.fileIntegrity.displayName');
    }

    /**
     * Gets the description of the plugin.
     *
     * @return string
     */
    public function getDescription()
    {
        return __('plugins.generic.fileIntegrity.description');
    }

    /**
     * Gets LinkActions to display in the plugin grid.
     *
     * @param Request $request The request object.
     * @param string $verb Action verb.
     * @return array
     */
    public function getActions($request, $verb)
    {
        $actions = parent::getActions($request, $verb);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        $actions[] = new LinkAction(
            'settings',
            new AjaxModal(
                $router->url(
                    $request,
                    null,
                    null,
                    'manage',
                    null,
                    [
                        'verb' => 'settings',
                        'plugin' => $this->getName(),
                        'category' => 'generic'
                    ]
                ),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        );

        $dispatcher = $request->getDispatcher();
        $csrfToken = $request->getSession()->getCSRFToken();

        // Creates a LinkAction to run the file integrity scan.
        $scanUrl = $dispatcher->url($request, ROUTE_PAGE, null, 'integrity', 'runScan', null, ['csrfToken' => $csrfToken]);
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

        // Creates a LinkAction to clear the file integrity hash cache.
        $clearCacheUrl = $dispatcher->url($request, ROUTE_PAGE, null, 'integrity', 'clearCache', null, ['csrfToken' => $csrfToken]);
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

    /**
     * Callback for the LoadHandler hook. Loads the custom handler for integrity URLs.
     *
     * @param string $hookName Hook name.
     * @param array $args Hook arguments, including page and operation.
     * @return bool True if the handler was loaded successfully.
     */
    public function callbackLoadHandler($hookName, $args)
    {
        $page = &$args[0];
        $op = &$args[1];

        // If the page is 'integrity' and the op is either 'runScan' or 'clearCache', load AshFileIntegrityHandler.
        if ($page === 'integrity' && in_array($op, ['runScan', 'clearCache'])) {
            define('HANDLER_CLASS', 'AshFileIntegrityHandler');
            $this->import('AshFileIntegrityHandler');
            return true;
        }
        return false;
    }

    /**
     * Performs plugin management actions (as displayed in the plugin grid).
     *
     * @param array $args Arguments passed to the manage function.
     * @param Request $request The request object.
     * @return string
     */
    public function manage($args, $request)
    {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $this->import('classes.AshFileIntegritySettingsForm');
                $form = new AshFileIntegritySettingsForm($this);

                if (!$request->getUserVar('save')) {
                    $form->initData();
                    return new JSONMessage(true, $form->fetch($request));
                }

                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    return new JSONMessage(true);
                }
                return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }

    /**
     * Callback for the AcronPlugin::parseCronTab hook. Registers the plugin's scheduled task.
     *
     * @param string $hookName Hook name.
     * @param array $args Hook arguments, an array of scheduled task XML file paths.
     * @return bool False
     */
    public function callbackParseCronTab($hookName, $args)
    {
        // Adds the path to the plugin's scheduled task XML file to the list of tasks.
        $tasks = &$args[0];
        $tasks[] = $this->getPluginPath() . '/scheduledTasks.xml';
        return false;
    }
}

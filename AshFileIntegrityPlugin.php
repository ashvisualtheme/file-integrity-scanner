<?php

/**
 * @file plugins/generic/ashFileIntegrity/AshFileIntegrityPlugin.php
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

namespace APP\plugins\generic\ashFileIntegrity;

use PKP\plugins\GenericPlugin;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RemoteActionConfirmationModal;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\Hook;
use PKP\core\JSONMessage;
use APP\plugins\generic\ashFileIntegrity\classes\AshFileIntegritySettingsForm;
use APP\core\Application;
use PKP\security\Role;
use APP\plugins\generic\ashFileIntegrity\classes\RunFileIntegrityScanCommand;
use APP\plugins\generic\ashFileIntegrity\classes\AshFileIntegrityScanScheduledTask;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;

class AshFileIntegrityPlugin extends GenericPlugin implements HasTaskScheduler
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
        $success = parent::register($category, $path, $mainContextId);
        // Registers hooks only if the plugin is successfully registered and enabled.
        if ($success && $this->getEnabled()) {
            Hook::add('LoadHandler', [$this, 'callbackLoadHandler']);
            Hook::add('Console::RegisterCommands', [$this, 'registerCommands']);
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
     * Site-wide plugins should override this function to return true.
     *
     * @return boolean
     */
    function isSitePlugin()
    {
        return true;
    }

    /**
     * Return if user can edit a plugin settings or not.
     * @param $plugin Plugin
     * @return boolean
     */
    protected function _canEdit($plugin)
    {
        // Only site administrators can manage the settings for this site-wide plugin.
        return in_array(Role::ROLE_ID_SITE_ADMIN, $this->_userRoles);
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
        // Always check against the site-wide context for this site-level plugin.
        if (!$this->getEnabled(CONTEXT_SITE)) {
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
        $csrfToken = $request->getSession()->token();

        // Creates a LinkAction to run the file integrity scan.
        $scanUrl = $dispatcher->url($request, ROUTE_PAGE, 'index', 'integrity', 'runScan', null, ['csrfToken' => $csrfToken]);
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
        $clearCacheUrl = $dispatcher->url($request, ROUTE_PAGE, 'index', 'integrity', 'clearCache', null, ['csrfToken' => $csrfToken]);
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
        $page = $args[0];
        $op = $args[1];

        // If the page is 'integrity' and the op is either 'runScan' or 'clearCache', load FileIntegrityHandler.
        if ($page === 'integrity' && in_array($op, ['runScan', 'clearCache'])) {
            $handlerPath = $this->getPluginPath() . '/AshFileIntegrityHandler.php';
            if (file_exists($handlerPath)) {
                require_once($handlerPath);

                // Manual Dispatch: Instantiate and execute the handler directly to bypass Router issues
                $handler = new AshFileIntegrityHandler();
                $request = Application::get()->getRequest();
                
                // Trigger lifecycle methods
                $handler->initialize($request);
                // Pass dummy args since we are handling auth internally in the method
                $handler->authorize($request, $args, []); 
                
                // Execute the operation
                $result = $handler->$op($args, $request);
                
                // Output the JSON result and terminate OJS execution
                echo $result->getString();
                exit;
            }
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
                $form = new AshFileIntegritySettingsForm($this);

                // Fetch the form the first time it loads, before
                // the user has tried to save it
                if (!$request->getUserVar('save')) {
                    $form->initData();
                    return new JSONMessage(true, $form->fetch($request));
                }

                // Validate and save the form data
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    return new JSONMessage(true);
                }
        }
        return parent::manage($args, $request);
    }

    /**
     * Register the console commands.
     *
     * @param string $hookName
     * @param array $args [array $commands]
     */
    public function registerCommands($hookName, $args)
    {
        $commands = & $args[0];
        $commands[] = new RunFileIntegrityScanCommand();
    }

    /**
     * Registers this plugin's scheduled task with OJS's Laravel-based scheduler.
     * This is the OJS 3.5+ replacement for the old scheduledTasks.xml + Acron approach.
     *
     * The hour is read from the plugin's site settings (configurable in the
     * Settings UI); it defaults to 03:00 server time if never configured.
     *
     * @copydoc \PKP\plugins\interfaces\HasTaskScheduler::registerSchedules()
     */
    public function registerSchedules(PKPScheduler $scheduler): void
    {
        $scanHour = $this->getSetting(CONTEXT_SITE, 'scanHour');
        $scanHour = ($scanHour !== null && $scanHour !== '') ? (int) $scanHour : AshFileIntegritySettingsForm::DEFAULT_SCAN_HOUR;
        if ($scanHour < 0 || $scanHour > 23) {
            $scanHour = AshFileIntegritySettingsForm::DEFAULT_SCAN_HOUR;
        }

        $scheduler
            ->addSchedule(new AshFileIntegrityScanScheduledTask())
            ->dailyAt(sprintf('%02d:00', $scanHour))
            ->name(AshFileIntegrityScanScheduledTask::class)
            ->withoutOverlapping();
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\ashFileIntegrity\AshFileIntegrityPlugin', '\AshFileIntegrityPlugin');
}

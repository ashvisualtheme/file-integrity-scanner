<?php

/**
 * @file plugins/generic/ashFileIntegrity/classes/FileIntegritySettingsForm.inc.php
 *
 * Copyright (c) 2025 AshVisualTheme
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FileIntegritySettingsForm
 * @brief Form for managing the File Integrity plugin settings.
 */

import('lib.pkp.classes.form.Form');
import('classes.notification.NotificationManager');

class FileIntegritySettingsForm extends Form
{

    /** @var FileIntegrityPlugin  */
    public $plugin;

    /**
     * @copydoc Form::__construct()
     */
    public function __construct($plugin)
    {

        // Define the settings template and store a copy of the plugin object
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
        $this->plugin = $plugin;

        // Always add POST and CSRF validation to secure your form.
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));

        // Add a validator for the manualExcludes field.
        // This is optional, but if you want to enforce a specific format (e.g., one path per line), you can add a FormValidatorRegExp here.
        // For now, we'll just accept any text.
    }

    /**
     * Load settings already saved in the database
     *
     * Settings are stored by context, so that each journal or press
     * can have different settings.
     */
    public function initData()
    {
        $context = Application::get()->getRequest()->getContext();
        $contextId = $context ? $context->getId() : CONTEXT_SITE;

        $this->setData('manualExcludes', $this->plugin->getSetting($contextId, 'manualExcludes'));
        $this->setData('additionalEmails', $this->plugin->getSetting($contextId, 'additionalEmails'));

        parent::initData();
    }

    /**
     * Load data that was submitted with the form
     */
    public function readInputData()
    {
        $this->readUserVars(['manualExcludes', 'additionalEmails']);
        parent::readInputData();
    }

    /**
     * Fetch any additional data needed for your form.
     *
     * Data assigned to the form using $this->setData() during the
     * initData() or readInputData() methods will be passed to the
     * template.
     *
     * @return string
     */
    public function fetch($request, $template = null, $display = false)
    {

        // Pass the plugin name to the template so that it can be
        // used in the URL that the form is submitted to
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->plugin->getName());

        return parent::fetch($request, $template, $display);
    }

    /**
     * Save the settings
     *
     * @return null|mixed
     */
    public function execute(...$functionArgs)
    {
        $context = Application::get()->getRequest()->getContext();
        $contextId = $context ? $context->getId() : CONTEXT_SITE;

        $this->plugin->updateSetting($contextId, 'manualExcludes', $this->getData('manualExcludes'));
        $this->plugin->updateSetting($contextId, 'additionalEmails', $this->getData('additionalEmails'));

        // Tell the user that the save was successful.
        $notificationMgr = new NotificationManager();
        $notificationMgr->createTrivialNotification(
            Application::get()->getRequest()->getUser()->getId(),
            NOTIFICATION_TYPE_SUCCESS,
            ['contents' => __('common.changesSaved')]
        );

        return parent::execute(...$functionArgs);
    }
}

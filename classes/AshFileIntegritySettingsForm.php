<?php

/**
 * @file plugins/generic/ashFileIntegrity/classes/FileIntegritySettingsForm.inc.php
 *
 * Copyright (c) 2025 AshVisualTheme
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AshFileIntegritySettingsForm
 * @brief Form for managing the File Integrity plugin settings.
 */

namespace APP\plugins\generic\ashFileIntegrity\classes;

use PKP\form\Form;
use APP\notification\NotificationManager;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorCSRF;
use APP\template\TemplateManager;
use APP\core\Application;
use PKP\notification\Notification;

class AshFileIntegritySettingsForm extends Form
{

    /** @var FileIntegrityPlugin  */
    public $plugin;

    /**
     * @copydoc Form::__construct()
     */
    public function __construct($plugin)
    {
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
     */
    public function initData()
    {
        // For this site-wide plugin, all settings are stored at the site level.
        $this->setData('manualExcludes', $this->plugin->getSetting(CONTEXT_SITE, 'manualExcludes'));
        $this->setData('additionalEmails', $this->plugin->getSetting(CONTEXT_SITE, 'additionalEmails'));

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
        // Save settings at the site level.
        $this->plugin->updateSetting(CONTEXT_SITE, 'manualExcludes', $this->getData('manualExcludes'));
        $this->plugin->updateSetting(CONTEXT_SITE, 'additionalEmails', $this->getData('additionalEmails'));

        // Tell the user that the save was successful.
        $notificationMgr = new NotificationManager();
        $notificationMgr->createTrivialNotification(
            Application::get()->getRequest()->getUser()->getId(),
            \PKP\notification\Notification::NOTIFICATION_TYPE_SUCCESS,
            ['contents' => __('common.changesSaved')]
        );

        return parent::execute(...$functionArgs);
    }
}

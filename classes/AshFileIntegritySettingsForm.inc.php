<?php

/**
 * @file plugins/generic/ashFileIntegrity/classes/AshFileIntegritySettingsForm.inc.php
 *
 * Copyright (c) 2025 AshVisualTheme
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AshFileIntegritySettingsForm
 * @ingroup plugins_generic_ashFileIntegrity
 *
 * @brief Form for administrators to manage file integrity plugin settings.
 */

import('lib.pkp.classes.form.Form');

class AshFileIntegritySettingsForm extends Form
{

    /** @var AshFileIntegrityPlugin The plugin instance */
    var $_plugin;

    /**
     * Constructor.
     * @param $plugin AshFileIntegrityPlugin
     */
    function __construct($plugin)
    {
        $this->_plugin = $plugin;
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * Initialize form data.
     */
    function initData()
    {
        $plugin = $this->_plugin;
        $this->setData('manualExcludeValue', $plugin->getSetting(0, 'exclusions'));
    }

    /**
     * Assign form data to user-submitted data.
     */
    function readInputData()
    {
        $this->readUserVars(array('manualExcludeValue'));
    }

    /**
     * Save settings.
     */
    function execute(...$functionArgs)
    {
        $plugin = $this->_plugin;
        $exclusions = $this->getData('manualExcludeValue');
        $plugin->updateSetting(0, 'exclusions', $exclusions);
        parent::execute(...$functionArgs);
    }
}

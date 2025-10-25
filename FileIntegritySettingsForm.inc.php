<?php

import('lib.pkp.classes.form.Form');

class FileIntegritySettingsForm extends Form
{
    public $plugin;
    public $contextId;

    public function __construct($plugin, $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
        $this->plugin = $plugin;
        $this->contextId = $contextId;

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    public function initData()
    {
        $this->setData('excludedPaths', $this->plugin->getSetting($this->contextId, 'excludedPaths'));
    }

    public function readInputData()
    {
        $this->readUserVars(['excludedPaths']);
    }

    public function execute(...$functionArgs)
    {
        $this->plugin->updateSetting($this->contextId, 'excludedPaths', $this->getData('excludedPaths'), 'string');
        parent::execute(...$functionArgs);
    }
}

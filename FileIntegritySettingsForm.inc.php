<?php

/**
 * @file plugins/generic/ashFileIntegrity/FileIntegritySettingsForm.inc.php
 */

import('lib.pkp.classes.form.Form');

class FileIntegritySettingsForm extends Form
{

    protected $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
    }

    public function initData()
    {
        $plugin = $this->plugin;
        $contextId = Application::get()->getRequest()->getContext()->getId();

        $lastCreatedTimestamp = $plugin->getSetting($contextId, 'baselineLastCreated');
        if ($lastCreatedTimestamp) {
            $this->setData('lastCreated', strftime('%Y-%m-%d %H:%M:%S', $lastCreatedTimestamp));
        } else {
            $this->setData('lastCreated', null);
        }

        $this->setData('createBaselineUrl', Application::get()->getRequest()->getDispatcher()->url(
            Application::get()->getRequest(),
            ROUTE_COMPONENT,
            null,
            'integrity',
            'createBaseline',
            null
        ));

        $this->setData('runScanUrl', Application::get()->getRequest()->getDispatcher()->url(
            Application::get()->getRequest(),
            ROUTE_COMPONENT,
            null,
            'integrity',
            'runScan',
            null
        ));
    }
}

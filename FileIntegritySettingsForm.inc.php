<?php

import('lib.pkp.classes.form.Form');

class FileIntegritySettingsForm extends Form
{
    /** @var FileIntegrityPlugin */
    public $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    public function initData()
    {
        $this->_data = [
            'excludedPaths' => $this->plugin->getSetting(0, 'excludedPaths') ?? '',
        ];
        parent::initData();
    }

    public function readInputData()
    {
        $this->readUserVars(['excludedPaths']);
        parent::readInputData();
    }

    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $dispatcher = $request->getDispatcher();
        $scanUrl = $dispatcher->url(
            $request,
            ROUTE_PAGE,
            null,
            'integrity',
            'runScan'
        );
        $templateMgr->assign('scanUrl', $scanUrl);
        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionArgs)
    {
        $this->plugin->updateSetting(0, 'excludedPaths', $this->getData('excludedPaths'));
        parent::execute(...$functionArgs);
    }
}

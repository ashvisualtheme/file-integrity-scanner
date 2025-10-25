<?php

import('classes.handler.Handler');
import('lib.pkp.classes.core.Core');

class FileIntegrityHandler extends Handler
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @param array $args
     * @param PKPRequest $request
     */
    public function runScan($args, $request)
    {
        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $plugin = PluginRegistry::getPlugin('generic', 'ashfileintegrityplugin');

        // Gunakan Core::getBaseDir() sebagai pengganti getBasePath()
        $basePath = Core::getBaseDir() . DIRECTORY_SEPARATOR;
        $hashFileUrl = 'https://raw.githubusercontent.com/ash-publications/hash-repo/main/ojs/core/ojs-3.3.0-8.json';

        $response = \Http::get($hashFileUrl);
        if ($response->status() != 200) {
            $templateMgr->assign('error', 'Could not retrieve hash file from ' . $hashFileUrl);
            return $templateMgr->display($plugin->getTemplateResource('results.tpl'));
        }

        $hashes = json_decode($response->body(), true);
        $mismatchedFiles = [];
        $missingFiles = [];

        foreach ($hashes as $file => $hash) {
            $filePath = $basePath . $file;
            if (file_exists($filePath)) {
                if (sha1_file($filePath) !== $hash) {
                    $mismatchedFiles[] = $file;
                }
            } else {
                $missingFiles[] = $file;
            }
        }

        $templateMgr->assign('mismatchedFiles', $mismatchedFiles);
        $templateMgr->assign('missingFiles', $missingFiles);
        return $templateMgr->display($plugin->getTemplateResource('results.tpl'));
    }
}

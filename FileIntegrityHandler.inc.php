<?php

import('classes.handler.Handler');

class FileIntegrityHandler extends Handler
{
    public function __construct()
    {
        parent::__construct();
    }

    public function runScan($args, $request)
    {
        $plugin = PluginRegistry::getPlugin('generic', 'fileintegrityplugin');

        // *** INILAH PERBAIKAN UTAMANYA ***
        // Menggunakan getBasePath() untuk mendapatkan direktori root instalasi OJS
        $baseDir = getBasePath();

        // Ambil dan proses daftar pengecualian
        $excludedPathsSetting = $plugin->getSetting(0, 'excludedPaths');
        $excludedPaths = [];
        if ($excludedPathsSetting) {
            $excludedPaths = array_filter(array_map('trim', explode("\n", $excludedPathsSetting)));
        }

        // Dapatkan versi OJS saat ini
        $versionDao = DAORegistry::getDAO('VersionDAO');
        $currentVersion = $versionDao->getCurrentVersion();
        $versionString = $currentVersion->getVersionString(false);

        // Bangun URL ke file hash
        $hashRepoUrl = 'https://raw.githubusercontent.com/ash-raj/hash-repo/main/ojs/core/ojs-' . $versionString . '.json';

        // Unduh dan decode file hash
        $hashJson = @file_get_contents($hashRepoUrl);
        if ($hashJson === false) {
            // Tangani error jika file hash tidak bisa diunduh
            $templateMgr = TemplateManager::getManager($request);
            $templateMgr->assign('error', 'Could not download the hash file for your OJS version. Please check your internet connection or the hash repository.');
            return $templateMgr->display($plugin->getTemplateResource('templates/results.tpl'));
        }
        $officialHashes = json_decode($hashJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $templateMgr = TemplateManager::getManager($request);
            $templateMgr->assign('error', 'Could not decode the hash file.');
            return $templateMgr->display($plugin->getTemplateResource('templates/results.tpl'));
        }

        $localFiles = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $filePath = $file->getPathname();

            // Buat path relatif dari base directory
            $relativePath = str_replace($baseDir . '/', '', $filePath);

            // Periksa apakah file harus dikecualikan
            $isExcluded = false;
            foreach ($excludedPaths as $excludedPath) {
                if (strpos($relativePath, $excludedPath) === 0) {
                    $isExcluded = true;
                    break;
                }
            }

            if ($isExcluded) {
                continue;
            }

            $localFiles[$relativePath] = sha1_file($filePath);
        }

        $modifiedFiles = array_diff_assoc($localFiles, $officialHashes);
        $deletedFiles = array_diff_key($officialHashes, $localFiles);
        $addedFiles = array_diff_key($localFiles, $officialHashes);

        // Siapkan hasil untuk ditampilkan
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('modifiedFiles', $modifiedFiles);
        $templateMgr->assign('deletedFiles', array_keys($deletedFiles));
        $templateMgr->assign('addedFiles', array_keys($addedFiles));
        $templateMgr->assign('scanRan', true);
        return $templateMgr->display($plugin->getTemplateResource('templates/results.tpl'));
    }
}

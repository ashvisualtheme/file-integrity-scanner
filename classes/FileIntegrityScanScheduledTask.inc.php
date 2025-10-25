<?php

import('lib.pkp.classes.scheduledTask.ScheduledTask');
import('lib.pkp.classes.mail.Mail');
import('lib.pkp.classes.plugins.PluginRegistry');

class FileIntegrityScanScheduledTask extends ScheduledTask
{
    const GITHUB_HASH_REPO_URL = 'https://raw.githubusercontent.com/ashvisualtheme/hash-repo/main/ojs/';

    public function executeActions($forceRefresh = false)
    {
        // TAHAP 1: KUMPULKAN DATABASE HASH GABUNGAN
        $baselineHashes = $this->_getCombinedBaselineHashes($forceRefresh);
        if ($baselineHashes === null || empty($baselineHashes)) {
            error_log('FileIntegrityPlugin: CRITICAL - Failed to assemble any baseline hashes. Aborting.');
            return false;
        }

        // TAHAP 2: Pindai File Lokal
        $currentHashes = $this->_getHashes();

        // --- KODE BARU UNTUK DEBUGGING ---
        // Mencatat semua hash file lokal ke dalam log sebagai JSON yang rapi
        error_log('[LOCAL SCAN RESULT] ' . json_encode($currentHashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        // --- AKHIR KODE BARU ---

        // TAHAP 3: Bandingkan
        $modified = [];
        $added = [];
        $deleted = [];

        foreach ($baselineHashes as $filePath => $baselineHash) {
            if (!isset($currentHashes[$filePath])) {
                $deleted[] = $filePath;
            } elseif ($currentHashes[$filePath] !== $baselineHash) {
                $modified[] = $filePath;
            }
        }

        foreach ($currentHashes as $filePath => $currentHash) {
            if (!isset($baselineHashes[$filePath])) {
                $added[] = $filePath;
            }
        }

        if (empty($modified) && empty($added) && empty($deleted)) {
            return true;
        }

        $this->_sendNotificationEmail($modified, $added, $deleted);
        return true;
    }

    private function _getCombinedBaselineHashes($forceRefresh = false)
    {
        $cacheDir = Core::getBaseDir() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'integrityFilesScan';
        $encryption_key = Config::getVar('security', 'salt');

        $ojsVersionString = Application::get()->getCurrentVersion()->getVersionString();
        $formattedOjsVersion = $this->_formatVersionString($ojsVersionString);
        $combinedCacheId = 'combined-' . $formattedOjsVersion;

        $cacheFileName = 'integrity_hashes_' . hash_hmac('sha256', $combinedCacheId, $encryption_key) . '.json';
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheFileName;

        if (!$forceRefresh && file_exists($cacheFile)) {
            $jsonContent = @file_get_contents($cacheFile);
            if ($jsonContent) return json_decode($jsonContent, true);
        }

        $allHashes = [];

        $coreHashes = $this->_downloadHashFile('core', ['version' => $formattedOjsVersion]);
        if ($coreHashes) {
            $allHashes = $coreHashes;
        }

        $pluginCategories = PluginRegistry::getCategories();
        foreach ($pluginCategories as $category) {
            PluginRegistry::loadCategory($category);
            $plugins = PluginRegistry::getPlugins($category);
            if (empty($plugins)) continue;

            foreach ($plugins as $plugin) {
                if (!$plugin->getEnabled()) continue;

                $version = $plugin->getCurrentVersion();
                if (!$version) continue;

                $pluginName = basename($plugin->getPluginPath());
                $versionString = $this->_formatVersionString($version->getVersionString());

                $pluginHashes = $this->_downloadHashFile('plugin', [
                    'category'   => $category,
                    'pluginName' => $pluginName,
                    'version'    => $versionString,
                ]);

                if ($pluginHashes) {
                    $allHashes = array_merge($allHashes, $pluginHashes);
                }
            }
        }

        if (empty($allHashes)) {
            return null;
        }

        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
        @file_put_contents($cacheFile, json_encode($allHashes));

        return $allHashes;
    }

    private function _downloadHashFile($type, $data)
    {
        $url = null;
        if ($type === 'core') {
            $url = self::GITHUB_HASH_REPO_URL . 'core/ojs-' . $data['version'] . '.json';
        } elseif ($type === 'plugin') {
            $url = self::GITHUB_HASH_REPO_URL . "plugins/{$data['category']}/{$data['pluginName']}-v{$data['version']}.json";
        } else {
            return null;
        }

        $jsonContent = @file_get_contents($url);

        if ($jsonContent === false) {
            error_log("FileIntegrityPlugin: FAILED to download from {$url}");
            return null;
        }

        return json_decode($jsonContent, true);
    }

    private function _formatVersionString($versionString)
    {
        $lastDotPosition = strrpos($versionString, '.');
        if ($lastDotPosition !== false) {
            if (strpos(substr($versionString, $lastDotPosition), '-') === false) {
                return substr_replace($versionString, '-', $lastDotPosition, 1);
            }
        }
        return $versionString;
    }

    private function _getHashes()
    {
        $hashes = [];
        $basePath = Core::getBaseDir();
        $filesDir = Config::getVar('files', 'files_dir');
        $publicDir = Config::getVar('files', 'public_files_dir');
        $excludedPaths = [
            realpath($filesDir),
            realpath($publicDir),
            realpath($basePath . '/cache'),
            $basePath . '/lscache'
        ];

        $excludedPaths = array_filter($excludedPaths);
        $excludedPaths = array_map(function ($path) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }, $excludedPaths);

        try {
            $directoryIterator = new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS);
            $iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::SELF_FIRST);
        } catch (Exception $e) {
            error_log('FileIntegrityPlugin: Directory iteration failed: ' . $e->getMessage());
            return [];
        }

        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isReadable()) continue;

            $filePath = $file->getRealPath();
            $isExcluded = false;
            foreach ($excludedPaths as $excludedPath) {
                if ($excludedPath && strpos($filePath, $excludedPath) === 0) {
                    $isExcluded = true;
                    break;
                }
            }

            if ($isExcluded || !$file->isFile() || basename($filePath) == 'config.inc.php') {
                continue;
            }

            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $filePath);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            $hashes[$relativePath] = hash_file('sha256', $filePath);
        }
        return $hashes;
    }

    private function _sendNotificationEmail($modified, $added, $deleted)
    {
        import('lib.pkp.classes.mail.MailTemplate');
        $site = Application::get()->getRequest()->getSite();
        $contactEmail = $site->getLocalizedContactEmail();

        $mail = new MailTemplate();
        $mail->setContentType('text/html; charset=utf-8');
        $mail->setSubject(__('plugins.generic.fileIntegrity.email.subject'));
        $mail->addRecipient($contactEmail, $site->getLocalizedContactName());

        $body = '<p>' . __('plugins.generic.fileIntegrity.email.body.issues') . '</p>';

        if (!empty($modified)) {
            $body .= '<h2>' . __('plugins.generic.fileIntegrity.email.body.modified') . '</h2>';
            $body .= '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $modified)) . '</li></ul>';
        }
        if (!empty($added)) {
            $body .= '<h2>' . __('plugins.generic.fileIntegrity.email.body.added') . '</h2>';
            $body .= '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $added)) . '</li></ul>';
        }
        if (!empty($deleted)) {
            $body .= '<h2>' . __('plugins.generic.fileIntegrity.email.body.deleted') . '</h2>';
            $body .= '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $deleted)) . '</li></ul>';
        }

        $mail->setBody($body);

        if (!$mail->send()) {
            error_log('FileIntegrityPlugin: Failed to send notification email using MailTemplate.');
        }
    }
}

<?php

import('lib.pkp.classes.scheduledTask.ScheduledTask');
import('lib.pkp.classes.mail.Mail');

class FileIntegrityScanScheduledTask extends ScheduledTask
{
    const GITHUB_HASH_REPO_URL = 'https://raw.githubusercontent.com/ashvisualtheme/hash-repo/main/ojs/core/ojs-';

    public function executeActions()
    {
        $baselineHashes = $this->_fetchAndCacheBaseline();
        if (!$baselineHashes) {
            return false;
        }
        $currentHashes = $this->_getHashes();
        $modified = [];
        $deleted = [];
        $added = [];
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
        if (empty($modified) && empty($deleted) && empty($added)) {
            return true;
        }
        $this->_sendNotificationEmail($modified, $added, $deleted);
        return true;
    }

    private function _fetchAndCacheBaseline()
    {
        $ojsVersionString = Application::get()->getCurrentVersion()->getVersionString();
        $lastDotPosition = strrpos($ojsVersionString, '.');
        if ($lastDotPosition !== false) {
            $formattedVersion = substr_replace($ojsVersionString, '-', $lastDotPosition, 1);
        } else {
            $formattedVersion = $ojsVersionString;
        }
        $url = self::GITHUB_HASH_REPO_URL . $formattedVersion . '.json';
        $jsonContent = @file_get_contents($url);
        if ($jsonContent === false) {
            return null;
        }
        return json_decode($jsonContent, true);
    }

    private function _getHashes()
    {
        $plugin = PluginRegistry::getPlugin('generic', 'ashfileintegrityplugin');
        // Mengambil pengaturan dari level situs (Site ID = 0)
        $userExcludedPathsSetting = $plugin->getSetting(CONTEXT_SITE, 'excludedPaths');

        // Mengubah string dari textarea menjadi array, membuang baris kosong
        $userExcludedPaths = [];
        if (!empty($userExcludedPathsSetting)) {
            $userExcludedPaths = array_filter(array_map('trim', explode("\n", $userExcludedPathsSetting)));
        }

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

        // Menambahkan path dari pengaturan pengguna
        foreach ($userExcludedPaths as $userPath) {
            if (strpos($userPath, '/') !== 0 && strpos($userPath, ':') !== 1) {
                $excludedPaths[] = realpath($basePath . DIRECTORY_SEPARATOR . $userPath);
            } else {
                $excludedPaths[] = realpath($userPath);
            }
        }

        $excludedPaths = array_filter($excludedPaths);
        $excludedPaths = array_map(function ($path) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }, $excludedPaths);

        try {
            $directoryIterator = new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS);
            $iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::SELF_FIRST);
        } catch (Exception $e) {
            return [];
        }

        foreach ($iterator as $file) {
            $filePath = $file->getRealPath();
            if ($file->isDir() && !$file->isReadable()) {
                continue;
            }
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
        $mail->send();
    }
}

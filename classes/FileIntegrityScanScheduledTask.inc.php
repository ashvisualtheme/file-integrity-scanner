<?php

import('lib.pkp.classes.scheduledTask.ScheduledTask');
import('lib.pkp.classes.mail.Mail');

class FileIntegrityScanScheduledTask extends ScheduledTask
{
    const GITHUB_HASH_REPO_URL = 'https://raw.githubusercontent.com/ashvisualtheme/hash-repo/main/ojs/core/ojs-';

    public function executeActions($forceRefresh = false)
    {
        $baselineHashes = $this->_fetchAndCacheBaseline($forceRefresh);
        if (!$baselineHashes) {
            error_log('FileIntegrityPlugin: Failed to fetch or cache baseline hashes.');
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

    private function _fetchAndCacheBaseline($forceRefresh = false)
    {
        $ojsVersionString = Application::get()->getCurrentVersion()->getVersionString();
        $lastDotPosition = strrpos($ojsVersionString, '.');
        if ($lastDotPosition !== false) {
            $formattedVersion = substr_replace($ojsVersionString, '-', $lastDotPosition, 1);
        } else {
            $formattedVersion = $ojsVersionString;
        }

        $cacheDir = Core::getBaseDir() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'integrityFilesScan';
        $encryption_key = Config::getVar('security', 'salt');
        $cacheFileName = 'integrity_hashes_' . hash_hmac('sha256', $formattedVersion, $encryption_key) . '.json';
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheFileName;

        if (!$forceRefresh && file_exists($cacheFile)) {
            $jsonContent = file_get_contents($cacheFile);
            if ($jsonContent !== false) {
                return json_decode($jsonContent, true);
            }
        }

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $url = self::GITHUB_HASH_REPO_URL . $formattedVersion . '.json';
        $jsonContent = @file_get_contents($url);

        if ($jsonContent === false) {
            error_log('FileIntegrityPlugin: Failed to download hash file from ' . $url);
            return null;
        }

        file_put_contents($cacheFile, $jsonContent);

        return json_decode($jsonContent, true);
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
            realpath($basePath . '/cache')
        ];

        $excludedPaths = array_filter($excludedPaths);
        $excludedPaths = array_map(function ($path) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }, $excludedPaths);

        try {
            $directoryIterator = new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS);
            $iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::SELF_FIRST);
        } catch (Exception $e) {
            error_log('FileIntegrityPlugin: Error iterating directory: ' . $e->getMessage());
            return [];
        }

        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isReadable()) {
                continue;
            }

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

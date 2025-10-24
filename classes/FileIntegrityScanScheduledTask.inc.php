<?php

/**
 * @file plugins/generic/ashFileIntegrity/classes/FileIntegrityScanScheduledTask.inc.php
 */

import('lib.pkp.classes.scheduledTask.ScheduledTask');
import('lib.pkp.classes.mail.Mail');

class FileIntegrityScanScheduledTask extends ScheduledTask
{
    const GITHUB_HASH_REPO_URL = 'https://raw.githubusercontent.com/ashvisualtheme/hash-repo/main/ojs/core/ojs-';

    public function executeActions()
    {
        $baselineHashes = $this->_fetchAndCacheBaseline();
        if (!$baselineHashes) {
            error_log('FileIntegrityPlugin: Scan failed. Could not fetch official baseline from GitHub.');
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
            error_log('FileIntegrityPlugin: Scan completed. All files match the official baseline.');
            return true;
        }

        $this->_sendNotificationEmail($modified, $added, $deleted);
        return true;
    }

    private function _fetchAndCacheBaseline()
    {
        $ojsVersionString = Application::get()->getCurrentVersion()->getVersionString();

        // --- PERBAIKAN DI SINI: Mengganti titik terakhir dengan tanda hubung ---
        $lastDotPosition = strrpos($ojsVersionString, '.');
        if ($lastDotPosition !== false) {
            $formattedVersion = substr_replace($ojsVersionString, '-', $lastDotPosition, 1);
        } else {
            $formattedVersion = $ojsVersionString;
        }

        $url = self::GITHUB_HASH_REPO_URL . $formattedVersion . '.json';
        // --- Akhir Perbaikan ---

        $jsonContent = @file_get_contents($url);

        if ($jsonContent === false) {
            error_log('FileIntegrityPlugin: Failed to download hash file from ' . $url);
            return null;
        }

        $cacheDir = Core::getBaseDir() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'AshVisual';
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'baseline-' . $formattedVersion . '.json';

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        file_put_contents($cacheFile, $jsonContent);

        return json_decode($jsonContent, true);
    }

    private function _getHashes()
    {
        $hashes = [];
        $basePath = realpath(dirname(__FILE__) . '/../../../../..');

        $filesDir = Config::getVar('files', 'files_dir');
        $publicDir = Config::getVar('files', 'public_files_dir');

        $excludedPaths = [
            realpath($filesDir),
            realpath($publicDir),
            realpath($basePath . '/cache'),
        ];

        $excludedPaths = array_filter($excludedPaths);
        $excludedPaths = array_map(function ($path) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }, $excludedPaths);

        $directoryIterator = new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directoryIterator);

        foreach ($iterator as $file) {
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
        $site = Application::get()->getRequest()->getSite();
        $contactEmail = $site->getLocalizedContactEmail();

        $mail = new Mail();
        $mail->setTo($contactEmail, $site->getLocalizedContactName());
        $mail->setSubject(__('plugins.generic.fileIntegrity.email.subject'));

        $body = __('plugins.generic.fileIntegrity.email.body.issues') . "\n\n";

        if (!empty($modified)) {
            $body .= "--- " . __('plugins.generic.fileIntegrity.email.body.modified') . " ---\n";
            $body .= implode("\n", $modified) . "\n\n";
        }
        if (!empty($added)) {
            $body .= "--- " . __('plugins.generic.fileIntegrity.email.body.added') . " ---\n";
            $body .= implode("\n", $added) . "\n\n";
        }
        if (!empty($deleted)) {
            $body .= "--- " . __('plugins.generic.fileIntegrity.email.body.deleted') . " ---\n";
            $body .= implode("\n", $deleted) . "\n\n";
        }

        $mail->setBody($body);
        if (!$mail->send()) {
            error_log('FileIntegrityPlugin: Failed to send notification email.');
        }
    }
}

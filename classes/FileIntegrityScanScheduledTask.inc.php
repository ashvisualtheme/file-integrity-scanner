<?php

import('lib.pkp.classes.scheduledTask.ScheduledTask');
import('lib.pkp.classes.mail.Mail'); // Diperlukan untuk MailTemplate

class FileIntegrityScanScheduledTask extends ScheduledTask
{
    const GITHUB_HASH_REPO_URL = 'https://raw.githubusercontent.com/ashvisualtheme/hash-repo/main/ojs/core/ojs-';

    public function executeActions()
    {
        error_log('FileIntegrityScanScheduledTask: executeActions() started.'); // DEBUG

        $baselineHashes = $this->_fetchAndCacheBaseline();
        if (!$baselineHashes) {
            error_log('FileIntegrityScanScheduledTask: executeActions() FAILED because baseline could not be fetched.'); // DEBUG
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
            error_log('FileIntegrityScanScheduledTask: Scan completed. All files match the official baseline.'); // DEBUG
            return true;
        }

        error_log('FileIntegrityScanScheduledTask: Found issues. Modified: ' . count($modified) . ', Added: ' . count($added) . ', Deleted: ' . count($deleted)); // DEBUG
        $this->_sendNotificationEmail($modified, $added, $deleted);
        return true;
    }

    private function _fetchAndCacheBaseline()
    {
        error_log('FileIntegrityScanScheduledTask: _fetchAndCacheBaseline() started.'); // DEBUG
        $ojsVersionString = Application::get()->getCurrentVersion()->getVersionString();
        error_log('FileIntegrityScanScheduledTask: OJS version string: ' . $ojsVersionString); // DEBUG

        $lastDotPosition = strrpos($ojsVersionString, '.');
        if ($lastDotPosition !== false) {
            $formattedVersion = substr_replace($ojsVersionString, '-', $lastDotPosition, 1);
        } else {
            $formattedVersion = $ojsVersionString;
        }
        error_log('FileIntegrityScanScheduledTask: Formatted version for URL: ' . $formattedVersion); // DEBUG

        $url = self::GITHUB_HASH_REPO_URL . $formattedVersion . '.json';
        error_log('FileIntegrityScanScheduledTask: Attempting to download from URL: ' . $url); // DEBUG

        $jsonContent = @file_get_contents($url);

        if ($jsonContent === false) {
            error_log('FileIntegrityScanScheduledTask: FAILED to download hash file.'); // DEBUG
            return null;
        }
        error_log('FileIntegrityScanScheduledTask: Successfully downloaded hash file.'); // DEBUG

        return json_decode($jsonContent, true);
    }

    private function _getHashes()
    {
        // (Kode di sini tidak berubah)
        $hashes = [];
        $basePath = realpath(dirname(__FILE__) . '/../../../../..');
        $filesDir = Config::getVar('files', 'files_dir');
        $publicDir = Config::getVar('files', 'public_files_dir');
        $excludedPaths = [realpath($filesDir), realpath($publicDir), realpath($basePath . '/cache')];
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
            if ($isExcluded || !$file->isFile() || basename($filePath) == 'config.inc.php') continue;
            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $filePath);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            $hashes[$relativePath] = hash_file('sha256', $filePath);
        }
        return $hashes;
    }

    private function _sendNotificationEmail($modified, $added, $deleted)
    {
        error_log('FileIntegrityScanScheduledTask: _sendNotificationEmail() started.'); // DEBUG
        import('lib.pkp.classes.mail.MailTemplate');
        $site = Application::get()->getRequest()->getSite();
        $contactEmail = $site->getLocalizedContactEmail();

        $mail = new MailTemplate();
        $mail->setSubject(__('plugins.generic.fileIntegrity.email.subject'));
        $mail->addRecipient($contactEmail, $site->getLocalizedContactName());

        $body = __('plugins.generic.fileIntegrity.email.body.issues') . "\n\n";

        if (!empty($modified)) $body .= "--- " . __('plugins.generic.fileIntegrity.email.body.modified') . " ---\n" . implode("\n", $modified) . "\n\n";
        if (!empty($added)) $body .= "--- " . __('plugins.generic.fileIntegrity.email.body.added') . " ---\n" . implode("\n", $added) . "\n\n";
        if (!empty($deleted)) $body .= "--- " . __('plugins.generic.fileIntegrity.email.body.deleted') . " ---\n" . implode("\n", $deleted) . "\n\n";

        $mail->setBody($body);

        if (!$mail->send()) {
            error_log('FileIntegrityScanScheduledTask: FAILED to send notification email.'); // DEBUG
        } else {
            error_log('FileIntegrityScanScheduledTask: Successfully sent notification email.'); // DEBUG
        }
    }
}

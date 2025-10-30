<?php

/**
 * @file plugins/generic/ashFileIntegrity/classes/FileIntegrityScanScheduledTask.inc.php
 *
 * Copyright (c) 2025 AshVisualTheme
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FileIntegrityScanScheduledTask
 * @ingroup plugins_generic_ashFileIntegrity
 *
 * @brief Scheduled task to run the file integrity scan.
 */

import('lib.pkp.classes.scheduledTask.ScheduledTask');
import('lib.pkp.classes.mail.Mail');
import('lib.pkp.classes.plugins.PluginRegistry');
import('lib.pkp.classes.mail.MailTemplate');

class FileIntegrityScanScheduledTask extends ScheduledTask
{
    // Base URL of the hash repository on GitHub
    const GITHUB_HASH_REPO_URL = 'https://raw.githubusercontent.com/ashvisualtheme/hash-repo/main/ojs/';

    /**
     * Cleans up orphaned cache files from previous versions of OJS or plugins.
     */
    private function cleanupOrphanedCacheFiles()
    {
        $cacheDir = Core::getBaseDir() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'integrityFilesScan';
        if (!is_dir($cacheDir)) {
            return;
        }

        $activeCacheFiles = [];
        $encryption_key = Config::getVar('security', 'salt');
        $versionString = Application::get()->getCurrentVersion()->getVersionString();
        $cacheId = 'core-' . $versionString;
        $activeCacheFiles[] = 'integrity_hashes_' . hash_hmac('sha256', $cacheId, $encryption_key) . '.json';

        $pluginCategories = ['blocks', 'generic', 'gateways', 'importexport', 'reports', 'themes'];
        foreach ($pluginCategories as $category) {
            $plugins = PluginRegistry::getPlugins($category);
            if (is_array($plugins)) {
                foreach ($plugins as $plugin) {
                    $version = $plugin->getCurrentVersion();
                    if ($version) {
                        $pluginCacheId = "plugin-{$category}-{$plugin->getName()}-{$version->getVersionString()}";
                        $activeCacheFiles[] = 'integrity_hashes_' . hash_hmac('sha256', $pluginCacheId, $encryption_key) . '.json';
                    }
                }
            }
        }

        $allCacheFiles = glob($cacheDir . DIRECTORY_SEPARATOR . 'integrity_hashes_*.json');
        if (is_array($allCacheFiles)) {
            foreach ($allCacheFiles as $filePath) {
                $fileName = basename($filePath);
                if (!in_array($fileName, $activeCacheFiles)) {
                    @unlink($filePath);
                }
            }
        }
    }

    /**
     * Executes the task actions.
     *
     * @param bool $forceRefresh If true, baseline hashes will be redownloaded from GitHub, ignoring the cache.
     * @return bool True if successful, false if failed.
     */
    public function executeActions($forceRefresh = false)
    {
        $this->cleanupOrphanedCacheFiles();

        // --- STEP 0: Define Exclusion Lists ---
        $plugin = PluginRegistry::loadPlugin('generic', 'ashFileIntegrity');
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $contextId = $context ? $context->getId() : CONTEXT_SITE;

        // 1. Manual Excludes (from settings)
        $manualExcludesSetting = $plugin->getSetting($contextId, 'manualExcludes');
        $manualExcludes = [];
        if (!empty($manualExcludesSetting)) {
            $manualExcludes = array_filter(array_map('trim', explode("\n", $manualExcludesSetting)));
        }

        // 2. Default Excludes (to be monitored)
        $defaultExcludes = [
            'public/',
            'config.inc.php'
        ];

        // Combine into a single list of files/folders to be monitored
        $monitoredExcludes = array_unique(array_merge($manualExcludes, $defaultExcludes));

        // Load the cache for monitored files
        $excludedHashesCacheFile = $this->_getExcludedHashesCacheFile();
        $cachedExcludedHashes = $this->_loadJsonFile($excludedHashesCacheFile);
        $newExcludedHashesCache = [];

        $excludedModified = [];
        $excludedAdded = [];
        $excludedDeleted = [];


        // --- STEP 1: Download Core JSON File and Perform Scan ---
        $coreHashes = $this->_fetchAndCacheBaseline('core', null, $forceRefresh);
        if ($coreHashes === null) {
            return false;
        }

        // Get hashes for all files EXCEPT permanent excludes
        $currentHashes = $this->_getHashes();
        $initialModified = [];
        $initialAdded = [];
        $basePath = Core::getBaseDir();

        // Scan local files
        foreach ($currentHashes as $filePath => $currentHash) {
            // Check if the file is in the MONITORED list
            if ($this->_isPathExcluded($filePath, $monitoredExcludes)) {
                $fullPath = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $filePath);
                $lastModifiedTime = @filemtime($fullPath);

                if (isset($cachedExcludedHashes[$filePath])) {
                    if ($cachedExcludedHashes[$filePath]['hash'] !== $currentHash) {
                        $excludedModified[$filePath] = $cachedExcludedHashes[$filePath]['last_modified'];
                    }
                } else {
                    $excludedAdded[] = $filePath;
                }
                $newExcludedHashesCache[$filePath] = ['hash' => $currentHash, 'last_modified' => $lastModifiedTime];
            } else {
                // If not monitored, treat as a standard file and compare to baseline
                if (isset($coreHashes[$filePath])) {
                    if ($currentHash !== $coreHashes[$filePath]) {
                        $initialModified[] = $filePath;
                    }
                } else {
                    $initialAdded[] = $filePath;
                }
            }
        }

        // Check for deleted monitored files
        foreach ($cachedExcludedHashes as $filePath => $cacheData) {
            if (!isset($currentHashes[$filePath])) {
                $excludedDeleted[$filePath] = $cacheData['last_modified'];
            }
        }

        // Save the updated cache for monitored files
        $this->_saveJsonFile($excludedHashesCacheFile, $newExcludedHashesCache);


        // Check for deleted core files (that are not in the monitored list)
        $initialDeleted = [];
        foreach ($coreHashes as $filePath => $baselineHash) {
            if (!isset($currentHashes[$filePath]) && !$this->_isPathExcluded($filePath, $monitoredExcludes)) {
                $initialDeleted[] = $filePath;
            }
        }

        // --- STEP 2: Separate Results & Identify Plugins for Re-validation ---
        $finalModified = [];
        $finalAdded = [];
        $finalDeleted = $initialDeleted;

        $pluginsToRecheck = [];
        foreach (array_merge($initialModified, $initialAdded) as $filePath) {
            if (strpos($filePath, 'plugins/') === 0) {
                $parts = explode('/', $filePath);
                if (count($parts) >= 3) {
                    $pluginDir = 'plugins/' . $parts[1] . '/' . $parts[2];
                    if (!isset($pluginsToRecheck[$pluginDir])) {
                        $pluginsToRecheck[$pluginDir] = [];
                    }
                    $pluginsToRecheck[$pluginDir][] = $filePath;
                }
            } else {
                if (isset($coreHashes[$filePath])) {
                    $finalModified[] = $filePath;
                } else {
                    $finalAdded[] = $filePath;
                }
            }
        }

        // --- STEP 3: Download Plugin JSON and Perform Re-validation ---
        foreach ($pluginsToRecheck as $pluginDir => $files) {
            $parts = explode('/', $pluginDir);
            $category = $parts[1];
            $pluginName = basename($pluginDir);
            $pluginHashes = null;

            $plugin = PluginRegistry::loadPlugin($category, $pluginName);
            if ($plugin && ($version = $plugin->getCurrentVersion())) {
                $pluginHashes = $this->_fetchAndCacheBaseline('plugin', [
                    'category'   => $category,
                    'pluginName' => $pluginName,
                    'version'    => $version->getVersionString(),
                ], $forceRefresh);
            }

            if ($pluginHashes === null) {
                $finalAdded = array_merge($finalAdded, $files);
                continue;
            }

            foreach ($files as $filePath) {
                if (isset($currentHashes[$filePath])) {
                    if (!isset($pluginHashes[$filePath])) {
                        $finalAdded[] = $filePath;
                    } elseif ($currentHashes[$filePath] !== $pluginHashes[$filePath]) {
                        $finalModified[] = $filePath;
                    }
                }
            }

            foreach ($pluginHashes as $filePath => $hash) {
                if ($this->_isPathExcluded($filePath, $monitoredExcludes)) {
                    continue;
                }

                if (strpos($filePath, $pluginDir) === 0 && !isset($currentHashes[$filePath])) {
                    $finalDeleted[] = $filePath;
                }
            }
        }

        // --- STEP 4: Send Final Results via Email ---
        $finalModified = array_unique($finalModified);
        $finalAdded = array_unique($finalAdded);
        $finalDeleted = array_unique($finalDeleted);

        $this->_sendNotificationEmail(
            $finalModified,
            $finalAdded,
            $finalDeleted,
            $monitoredExcludes,
            $excludedModified,
            $excludedAdded,
            $excludedDeleted
        );
        return true;
    }

    /**
     * Fetches the hash baseline from GitHub or local cache.
     *
     * @param string $type Baseline type ('core' or 'plugin').
     * @param array|null $pluginData Plugin data if type is 'plugin'.
     * @param bool $forceRefresh If true, ignores the cache.
     * @return array|null An array of hashes with path as key, or null on failure.
     */
    private function _fetchAndCacheBaseline($type, $pluginData = null, $forceRefresh = false)
    {
        $cacheDir = Core::getBaseDir() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'integrityFilesScan';
        $encryption_key = Config::getVar('security', 'salt');
        $url = null;
        $cacheId = null;

        if ($type === 'core') {
            $versionString = Application::get()->getCurrentVersion()->getVersionString();
            $cacheId = 'core-' . $versionString;
            $url = self::GITHUB_HASH_REPO_URL . 'core/ojs-' . $versionString . '.json';
        } elseif ($type === 'plugin') {
            $versionString = $pluginData['version'];
            $pluginName = $pluginData['pluginName'];
            $category = $pluginData['category'];
            $cacheId = "plugin-{$category}-{$pluginName}-{$versionString}";
            $url = self::GITHUB_HASH_REPO_URL . "plugins/{$category}/{$pluginName}-{$versionString}.json";
        } else {
            return null;
        }

        $cacheFileName = 'integrity_hashes_' . hash_hmac('sha256', $cacheId, $encryption_key) . '.json';
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheFileName;

        $jsonContent = null;
        if (!$forceRefresh && file_exists($cacheFile)) {
            $jsonContent = @file_get_contents($cacheFile);
        }

        if (empty($jsonContent)) {
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ]);
            $downloadedContent = @file_get_contents($url, false, $context);

            if ($downloadedContent) {
                $jsonContent = $downloadedContent;
                if (!is_dir($cacheDir)) {
                    @mkdir($cacheDir, 0700, true);
                }
                @file_put_contents($cacheFile, $jsonContent);
                @chmod($cacheFile, 0600);
            } else {
                error_log('FileIntegrityPlugin: Failed to download baseline from ' . $url);
                return null;
            }
        }

        $hashes = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($hashes)) {
            error_log('FileIntegrityPlugin: Received invalid JSON from ' . $url);
            return null;
        }

        foreach ($hashes as $path => $hash) {
            if (!is_string($path) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/i', $hash)) {
                error_log('FileIntegrityPlugin: Found corrupted hash data in baseline from ' . $url);
                return null;
            }
        }

        if ($type === 'plugin') {
            $prefixedHashes = [];
            $pluginPathPrefix = 'plugins/' . $pluginData['category'] . '/' . $pluginData['pluginName'] . '/';

            foreach ($hashes as $subPath => $hash) {
                $prefixedHashes[$pluginPathPrefix . $subPath] = $hash;
            }
            return $prefixedHashes;
        }

        return $hashes;
    }

    /**
     * Calculates the SHA256 hash of all files, ignoring only permanent excludes.
     *
     * @return array An array of hashes with the relative path from the base directory as the key.
     */
    private function _getHashes()
    {
        $hashes = [];
        $basePath = Core::getBaseDir();

        // 3. Permanent Excludes (to be completely ignored)
        $permanentExcludes = [
            realpath(Config::getVar('files', 'files_dir')),
            realpath($basePath . '/cache')
        ];

        $permanentExcludes = array_filter($permanentExcludes);
        $permanentExcludes = array_map(function ($path) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }, $permanentExcludes);

        try {
            $directoryIterator = new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS);
            $iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::SELF_FIRST);
        } catch (Exception $e) {
            return [];
        }

        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isReadable()) continue;

            $filePath = $file->getRealPath();
            $isPermanentlyExcluded = false;
            foreach ($permanentExcludes as $excludedPath) {
                if ($excludedPath && strpos($filePath, $excludedPath) === 0) {
                    $isPermanentlyExcluded = true;
                    break;
                }
            }

            if ($isPermanentlyExcluded || !$file->isFile()) {
                continue;
            }

            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $filePath);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            $hashes[$relativePath] = hash_file('sha256', $filePath);
        }
        return $hashes;
    }

    /**
     * Checks if a given file path should be excluded based on a list of patterns.
     *
     * @param string $filePath The relative path of the file to check.
     * @param array $excludedPatterns An array of file and directory paths to exclude.
     * @return bool True if the path should be excluded, false otherwise.
     */
    private function _isPathExcluded($filePath, $excludedPatterns)
    {
        foreach ($excludedPatterns as $pattern) {
            $pattern = rtrim($pattern, '/');
            if ($filePath === $pattern || strpos($filePath . '/', $pattern . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sends a notification email summarizing the scan results.
     */
    private function _sendNotificationEmail($modified, $added, $deleted, $monitored, $excludedModified, $excludedAdded, $excludedDeleted)
    {
        $site = Application::get()->getRequest()->getSite();
        $contactEmail = $site->getLocalizedContactEmail();
        $hasIssues = !empty($modified) || !empty($added) || !empty($deleted) || !empty($excludedModified) || !empty($excludedAdded) || !empty($excludedDeleted);

        $mail = new MailTemplate();
        $mail->setContentType('text/html; charset=utf-8');

        if ($hasIssues) {
            $mail->setSubject(__('plugins.generic.fileIntegrity.email.subject'));
            $body = '<p>' . __('plugins.generic.fileIntegrity.email.body.issues') . '</p>';
        } else {
            $mail->setSubject(__('plugins.generic.fileIntegrity.email.subject.noIssues'));
            $body = '<p>' . __('plugins.generic.fileIntegrity.email.body.noIssues') . '</p>';
        }

        if (filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->addRecipient($contactEmail, $site->getLocalizedContactName());
        }

        $plugin = PluginRegistry::loadPlugin('generic', 'ashFileIntegrity');

        if ($plugin) {
            $request = Application::get()->getRequest();
            $context = $request->getContext();
            $contextId = $context ? $context->getId() : CONTEXT_SITE;
            $additionalEmailsSetting = $plugin->getSetting($contextId, 'additionalEmails');

            if (!empty($additionalEmailsSetting)) {
                $emails = preg_split('/[\s,;\v]+/', $additionalEmailsSetting);
                foreach ($emails as $email) {
                    $trimmedEmail = trim($email);
                    if (filter_var($trimmedEmail, FILTER_VALIDATE_EMAIL)) {
                        $mail->addRecipient($trimmedEmail);
                    }
                }
            }
        }

        // Helper function for formatting lists with timestamps
        $formatListWithTime = function ($files, $isAssociative = true) {
            $list = '<ul>';
            foreach ($files as $file => $time) {
                if (!$isAssociative) { // For simple arrays like $added
                    $file = $time;
                    $time = null;
                }
                $list .= '<li>' . htmlspecialchars($file);
                if ($time) {
                    $list .= ' <small>(Last seen: ' . date('Y-m-d H:i:s', $time) . ')</small>';
                }
                $list .= '</li>';
            }
            $list .= '</ul>';
            return $list;
        };

        if (!empty($modified)) {
            $body .= '<h3>' . __('plugins.generic.fileIntegrity.email.body.modified') . '</h3>';
            $body .= '<p>' . __('plugins.generic.fileIntegrity.email.body.modified.description') . '</p>';
            $body .= '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $modified)) . '</li></ul><hr>';
        }
        if (!empty($added)) {
            $body .= '<h3>' . __('plugins.generic.fileIntegrity.email.body.added') . '</h3>';
            $body .= '<p>' . __('plugins.generic.fileIntegrity.email.body.added.description') . '</p>';
            $body .= '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $added)) . '</li></ul><hr>';
        }
        if (!empty($deleted)) {
            $body .= '<h3>' . __('plugins.generic.fileIntegrity.email.body.deleted') . '</h3>';
            $body .= '<p>' . __('plugins.generic.fileIntegrity.email.body.deleted.description') . '</p>';
            $body .= '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $deleted)) . '</li></ul><hr>';
        }
        if (!empty($monitored)) {
            $body .= '<h3>' . __('plugins.generic.fileIntegrity.email.body.excluded') . '</h3>';
            $body .= '<p>' . __('plugins.generic.fileIntegrity.email.body.excluded.description') . '</p>';
            $body .= '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $monitored)) . '</li></ul><hr>';
        }
        if (!empty($excludedModified)) {
            $body .= '<h3>' . __('plugins.generic.fileIntegrity.email.body.excludedModified') . '</h3>';
            $body .= '<p>' . __('plugins.generic.fileIntegrity.email.body.excludedModified.description') . '</p>';
            $body .= $formatListWithTime($excludedModified) . '<hr>';
        }
        if (!empty($excludedAdded)) {
            $body .= '<h3>' . __('plugins.generic.fileIntegrity.email.body.excludedAdded') . '</h3>';
            $body .= '<p>' . __('plugins.generic.fileIntegrity.email.body.excludedAdded.description') . '</p>';
            $body .= $formatListWithTime($excludedAdded, false) . '<hr>';
        }
        if (!empty($excludedDeleted)) {
            $body .= '<h3>' . __('plugins.generic.fileIntegrity.email.body.excludedDeleted') . '</h3>';
            $body .= '<p>' . __('plugins.generic.fileIntegrity.email.body.excludedDeleted.description') . '</p>';
            $body .= $formatListWithTime($excludedDeleted) . '<hr>';
        }

        $mail->setBody($body);
        $mail->send();
    }

    /**
     * Helper methods for cache file handling
     */
    private function _getExcludedHashesCacheFile()
    {
        $cacheDir = Core::getBaseDir() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'integrityFilesScan';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0700, true);
        }

        $encryption_key = Config::getVar('security', 'salt');
        $cacheId = 'excluded-hashes-v1';
        $cacheFileName = 'excluded_hashes_' . hash_hmac('sha256', $cacheId, $encryption_key) . '.json';

        return $cacheDir . DIRECTORY_SEPARATOR . $cacheFileName;
    }

    private function _loadJsonFile($filePath)
    {
        if (!file_exists($filePath)) {
            return [];
        }
        $content = @file_get_contents($filePath);
        if (empty($content)) {
            return [];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || !isset($data['payload']) || !isset($data['hmac'])) {
            return [];
        }

        $encryption_key = Config::getVar('security', 'salt');
        $expectedHmac = hash_hmac('sha256', json_encode($data['payload']), $encryption_key);

        if (!hash_equals($expectedHmac, $data['hmac'])) {
            error_log('FileIntegrityPlugin: HMAC verification failed for ' . $filePath . '. The cache file may be corrupted or tampered with.');
            return [];
        }

        return is_array($data['payload']) ? $data['payload'] : [];
    }

    private function _saveJsonFile($filePath, $data)
    {
        $encryption_key = Config::getVar('security', 'salt');
        $payload = $data;
        $hmac = hash_hmac('sha256', json_encode($payload), $encryption_key);
        $contentToSave = ['payload' => $payload, 'hmac' => $hmac];
        @file_put_contents($filePath, json_encode($contentToSave, JSON_PRETTY_PRINT));
        @chmod($filePath, 0600);
    }
}

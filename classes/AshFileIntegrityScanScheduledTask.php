<?php

/**
 * @file plugins/generic/ashFileIntegrity/classes/AshFileIntegrityScanScheduledTask.php
 *
 * Copyright (c) 2025 AshVisualTheme
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AshFileIntegrityScanScheduledTask
 * @ingroup plugins_generic_ashFileIntegrity
 *
 * @brief Scheduled task to run the file integrity scan.
 */

namespace APP\plugins\generic\ashFileIntegrity\classes;

use PKP\scheduledTask\ScheduledTask;
use PKP\plugins\PluginRegistry;
use PKP\mail\Mailable;
use Illuminate\Support\Facades\Mail;
use PKP\config\Config;
use GuzzleHttp\Exception\RequestException;
use APP\core\Application;
use PKP\core\Core;
use RecursiveDirectoryIterator;
use FilesystemIterator;
use RecursiveIteratorIterator;
use Exception;

class AshFileIntegrityScanScheduledTask extends ScheduledTask
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

        $pluginCategories = PluginRegistry::getCategories();
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

        // --- STEP 0: Load Settings & Define Exclusion Lists ---
        $plugin = PluginRegistry::getPlugin('generic', 'ashfileintegrityplugin');

        // 1. Manual Excludes (from settings)
        $manualExcludesSetting = $plugin->getSetting(CONTEXT_SITE, 'manualExcludes');
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
            $isPluginFile = false;
            if (strpos($filePath, 'plugins/') === 0) {
                $parts = explode('/', $filePath);
                if (count($parts) >= 3) {
                    $pluginDir = 'plugins/' . $parts[1] . '/' . $parts[2];
                    if (!isset($pluginsToRecheck[$pluginDir])) {
                        $pluginsToRecheck[$pluginDir] = [];
                    }
                    $pluginsToRecheck[$pluginDir][] = $filePath;
                    $isPluginFile = true;
                }
            }
            if (!$isPluginFile) {
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
            // $pluginName di sini adalah NAMA DIREKTORI (misal: 'ashfileintegrity')
            $pluginDirName = basename($pluginDir);
            $pluginHashes = null;

            // Find the plugin object by its directory name, not its registered name,
            // as getPlugin() only works for enabled plugins.
            $foundPlugin = null;
            $allPluginsInCategory = PluginRegistry::getPlugins($category);
            if (is_array($allPluginsInCategory)) {
                foreach ($allPluginsInCategory as $pluginObject) {
                    // Match exact directory name (e.g., 'material')
                    // or suffixed name for built-in plugins (e.g., 'quickSubmit' dir vs 'quickSubmitPlugin' registered name)
                    $registeredDirName = $pluginObject->getDirName();
                    if ($registeredDirName === $pluginDirName || $registeredDirName === $pluginDirName . 'Plugin') {
                        $foundPlugin = $pluginObject;
                        break;
                    }
                }
            }
            $plugin = $foundPlugin;

            $versionString = null;
            $registeredPluginName = null;

            if ($plugin) {
                // Plugin is active, get version and name from the object
                $version = $plugin->getCurrentVersion();
                if ($version) {
                    $versionString = $version->getVersionString();
                    $registeredPluginName = $plugin->getName();
                }
            } else {
                // Plugin is not active, try to read version.xml as a fallback
                $versionFile = Core::getBaseDir() . DIRECTORY_SEPARATOR . $pluginDir . DIRECTORY_SEPARATOR . 'version.xml';
                if (file_exists($versionFile)) {
                    $versionXml = @simplexml_load_file($versionFile);
                    if ($versionXml && isset($versionXml->release)) {
                        $versionString = (string) $versionXml->release;
                        // We don't have the registered name, but the directory name is what we need for the URL
                        $registeredPluginName = $pluginDirName; // Fallback for logging/cache ID
                    }
                }
            }

            if ($versionString) {
                $pluginHashes = $this->_fetchAndCacheBaseline('plugin', [
                    'category'   => $category,
                    'pluginName' => $registeredPluginName,
                    'pluginDirName' => $pluginDirName, // This is the directory name, which is correct for the URL
                    'version'    => $versionString,
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
            $pluginDirName = $pluginData['pluginDirName'];
            $cacheId = "plugin-{$category}-{$pluginName}-{$versionString}";
            $url = self::GITHUB_HASH_REPO_URL . "plugins/{$category}/{$pluginDirName}-{$versionString}.json";
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
            try {
                // Use the application's HTTP client, which respects config.inc.php proxy settings
                $httpClient = Application::get()->getHttpClient();
                $response = $httpClient->request('GET', $url);

                if ($response->getStatusCode() === 200) {
                    $jsonContent = $response->getBody()->getContents();
                    if (!is_dir($cacheDir)) {
                        @mkdir($cacheDir, 0700, true);
                    }
                    @file_put_contents($cacheFile, $jsonContent);
                    @chmod($cacheFile, 0600);
                } else {
                    error_log('FileIntegrityPlugin: Failed to download baseline from ' . $url . '. Status: ' . $response->getStatusCode());
                    return null;
                }
            } catch (RequestException $e) {
                error_log('FileIntegrityPlugin: Failed to download baseline from ' . $url . ' - Reason: ' . $e->getMessage());
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
            $pluginPathPrefix = 'plugins/' . $pluginData['category'] . '/' . $this->_getPluginDirNameFromRegisteredName($pluginData['category'], $pluginData['pluginName']) . '/';

            foreach ($hashes as $subPath => $hash) {
                $prefixedHashes[$pluginPathPrefix . $subPath] = $hash;
            }
            return $prefixedHashes;
        }

        return $hashes;
    }

    /**
     * Finds a plugin's directory name based on its registered name.
     * This is necessary because the registered name (used for URLs) can differ from the directory name.
     *
     * @param string $category The plugin category.
     * @param string $registeredName The registered name of the plugin (e.g., 'ashfileintegrityplugin').
     * @return string|null The directory name (e.g., 'ashFileIntegrity') or null if not found.
     */
    private function _getPluginDirNameFromRegisteredName($category, $registeredName)
    {
        $allPluginsInCategory = PluginRegistry::getPlugins($category);
        if (is_array($allPluginsInCategory)) {
            foreach ($allPluginsInCategory as $pluginObject) {
                if (strtolower($pluginObject->getName()) === strtolower($registeredName)) {
                    return $pluginObject->getDirName();
                }
            }
        }
        // Fallback for cases where the plugin might not be found directly,
        // assuming the registered name is close to the directory name.
        // This handles cases like 'ashfileintegrityplugin' -> 'ashFileIntegrity'.
        // It's not perfect but better than failing.
        return str_replace(strtolower($category) . 'plugin', '', $registeredName);
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
        $contactName = $site->getLocalizedContactName();
        $hasIssues = !empty($modified) || !empty($added) || !empty($deleted) || !empty($excludedModified) || !empty($excludedAdded) || !empty($excludedDeleted);

        $mail = new Mailable();

        if ($hasIssues) {
            $subject = __('plugins.generic.fileIntegrity.email.subject');
            $body = '<p>' . __('plugins.generic.fileIntegrity.email.body.issues') . '</p>';
        } else {
            $subject = __('plugins.generic.fileIntegrity.email.subject.noIssues');
            $body = '<p>' . __('plugins.generic.fileIntegrity.email.body.noIssues') . '</p>';
        }
        $mail->subject($subject);

        // Set the 'From' header using the site's principal contact
        if (filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->from($contactEmail, $contactName);
        }

        if (filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->to($contactEmail, $site->getLocalizedContactName());
        }

        // Collect additional emails from the site-wide settings
        $plugin = PluginRegistry::getPlugin('generic', 'ashfileintegrityplugin');
        if ($plugin) {
            $siteEmailsSetting = $plugin->getSetting(CONTEXT_SITE, 'additionalEmails');
            if (!empty($siteEmailsSetting)) {
                $emails = preg_split('/[\s,;\v]+/', $siteEmailsSetting);
                foreach (array_unique($emails) as $email) {
                    $trimmedEmail = trim($email);
                    if (filter_var($trimmedEmail, FILTER_VALIDATE_EMAIL)) {
                        $mail->to($trimmedEmail);
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

        $mail->body($body);

        if (!empty($mail->to)) {
            Mail::send($mail);
        }
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

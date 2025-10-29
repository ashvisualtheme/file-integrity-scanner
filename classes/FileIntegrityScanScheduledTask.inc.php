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

class FileIntegrityScanScheduledTask extends ScheduledTask
{
    // Base URL of the hash repository on GitHub
    const GITHUB_HASH_REPO_URL = 'https://raw.githubusercontent.com/ashvisualtheme/hash-repo/main/ojs/';

    /**
     * Cleans up orphaned cache files from previous versions of OJS or plugins.
     * This method prevents the cache directory from accumulating outdated files over time
     * by removing any cache files that do not correspond to the currently installed software versions.
     *
     * @return void
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

        // --- STEP 0: Load Manual Excludes from Settings ---
        // Instantiate the plugin directly to ensure it's available.
        $plugin = PluginRegistry::loadPlugin('generic', 'ashFileIntegrity');
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $contextId = $context ? $context->getId() : CONTEXT_SITE;

        $manualExcludesSetting = $plugin->getSetting($contextId, 'manualExcludes');
        $manualExcludes = [];
        if (!empty($manualExcludesSetting)) {
            // Convert the newline-separated string into an array of paths, trimming whitespace and removing empty lines.
            $manualExcludes = array_filter(array_map('trim', explode("\n", $manualExcludesSetting)));
        }
        // Also add the default ignored files to this list.
        $manualExcludes[] = 'config.inc.php';
        $manualExcludes[] = 'public/index.html';

        // --- STEP 1: Download Core JSON File and Perform Initial Scan ---
        // Fetches the application's Core baseline hashes.
        $coreHashes = $this->_fetchAndCacheBaseline('core', null, $forceRefresh);
        if ($coreHashes === null) {
            return false;
        }

        // Calculates the hashes of the locally installed files.
        $currentHashes = $this->_getHashes();
        $initialModified = [];
        $initialAdded = [];
        $initialDeleted = [];

        // Initial comparison against Core files.
        foreach ($coreHashes as $filePath => $baselineHash) {
            // Skip manually excluded files.
            if ($this->_isPathExcluded($filePath, $manualExcludes)) {
                continue;
            }

            // Deleted File: Exists in baseline, not in local.
            if (!isset($currentHashes[$filePath])) {
                $initialDeleted[] = $filePath;
                // Modified File: Exists in both, but hashes do not match.
            } elseif ($currentHashes[$filePath] !== $baselineHash) {
                $initialModified[] = $filePath;
            }
        }

        // Added File: Exists in local, not in Core baseline.
        foreach ($currentHashes as $filePath => $currentHash) {
            // Skip manually excluded files.
            if (!isset($coreHashes[$filePath]) && !$this->_isPathExcluded($filePath, $manualExcludes)) {
                $initialAdded[] = $filePath;
            }
        }

        // --- STEP 2: Separate Results & Identify Plugins for Re-validation ---
        $finalModified = [];
        $finalAdded = [];
        $finalDeleted = $initialDeleted; // Deleted core files are final

        $pluginsToRecheck = [];
        // Groups Modified/Added files that are in the 'plugins/' folder for re-validation against plugin baselines.
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
                // Non-plugin files are added directly to the final results.
                if (isset($coreHashes[$filePath])) { // Must exist in baseline to be "modified"
                    $finalModified[] = $filePath;
                } else { // Otherwise it's "added"
                    $finalAdded[] = $filePath;
                }
            }
        }

        // --- STEP 3: Download Plugin JSON and Perform Re-validation ---
        // Iterates through each identified plugin for re-validation.
        foreach ($pluginsToRecheck as $pluginDir => $files) {
            $parts = explode('/', $pluginDir);
            $category = $parts[1];
            $pluginName = basename($pluginDir);
            $pluginPathName = $pluginName;
            $failureReason = null;
            $pluginHashes = null;

            // Tries to load the plugin object to get the version string.
            $plugin = PluginRegistry::loadPlugin($category, $pluginPathName);
            $canProceed = false;

            if (!$plugin) {
                $failureReason = 'Plugin object not found.';
            } else {
                $version = $plugin->getCurrentVersion();
                if (!$version) {
                    $failureReason = 'Plugin version string not available.';
                } else {
                    $canProceed = true;
                }
            }

            if ($canProceed) {
                $versionString = $version->getVersionString();
                // Fetches the Plugin baseline hash.
                $pluginHashes = $this->_fetchAndCacheBaseline('plugin', [
                    'category'   => $category,
                    'pluginName' => $pluginName,
                    'version'    => $versionString,
                ], $forceRefresh);

                if ($pluginHashes === null) {
                    $failureReason = 'Failed to download JSON baseline from GitHub/cache.';
                }
            }

            // If plugin hash retrieval failed, all associated files are marked as 'Added'.
            if ($pluginHashes === null) {
                if (is_null($failureReason)) {
                    $failureReason = 'Unknown error.';
                }
                // If hashes fail to download, these files are treated as unexpected modifications/additions.
                $finalAdded = array_merge($finalAdded, $files);
                continue;
            }

            // Re-validates the plugin files.
            foreach ($files as $filePath) {
                if (isset($currentHashes[$filePath])) {
                    // Added File: Exists locally, not in plugin baseline (may be a custom file).
                    if (!isset($pluginHashes[$filePath])) {
                        $finalAdded[] = $filePath;
                        // Modified File: Exists in local and plugin baseline, but hashes do not match.
                    } elseif ($currentHashes[$filePath] !== $pluginHashes[$filePath]) {
                        $finalModified[] = $filePath;
                    }
                    // If hashes match, it's an authentic plugin file (false positive eliminated).
                }
            }

            // Checks for files possibly deleted from the plugin (Exists in plugin baseline, not in local).
            foreach ($pluginHashes as $filePath => $hash) {
                // Skip manually excluded files.
                if ($this->_isPathExcluded($filePath, $manualExcludes)) {
                    continue;
                }

                if (strpos($filePath, $pluginDir) === 0 && !isset($currentHashes[$filePath])) {
                    $finalDeleted[] = $filePath;
                }
            }
        }

        // --- STEP 4: Send Final Results via Email ---
        // Removes duplicates and counts the final results.
        $finalModified = array_unique($finalModified);
        $finalAdded = array_unique($finalAdded);
        $finalDeleted = array_unique($finalDeleted);

        $modifiedCount = count($finalModified);
        $addedCount = count($finalAdded);
        $deletedCount = count($finalDeleted);

        // Always send a notification email. The content will vary based on scan results.
        $this->_sendNotificationEmail($finalModified, $finalAdded, $finalDeleted, $manualExcludes);
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
        $cacheFileName = null;
        $cacheId = null;

        if ($type === 'core') {
            // Creates URL and cache ID for Core hashes based on the application version.
            $versionString = Application::get()->getCurrentVersion()->getVersionString();
            $cacheId = 'core-' . $versionString;
            $url = self::GITHUB_HASH_REPO_URL . 'core/ojs-' . $versionString . '.json';
        } elseif ($type === 'plugin') {
            // Creates URL and cache ID for Plugin hashes based on category, name, and version.
            $versionString = $pluginData['version'];
            $pluginName = $pluginData['pluginName'];
            $category = $pluginData['category'];
            $cacheId = "plugin-{$category}-{$pluginName}-{$versionString}";
            $url = self::GITHUB_HASH_REPO_URL . "plugins/{$category}/{$pluginName}-{$versionString}.json";
        } else {
            return null;
        }

        // Uses HMAC hash for the cache filename for security.
        $cacheFileName = 'integrity_hashes_' . hash_hmac('sha256', $cacheId, $encryption_key) . '.json';
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheFileName;

        $jsonContent = null;

        // Step 1: Try reading from the cache first
        if (!$forceRefresh && file_exists($cacheFile)) {
            $jsonContent = @file_get_contents($cacheFile);
        }

        // Step 2: If not in cache (or content is empty), download from GitHub
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
                // Save to cache for future use
                if (!is_dir($cacheDir)) {
                    @mkdir($cacheDir, 0755, true);
                }
                @file_put_contents($cacheFile, $jsonContent);
            } else {
                // If download fails, stop the process
                error_log('FileIntegrityPlugin: Failed to download baseline from ' . $url);
                return null;
            }
        }

        // Step 3: Decode and validate the JSON content
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

        // Step 4: Perform path correction for plugins (logic from the previous `process_hashes`)
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
     * Calculates the SHA256 hash of all relevant files in the installation.
     *
     * @return array An array of hashes with the relative path from the base directory as the key.
     */
    private function _getHashes()
    {
        $hashes = [];
        $basePath = Core::getBaseDir();
        // Retrieves paths that must be excluded from the scan (e.g., files, cache, lscache folders).
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

        // Uses a recursive directory iterator to traverse all files.
        try {
            $directoryIterator = new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS);
            $iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::SELF_FIRST);
        } catch (Exception $e) {
            return [];
        }

        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isReadable()) continue;

            $filePath = $file->getRealPath();
            $isExcluded = false;
            // Checks if the file path is within the list of excluded paths.
            foreach ($excludedPaths as $excludedPath) {
                if ($excludedPath && strpos($filePath, $excludedPath) === 0) {
                    $isExcluded = true;
                    break;
                }
            }

            // Skips directories, excluded files, or config.inc.php.
            if ($isExcluded || !$file->isFile() || basename($filePath) == 'config.inc.php') {
                continue;
            }

            // Calculates the SHA256 hash and stores it with the relative path as the key.
            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $filePath);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            $hashes[$relativePath] = hash_file('sha256', $filePath);
        }
        return $hashes;
    }

    /**
     * Checks if a given file path should be excluded based on the manual excludes list.
     * This supports both exact file matches and directory-based exclusions.
     *
     * @param string $filePath The relative path of the file to check.
     * @param array $excludedPatterns An array of file and directory paths to exclude.
     * @return bool True if the path should be excluded, false otherwise.
     */
    private function _isPathExcluded($filePath, $excludedPatterns)
    {
        foreach ($excludedPatterns as $pattern) {
            // Trim trailing slash for consistent directory matching
            $pattern = rtrim($pattern, '/');

            // Check for exact match or if the path is inside an excluded directory.
            // The `.` concatenation ensures "plugins/generic/test" doesn't match "plugins/generic/testing".
            if ($filePath === $pattern || strpos($filePath . '/', $pattern . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sends a notification email summarizing the scan results.
     *
     * @param array $modified List of modified files.
     * @param array $added List of added files.
     * @param array $deleted List of deleted files.
     * @param array $excluded List of excluded files and directories.
     */
    private function _sendNotificationEmail($modified, $added, $deleted, $excluded)
    {
        import('lib.pkp.classes.mail.MailTemplate');
        $site = Application::get()->getRequest()->getSite();
        $contactEmail = $site->getLocalizedContactEmail();
        $hasIssues = !empty($modified) || !empty($added) || !empty($deleted);

        // Uses MailTemplate to send an email to the site contact.
        $mail = new MailTemplate();
        $mail->setContentType('text/html; charset=utf-8');

        if ($hasIssues) {
            $mail->setSubject(__('plugins.generic.fileIntegrity.email.subject'));
            $body = '<p>' . __('plugins.generic.fileIntegrity.email.body.issues') . '</p>';
        } else {
            $mail->setSubject(__('plugins.generic.fileIntegrity.email.subject.noIssues'));
            $body = '<p>' . __('plugins.generic.fileIntegrity.email.body.noIssues') . '</p>';
        }

        $mail->addRecipient($contactEmail, $site->getLocalizedContactName());

        // Constructs the email body with a list of problematic files.
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

        // Always include the list of excluded files for transparency.
        if (!empty($excluded)) {
            $body .= '<h2>' . __('plugins.generic.fileIntegrity.email.body.excluded') . '</h2>';
            $body .= '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $excluded)) . '</li></ul>';
        }

        $mail->setBody($body);
        $mail->send();
    }
}

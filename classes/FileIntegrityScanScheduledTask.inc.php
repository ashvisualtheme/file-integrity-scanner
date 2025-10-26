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
     * Executes the task actions.
     *
     * @param bool $forceRefresh If true, baseline hashes will be redownloaded from GitHub, ignoring the cache.
     * @return bool True if successful, false if failed.
     */
    public function executeActions($forceRefresh = false)
    {
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
            if (!isset($coreHashes[$filePath])) {
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
                if (in_array($filePath, $initialModified)) $finalModified[] = $filePath;
                if (in_array($filePath, $initialAdded)) $finalAdded[] = $filePath;
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

        // Sends an email only if issues are detected.
        if ($modifiedCount === 0 && $addedCount === 0 && $deletedCount === 0) {
            return true;
        }

        $this->_sendNotificationEmail($finalModified, $finalAdded, $finalDeleted);
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

        // Reads from cache if available and not forced to refresh.
        if (!$forceRefresh && file_exists($cacheFile)) {
            $jsonContent = @file_get_contents($cacheFile);
            if ($jsonContent) {
                $hashes = json_decode($jsonContent, true);
                goto process_hashes;
            }
        }

        // Downloads from the GitHub URL.
        $jsonContent = @file_get_contents($url);
        if ($jsonContent === false) {
            return null;
        }

        // Saves to cache.
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        @file_put_contents($cacheFile, $jsonContent);

        $hashes = json_decode($jsonContent, true);

        process_hashes:

        // Plugin Path Correction: Plugin baseline hash files are usually relative to the plugin folder,
        // but local hashes use the full relative path (e.g., plugins/generic/pluginName/file.php).
        // A prefix is added so the hash keys match the local file paths.
        if ($type === 'plugin' && $hashes !== null) {
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
     * Sends a notification email summarizing the scan results.
     *
     * @param array $modified List of modified files.
     * @param array $added List of added files.
     * @param array $deleted List of deleted files.
     */
    private function _sendNotificationEmail($modified, $added, $deleted)
    {
        import('lib.pkp.classes.mail.MailTemplate');
        $site = Application::get()->getRequest()->getSite();
        $contactEmail = $site->getLocalizedContactEmail();

        // Uses MailTemplate to send an email to the site contact.
        $mail = new MailTemplate();
        $mail->setContentType('text/html; charset=utf-8');
        $mail->setSubject(__('plugins.generic.fileIntegrity.email.subject'));
        $mail->addRecipient($contactEmail, $site->getLocalizedContactName());

        $body = '<p>' . __('plugins.generic.fileIntegrity.email.body.issues') . '</p>';

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

        $mail->setBody($body);
        $mail->send();
    }
}

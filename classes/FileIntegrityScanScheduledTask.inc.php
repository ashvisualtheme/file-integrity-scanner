<?php

import('lib.pkp.classes.scheduledTask.ScheduledTask');
import('lib.pkp.classes.mail.Mail');
import('lib.pkp.classes.plugins.PluginRegistry');

class FileIntegrityScanScheduledTask extends ScheduledTask
{
    const GITHUB_HASH_REPO_URL = 'https://raw.githubusercontent.com/ashvisualtheme/hash-repo/main/ojs/';

    public function executeActions($forceRefresh = false)
    {
        error_log('FileIntegrityPlugin: DEBUG - Starting integrity scan. Force Refresh: ' . ($forceRefresh ? 'TRUE' : 'FALSE'));

        // --- LANGKAH 1: Unduh File Core JSON dan Lakukan Pemindaian Awal ---
        error_log('FileIntegrityPlugin: DEBUG - STEP 1: Fetching core baseline hashes.');
        $coreHashes = $this->_fetchAndCacheBaseline('core', null, $forceRefresh);
        if ($coreHashes === null) {
            error_log('FileIntegrityPlugin: CRITICAL - Failed to fetch core baseline hashes. Aborting.');
            return false;
        }
        error_log('FileIntegrityPlugin: DEBUG - Core baseline fetched successfully. Total core files: ' . count($coreHashes));

        $currentHashes = $this->_getHashes();
        error_log('FileIntegrityPlugin: DEBUG - Local files hashes calculated. Total local files: ' . count($currentHashes));

        $initialModified = [];
        $initialAdded = [];
        $initialDeleted = [];

        // Perbandingan awal terhadap file Core
        foreach ($coreHashes as $filePath => $baselineHash) {
            if (!isset($currentHashes[$filePath])) {
                $initialDeleted[] = $filePath;
            } elseif ($currentHashes[$filePath] !== $baselineHash) {
                $initialModified[] = $filePath;
            }
        }
        foreach ($currentHashes as $filePath => $currentHash) {
            if (!isset($coreHashes[$filePath])) {
                $initialAdded[] = $filePath;
            }
        }

        error_log('FileIntegrityPlugin: DEBUG - Initial comparison complete. Modified: ' . count($initialModified) . ', Added: ' . count($initialAdded) . ', Deleted: ' . count($initialDeleted));

        // --- LANGKAH 2: Pisahkan Hasil & Identifikasi Plugin yang Perlu Diverifikasi Ulang ---
        error_log('FileIntegrityPlugin: DEBUG - STEP 2: Separating results and identifying plugins for recheck.');
        $finalModified = [];
        $finalAdded = [];
        $finalDeleted = $initialDeleted; // File inti yang dihapus bersifat final

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
                // File non-plugin langsung masuk ke hasil akhir
                if (in_array($filePath, $initialModified)) $finalModified[] = $filePath;
                if (in_array($filePath, $initialAdded)) $finalAdded[] = $filePath;
            }
        }

        error_log('FileIntegrityPlugin: DEBUG - ' . count($pluginsToRecheck) . ' plugins identified for recheck.');

        // --- LANGKAH 3: Unduh JSON Plugin dan Lakukan Pemindaian Ulang ---
        error_log('FileIntegrityPlugin: DEBUG - STEP 3: Re-validating files against plugin baselines.');
        foreach ($pluginsToRecheck as $pluginDir => $files) {
            $parts = explode('/', $pluginDir);
            $category = $parts[1];
            $pluginName = basename($pluginDir);

            $pluginPathName = $pluginName;

            $failureReason = null;
            $pluginHashes = null;

            // Perbaikan: Gunakan loadPlugin untuk secara eksplisit memuat dan mendaftarkan objek plugin tunggal
            $plugin = PluginRegistry::loadPlugin($category, $pluginPathName);

            $canProceed = false;

            if (!$plugin) {
                // KASUS 1: Objek Plugin tidak dapat dimuat sama sekali (Masalah file/path)
                $failureReason = 'Plugin object not found (Registry failed to instantiate or file path mismatch).';
            } else {
                // KASUS 2: Objek Plugin berhasil dimuat. Kita dapat mengambil versi.
                $version = $plugin->getCurrentVersion();

                if (!$version) {
                    $failureReason = 'Plugin version string not available.';
                } else {
                    // Plugin ditemukan dan versi tersedia, kita BISA melanjutkan pemindaian HASH
                    $canProceed = true;

                    // Catat status plugin (hanya untuk log)
                    if (!$plugin->getEnabled()) {
                        error_log("FileIntegrityPlugin: DEBUG - Plugin {$pluginName} is disabled, but integrity check will proceed.");
                    }
                }
            }

            if ($canProceed) {
                $versionString = $version->getVersionString();
                error_log("FileIntegrityPlugin: DEBUG - Fetching baseline for plugin: {$pluginName}, version: {$versionString}");
                $pluginHashes = $this->_fetchAndCacheBaseline('plugin', [
                    'category'   => $category,
                    'pluginName' => $pluginName,
                    'version'    => $versionString,
                ], $forceRefresh);

                if ($pluginHashes === null) {
                    $failureReason = 'Failed to download JSON baseline from GitHub/cache.';
                }
            }

            if ($pluginHashes === null) {
                // Jika $pluginHashes null, berarti gagal total atau tidak dapat dimuat.
                // Jika reason sudah diisi (dari KASUS 1 atau versi tidak ada), gunakan itu.
                if (is_null($failureReason)) {
                    $failureReason = 'Unknown error (Plugin object found but hash fetch failed).';
                }

                error_log("FileIntegrityPlugin: WARNING - Failed to verify plugin {$pluginName}. Reason: {$failureReason}. All associated files marked as Added.");
                $finalAdded = array_merge($finalAdded, $files);
                continue;
            }
            error_log("FileIntegrityPlugin: DEBUG - Plugin baseline for {$pluginName} fetched. Total files in baseline: " . count($pluginHashes));

            // Lakukan verifikasi ulang untuk file-file plugin ini
            foreach ($files as $filePath) {
                if (isset($currentHashes[$filePath])) {
                    if (!isset($pluginHashes[$filePath])) {
                        $finalAdded[] = $filePath; // Ada di lokal, tidak ada di JSON plugin
                        error_log("FileIntegrityPlugin: DEBUG - Plugin file {$filePath} marked as Added (not in plugin baseline).");
                    } elseif ($currentHashes[$filePath] !== $pluginHashes[$filePath]) {
                        $finalModified[] = $filePath; // Hash tidak cocok
                        error_log("FileIntegrityPlugin: DEBUG - Plugin file {$filePath} marked as Modified (hash mismatch).");
                    }
                    // Jika hash cocok, file tersebut diabaikan (false positive dihilangkan)
                }
            }

            // Periksa file yang mungkin dihapus dari plugin
            foreach ($pluginHashes as $filePath => $hash) {
                if (strpos($filePath, $pluginDir) === 0 && !isset($currentHashes[$filePath])) {
                    $finalDeleted[] = $filePath;
                    error_log("FileIntegrityPlugin: DEBUG - Plugin file {$filePath} marked as Deleted.");
                }
            }
        }

        // --- LANGKAH 4: Kirim Hasil Final ke Email ---
        error_log('FileIntegrityPlugin: DEBUG - STEP 4: Finalizing results.');
        $finalModified = array_unique($finalModified);
        $finalAdded = array_unique($finalAdded);
        $finalDeleted = array_unique($finalDeleted);

        $modifiedCount = count($finalModified);
        $addedCount = count($finalAdded);
        $deletedCount = count($finalDeleted);

        error_log("FileIntegrityPlugin: INFO - Scan completed. Final results: Modified: {$modifiedCount}, Added: {$addedCount}, Deleted: {$deletedCount}.");

        if ($modifiedCount === 0 && $addedCount === 0 && $deletedCount === 0) {
            error_log('FileIntegrityPlugin: INFO - All files OK. No issues detected.');
            return true;
        }

        $this->_sendNotificationEmail($finalModified, $finalAdded, $finalDeleted);
        error_log('FileIntegrityPlugin: INFO - Sending notification email.');
        return true;
    }

    private function _fetchAndCacheBaseline($type, $pluginData = null, $forceRefresh = false)
    {
        $cacheDir = Core::getBaseDir() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'integrityFilesScan';
        $encryption_key = Config::getVar('security', 'salt');

        $url = null;
        $cacheFileName = null;
        $cacheId = null;

        if ($type === 'core') {
            $versionString = Application::get()->getCurrentVersion()->getVersionString();
            $cacheId = 'core-' . $versionString;
            // Format URL yang benar: ojs-3.3.0.21.json
            $url = self::GITHUB_HASH_REPO_URL . 'core/ojs-' . $versionString . '.json';
            error_log("FileIntegrityPlugin: DEBUG - Core Baseline URL: {$url}");
        } elseif ($type === 'plugin') {
            $versionString = $pluginData['version'];
            $pluginName = $pluginData['pluginName'];
            $category = $pluginData['category'];

            $cacheId = "plugin-{$category}-{$pluginName}-{$versionString}";
            // Format URL yang benar: namaPlugin-1.2.0.1.json
            $url = self::GITHUB_HASH_REPO_URL . "plugins/{$category}/{$pluginName}-{$versionString}.json";
            error_log("FileIntegrityPlugin: DEBUG - Plugin Baseline URL: {$url}");
        } else {
            error_log('FileIntegrityPlugin: ERROR - Invalid baseline type requested: ' . $type);
            return null;
        }

        $cacheFileName = 'integrity_hashes_' . hash_hmac('sha256', $cacheId, $encryption_key) . '.json';
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheFileName;

        if (!$forceRefresh && file_exists($cacheFile)) {
            error_log("FileIntegrityPlugin: DEBUG - Reading baseline from cache: {$cacheFile}");
            $jsonContent = @file_get_contents($cacheFile);
            if ($jsonContent) {
                $hashes = json_decode($jsonContent, true);
                goto process_hashes; // Lanjutkan ke pemrosesan prefix
            }
        }

        error_log("FileIntegrityPlugin: DEBUG - Downloading baseline from {$url}");
        $jsonContent = @file_get_contents($url);
        if ($jsonContent === false) {
            error_log("FileIntegrityPlugin: FAILED to download from {$url}");
            return null;
        }

        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
            error_log("FileIntegrityPlugin: DEBUG - Created cache directory: {$cacheDir}");
        }
        @file_put_contents($cacheFile, $jsonContent);
        error_log("FileIntegrityPlugin: DEBUG - Baseline saved to cache: {$cacheFile}");

        $hashes = json_decode($jsonContent, true);


        process_hashes:

        // --- KOREKSI PATH PLUGIN UNTUK MATCHING ---
        if ($type === 'plugin' && $hashes !== null) {
            $prefixedHashes = [];
            $pluginPathPrefix = 'plugins/' . $pluginData['category'] . '/' . $pluginData['pluginName'] . '/';

            foreach ($hashes as $subPath => $hash) {
                // Menambahkan prefix agar kunci hash cocok dengan path file lokal
                $prefixedHashes[$pluginPathPrefix . $subPath] = $hash;
            }
            error_log("FileIntegrityPlugin: DEBUG - Plugin hash keys prefixed with: {$pluginPathPrefix}");
            return $prefixedHashes;
        }
        // --- END KOREKSI PATH PLUGIN ---

        return $hashes;
    }

    private function _getHashes()
    {
        error_log('FileIntegrityPlugin: DEBUG - Starting local file hash calculation.');
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

        error_log('FileIntegrityPlugin: DEBUG - Excluding paths: ' . implode(', ', array_filter($excludedPaths)));

        $excludedPaths = array_filter($excludedPaths);
        $excludedPaths = array_map(function ($path) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }, $excludedPaths);

        try {
            $directoryIterator = new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS);
            $iterator = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::SELF_FIRST);
            error_log('FileIntegrityPlugin: DEBUG - Directory iteration initialized.');
        } catch (Exception $e) {
            error_log('FileIntegrityPlugin: Directory iteration failed: ' . $e->getMessage());
            return [];
        }

        $fileCount = 0;
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
            $fileCount++;
        }
        error_log('FileIntegrityPlugin: DEBUG - Local file hash calculation finished. Hashed files: ' . $fileCount);
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
        } else {
            error_log('FileIntegrityPlugin: INFO - Notification email sent successfully.');
        }
    }
}

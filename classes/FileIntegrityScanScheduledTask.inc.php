<?php

import('lib.pkp.classes.scheduledTask.ScheduledTask');
import('lib.pkp.classes.mail.Mail');
import('lib.pkp.classes.plugins.PluginRegistry');

class FileIntegrityScanScheduledTask extends ScheduledTask
{
    const GITHUB_HASH_REPO_URL = 'https://raw.githubusercontent.com/ashvisualtheme/hash-repo/main/ojs/';

    public function executeActions($forceRefresh = false)
    {
        // --- LANGKAH 1: Unduh File Core JSON dan Lakukan Pemindaian Awal ---
        $coreHashes = $this->_fetchAndCacheBaseline('core', null, $forceRefresh);
        if ($coreHashes === null) {
            error_log('FileIntegrityPlugin: CRITICAL - Failed to fetch core baseline hashes. Aborting.');
            return false;
        }

        $currentHashes = $this->_getHashes();

        $initialModified = [];
        $initialAdded = [];
        $initialDeleted = [];

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

        // --- LANGKAH 2: Pisahkan Hasil & Identifikasi Plugin yang Perlu Diverifikasi Ulang ---
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

        // --- LANGKAH 3: Unduh JSON Plugin dan Lakukan Pemindaian Ulang ---
        foreach ($pluginsToRecheck as $pluginDir => $files) {
            $parts = explode('/', $pluginDir);
            $category = $parts[1];
            $pluginName = basename($pluginDir);

            PluginRegistry::loadCategory($category);
            $plugin = PluginRegistry::getPlugin($category, $pluginName);

            $pluginHashes = null;
            if ($plugin && $plugin->getEnabled()) {
                $version = $plugin->getCurrentVersion();
                if ($version) {
                    $versionString = $this->_formatVersionString($version->getVersionString());
                    $pluginHashes = $this->_fetchAndCacheBaseline('plugin', [
                        'category'   => $category,
                        'pluginName' => $pluginName,
                        'version'    => $versionString,
                    ], $forceRefresh);
                }
            }

            if ($pluginHashes === null) {
                // Jika hash plugin tidak ditemukan, semua file dari plugin ini dianggap "ditambahkan"
                $finalAdded = array_merge($finalAdded, $files);
                continue;
            }

            // Lakukan verifikasi ulang untuk file-file plugin ini
            foreach ($files as $filePath) {
                if (isset($currentHashes[$filePath])) {
                    if (!isset($pluginHashes[$filePath])) {
                        $finalAdded[] = $filePath; // Ada di lokal, tidak ada di JSON plugin
                    } elseif ($currentHashes[$filePath] !== $pluginHashes[$filePath]) {
                        $finalModified[] = $filePath; // Hash tidak cocok
                    }
                    // Jika hash cocok, file tersebut diabaikan (false positive dihilangkan)
                }
            }

            // Periksa file yang mungkin dihapus dari plugin
            foreach ($pluginHashes as $filePath => $hash) {
                if (strpos($filePath, $pluginDir) === 0 && !isset($currentHashes[$filePath])) {
                    $finalDeleted[] = $filePath;
                }
            }
        }

        // --- LANGKAH 4: Kirim Hasil Final ke Email ---
        $finalModified = array_unique($finalModified);
        $finalAdded = array_unique($finalAdded);
        $finalDeleted = array_unique($finalDeleted);

        if (empty($finalModified) && empty($finalAdded) && empty($finalDeleted)) {
            return true;
        }

        $this->_sendNotificationEmail($finalModified, $finalAdded, $finalDeleted);
        return true;
    }

    private function _fetchAndCacheBaseline($type, $pluginData = null, $forceRefresh = false)
    {
        $cacheDir = Core::getBaseDir() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'integrityFilesScan';
        $encryption_key = Config::getVar('security', 'salt');

        $url = null;
        $cacheFileName = null;

        if ($type === 'core') {
            $versionString = Application::get()->getCurrentVersion()->getVersionString();
            $formattedVersion = $this->_formatVersionString($versionString);
            $cacheId = 'core-' . $formattedVersion;
            $url = self::GITHUB_HASH_REPO_URL . 'core/ojs-' . $formattedVersion . '.json';
        } elseif ($type === 'plugin') {
            $cacheId = "plugin-{$pluginData['category']}-{$pluginData['pluginName']}-v{$pluginData['version']}";
            $url = self::GITHUB_HASH_REPO_URL . "plugins/{$pluginData['category']}/{$pluginData['pluginName']}-v{$pluginData['version']}.json";
        } else {
            return null;
        }

        $cacheFileName = 'integrity_hashes_' . hash_hmac('sha256', $cacheId, $encryption_key) . '.json';
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $cacheFileName;

        if (!$forceRefresh && file_exists($cacheFile)) {
            $jsonContent = @file_get_contents($cacheFile);
            if ($jsonContent) return json_decode($jsonContent, true);
        }

        $jsonContent = @file_get_contents($url);
        if ($jsonContent === false) {
            error_log("FileIntegrityPlugin: FAILED to download from {$url}");
            return null;
        }

        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
        @file_put_contents($cacheFile, $jsonContent);

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

<?php

/**
 * @file plugins/generic/ashFileIntegrity/classes/FileIntegrityScanScheduledTask.inc.php
 *
 */

import('lib.pkp.classes.scheduledTask.ScheduledTask');
import('lib.pkp.classes.mail.Mail');

class FileIntegrityScanScheduledTask extends ScheduledTask
{

    // DEFINISIKAN REPOSITORI GITHUB ANDA DI SINI
    const GITHUB_HASH_REPO_URL = 'https://raw.githubusercontent.com/ashvisualtheme/hash-repo/main/ojs/core/ojs-';

    public function executeActions()
    {
        // 1. Ambil baseline resmi dari GitHub
        $baselineHashes = $this->_fetchBaselineFromGithub();
        if (!$baselineHashes) {
            error_log('FileIntegrityPlugin: Scan failed. Could not fetch official baseline from GitHub.');
            return false; // Gagal mengambil baseline, hentikan proses.
        }

        // 2. Dapatkan hash file lokal saat ini
        $currentHashes = $this->_getHashes();

        $modified = [];
        $deleted = [];
        $added = [];

        // Bandingkan dengan logika yang sama seperti sebelumnya
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

        // Kirim email notifikasi jika ada masalah
        $this->_sendNotificationEmail($modified, $added, $deleted);
        return true;
    }

    /**
     * Fetches the official baseline hash file from GitHub.
     * @return array|null The baseline hashes or null on failure.
     */
    private function _fetchBaselineFromGithub()
    {
        $ojsVersion = Application::get()->getCurrentVersion()->getVersionString();
        $url = self::GITHUB_HASH_REPO_URL . $ojsVersion . '.json';

        // Gunakan @ untuk menekan warning jika file tidak ada
        $jsonContent = @file_get_contents($url);

        if ($jsonContent === false) {
            return null;
        }

        return json_decode($jsonContent, true);
    }

    /**
     * Gets a list of local file paths and their SHA256 hashes.
     * @return array ['filepath' => 'hash']
     */
    private function _getHashes()
    {
        $hashes = [];
        $basePath = realpath(dirname(__FILE__) . '/../../../../..'); // OJS root directory

        $filesDir = Config::getVar('files', 'files_dir');
        $publicDir = Config::getVar('files', 'public_files_dir');
        $excludedPaths = [
            realpath($filesDir),
            realpath($publicDir),
            realpath($basePath . '/cache'),
            // Tambahkan file/folder lain yang ingin dikecualikan
        ];
        $excludedPaths = array_filter($excludedPaths); // Hapus path yang tidak valid
        $excludedPaths = array_map(function ($path) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }, $excludedPaths);

        $directoryIterator = new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directoryIterator);

        foreach ($iterator as $file) {
            $filePath = $file->getRealPath();

            $isExcluded = false;
            foreach ($excludedPaths as $excludedPath) {
                if (strpos($filePath, $excludedPath) === 0) {
                    $isExcluded = true;
                    break;
                }
            }
            if ($isExcluded || !$file->isFile() || basename($filePath) == 'config.inc.php') {
                continue;
            }

            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $filePath);
            // GANTI DENGAN sha256_file
            $hashes[$relativePath] = sha256_file($filePath);
        }

        return $hashes;
    }

    /**
     * Fungsi _sendNotificationEmail tetap sama seperti sebelumnya
     */
    private function _sendNotificationEmail($modified, $added, $deleted)
    {
        // Kode dari versi sebelumnya tidak perlu diubah
        // ... (lihat kode di respons sebelumnya)
    }
}

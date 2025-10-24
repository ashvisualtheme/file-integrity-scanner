<?php

/**
 * @file plugins/generic/ashFileIntegrity/classes/FileIntegrityScanScheduledTask.inc.php
 */

import('lib.pkp.classes.scheduledTask.ScheduledTask');
import('lib.pkp.classes.mail.Mail');

class FileIntegrityScanScheduledTask extends ScheduledTask
{
    // Repositori hash resmi di GitHub
    const GITHUB_HASH_REPO_URL = 'https://raw.githubusercontent.com/ashvisualtheme/hash-repo/main/ojs/core/ojs-';

    public function executeActions()
    {
        // 1. Ambil dan cache baseline resmi dari GitHub
        $baselineHashes = $this->_fetchAndCacheBaseline();
        if (!$baselineHashes) {
            error_log('FileIntegrityPlugin: Scan failed. Could not fetch official baseline from GitHub.');
            return false; // Gagal mengambil baseline, hentikan proses.
        }

        // 2. Dapatkan hash file lokal saat ini
        $currentHashes = $this->_getHashes();

        $modified = [];
        $deleted = [];
        $added = [];

        // 3. Bandingkan hash lokal dengan baseline resmi
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

        // 4. Laporkan hasil
        if (empty($modified) && empty($deleted) && empty($added)) {
            error_log('FileIntegrityPlugin: Scan completed. All files match the official baseline.');
            // Jika Anda ingin notifikasi email bahkan saat berhasil, panggil dari sini.
            // $this->_sendNotificationEmail([], [], [], true); // Contoh
            return true;
        }

        // Kirim email notifikasi jika ada masalah
        $this->_sendNotificationEmail($modified, $added, $deleted);
        return true;
    }

    /**
     * Mengunduh file hash baseline resmi dari GitHub, menyimpannya ke cache,
     * dan mengembalikannya sebagai array.
     * @return array|null Baseline hashes atau null jika gagal.
     */
    private function _fetchAndCacheBaseline()
    {
        $ojsVersion = Application::get()->getCurrentVersion()->getVersionString();
        $url = self::GITHUB_HASH_REPO_URL . $ojsVersion . '.json';

        // Gunakan @ untuk menekan warning jika file tidak ditemukan (404 Not Found)
        $jsonContent = @file_get_contents($url);

        if ($jsonContent === false) {
            error_log('FileIntegrityPlugin: Failed to download hash file from ' . $url);
            return null;
        }

        // Tentukan path cache
        $cacheDir = Core::getBaseDir() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'AshVisual';
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'baseline-' . $ojsVersion . '.json';

        // Buat direktori cache jika belum ada
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        // Simpan file ke cache
        file_put_contents($cacheFile, $jsonContent);

        // Decode dan kembalikan konten JSON
        return json_decode($jsonContent, true);
    }

    /**
     * Mendapatkan daftar path file lokal dan hash SHA-256 mereka.
     * @return array ['filepath' => 'hash']
     */
    private function _getHashes()
    {
        $hashes = [];
        $basePath = realpath(dirname(__FILE__) . '/../../../../..'); // Direktori root OJS

        $filesDir = Config::getVar('files', 'files_dir');
        $publicDir = Config::getVar('files', 'public_files_dir');

        $excludedPaths = [
            realpath($filesDir),
            realpath($publicDir),
            realpath($basePath . '/cache'),
            // Tambahkan path lain yang ingin dikecualikan di sini
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
                // Pastikan $excludedPath tidak kosong sebelum melakukan perbandingan
                if ($excludedPath && strpos($filePath, $excludedPath) === 0) {
                    $isExcluded = true;
                    break;
                }
            }
            // Juga kecualikan file config.inc.php
            if ($isExcluded || !$file->isFile() || basename($filePath) == 'config.inc.php') {
                continue;
            }

            // Gunakan DIRECTORY_SEPARATOR untuk konsistensi antar sistem operasi
            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $filePath);

            // Ganti slash Windows dengan slash Unix agar cocok dengan format di file JSON
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

            $hashes[$relativePath] = hash_file('sha256', $filePath);
        }

        return $hashes;
    }

    /**
     * Mengirim email notifikasi.
     */
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

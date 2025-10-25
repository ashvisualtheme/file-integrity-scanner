<?php

import('lib.pkp.classes.scheduledTask.ScheduledTask');

class FileIntegrityScanScheduledTask extends ScheduledTask
{
    /**
     * @copydoc ScheduledTask::executeActions()
     */
    public function executeActions()
    {
        $plugin = PluginRegistry::getPlugin('generic', 'fileintegrityplugin');

        // Mengambil versi OJS saat ini
        $versionDao = DAORegistry::getDAO('VersionDAO');
        $currentVersion = $versionDao->getCurrentVersion();
        $versionString = $currentVersion->getVersionString(false);

        // Membangun URL ke file hash (URL yang benar dan konsisten)
        $hashRepoUrl = 'https://raw.githubusercontent.com/ash-raj/hash-repo/main/ojs/core/ojs-' . $versionString . '.json';

        $officialHashesJson = @file_get_contents($hashRepoUrl);
        if ($officialHashesJson === false) {
            error_log('FileIntegrityPlugin: Scheduled task could not download hash file.');
            return false;
        }

        $officialHashes = json_decode($officialHashesJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('FileIntegrityPlugin: Scheduled task could not decode hash file.');
            return false;
        }

        // Ambil dan proses daftar pengecualian dari pengaturan plugin
        $excludedPathsSetting = $plugin->getSetting(0, 'excludedPaths');
        $excludedPaths = [];
        if ($excludedPathsSetting) {
            $excludedPaths = array_filter(array_map('trim', explode("\n", $excludedPathsSetting)));
        }
        // Tambahkan config.inc.php sebagai file yang selalu dikecualikan
        $excludedPaths[] = 'config.inc.php';


        $localFiles = [];
        $baseDir = getBasePath(); // Menggunakan getBasePath() yang benar
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $filePath = $file->getPathname();
            $relativePath = str_replace($baseDir . '/', '', $filePath);

            // Logika pengecualian yang konsisten dengan handler manual
            $isExcluded = false;
            foreach ($excludedPaths as $excludedPath) {
                if (strpos($relativePath, $excludedPath) === 0) {
                    $isExcluded = true;
                    break;
                }
            }

            if ($isExcluded) {
                continue;
            }

            // Menggunakan sha1_file yang benar
            $localFiles[$relativePath] = sha1_file($filePath);
        }

        $modifiedFiles = array_diff_assoc($localFiles, $officialHashes);
        $deletedFiles = array_diff_key($officialHashes, $localFiles);
        $addedFiles = array_diff_key($localFiles, $officialHashes);

        // Hanya kirim email jika ada masalah yang ditemukan
        if (!empty($modifiedFiles) || !empty($deletedFiles) || !empty($addedFiles)) {
            $this->_sendNotificationEmail(
                array_keys($modifiedFiles),
                array_keys($addedFiles),
                array_keys($deletedFiles)
            );
        }

        return true;
    }

    /**
     * Mengirim email notifikasi.
     */
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

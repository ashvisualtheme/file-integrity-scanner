<?php

import('classes.handler.Handler');
import('plugins.generic.fileIntegrity.classes.FileIntegrityScanScheduledTask');

class FileIntegrityHandler extends Handler
{
    public function __construct()
    {
        parent::__construct();
        $this->addRoleAssignment(
            [ROLE_ID_MANAGER],
            ['runScan'] // Hanya runScan yang tersisa
        );
    }

    public function runScan($args, $request)
    {
        // Logika ini sekarang tidak diperlukan karena baseline selalu diambil dari GitHub
        // Cukup jalankan tugasnya
        $task = new FileIntegrityScanScheduledTask();
        $task->executeActions();

        // Beri tahu pengguna bahwa pemindaian telah dimulai dan hasilnya akan dikirim melalui email
        return new JSONMessage(true, __('plugins.generic.fileIntegrity.scan.success'));
    }
}

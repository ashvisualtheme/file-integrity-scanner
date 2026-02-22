<?php

/**
 * @file plugins/generic/ashFileIntegrity/classes/RunFileIntegrityScanCommand.php
 *
 * Copyright (c) 2025 AshVisualTheme
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class RunFileIntegrityScanCommand
 * @ingroup plugins_generic_ashFileIntegrity_classes
 *
 * @brief Console command to run the file integrity scan.
 */

namespace APP\plugins\generic\ashFileIntegrity\classes;

use Illuminate\Console\Command;

class RunFileIntegrityScanCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ash:scan-integrity {--force : Force refresh baseline hashes from GitHub}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the Ash File Integrity Scan';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Starting File Integrity Scan...');

        $task = new AshFileIntegrityScanScheduledTask();
        
        // Check for the --force flag
        $forceRefresh = $this->option('force') ? true : false;

        // Execute the task logic directly
        // Note: We call executeActions directly to pass the force parameter.
        // Standard ScheduledTask::execute() does not accept parameters.
        $success = $task->executeActions($forceRefresh);

        if ($success) {
            $this->info('Scan completed successfully.');
            return 0;
        } else {
            $this->error('Scan failed or found issues (check logs/email).');
            return 1;
        }
    }
}

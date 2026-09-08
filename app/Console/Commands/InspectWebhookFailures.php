<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class InspectWebhookFailures extends Command
{
    protected $signature = 'webhooks:failures {--lines=20 : Number of recent log lines to display}';

    protected $description = 'Display recent payment webhook failures from the local application log';

    public function handle(): int
    {
        $lineCount = filter_var($this->option('lines'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 500],
        ]);

        if ($lineCount === false) {
            $this->error('The --lines option must be between 1 and 500.');

            return self::FAILURE;
        }

        $files = glob(storage_path('logs/webhook-*.log')) ?: [];
        rsort($files, SORT_STRING);

        if ($files === []) {
            $this->info('No webhook failure log exists yet.');

            return self::SUCCESS;
        }

        $lines = file($files[0], FILE_IGNORE_NEW_LINES) ?: [];
        foreach (array_slice($lines, -$lineCount) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}

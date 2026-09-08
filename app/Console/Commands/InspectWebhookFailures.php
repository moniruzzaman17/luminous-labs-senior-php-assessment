<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class InspectWebhookFailures extends Command
{
    protected $signature = 'webhooks:failures
                            {--type=processing : Failure type: processing, security, or all}
                            {--lines=20 : Number of recent lines to display per type}';

    protected $description = 'Display recent verified processing failures or security rejections';

    public function handle(): int
    {
        $lineCount = filter_var($this->option('lines'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 500],
        ]);

        if ($lineCount === false) {
            $this->error('The --lines option must be between 1 and 500.');

            return self::FAILURE;
        }

        $type = (string) $this->option('type');
        if (! in_array($type, ['processing', 'security', 'all'], true)) {
            $this->error('The --type option must be processing, security, or all.');

            return self::FAILURE;
        }

        $types = $type === 'all' ? ['processing', 'security'] : [$type];
        foreach ($types as $selectedType) {
            $this->display($selectedType, $lineCount);
        }

        return self::SUCCESS;
    }

    private function display(string $type, int $lineCount): void
    {
        $this->info(ucfirst($type).' webhook records:');

        $files = glob(storage_path("logs/webhook-{$type}-*.log")) ?: [];
        rsort($files, SORT_STRING);

        if ($files === []) {
            $this->line("No {$type} webhook record exists yet.");

            return;
        }

        $lines = file($files[0], FILE_IGNORE_NEW_LINES) ?: [];
        foreach (array_slice($lines, -$lineCount) as $line) {
            $this->line($line);
        }
    }
}

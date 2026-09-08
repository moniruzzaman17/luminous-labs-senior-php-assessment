<?php

namespace App\Console\Commands;

use App\Models\Shipment;
use App\Services\Shipments\ShipmentDateParser;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ImportShipments extends Command
{
    protected $signature = 'shipments:import {path} {--commit : Persist only after every row validates}';

    protected $description = 'Validate and optionally import a Northgate shipment CSV';

    public function handle(ShipmentDateParser $parser): int
    {
        $handle = fopen((string) $this->argument('path'), 'rb');
        if ($handle === false) {
            $this->error('Could not open the CSV file.');

            return self::FAILURE;
        }

        $header = fgetcsv($handle, escape: '');
        if ($header !== ['external_id', 'source', 'import_batch', 'shipment_date']) {
            fclose($handle);
            $this->error('Expected CSV headers: external_id,source,import_batch,shipment_date');

            return self::FAILURE;
        }

        $rows = [];
        $externalIds = [];
        $line = 1;
        while (($values = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            try {
                if (count($values) !== 4 || in_array('', $values, true)) {
                    throw new RuntimeException('Every column is required.');
                }

                if (array_map('trim', $values) !== $values) {
                    throw new RuntimeException('Leading or trailing whitespace is not accepted.');
                }

                if (strlen($values[0]) > 100 || strlen($values[1]) > 50 || strlen($values[2]) > 100 || strlen($values[3]) > 50) {
                    throw new RuntimeException('A value exceeds its documented maximum length.');
                }

                if (isset($externalIds[$values[0]])) {
                    throw new RuntimeException("Duplicate external_id [{$values[0]}] in this file.");
                }
                $externalIds[$values[0]] = true;

                $rows[] = [
                    'external_id' => $values[0],
                    'source' => $values[1],
                    'import_batch' => $values[2],
                    'raw_shipment_date' => $values[3],
                    'shipment_date' => $parser->parse($values[3], $values[1])->format('Y-m-d'),
                ];
            } catch (Throwable $exception) {
                fclose($handle);
                $this->error("Line {$line}: {$exception->getMessage()}");

                return self::FAILURE;
            }
        }
        fclose($handle);

        if (! $this->option('commit')) {
            $this->info('Dry run passed: '.count($rows).' row(s) are valid; no database changes were made.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($rows): void {
                foreach ($rows as $row) {
                    Shipment::create($row);
                }
            });
        } catch (UniqueConstraintViolationException) {
            $this->error('Import aborted: an external_id already exists; no rows were changed.');

            return self::FAILURE;
        }

        $this->info('Imported '.count($rows).' row(s).');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Shipment;
use App\Services\Shipments\ShipmentDateParser;
use Illuminate\Console\Command;
use Throwable;

class AuditShipmentDates extends Command
{
    protected $signature = 'shipments:audit-existing';

    protected $description = 'Report existing shipment dates that cannot be verified or differ from retained source data';

    public function handle(ShipmentDateParser $parser): int
    {
        $issues = 0;

        Shipment::query()->orderBy('id')->chunkById(500, function ($shipments) use ($parser, &$issues): void {
            foreach ($shipments as $shipment) {
                if ($shipment->raw_shipment_date === null) {
                    $issues++;
                    $this->warn("{$shipment->external_id} [{$shipment->import_batch}]: unresolved - original source date is unavailable");

                    continue;
                }

                try {
                    $expected = $parser->parse($shipment->raw_shipment_date, $shipment->source)->format('Y-m-d');
                    $stored = $shipment->shipment_date?->format('Y-m-d');
                    if ($expected !== $stored) {
                        $issues++;
                        $this->warn("{$shipment->external_id} [{$shipment->import_batch}]: mismatch - stored={$stored}, expected={$expected}");
                    }
                } catch (Throwable $exception) {
                    $issues++;
                    $this->warn("{$shipment->external_id} [{$shipment->import_batch}]: unresolved - {$exception->getMessage()}");
                }
            }
        });

        $this->info("Audit complete: {$issues} issue(s); no records were changed.");

        return self::SUCCESS;
    }
}

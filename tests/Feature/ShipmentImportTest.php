<?php

namespace Tests\Feature;

use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_validates_but_does_not_write(): void
    {
        $this->artisan('shipments:import', ['path' => base_path('examples/shipments.csv')])
            ->expectsOutputToContain('Dry run passed: 3 row(s) are valid')
            ->assertSuccessful();

        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_commit_stores_raw_evidence_and_parsed_date(): void
    {
        $this->artisan('shipments:import', [
            'path' => base_path('examples/shipments.csv'),
            '--commit' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('shipments', [
            'external_id' => 'SHIP-UK-001',
            'import_batch' => 'BATCH-UK-2026-04',
            'raw_shipment_date' => '03/04/2026',
            'shipment_date' => '2026-04-03',
        ]);
    }

    public function test_unknown_source_file_is_rejected_without_writes(): void
    {
        $this->artisan('shipments:import', ['path' => base_path('examples/shipments-invalid.csv')])
            ->expectsOutputToContain('No shipment date format is configured')
            ->assertFailed();

        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_existing_record_is_not_overwritten_and_partial_import_is_rolled_back(): void
    {
        Shipment::create([
            'external_id' => 'SHIP-US-001',
            'source' => 'northgate_us',
            'import_batch' => 'ORIGINAL-BATCH',
            'raw_shipment_date' => '01/02/2025',
            'shipment_date' => '2025-01-02',
        ]);

        $this->artisan('shipments:import', [
            'path' => base_path('examples/shipments.csv'),
            '--commit' => true,
        ])
            ->expectsOutputToContain('Import aborted: an external_id already exists; no rows were changed.')
            ->assertFailed();

        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseHas('shipments', [
            'external_id' => 'SHIP-US-001',
            'import_batch' => 'ORIGINAL-BATCH',
            'raw_shipment_date' => '01/02/2025',
        ]);
        $this->assertDatabaseMissing('shipments', ['external_id' => 'SHIP-UK-001']);
    }
}

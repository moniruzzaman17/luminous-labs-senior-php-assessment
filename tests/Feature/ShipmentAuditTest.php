<?php

namespace Tests\Feature;

use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_reports_mismatch_and_missing_evidence_without_changing_records(): void
    {
        $mismatch = Shipment::create([
            'external_id' => 'SHIP-1',
            'source' => 'northgate_uk',
            'import_batch' => 'BATCH-1',
            'raw_shipment_date' => '03/04/2026',
            'shipment_date' => '2026-03-04',
        ]);
        Shipment::create([
            'external_id' => 'SHIP-2',
            'source' => 'northgate_uk',
            'import_batch' => 'BATCH-2',
            'raw_shipment_date' => null,
            'shipment_date' => '2026-05-06',
        ]);

        $this->artisan('shipments:audit-existing')
            ->expectsOutputToContain('SHIP-1 [BATCH-1]: mismatch')
            ->expectsOutputToContain('SHIP-2 [BATCH-2]: unresolved')
            ->expectsOutputToContain('2 issue(s); no records were changed')
            ->assertSuccessful();

        $this->assertSame('2026-03-04', $mismatch->fresh()->shipment_date->format('Y-m-d'));
    }
}

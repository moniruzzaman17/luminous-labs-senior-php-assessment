<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Shipment;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $now = now();
        $titles = [
            'Riverside Community Market',
            'Autumn Makers Workshop',
            'Storytelling by the Bridge',
            'Community Garden Morning',
            'Local History Walk',
            'Fictional Food Festival',
            'Young Artists Exhibition',
            'Town Choir Rehearsal',
            'Library Reading Circle',
            'River Safety Workshop',
            'Community Photography Walk',
            'Winter Craft Preview',
        ];

        Event::query()->insert(array_map(
            fn (string $title, int $offset): array => [
                'title' => $title,
                'starts_at' => $now->copy()->addDays($offset + 1),
                'location' => $offset % 2 === 0 ? 'Marlow Town Hall' : 'Fictional Arts Centre',
                'is_public' => true,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $titles,
            array_keys($titles),
        ));

        Shipment::query()->insert([
            [
                'external_id' => 'SHIP-HIST-001',
                'source' => 'northgate_uk',
                'import_batch' => 'DEMO-HISTORICAL-001',
                'raw_shipment_date' => '03/04/2025',
                'shipment_date' => '2025-03-04',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'external_id' => 'SHIP-HIST-002',
                'source' => 'northgate_us',
                'import_batch' => 'DEMO-HISTORICAL-002',
                'raw_shipment_date' => null,
                'shipment_date' => '2025-03-04',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}

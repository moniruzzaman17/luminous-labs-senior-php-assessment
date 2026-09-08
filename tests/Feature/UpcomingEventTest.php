<?php

namespace Tests\Feature;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpcomingEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-09-08 10:00:00 UTC');
    }

    public function test_access_is_denied_until_explicitly_enabled(): void
    {
        config(['features.public_upcoming_events' => false]);
        $this->getJson('/api/events/upcoming')->assertForbidden();
    }

    public function test_returns_only_next_ten_eligible_events_in_deterministic_order(): void
    {
        config(['features.public_upcoming_events' => true]);

        foreach (range(1, 12) as $number) {
            $this->event(['title' => "Public {$number}", 'starts_at' => now()->addDays($number)]);
        }
        $this->event(['title' => 'Private', 'is_public' => false]);
        $this->event(['title' => 'Draft', 'published_at' => null]);
        $this->event(['title' => 'Cancelled', 'cancelled_at' => now()]);
        $this->event(['title' => 'Past', 'starts_at' => now()->subMinute()]);

        $data = $this->getJson('/api/events/upcoming')->assertOk()->json('data');

        $this->assertCount(10, $data);
        $this->assertSame('Public 1', $data[0]['title']);
        $this->assertSame('Public 10', $data[9]['title']);
        $this->assertSame(['id', 'title', 'starts_at', 'location'], array_keys($data[0]));
    }

    public function test_equal_start_times_use_id_as_tie_breaker(): void
    {
        config(['features.public_upcoming_events' => true]);
        $first = $this->event(['title' => 'First']);
        $second = $this->event(['title' => 'Second']);

        $ids = collect($this->getJson('/api/events/upcoming')->json('data'))->pluck('id')->all();
        $this->assertSame([$first->id, $second->id], $ids);
    }

    public function test_explicit_enablement_is_respected_in_production(): void
    {
        config(['features.public_upcoming_events' => true, 'app.env' => 'production']);
        $this->event(['title' => 'Approved public event']);

        $this->getJson('/api/events/upcoming')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Approved public event');
    }

    public function test_exact_time_boundary_is_included_and_response_is_utc(): void
    {
        config(['features.public_upcoming_events' => true]);
        $this->event(['title' => 'Boundary', 'starts_at' => now()]);
        $this->event(['title' => 'Already started', 'starts_at' => now()->subSecond()]);

        $data = $this->getJson('/api/events/upcoming')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Boundary', $data[0]['title']);
        $this->assertStringEndsWith('Z', $data[0]['starts_at']);
    }

    public function test_empty_eligible_set_returns_an_empty_array(): void
    {
        config(['features.public_upcoming_events' => true]);
        $this->event(['starts_at' => now()->subDay()]);

        $this->getJson('/api/events/upcoming')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    private function event(array $overrides = []): Event
    {
        return Event::create(array_merge([
            'title' => 'Fictional public event',
            'starts_at' => now()->addDay(),
            'location' => 'Marlow Town Hall',
            'is_public' => true,
            'published_at' => now(),
            'cancelled_at' => null,
        ], $overrides));
    }
}

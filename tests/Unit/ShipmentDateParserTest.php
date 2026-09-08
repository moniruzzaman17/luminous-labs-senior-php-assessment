<?php

namespace Tests\Unit;

use App\Services\Shipments\ShipmentDateParser;
use InvalidArgumentException;
use Tests\TestCase;

class ShipmentDateParserTest extends TestCase
{
    public function test_source_configuration_resolves_ambiguous_values_without_guessing(): void
    {
        $parser = app(ShipmentDateParser::class);

        $this->assertSame('2026-04-03', $parser->parse('03/04/2026', 'northgate_uk')->format('Y-m-d'));
        $this->assertSame('2026-03-04', $parser->parse('03/04/2026', 'northgate_us')->format('Y-m-d'));
    }

    public function test_invalid_calendar_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ShipmentDateParser::class)->parse('31/02/2026', 'northgate_uk');
    }

    public function test_iso_source_and_leap_year_are_parsed_strictly(): void
    {
        $parser = app(ShipmentDateParser::class);

        $this->assertSame('2026-04-03', $parser->parse('2026-04-03', 'northgate_iso')->format('Y-m-d'));
        $this->assertSame('2024-02-29', $parser->parse('29/02/2024', 'northgate_uk')->format('Y-m-d'));
    }

    public function test_non_leap_year_and_surrounding_whitespace_are_rejected(): void
    {
        $parser = app(ShipmentDateParser::class);

        foreach (['29/02/2025', ' 03/04/2026'] as $value) {
            try {
                $parser->parse($value, 'northgate_uk');
                $this->fail("Expected [{$value}] to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_unknown_source_is_rejected_instead_of_guessed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ShipmentDateParser::class)->parse('03/04/2026', 'unknown_office');
    }
}

<?php

namespace App\Services\Shipments;

use DateTimeImmutable;
use InvalidArgumentException;

final class ShipmentDateParser
{
    public function parse(string $value, string $source): DateTimeImmutable
    {
        $format = config("imports.shipment_date_formats.{$source}");
        if (! is_string($format)) {
            throw new InvalidArgumentException("No shipment date format is configured for source [{$source}].");
        }

        $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format($format) !== $value) {
            throw new InvalidArgumentException("Invalid shipment date [{$value}] for source [{$source}].");
        }

        return $date;
    }
}

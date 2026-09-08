<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    protected $fillable = [
        'external_id', 'source', 'import_batch', 'raw_shipment_date', 'shipment_date',
    ];

    protected function casts(): array
    {
        return ['shipment_date' => 'immutable_date'];
    }
}

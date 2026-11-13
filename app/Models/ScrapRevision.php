<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScrapRevision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'snapshot_id',
        'cost_center_code',
        'work_order',
        'product_code',
        'revision',
        'value_m2',
        'recorded_at',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'value_m2' => 'decimal:6',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}

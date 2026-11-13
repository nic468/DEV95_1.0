<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SnapshotMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'snapshot_id',
        'grouping_mask',
        'cost_center_key',
        'movement_type_key',
        'unit_code_key',
        'record_count',
        'distinct_work_orders',
        'total_cost',
        'quantity_total',
        'scrap_total_m2',
        'reported_scrap_count',
    ];

    protected function casts(): array
    {
        return [
            'total_cost' => 'decimal:2',
            'quantity_total' => 'decimal:6',
            'scrap_total_m2' => 'decimal:6',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(UploadSnapshot::class, 'snapshot_id');
    }
}

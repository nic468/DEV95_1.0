<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SnapshotMovementRevision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'snapshot_movement_id',
        'revision',
        'cost_center_code',
        'work_order',
        'movement_type',
        'product_code',
        'description',
        'quantity',
        'unit_code',
        'unit_cost',
        'cost',
        'document',
        'issue_date',
        'standard_cost',
        'standard_total',
        'recorded_at',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'quantity' => 'decimal:6',
            'unit_cost' => 'decimal:6',
            'cost' => 'decimal:2',
            'standard_cost' => 'decimal:6',
            'standard_total' => 'decimal:6',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(SnapshotMovement::class, 'snapshot_movement_id');
    }
}

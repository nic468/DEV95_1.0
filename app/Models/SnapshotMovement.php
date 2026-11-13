<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SnapshotMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'snapshot_id',
        'source_row',
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
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(UploadSnapshot::class, 'snapshot_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_code', 'code');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_code', 'code');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SnapshotMovementRevision::class, 'snapshot_movement_id');
    }
}

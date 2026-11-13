<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UploadSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'reference_date',
        'version',
        'filename',
        'source_file_path',
        'sha256',
        'imported_at',
        'row_count',
        'source_total',
        'imported_total',
        'reconciliation_difference',
        'reconciliation_status',
        'confirmed_by',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'reference_date' => 'date',
            'imported_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'source_total' => 'decimal:2',
            'imported_total' => 'decimal:2',
            'reconciliation_difference' => 'decimal:2',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(SnapshotMovement::class, 'snapshot_id');
    }

    public function scraps(): HasMany
    {
        return $this->hasMany(SnapshotScrap::class, 'snapshot_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(SnapshotMetric::class, 'snapshot_id');
    }
}

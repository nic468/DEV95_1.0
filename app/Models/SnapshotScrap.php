<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SnapshotScrap extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'snapshot_id',
        'cost_center_code',
        'work_order',
        'product_code',
        'description',
        'movement_type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ScrapRevision::class, 'snapshot_id', 'snapshot_id')
            ->where('cost_center_code', $this->cost_center_code)
            ->where('work_order', $this->work_order)
            ->where('product_code', $this->product_code);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostCenter extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name'];

    public function movements(): HasMany
    {
        return $this->hasMany(SnapshotMovement::class, 'cost_center_code', 'code');
    }
}

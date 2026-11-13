<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnitOfMeasure extends Model
{
    public $timestamps = false;

    protected $table = 'units_of_measure';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code'];

    public function movements(): HasMany
    {
        return $this->hasMany(SnapshotMovement::class, 'unit_code', 'code');
    }
}

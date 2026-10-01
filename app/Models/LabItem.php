<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabItem extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(LabMovement::class);
    }
}

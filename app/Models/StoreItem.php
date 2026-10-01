<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreItem extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:2'];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StoreMovement::class);
    }
}

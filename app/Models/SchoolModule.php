<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolModule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['price_kes' => 'decimal:2', 'is_active' => 'boolean'];
    }
}

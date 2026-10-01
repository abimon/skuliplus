<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Department extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    public function hod(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hod_id');
    }
}

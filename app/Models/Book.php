<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Book extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    public function loans(): HasMany
    {
        return $this->hasMany(BookLoan::class);
    }
}

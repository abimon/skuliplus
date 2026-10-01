<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabMovement extends Model
{
    use BelongsToSchool;

    protected $guarded = [];

    public function item(): BelongsTo
    {
        return $this->belongsTo(LabItem::class, 'lab_item_id');
    }
}

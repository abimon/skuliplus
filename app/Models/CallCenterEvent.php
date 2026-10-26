<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallCenterEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(CallCenterCall::class, 'call_center_call_id');
    }
}

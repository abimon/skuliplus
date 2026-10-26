<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CallCenterCall extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'provider_response' => 'array',
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(CallCenterEvent::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subject extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_core' => 'boolean'];
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(CurriculumLevel::class, 'curriculum_level_id');
    }
}

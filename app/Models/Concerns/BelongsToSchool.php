<?php

namespace App\Models\Concerns;

use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Model;

trait BelongsToSchool
{
    public static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new SchoolScope);

        static::creating(function (Model $model) {
            $user = auth()->user();

            if ($user && $user->school_id && empty($model->getAttribute('school_id'))) {
                $model->setAttribute('school_id', $user->school_id);
            }
        });
    }

    public function school()
    {
        return $this->belongsTo(\App\Models\School::class);
    }
}

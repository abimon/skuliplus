<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class SchoolScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (! $user || $user->hasRole('super_admin') || ! $user->school_id) {
            return;
        }

        $builder->where($model->getTable().'.school_id', $user->school_id);
    }
}

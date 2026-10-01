<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    public const MODULES = [
        'users' => 'People',
        'academics' => 'Academics and curriculum',
        'finance' => 'Finance',
        'library' => 'Library',
        'gate' => 'Gate visits',
        'stores' => 'Stores',
        'activities' => 'Activities',
        'labs' => 'Laboratories',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled_modules' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function enabledModuleKeys(): array
    {
        return $this->enabled_modules ?? array_keys(self::MODULES);
    }

    public function hasModuleEnabled(string $module): bool
    {
        return in_array($module, $this->enabledModuleKeys(), true);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function curricula(): BelongsToMany
    {
        return $this->belongsToMany(Curriculum::class)->withPivot('is_primary')->withTimestamps();
    }

    public function academicYears(): HasMany
    {
        return $this->hasMany(AcademicYear::class);
    }

    public function currentYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'id', 'school_id')->where('is_current', true);
    }

    public function branding(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'logo_path' => $this->logo_path,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'accent_color' => $this->accent_color,
            'motto' => $this->motto,
            'mission' => $this->mission,
            'vision' => $this->vision,
            'aim' => $this->aim,
            'county' => $this->county,
            'phone' => $this->phone,
            'email' => $this->email,
            'po_box' => $this->po_box,
        ];
    }
}

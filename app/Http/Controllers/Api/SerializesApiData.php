<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait SerializesApiData
{
    /**
     * Deterministic profile payload shared by /auth/me, /profile and sync.
     */
    protected function profilePayload(User $user): array
    {
        $user->loadMissing([
            'school', 'studentProfile', 'teacherProfile.department',
            'parentProfile', 'staffProfile',
        ]);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'national_id' => $user->national_id,
            'gender' => $user->gender,
            'date_of_birth' => $user->date_of_birth?->toDateString(),
            'address' => $user->address,
            'county' => $user->county,
            'sub_county' => $user->sub_county,
            'photo_path' => $user->photo_path,
            'status' => $user->status,
            'must_change_password' => (bool) $user->must_change_password,
            'admission_number' => $user->admission_number,
            'employee_number' => $user->employee_number,
            'school_id' => $user->school_id,
            'roles' => $this->roles($user),
            'primary_role' => $this->primaryRole($user),
            'permissions' => $this->permissions($user),
            'can_login' => (bool) $user->can_login,
            'school' => $user->school?->branding(),
            'enabled_modules' => $user->school?->enabledModuleKeys() ?? [],
            'profiles' => array_filter([
                'student' => $user->studentProfile,
                'teacher' => $user->teacherProfile,
                'parent' => $user->parentProfile,
                'staff' => $user->staffProfile,
            ]),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Compact user representation used by listings and pickers.
     */
    protected function userPayload(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'admission_number' => $user->admission_number,
            'employee_number' => $user->employee_number,
            'photo_path' => $user->photo_path,
            'roles' => $user->getRoleNames()->values()->all(),
        ];
    }

    /**
     * Normalise pagination meta so the mobile sync layer can compare cursors.
     */
    protected function paginated($paginator, string $resource): JsonResponse
    {
        return $this->ok($paginator->items(), [
            'resource' => $resource,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    protected function timestamped(?\DateTimeInterface $value): ?string
    {
        return $value?->format(DATE_ATOM);
    }
}
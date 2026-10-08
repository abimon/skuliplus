<?php

namespace App\Http\Controllers\Api;

use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait InteractsWithApi
{
    /**
     * Roles that belong to the central (platform) management interface.
     */
    protected const CENTRAL_ROLES = ['super_admin'];

    /**
     * Roles that manage a single school.
     */
    protected const SCHOOL_ROLES = ['school_admin', 'hod_academics'];

    /**
     * Roles that operate day to day on the ground.
     */
    protected const STAFF_ROLES = [
        'teacher', 'librarian', 'lab_technician', 'gatekeeper',
        'finance_officer', 'stores_officer', 'support_staff',
    ];

    protected function ok(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => array_merge(['server_time' => now()->toIso8601String()], $meta),
        ], $status);
    }

    protected function fail(string $message, int $status = 422, array $errors = []): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $message,
            'errors' => $errors ?: null,
        ]), $status);
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    protected function roles(User $user): array
    {
        return $user->getRoleNames()->values()->all();
    }

    protected function permissions(User $user): array
    {
        return $user->getAllPermissions()->pluck('name')->values()->all();
    }

    /**
     * The primary "interface" the mobile app should render for this user.
     */
    protected function primaryRole(User $user): string
    {
        $roles = $this->roles($user);

        foreach (['super_admin', 'school_admin', 'hod_academics', 'teacher', 'parent', 'student'] as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return $roles[0] ?? 'support_staff';
    }

    protected function ensureSchool(Request $request): School
    {
        $user = $this->user($request);

        abort_unless($user->school_id, 403, 'Your account is not linked to a school.');

        $school = $user->school;

        abort_unless($school, 403, 'Your account is not linked to a school.');

        return $school;
    }

    protected function ensureSchoolModule(Request $request, string $module): School
    {
        $school = $this->ensureSchool($request);

        abort_unless(
            $school->hasModuleEnabled($module),
            403,
            sprintf('The %s module is not enabled for %s.', ucfirst($module), $school->name)
        );

        return $school;
    }

    protected function ensureRole(Request $request, array|string $roles): void
    {
        $roles = (array) $roles;

        abort_unless(
            $this->user($request)->hasAnyRole($roles),
            403,
            'You do not have access to this resource.'
        );
    }

    /**
     * Resolve the term to operate on: the requested one, else the current one.
     */
    protected function currentTerm(int $schoolId, ?int $termId = null): ?\App\Models\Term
    {
        return \App\Models\Term::where('school_id', $schoolId)
            ->when($termId, fn ($q) => $q->whereKey($termId), fn ($q) => $q->where('is_current', true))
            ->first();
    }
}
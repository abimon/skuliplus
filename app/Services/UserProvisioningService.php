<?php

namespace App\Services;

use App\Models\StudentTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Single source of truth for creating and re-provisioning the people a
 * school manages. Shared by the Inertia web UI and the mobile API so both
 * surfaces behave identically.
 */
class UserProvisioningService
{
    public const MANAGEABLE_ROLES = [
        'student', 'parent', 'teacher', 'hod_academics', 'support_staff',
        'librarian', 'lab_technician', 'gatekeeper', 'finance_officer', 'stores_officer',
    ];

    public const STAFF_ROLES = [
        'teacher', 'hod_academics', 'support_staff', 'librarian',
        'lab_technician', 'gatekeeper', 'finance_officer', 'stores_officer',
    ];

    /**
     * Validation rules shared by create and update. `$userId` lets unique
     * rules ignore the record currently being edited.
     */
    public static function rules(array $input, int $schoolId, ?int $userId = null, bool $existingStudent = false): array
    {
        $isStudent = ($input['role'] ?? null) === 'student';

        return [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'role' => ['required', Rule::in(self::MANAGEABLE_ROLES)],
            'email' => [
                Rule::requiredIf(fn () => ($input['role'] ?? null) !== 'student'),
                'nullable', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone' => 'nullable|string|max:40',
            'gender' => 'nullable|in:male,female,other',
            'date_of_birth' => 'nullable|date',
            'admission_number' => [
                'required_if:role,student', 'nullable', 'string', 'max:80',
                Rule::unique('users', 'admission_number')->where('school_id', $schoolId)->ignore($userId),
            ],
            'employee_number' => [
                'nullable', 'string', 'max:80',
                Rule::unique('users', 'employee_number')->where('school_id', $schoolId)->ignore($userId),
            ],
            'guardian_id' => [
                Rule::requiredIf(fn () => $isStudent && ! $existingStudent && empty($input['guardian_email'])),
                'nullable', 'integer',
            ],
            'guardian_email' => 'nullable|email',
            'relationship' => 'nullable|string|max:50',
            'boarding_status' => 'nullable|in:day,boarding',
            'previous_school' => 'nullable|string|max:255',
            'rank' => 'nullable|string|max:100',
            'staff_category' => 'nullable|string|max:100',
            'duty_station' => 'nullable|string|max:255',
            'tsc_number' => 'nullable|string|max:100',
            'qualification' => 'nullable|string|max:255',
            'specialization' => 'nullable|string|max:255',
            'occupation' => 'nullable|string|max:255',
            'workplace' => 'nullable|string|max:255',
            'department_id' => 'nullable|integer|exists:departments,id',
            'password' => [
                Rule::requiredIf(fn () => ($input['role'] ?? null) !== 'student' && (! $userId || $existingStudent)),
                'nullable', 'string', 'min:10',
            ],
        ];
    }
    /**
     * Resolve a guardian reference into a concrete parent id.
     */
    public static function resolveGuardian(array $data, int $schoolId, bool $existingStudent = false): array
    {
        if ($data['role'] !== 'student') {
            return $data;
        }

        if (! $existingStudent && empty($data['guardian_email'])) {
            $guardian = User::role('parent')->where('school_id', $schoolId)->find($data['guardian_id'] ?? null);

            if (! $guardian) {
                throw ValidationException::withMessages([
                    'guardian_id' => ['Choose a parent or guardian from this school.'],
                ]);
            }
        }

        if (! empty($data['guardian_email']) || ! empty($data['guardian_id'])) {
            $query = User::role('parent')->where('school_id', $schoolId);
            $guardian = ! empty($data['guardian_email'])
                ? $query->where('email', $data['guardian_email'])->first()
                : $query->find($data['guardian_id']);

            if (! $guardian) {
                throw ValidationException::withMessages([
                    'guardian_email' => ['Choose a parent or guardian from this school.'],
                ]);
            }

            $data['guardian_id'] = $guardian->id;
        }

        return $data;
    }

    /**
     * Create a person (learner, parent, teacher or staff member).
     *
     * @return array{0: User, 1: ?string} the user and the generated password
     */
    public static function provision(int $schoolId, array $data): array
    {
        $student = $data['role'] === 'student';
        $generated = null;

        if (! $student && empty($data['password'])) {
            $data['password'] = $generated = Str::password(10);
        }

        [$user] = DB::transaction(function () use ($schoolId, $data, $student) {
            $user = User::create([
                'school_id' => $schoolId,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'email' => $student ? null : ($data['email'] ?? null),
                'phone' => $data['phone'] ?? null,
                'gender' => $data['gender'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'admission_number' => $student ? ($data['admission_number'] ?? null) : null,
                'employee_number' => in_array($data['role'], self::STAFF_ROLES, true) ? ($data['employee_number'] ?? null) : null,
                'can_login' => ! $student,
                'status' => 'active',
                'password' => $student ? null : Hash::make($data['password']),
                'must_change_password' => ! $student,
                'email_verified_at' => $student ? null : now(),
            ]);

            $user->syncRoles([$data['role']]);
            self::syncProfiles($user, $schoolId, $data);

            if ($student && ! empty($data['guardian_id'])) {
                $user->guardians()->syncWithoutDetaching([
                    $data['guardian_id'] => ['relationship' => $data['relationship'] ?? 'parent', 'is_primary' => true],
                ]);
            }

            if ($student && ! empty($data['previous_school'])) {
                StudentTransfer::create([
                    'school_id' => $schoolId,
                    'student_id' => $user->id,
                    'direction' => 'in',
                    'other_school' => $data['previous_school'],
                    'reason' => 'Learner record imported from a previous school system',
                    'effective_on' => now()->toDateString(),
                    'imported_payload' => [
                        'source' => 'user_management',
                        'admission_number' => $user->admission_number,
                    ],
                    'status' => 'completed',
                ]);
            }

            return [$user];
        });

        return [$user, $generated];
    }
    /**
     * Update an existing person, re-syncing role and role-specific profile.
     */
    public static function reprovision(User $user, int $schoolId, array $data): User
    {
        $previousRole = $user->getRoleNames()->first();

        DB::transaction(function () use ($user, $data, $schoolId, $previousRole) {
            $isStudent = $data['role'] === 'student';

            $user->update([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'email' => $isStudent ? null : ($data['email'] ?? null),
                'phone' => $data['phone'] ?? null,
                'gender' => $data['gender'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'admission_number' => $isStudent ? ($data['admission_number'] ?? null) : null,
                'employee_number' => in_array($data['role'], self::STAFF_ROLES, true) ? ($data['employee_number'] ?? null) : null,
                'can_login' => $isStudent ? false : $user->can_login,
                'password' => $isStudent ? null : (! empty($data['password']) ? Hash::make($data['password']) : $user->password),
                'must_change_password' => ! $isStudent && ! empty($data['password']) ? true : $user->must_change_password,
            ]);

            $user->syncRoles([$data['role']]);
            self::syncProfiles($user, $schoolId, $data);

            if ($previousRole === 'student' && ! $isStudent) {
                $user->guardians()->detach();
            }

            if ($previousRole === 'parent' && $data['role'] !== 'parent') {
                $user->children()->detach();
            }

            if ($isStudent && ! empty($data['guardian_id'])) {
                $user->guardians()->syncWithoutDetaching([
                    $data['guardian_id'] => ['relationship' => $data['relationship'] ?? 'parent', 'is_primary' => true],
                ]);
            }
        });

        return $user->fresh(['roles']);
    }

    /**
     * Recreate the role specific profile table row for the user.
     */
    public static function syncProfiles(User $user, int $schoolId, array $data): void
    {
        $user->studentProfile()->delete();
        $user->teacherProfile()->delete();
        $user->parentProfile()->delete();
        $user->staffProfile()->delete();

        if ($data['role'] === 'student') {
            $user->studentProfile()->create([
                'school_id' => $schoolId,
                'boarding_status' => $data['boarding_status'] ?? 'day',
                'previous_school' => $data['previous_school'] ?? null,
                'year_admitted' => now()->year,
            ]);
        } elseif ($data['role'] === 'parent') {
            $user->parentProfile()->create([
                'school_id' => $schoolId,
                'relationship' => $data['relationship'] ?? 'parent',
                'occupation' => $data['occupation'] ?? null,
                'workplace' => $data['workplace'] ?? null,
            ]);
        } elseif (in_array($data['role'], self::STAFF_ROLES, true)) {
            if (in_array($data['role'], ['teacher', 'hod_academics'], true)) {
                $user->teacherProfile()->create([
                    'school_id' => $schoolId,
                    'rank' => $data['rank'] ?? ($data['role'] === 'hod_academics' ? 'hod' : 'teacher'),
                    'tsc_number' => $data['tsc_number'] ?? null,
                    'qualification' => $data['qualification'] ?? null,
                    'specialization' => $data['specialization'] ?? null,
                    'department_id' => $data['department_id'] ?? null,
                ]);
            } else {
                $user->staffProfile()->create([
                    'school_id' => $schoolId,
                    'staff_category' => $data['staff_category'] ?? $data['role'],
                    'duty_station' => $data['duty_station'] ?? null,
                ]);
            }
        }
    }
}

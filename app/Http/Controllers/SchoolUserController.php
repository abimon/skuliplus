<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\StudentTransfer;
use App\Models\User;
use App\Services\QrCodeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SchoolUserController extends Controller
{
    private const MANAGEABLE_ROLES = [
        'student',
        'parent',
        'teacher',
        'hod_academics',
        'support_staff',
        'librarian',
        'lab_technician',
        'gatekeeper',
        'finance_officer',
        'stores_officer',
    ];

    private const STAFF_ROLES = [
        'teacher',
        'hod_academics',
        'support_staff',
        'librarian',
        'lab_technician',
        'gatekeeper',
        'finance_officer',
        'stores_officer',
    ];

    public function index(Request $request): Response
    {
        $this->authorizePermission($request, 'users.update');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $query = User::query()
            ->where('school_id', $schoolId)
            ->with(['roles', 'studentProfile', 'teacherProfile', 'parentProfile', 'staffProfile', 'guardians' => fn (BelongsToMany $guardians) => $guardians->where('users.school_id', $schoolId)])
            ->when($request->string('search')->trim()->isNotEmpty(), function (Builder $query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $query->where(fn (Builder $users) => $users
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('admission_number', 'like', $term)
                    ->orWhere('employee_number', 'like', $term));
            })
            ->when(in_array($request->query('role'), self::MANAGEABLE_ROLES, true), fn (Builder $query) => $query->role($request->query('role')))
            ->latest();

        return Inertia::render('users/index', [
            'school' => $request->user()->school()->first(['id', 'name', 'code', 'county']),
            'users' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only(['search', 'role']),
            'roles' => self::MANAGEABLE_ROLES,
            'modules' => collect([
                ['key' => 'users', 'name' => 'People', 'permission' => 'users.view', 'href' => '/users'],
                ['key' => 'academics', 'name' => 'Academics', 'permission' => 'classes.view', 'href' => $request->user()->can('classes.manage') ? '/academics' : null],
                ['key' => 'curriculum', 'name' => 'Curriculum', 'permission' => 'classes.view'],
                ['key' => 'finance', 'name' => 'Finance', 'permission' => 'finance.view', 'href' => $request->user()->can('finance.manage') ? '/finance' : null],
                ['key' => 'library', 'name' => 'Library', 'permission' => 'library.view'],
                ['key' => 'gate', 'name' => 'Gate visits', 'permission' => 'gate.view'],
                ['key' => 'stores', 'name' => 'Stores', 'permission' => 'stores.view'],
                ['key' => 'activities', 'name' => 'Activities', 'permission' => 'clubs.view'],
                ['key' => 'labs', 'name' => 'Laboratories', 'permission' => 'labs.view'],
            ])->filter(fn (array $module) => $request->user()->can($module['permission']))
                ->filter(fn (array $module) => $request->user()->school->hasModuleEnabled($module['key'] === 'curriculum' ? 'academics' : $module['key']))
                ->map(fn (array $module) => collect($module)->except('permission')->all())
                ->values(),
            'guardians' => User::role('parent')->where('school_id', $schoolId)->orderBy('name')->get(['id', 'name', 'email']),
            'can' => [
                'create' => $request->user()->can('users.create'),
                'update' => $request->user()->can('users.update'),
                'import' => $request->user()->can('users.import'),
                'id_cards' => $request->user()->can('users.id-cards'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'users.create');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $data = $this->validatedUser($request, $schoolId);

        DB::transaction(fn () => $this->provisionUser($schoolId, $data));

        return back()->with('success', 'User registered successfully.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorizePermission($request, 'users.update');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);
        abort_unless($user->school_id === $schoolId && ! $user->hasRole('school_admin'), 404);

        $data = $this->validatedUser($request, $schoolId, $user->id, $user->hasRole('student'));

        $previousRole = $user->getRoleNames()->first();

        DB::transaction(function () use ($user, $data, $schoolId, $previousRole) {
            $user->update([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'email' => $data['role'] === 'student' ? null : ($data['email'] ?? null),
                'phone' => $data['phone'] ?? null,
                'gender' => $data['gender'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'admission_number' => $data['role'] === 'student' ? ($data['admission_number'] ?? null) : null,
                'employee_number' => in_array($data['role'], self::STAFF_ROLES, true) ? ($data['employee_number'] ?? null) : null,
                'can_login' => $data['role'] !== 'student',
                'password' => $data['role'] === 'student' ? null : (! empty($data['password']) ? Hash::make($data['password']) : $user->password),
                'must_change_password' => $data['role'] !== 'student' && ! empty($data['password']) ? true : $user->must_change_password,
            ]);

            $user->syncRoles([$data['role']]);
            $this->syncProfiles($user, $schoolId, $data);

            if ($previousRole === 'student' && $data['role'] !== 'student') {
                $user->guardians()->detach();
            }
            if ($previousRole === 'parent' && $data['role'] !== 'parent') {
                $user->children()->detach();
            }
            if ($data['role'] === 'student' && ! empty($data['guardian_id'])) {
                $user->guardians()->sync([$data['guardian_id'] => ['relationship' => $data['relationship'] ?? 'parent', 'is_primary' => true]]);
            }
        });

        return back()->with('success', 'User profile updated.');
    }

    public function template(Request $request): StreamedResponse
    {
        $this->authorizePermission($request, 'users.import');

        return response()->streamDownload(function () {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'first_name',
                'last_name',
                'role',
                'email',
                'phone',
                'gender',
                'date_of_birth',
                'admission_number',
                'employee_number',
                'guardian_email',
                'relationship',
                'boarding_status',
                'previous_school',
                'rank',
                'staff_category',
                'tsc_number',
                'password',
            ]);
            fclose($output);
        }, 'school-users-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'users.import');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $headers = fgetcsv($handle);

        if (! $headers || ! in_array('first_name', $headers, true) || ! in_array('role', $headers, true)) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'CSV must include the template headers, including first_name and role.']);
        }

        $rows = [];
        $rowNumber = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if (count(array_filter($values, fn ($value) => $value !== null && $value !== '')) === 0) {
                continue;
            }
            if (count($headers) !== count($values)) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => "CSV row {$rowNumber} has a different number of columns than the header."]);
            }
            $rows[$rowNumber] = array_combine($headers, $values);
        }
        fclose($handle);

        if (count($rows) > 500) {
            throw ValidationException::withMessages(['file' => 'Import is limited to 500 users per file.']);
        }

        foreach ($rows as $rowNumber => $row) {
            $data = $this->validatedUserData($row, $schoolId);
            if ($data['role'] === 'student') {
                $guardian = User::role('parent')->where('school_id', $schoolId)->where('email', $data['guardian_email'] ?? null)->exists();
                if (! $guardian) {
                    throw ValidationException::withMessages(['file' => "CSV row {$rowNumber} must reference an existing parent email in this school."]);
                }
            }
            $rows[$rowNumber] = $data;
        }

        DB::transaction(function () use ($rows, $schoolId) {
            foreach ($rows as $data) {
                $this->provisionUser($schoolId, $data);
            }
        });

        return back()->with('success', count($rows).' users imported successfully.');
    }

    public function idCard(Request $request, User $user, QrCodeService $qrCode): Response
    {
        $this->authorizePermission($request, 'users.id-cards');
        $school = School::findOrFail($request->user()->school_id);
        abort_unless($user->school_id === $school->id, 404);
        abort_unless($user->hasRole('student') || $user->hasAnyRole(self::STAFF_ROLES), 404);

        $identifier = $user->admission_number ?: $user->employee_number ?: 'USER-'.$user->id;
        $role = $user->getRoleNames()->first();
        $payload = [
            'name' => $user->name,
            'identifier' => $identifier,
            'role' => $role,
            'school' => $school->name,
            'county' => $school->county,
        ];
        if ($role == 'student') {
            array_push($payload, [
                'class' => $user->studentProfile?->schoolClass?->name,
                'admission number' => $user->admission_number,
            ]);
        }

        return Inertia::render('users/id-card', [
            'user' => $user->only(['id', 'name', 'photo_path', 'admission_number', 'employee_number', 'phone']),
            'role' => $user->getRoleNames()->first(),
            'school' => $school->only(['name', 'code', 'logo_path', 'primary_color', 'secondary_color', 'motto', 'county']),
            'qrCode' => $qrCode->dataUri(json_encode($payload), 220),
            'identifier' => $identifier,
        ]);
    }

    private function validatedUser(Request $request, int $schoolId, ?int $userId = null, bool $existingStudent = false): array
    {
        return $this->validatedUserData($request->all(), $schoolId, $userId, $existingStudent);
    }

    private function validatedUserData(array $input, int $schoolId, ?int $userId = null, bool $existingStudent = false): array
    {
        $validator = Validator::make($input, [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'role' => ['required', Rule::in(self::MANAGEABLE_ROLES)],
            'email' => [Rule::requiredIf(fn () => ($input['role'] ?? null) !== 'student'), 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => 'nullable|string|max:40',
            'gender' => 'nullable|in:male,female,other',
            'date_of_birth' => 'nullable|date',
            'admission_number' => ['required_if:role,student', 'nullable', 'string', 'max:80', Rule::unique('users', 'admission_number')->where('school_id', $schoolId)->ignore($userId)],
            'employee_number' => ['nullable', 'string', 'max:80', Rule::unique('users', 'employee_number')->where('school_id', $schoolId)->ignore($userId)],
            'guardian_id' => [Rule::requiredIf(fn () => ($input['role'] ?? null) === 'student' && ! $existingStudent && empty($input['guardian_email'])), 'nullable', 'integer'],
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
            'password' => [Rule::requiredIf(fn () => ($input['role'] ?? null) !== 'student' && (! $userId || $existingStudent)), 'nullable', 'string', 'min:10'],
        ]);

        $data = $validator->validate();
        $role = $data['role'];

        if ($role === 'student' && ! $existingStudent && empty($data['guardian_email'])) {
            $guardian = User::role('parent')->where('school_id', $schoolId)->find($data['guardian_id'] ?? null);
            if (! $guardian) {
                throw ValidationException::withMessages(['guardian_id' => 'Choose a parent or guardian from this school.']);
            }
        }

        if ($role === 'student' && (! empty($data['guardian_email']) || ! empty($data['guardian_id']))) {
            $guardianQuery = User::role('parent')->where('school_id', $schoolId);
            $guardian = ! empty($data['guardian_email'])
                ? $guardianQuery->where('email', $data['guardian_email'])->first()
                : $guardianQuery->find($data['guardian_id']);
            if (! $guardian) {
                throw ValidationException::withMessages(['guardian_email' => 'Choose a parent or guardian from this school.']);
            }
            $data['guardian_id'] = $guardian->id;
        }

        return $data;
    }

    private function provisionUser(int $schoolId, array $data): User
    {
        $student = $data['role'] === 'student';
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
        $this->syncProfiles($user, $schoolId, $data);

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
                'imported_payload' => ['source' => 'user_management', 'admission_number' => $user->admission_number],
                'status' => 'completed',
            ]);
        }

        return $user;
    }

    private function syncProfiles(User $user, int $schoolId, array $data): void
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

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403);
    }
}

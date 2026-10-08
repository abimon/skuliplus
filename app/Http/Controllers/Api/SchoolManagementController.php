<?php

namespace App\Http\Controllers\Api;

use App\Models\CentralRequest;
use App\Models\Department;
use App\Models\School;
use App\Models\SchoolModule;
use App\Models\User;
use App\Services\UserProvisioningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * School management interface: the school's people, branding and its
 * requests to central management.
 */
class SchoolManagementController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'users');

        return $this->ok([
            'school' => $school->branding(),
            'is_active' => (bool) $school->is_active,
            'enabled_modules' => $school->enabledModuleKeys(),
            'modules' => collect(School::MODULES)->map(fn (string $name, string $key) => [
                'key' => $key,
                'name' => $name,
                'enabled' => $school->hasModuleEnabled($key),
                'available' => (bool) (SchoolModule::where('key', $key)->value('is_active') ?? true),
            ])->values()->all(),
            'roles' => UserProvisioningService::MANAGEABLE_ROLES,
            'stats' => [
                ['label' => 'People', 'value' => User::where('school_id', $school->id)->count()],
                ['label' => 'Learners', 'value' => User::role('student')->where('school_id', $school->id)->count()],
                ['label' => 'Staff', 'value' => User::where('school_id', $school->id)->where('can_login', true)->count()],
            ],
        ]);
    }

    /**
     * Paginated, filterable people directory.
     */
    public function people(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'users');

        abort_unless($this->user($request)->can('users.view'), 403, 'You do not have access to this resource.');

        $term = trim((string) $request->input('search', ''));

        $query = User::query()
            ->where('school_id', $school->id)
            ->with(['roles', 'studentProfile', 'teacherProfile', 'parentProfile', 'staffProfile',
                'guardians' => fn (BelongsToMany $guardians) => $guardians->where('users.school_id', $school->id)])
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $users) => $users
                ->where('name', 'like', '%'.$term.'%')
                ->orWhere('email', 'like', '%'.$term.'%')
                ->orWhere('admission_number', 'like', '%'.$term.'%')
                ->orWhere('employee_number', 'like', '%'.$term.'%')))
            ->when(in_array($request->query('role'), UserProvisioningService::MANAGEABLE_ROLES, true),
                fn (Builder $q) => $q->role($request->query('role')))
            ->latest();

        return $this->paginated($query->paginate(min((int) $request->integer('per_page', 20), 100)), 'people');
    }

    public function showPerson(Request $request, User $user): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'users');

        abort_unless($user->school_id === $school->id, 404);

        $user->load(['roles', 'studentProfile', 'teacherProfile.department', 'parentProfile', 'staffProfile',
            'guardians', 'currentEnrollment.schoolClass', 'currentEnrollment.stream']);

        return $this->ok([
            'person' => $this->personPayload($user),
            'profiles' => array_filter([
                'student' => $user->studentProfile,
                'teacher' => $user->teacherProfile,
                'parent' => $user->parentProfile,
                'staff' => $user->staffProfile,
            ]),
            'guardians' => $user->guardians->map(fn ($guardian) => $this->userPayload($guardian))->all(),
            'children' => $user->children()->get()->map(fn ($child) => $this->userPayload($child))->all(),
        ]);
    }
    public function storePerson(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'users');

        abort_unless($this->user($request)->can('users.create'), 403, 'You do not have access to this resource.');

        $clientSuppliedPassword = $request->filled('password');
        $data = $this->validatedPerson($request, $school->id);
        [$person, $generated] = UserProvisioningService::provision($school->id, $data);

        return $this->ok([
            'person' => $this->personPayload($person->fresh(['roles', 'studentProfile', 'teacherProfile', 'parentProfile', 'staffProfile'])),
            // Only meaningful the first time: the password is never stored in
            // clear text, so it cannot be shown again later.
            'generated_password' => $clientSuppliedPassword ? null : ($generated ?? $data['password'] ?? null),
        ], [], 201);
    }

    public function updatePerson(Request $request, User $user): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'users');

        abort_unless($this->user($request)->can('users.update'), 403, 'You do not have access to this resource.');
        abort_unless($user->school_id === $school->id && ! $user->hasRole('school_admin'), 404);

        $data = $this->validatedPerson($request, $school->id, $user->id, $user->hasRole('student'));

        UserProvisioningService::reprovision($user, $school->id, $data);

        return $this->ok($this->personPayload($user->fresh(['roles', 'studentProfile', 'teacherProfile', 'parentProfile', 'staffProfile'])));
    }

    public function destroyPerson(Request $request, User $user): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'users');

        abort_unless($this->user($request)->can('users.update'), 403, 'You do not have access to this resource.');
        abort_unless($user->school_id === $school->id && ! $user->hasAnyRole(['school_admin', 'super_admin']), 404);

        $user->delete();

        return $this->ok(['removed' => true]);
    }

    public function branding(Request $request): JsonResponse
    {
        $school = $this->ensureSchool($request);

        abort_unless($this->user($request)->can('branding.update'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'motto' => ['nullable', 'string', 'max:255'],
            'mission' => ['nullable', 'string', 'max:5000'],
            'vision' => ['nullable', 'string', 'max:5000'],
            'aim' => ['nullable', 'string', 'max:5000'],
            'phone' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:190'],
            'po_box' => ['nullable', 'string', 'max:120'],
            'primary_color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['sometimes', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'remove_logo' => ['nullable', 'boolean'],
            'logo_base64' => ['nullable', 'string'],
        ]);

        if (! empty($data['logo_base64'])) {
            [$path] = $this->storeLogo($data['logo_base64']);

            if ($school->logo_path) {
                Storage::disk('public')->delete($school->logo_path);
            }

            $data['logo_path'] = $path;
        } elseif ($request->boolean('remove_logo')) {
            if ($school->logo_path) {
                Storage::disk('public')->delete($school->logo_path);
            }

            $data['logo_path'] = null;
        }

        unset($data['logo_base64'], $data['remove_logo']);

        $school->update($data);

        return $this->ok($school->fresh()->branding());
    }
    /**
     * Requests this school has raised with central management.
 */
    public function requests(Request $request): JsonResponse
    {
        $school = $this->ensureSchool($request);

        $query = CentralRequest::with('handler:id,name')
            ->where('school_id', $school->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest();

        return $this->paginated($query->paginate(min((int) $request->integer('per_page', 25), 100)), 'central_requests');
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $school = $this->ensureSchool($request);

        $data = $request->validate([
            'type' => ['required', 'in:module_activation,curriculum_activation,demo,contact,feedback'],
            'subject' => ['required', 'string', 'max:190'],
            'message' => ['nullable', 'string', 'max:5000'],
            'module_key' => ['nullable', 'string', Rule::in(array_keys(School::MODULES))],
            'curriculum_id' => ['nullable', 'integer', 'exists:curricula,id'],
        ]);

        $payload = array_filter([
            'module_key' => $data['module_key'] ?? null,
            'curriculum_id' => $data['curriculum_id'] ?? null,
        ]);

        if ($data['type'] === 'module_activation' && empty($payload['module_key'])) {
            return $this->fail('Choose the module you would like activated.', 422, [
                'module_key' => ['Choose the module you would like activated.'],
            ]);
        }

        if ($data['type'] === 'curriculum_activation' && empty($payload['curriculum_id'])) {
            return $this->fail('Choose the curriculum you would like activated.', 422, [
                'curriculum_id' => ['Choose the curriculum you would like activated.'],
            ]);
        }

        $centralRequest = CentralRequest::create([
            'school_id' => $school->id,
            'user_id' => $this->user($request)->id,
            'type' => $data['type'],
            'subject' => $data['subject'],
            'message' => $data['message'] ?? null,
            'payload' => $payload ?: null,
            'status' => 'pending',
        ]);

        return $this->ok($centralRequest, [], 201);
    }

    public function departments(Request $request): JsonResponse
    {
        $school = $this->ensureSchool($request);

        return $this->ok(
            Department::where('school_id', $school->id)->orderBy('name')->get(['id', 'name', 'code'])->all()
        );
    }

    public function guardians(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'users');

        return $this->ok(
            User::role('parent')->where('school_id', $school->id)
                ->orderBy('name')->get(['id', 'name', 'email', 'phone'])->all()
        );
    }

    /**
     * Validate the submitted person.
     *
     * A password is generated up-front when one was not supplied so that the
     * caller receives a usable login instead of a validation error.
     */
    private function validatedPerson(Request $request, int $schoolId, ?int $userId = null, bool $existingStudent = false): array
    {
        if (($request->input('role') !== 'student') && ! $request->filled('password')) {
            $request->merge(['password' => Str::password(10)]);
        }

        $validator = Validator::make(
            $request->all(),
            UserProvisioningService::rules($request->all(), $schoolId, $userId, $existingStudent)
        );

        return UserProvisioningService::resolveGuardian($validator->validate(), $schoolId, $existingStudent);
    }

    /**
     * Persist a base64 logo uploaded from the mobile app.
     *
     * @return array{0: string}
     */
    private function storeLogo(string $base64): array
    {
        if (! preg_match('#^data:image/(png|jpe?g|webp);base64,#i', $base64)) {
            abort(422, 'The logo must be a PNG, JPEG or WebP image.');
        }

        $binary = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64), true);

        abort_if($binary === false || strlen($binary) > 2_097_152, 422, 'The logo must be smaller than 2MB.');

        $extension = str_contains($base64, 'png') ? 'png' : (str_contains($base64, 'webp') ? 'webp' : 'jpg');
        $path = 'logos/'.Str::random(40).'.'.$extension;

        Storage::disk('public')->put($path, $binary);

        return [$path];
    }

    /**
     * Rich representation used by the people directory and detail screens.
     */
    private function personPayload(User $user): array
    {
        $user->loadMissing(['roles', 'studentProfile', 'teacherProfile.department', 'parentProfile', 'staffProfile']);

        return array_merge($this->userPayload($user), [
            'school_id' => $user->school_id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'gender' => $user->gender,
            'date_of_birth' => $user->date_of_birth?->toDateString(),
            'national_id' => $user->national_id,
            'status' => $user->status,
            'can_login' => (bool) $user->can_login,
            'address' => $user->address,
            'county' => $user->county,
            'primary_role' => $user->getRoleNames()->first(),
            'enrollment' => $user->currentEnrollment ? [
                'class' => $user->currentEnrollment->schoolClass?->name,
                'stream' => $user->currentEnrollment->stream?->name,
                'status' => $user->currentEnrollment->status,
            ] : null,
            'profiles' => array_filter([
                'student' => $user->studentProfile,
                'teacher' => $user->teacherProfile,
                'parent' => $user->parentProfile,
                'staff' => $user->staffProfile,
            ]),
            'updated_at' => $this->timestamped($user->updated_at),
        ]);
    }
}

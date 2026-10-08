<?php

namespace App\Http\Controllers\Api;

use App\Models\CentralRequest;
use App\Models\Curriculum;
use App\Models\CurriculumLevel;
use App\Models\School;
use App\Models\SchoolModule;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Central (platform) management: schools, curricula, module pricing and
 * the request queue. Restricted to super admins.
 */
class CentralManagementController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureRole($request, self::CENTRAL_ROLES);

        return $this->ok([
            'stats' => [
                ['label' => 'Schools', 'value' => School::count()],
                ['label' => 'Active schools', 'value' => School::where('is_active', true)->count()],
                ['label' => 'Suspended schools', 'value' => School::where('is_active', false)->count()],
                ['label' => 'Pending requests', 'value' => CentralRequest::where('status', 'pending')->count()],
            ],
            'module_catalog' => SchoolModule::orderBy('key')->get()
                ->map(fn (SchoolModule $module) => $this->modulePayload($module))->all(),
            'curricula' => Curriculum::withCount('schools')->orderBy('name')->get()
                ->map(fn (Curriculum $curriculum) => $this->curriculumPayload($curriculum))->all(),
        ]);
    }

    public function schools(Request $request): JsonResponse
    {
        $this->ensureRole($request, self::CENTRAL_ROLES);

        $query = School::with('curricula')->withCount('users')->latest()
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$request->input('q').'%')
                ->orWhere('code', 'like', '%'.$request->input('q').'%')
                ->orWhere('county', 'like', '%'.$request->input('q').'%')))
            ->when($request->filled('status'), function ($q) use ($request) {
                $request->input('status') === 'active'
                    ? $q->where('is_active', true)
                    : $q->where('is_active', false);
            });

        return $this->paginated($query->paginate(min((int) $request->integer('per_page', 25), 100)), 'schools');
    }

    public function curricula(Request $request): JsonResponse
    {
        $this->ensureRole($request, self::CENTRAL_ROLES);

        $query = Curriculum::withCount('schools')->with('levels')->orderBy('name')
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->input('q').'%'));

        return $this->ok($query->get()->map(fn (Curriculum $curriculum) => $this->curriculumPayload($curriculum))->all());
    }

    public function storeCurriculum(Request $request): JsonResponse
    {
        $this->ensureRole($request, self::CENTRAL_ROLES);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:curricula,code'],
            'name' => ['required', 'string', 'max:255'],
            'authority' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        return $this->ok($this->curriculumPayload(Curriculum::create($data)), [], 201);
    }

    public function updateCurriculum(Request $request, Curriculum $curriculum): JsonResponse
    {
        $this->ensureRole($request, self::CENTRAL_ROLES);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'authority' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        $curriculum->update($data);

        return $this->ok($this->curriculumPayload($curriculum->fresh('levels')));
    }

    public function storeLevel(Request $request, Curriculum $curriculum): JsonResponse
    {
        $this->ensureRole($request, self::CENTRAL_ROLES);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'stage' => ['nullable', 'string', 'max:120'],
            'order' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->ok($this->levelPayload($curriculum->levels()->create($data)), [], 201);
    }
    public function storeSchool(Request $request): JsonResponse
    {
        $this->ensureRole($request, self::CENTRAL_ROLES);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:40', 'unique:schools,code'],
            'type' => ['nullable', 'string', 'max:80'],
            'level' => ['nullable', 'string', 'max:120'],
            'county' => ['nullable', 'string', 'max:120'],
            'sub_county' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:190'],
            'enabled_modules' => ['required', 'array', 'min:1'],
            'enabled_modules.*' => ['required', 'string', Rule::in(array_keys(School::MODULES))],
            'curriculum_ids' => ['required', 'array', 'min:1'],
            'curriculum_ids.*' => ['exists:curricula,id'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'unique:users,email'],
            'admin_phone' => ['nullable', 'string', 'max:40'],
        ]);

        if (! in_array('users', $data['enabled_modules'], true)) {
            return $this->fail('The People module is required for every school.', 422, [
                'enabled_modules' => ['The People module is required for every school.'],
            ]);
        }

        [$school, $admin, $password] = DB::transaction(function () use ($data) {
            $school = School::create(collect($data)->except(['curriculum_ids', 'admin_name', 'admin_email', 'admin_phone'])->all());
            $school->curricula()->sync(collect($data['curriculum_ids'])
                ->mapWithKeys(fn ($id, $i) => [$id => ['is_primary' => $i === 0]])->all());

            $names = explode(' ', $data['admin_name'], 2);
            $password = Str::password(10);

            $admin = User::create([
                'school_id' => $school->id,
                'first_name' => $names[0],
                'last_name' => $names[1] ?? $names[0],
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'phone' => $data['admin_phone'] ?? null,
                'can_login' => true,
                'status' => 'active',
                'password' => $password,
                'must_change_password' => true,
                'email_verified_at' => now(),
            ]);
            $admin->assignRole('school_admin');

            return [$school, $admin, $password];
        });

        return $this->ok([
            'school' => $this->schoolPayload($school->fresh('curricula')),
            'admin' => $this->userPayload($admin),
            'admin_password' => $password,
        ], [], 201);
    }

    public function updateSchool(Request $request, School $school): JsonResponse
    {
        $this->ensureRole($request, self::CENTRAL_ROLES);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:40', Rule::unique('schools', 'code')->ignore($school->id)],
            'type' => ['nullable', 'string', 'max:80'],
            'level' => ['nullable', 'string', 'max:120'],
            'county' => ['nullable', 'string', 'max:120'],
            'sub_county' => ['nullable', 'string', 'max:120'],
            'ward' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:190'],
            'website' => ['nullable', 'url', 'max:255'],
            'po_box' => ['nullable', 'string', 'max:120'],
            'motto' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'enabled_modules' => ['present', 'array'],
            'enabled_modules.*' => ['string', Rule::in(array_keys(School::MODULES))],
        ]);

        $school->update($data);

        return $this->ok($this->schoolPayload($school->fresh('curricula')));
    }

    public function setSchoolStatus(Request $request, School $school): JsonResponse
    {
        $this->ensureRole($request, self::CENTRAL_ROLES);

        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $school->update($data);

        return $this->ok($this->schoolPayload($school->fresh()));
    }
    public function requests(Request $request): JsonResponse
    {
        $this->ensureRole($request, self::CENTRAL_ROLES);

        $types = ['module_activation', 'curriculum_activation', 'demo', 'contact', 'feedback', 'testimonial', 'inquiry'];

        abort_if(
            $request->filled('type') && array_diff((array) $request->input('type'), $types),
            404
        );

        $query = CentralRequest::with(['school:id,name,code', 'requester:id,name,email'])
            ->when($request->filled('type'), fn ($q) => $q->whereIn('type', (array) $request->input('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('school_id'), fn ($q) => $q->where('school_id', $request->input('school_id')))
            ->latest();

        return $this->paginated($query->paginate(min((int) $request->integer('per_page', 25), 100)), 'central_requests');
    }

    public function handleRequest(Request $request, CentralRequest $centralRequest): JsonResponse
    {
        $this->ensureRole($request, self::CENTRAL_ROLES);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'decline', 'close'])],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $centralRequest, $data) {
            $centralRequest = CentralRequest::lockForUpdate()->findOrFail($centralRequest->id);

            abort_if($centralRequest->status !== 'pending', 409, 'This request has already been handled.');

            if ($data['decision'] === 'approve' && $centralRequest->school_id) {
                $this->applyActivation($centralRequest);
            }

            $centralRequest->update([
                'status' => $data['decision'] === 'approve' ? 'approved' : ($data['decision'] === 'decline' ? 'declined' : 'closed'),
                'handled_by' => $request->user()->id,
                'handled_at' => now(),
                'resolution_note' => $data['resolution_note'] ?? null,
            ]);
        });

        return $this->ok($this->requestPayload($centralRequest->fresh(['school', 'requester'])));
    }

    private function applyActivation(CentralRequest $request): void
    {
        $school = School::findOrFail($request->school_id);
        $payload = $request->payload ?? [];

        if ($request->type === 'module_activation') {
            $module = SchoolModule::where('key', $payload['module_key'] ?? '')->firstOrFail();
            $module->update(['is_active' => true]);
            $school->update([
                'enabled_modules' => array_values(array_unique([...$school->enabledModuleKeys(), $module->key])),
            ]);
        }

        if ($request->type === 'curriculum_activation') {
            $curriculum = Curriculum::findOrFail($payload['curriculum_id'] ?? 0);
            $curriculum->update(['is_active' => true]);
            $school->curricula()->syncWithoutDetaching([
                $curriculum->id => ['is_primary' => ! $school->curricula()->exists()],
            ]);
        }
    }
    private function schoolPayload(School $school): array
    {
        return array_merge($school->branding(), [
            'type' => $school->type,
            'level' => $school->level,
            'is_active' => (bool) $school->is_active,
            'enabled_modules' => $school->enabledModuleKeys(),
            'users_count' => $school->users_count,
            'curricula' => $school->relationLoaded('curricula')
                ? $school->curricula->map(fn ($curriculum) => [
                    'id' => $curriculum->id,
                    'name' => $curriculum->name,
                    'code' => $curriculum->code,
                    'is_primary' => (bool) ($curriculum->pivot->is_primary ?? false),
                ])->all()
                : [],
            'updated_at' => $this->timestamped($school->updated_at),
        ]);
    }

    private function modulePayload(SchoolModule $module): array
    {
        return [
            'id' => $module->id,
            'key' => $module->key,
            'name' => $module->name,
            'description' => $module->description,
            'price_kes' => (float) $module->price_kes,
            'is_active' => (bool) $module->is_active,
        ];
    }

    private function curriculumPayload(Curriculum $curriculum): array
    {
        return [
            'id' => $curriculum->id,
            'code' => $curriculum->code,
            'name' => $curriculum->name,
            'authority' => $curriculum->authority,
            'description' => $curriculum->description,
            'is_active' => (bool) $curriculum->is_active,
            'schools_count' => $curriculum->schools_count,
            'levels' => $curriculum->relationLoaded('levels')
                ? $curriculum->levels->map(fn ($level) => $this->levelPayload($level))->all()
                : [],
        ];
    }

    private function levelPayload(CurriculumLevel $level): array
    {
        return [
            'id' => $level->id,
            'curriculum_id' => $level->curriculum_id,
            'code' => $level->code,
            'name' => $level->name,
            'stage' => $level->stage,
            'order' => $level->order,
        ];
    }

    private function requestPayload(CentralRequest $request): array
    {
        return [
            'id' => $request->id,
            'type' => $request->type,
            'subject' => $request->subject,
            'message' => $request->message,
            'status' => $request->status,
            'payload' => $request->payload,
            'resolution_note' => $request->resolution_note,
            'handled_at' => $this->timestamped($request->handled_at),
            'school' => $request->school ? [
                'id' => $request->school->id,
                'name' => $request->school->name,
                'code' => $request->school->code,
            ] : null,
            'requester' => $this->userPayload($request->requester),
            'created_at' => $this->timestamped($request->created_at),
        ];
    }
}

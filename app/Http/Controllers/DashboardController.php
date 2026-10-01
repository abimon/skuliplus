<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\BookLoan;
use App\Models\CentralRequest;
use App\Models\Club;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\ExamResult;
use App\Models\FeeInvoice;
use App\Models\Lab;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolModule;
use App\Models\StoreItem;
use App\Models\Subject;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function selectChild(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('parent'), 403);

        $data = $request->validate(['child_id' => 'required|integer']);
        $child = $request->user()->children()
            ->where('users.school_id', $request->user()->school_id)
            ->whereKey($data['child_id'])
            ->exists();

        abort_unless($child, 404);
        $request->session()->put('active_child_id', (int) $data['child_id']);

        return back();
    }

    public function __invoke(Request $request): Response
    {
        $user = $request->user()->load(['school', 'teacherProfile.department', 'parentProfile', 'staffProfile']);
        $roles = $user->getRoleNames();
        $schoolId = $user->school_id;

        $stats = [];
        $panels = [];
        $modules = [];
        $unavailableModules = [];
        $curriculumAvailability = [];
        $pendingModuleRequests = [];
        $pendingCurriculumRequests = [];

        if ($user->hasRole('super_admin')) {
            $stats = [
                ['label' => 'Schools', 'value' => School::count()],
                ['label' => 'Users', 'value' => User::count()],
                ['label' => 'Active schools', 'value' => School::where('is_active', true)->count()],
            ];
            $modules = collect([
                ['key' => 'schools', 'name' => 'Schools', 'description' => 'Register schools and manage tenant access', 'icon' => 'building-2', 'permission' => 'schools.view', 'value' => School::count(), 'unit' => 'registered schools', 'href' => '/admin/schools'],
                ['key' => 'curricula', 'name' => 'Curricula', 'description' => 'Manage KICD pathways and school assignments', 'icon' => 'list-tree', 'permission' => 'curricula.manage', 'value' => Curriculum::where('is_active', true)->count(), 'unit' => 'active pathways', 'href' => '/admin/curricula'],
                ['key' => 'management', 'name' => 'Central management', 'description' => 'Module pricing, school requests and inquiries', 'icon' => 'bell', 'permission' => 'schools.view', 'value' => CentralRequest::where('status', 'pending')->count(), 'unit' => 'pending requests', 'href' => '/admin/management'],
            ])->filter(fn (array $module) => $user->can($module['permission']))->values()->all();
        }

        if ($schoolId) {
            $school = $user->school;
            $stats = array_merge($stats, [
                ['label' => 'Learners', 'value' => User::role('student')->where('school_id', $schoolId)->count()],
                ['label' => 'Staff', 'value' => User::where('school_id', $schoolId)->where('can_login', true)->count()],
                ['label' => 'Outstanding fees', 'value' => FeeInvoice::where('school_id', $schoolId)->where('status', '!=', 'paid')->sum('balance')],
                ['label' => 'Visitors today', 'value' => Visitor::where('school_id', $schoolId)->whereDate('checked_in_at', today())->count()],
            ]);
            $statModules = [
                'Learners' => 'users',
                'Staff' => 'users',
                'Outstanding fees' => 'finance',
                'Visitors today' => 'gate',
            ];
            $stats = array_values(array_filter($stats, fn (array $stat) => ! isset($statModules[$stat['label']])
                || $school->hasModuleEnabled($statModules[$stat['label']])));

            $curriculumIds = $school->curricula()->pluck('curricula.id');
            $moduleCatalog = [
                ['key' => 'users', 'name' => 'People', 'description' => 'Learners, families and staff', 'icon' => 'users', 'permission' => 'users.view', 'value' => User::where('school_id', $schoolId)->count(), 'unit' => 'profiles', 'href' => $user->can('users.update') ? '/users' : null],
                ['key' => 'academics', 'name' => 'Academics', 'description' => 'Classes, lessons and assessment', 'icon' => 'graduation-cap', 'permission' => 'classes.view', 'value' => SchoolClass::where('school_id', $schoolId)->count(), 'unit' => 'classes', 'href' => $user->can('classes.manage') ? '/academics' : null],
                ['key' => 'curriculum', 'name' => 'Curriculum', 'description' => 'KICD learning areas and pathways', 'icon' => 'book-open', 'permission' => 'classes.view', 'value' => Subject::whereIn('curriculum_id', $curriculumIds)->count(), 'unit' => 'learning areas'],
                ['key' => 'finance', 'name' => 'Finance', 'description' => 'Fee accounts and payments', 'icon' => 'wallet', 'permission' => 'finance.view', 'value' => FeeInvoice::where('school_id', $schoolId)->where('status', '!=', 'paid')->count(), 'unit' => 'open invoices', 'href' => $user->can('finance.manage') ? '/finance' : null],
                ['key' => 'library', 'name' => 'Library', 'description' => 'Books, loans and returns', 'icon' => 'library', 'permission' => 'library.view', 'value' => BookLoan::where('school_id', $schoolId)->where('status', 'borrowed')->count(), 'unit' => 'active loans'],
                ['key' => 'gate', 'name' => 'Gate visits', 'description' => 'Visitor check-in and checkout', 'icon' => 'door-open', 'permission' => 'gate.view', 'value' => Visitor::where('school_id', $schoolId)->whereNull('checked_out_at')->count(), 'unit' => 'on campus'],
                ['key' => 'stores', 'name' => 'Stores', 'description' => 'School-wide stock and supplies', 'icon' => 'boxes', 'permission' => 'stores.view', 'value' => StoreItem::where('school_id', $schoolId)->whereColumn('quantity', '<=', 'reorder_level')->count(), 'unit' => 'low-stock items'],
                ['key' => 'activities', 'name' => 'Activities', 'description' => 'Clubs, sports and school life', 'icon' => 'trophy', 'permission' => 'clubs.view', 'value' => Club::where('school_id', $schoolId)->count(), 'unit' => 'clubs'],
                ['key' => 'labs', 'name' => 'Laboratories', 'description' => 'Practical learning and equipment', 'icon' => 'flask-conical', 'permission' => 'labs.view', 'value' => Lab::where('school_id', $schoolId)->count(), 'unit' => 'labs'],
            ];

            $modulePrices = SchoolModule::pluck('price_kes', 'key');
            $globallyActive = SchoolModule::where('is_active', true)->pluck('key')->all();
            $enabledKeys = $school->enabledModuleKeys();
            foreach ($moduleCatalog as &$catalogModule) {
                $catalogModule['price_kes'] = $catalogModule['key'] === 'users' ? null : $modulePrices->get($catalogModule['key']);
            }
            unset($catalogModule);

            $modules = collect($moduleCatalog)
                ->filter(fn (array $module) => $user->can($module['permission'])
                    && $school->hasModuleEnabled($module['key'] === 'curriculum' ? 'academics' : $module['key'])
                    && in_array($module['key'] === 'curriculum' ? 'academics' : $module['key'], $globallyActive, true))
                ->values()
                ->all();

            if ($user->hasRole('school_admin')) {
                $unavailableModules = collect($moduleCatalog)
                    ->reject(fn (array $module) => in_array($module['key'], ['users', 'curriculum'], true)
                        || (in_array($module['key'], $enabledKeys, true) && in_array($module['key'], $globallyActive, true)))
                    ->map(fn (array $module) => collect($module)->only(['key', 'name', 'description', 'price_kes'])->all())
                    ->values()
                    ->all();
                $assignedCurricula = $school->curricula()->pluck('curricula.id')->all();
                $curriculumAvailability = Curriculum::query()->orderBy('name')->get(['id', 'name', 'code', 'is_active'])
                    ->map(fn (Curriculum $curriculum) => [
                        'id' => $curriculum->id,
                        'name' => $curriculum->name,
                        'code' => $curriculum->code,
                        'is_available' => $curriculum->is_active && in_array($curriculum->id, $assignedCurricula, true),
                    ])->all();
                $pendingModuleRequests = CentralRequest::where('school_id', $schoolId)
                    ->where('type', 'module_activation')
                    ->where('status', 'pending')
                    ->get(['payload'])
                    ->pluck('payload')
                    ->filter()
                    ->pluck('module_key')
                    ->values()
                    ->all();
                $pendingCurriculumRequests = CentralRequest::where('school_id', $schoolId)
                    ->where('type', 'curriculum_activation')
                    ->where('status', 'pending')
                    ->get(['payload'])
                    ->pluck('payload')
                    ->filter()
                    ->pluck('curriculum_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();
            }

            $panels['school'] = [
                'name' => $school->name,
                'county' => $school->county,
                'motto' => $school->motto,
                'logo_path' => $school->logo_path,
                'curricula' => $school->curricula()->where('curricula.is_active', true)->get(['curricula.id', 'curricula.name', 'curricula.code']),
                'academic_year' => AcademicYear::where('school_id', $schoolId)->where('is_current', true)->value('name'),
            ];
        }

        if ($user->hasRole('parent')) {
            $children = $user->children()
                ->where('users.school_id', $schoolId)
                ->with(['currentEnrollment.schoolClass', 'currentEnrollment.stream', 'studentProfile'])
                ->get();
            $activeId = $request->session()->get('active_child_id', $children->first()?->id);
            $child = $children->firstWhere('id', $activeId) ?? $children->first();
            $panels['children'] = $children;
            $panels['active_child'] = $child ? $this->studentSnapshot($child) : null;
        }

        if ($user->hasRole('teacher') || $user->hasRole('hod_academics')) {
            $panels['lessons'] = Lesson::with(['subject', 'stream.schoolClass'])
                ->where('teacher_id', $user->id)
                ->latest()
                ->limit(12)
                ->get();
            $panels['department'] = $user->teacherProfile?->department;
        }

        if ($user->hasRole('student')) {
            $panels['profile'] = $this->studentSnapshot($user);
        }

        return Inertia::render('dashboard', [
            'stats' => $stats,
            'roles' => $roles,
            'panels' => $panels,
            'modules' => $modules,
            'unavailableModules' => $unavailableModules,
            'curriculumAvailability' => $curriculumAvailability,
            'pendingModuleRequests' => $pendingModuleRequests,
            'pendingCurriculumRequests' => $pendingCurriculumRequests,
        ]);
    }

    public function studentSnapshot(User $student): array
    {
        $enrollment = Enrollment::with(['schoolClass', 'stream'])->where('student_id', $student->id)->where('status', 'active')->latest()->first();

        return [
            'id' => $student->id,
            'name' => $student->name,
            'admission_number' => $student->admission_number,
            'class' => $enrollment?->schoolClass?->name,
            'stream' => $enrollment?->stream?->name,
            'fees_balance' => FeeInvoice::where('student_id', $student->id)->sum('balance'),
            'results' => ExamResult::with(['exam', 'subject'])->where('student_id', $student->id)->latest()->limit(8)->get(),
            'books' => BookLoan::with('book')->where('borrower_id', $student->id)->where('status', 'borrowed')->get(),
            'clubs' => $student->clubs()->get(['clubs.id', 'clubs.name', 'clubs.type']),
        ];
    }
}

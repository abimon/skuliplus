<?php

namespace App\Http\Controllers\Api;

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\BookLoan;
use App\Models\CentralRequest;
use App\Models\Club;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\ExamResult;
use App\Models\FeeInvoice;
use App\Models\LabItem;
use App\Models\Lesson;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolModule;
use App\Models\StoreItem;
use App\Models\Stream;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Home screen data. The shape adapts to whichever interface (central,
 * school, parent, teacher or staff) the signed-in user belongs to.
 */
class DashboardController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $payload = [
            'primary_role' => $this->primaryRole($user),
            'roles' => $this->roles($user),
            'permissions' => $this->permissions($user),
            'profile' => $this->profilePayload($user),
            'stats' => [],
            'modules' => [],
        ];

        if ($user->hasAnyRole(self::CENTRAL_ROLES)) {
            $payload['central'] = $this->centralSummary();
        }

        if ($user->school_id) {
            $payload['school'] = $this->schoolSummary($user);
            $payload['modules'] = $this->availableModules($user);
            $payload['stats'] = $this->schoolStats($user);
        }

        if ($user->hasRole('parent')) {
            $payload['parent'] = $this->parentSummary($user);
        }

        if ($user->hasAnyRole(['teacher', 'hod_academics'])) {
            $payload['teacher'] = $this->teacherSummary($user);
        }

        if ($user->hasRole('student')) {
            $payload['student'] = $this->studentSummary($user);
        }

        if ($user->hasAnyRole(self::STAFF_ROLES)) {
            $payload['staff'] = $this->staffSummary($user);
        }

        return $this->ok($payload);
    }

    private function centralSummary(): array
    {
        return [
            'stats' => [
                ['label' => 'Schools', 'value' => School::count()],
                ['label' => 'Active schools', 'value' => School::where('is_active', true)->count()],
                ['label' => 'Pending requests', 'value' => CentralRequest::where('status', 'pending')->count()],
                ['label' => 'Active curricula', 'value' => Curriculum::where('is_active', true)->count()],
            ],
            'modules' => SchoolModule::orderBy('key')->get()
                ->map(fn (SchoolModule $module) => [
                    'key' => $module->key,
                    'name' => $module->name,
                    'description' => $module->description,
                    'price_kes' => (float) $module->price_kes,
                    'is_active' => (bool) $module->is_active,
                ])->all(),
            'recent_requests' => CentralRequest::with(['school:id,name,code', 'requester:id,name,email'])
                ->latest()->limit(10)->get()
                ->map(fn ($request) => $this->requestPayload($request))->all(),
        ];
    }

    private function schoolSummary(User $user): array
    {
        $school = $user->school;

        return [
            'id' => $school->id,
            'name' => $school->name,
            'code' => $school->code,
            'county' => $school->county,
            'motto' => $school->motto,
            'logo_path' => $school->logo_path,
            'primary_color' => $school->primary_color,
            'secondary_color' => $school->secondary_color,
            'accent_color' => $school->accent_color,
            'academic_year' => AcademicYear::where('school_id', $user->school_id)
                ->where('is_current', true)->value('name'),
            'curricula' => $school->curricula()->where('curricula.is_active', true)
                ->get(['curricula.id', 'curricula.name', 'curricula.code'])->all(),
        ];
    }

    private function availableModules(User $user): array
    {
        $school = $user->school;

        return collect(School::MODULES)->map(fn (string $name, string $key) => [
            'key' => $key,
            'name' => $name,
            'enabled' => $school->hasModuleEnabled($key),
        ])->values()->all();
    }
    private function schoolStats(User $user): array
    {
        $schoolId = $user->school_id;

        $stats = [
            ['label' => 'Learners', 'value' => User::role('student')->where('school_id', $schoolId)->count()],
            ['label' => 'Teachers', 'value' => User::role('teacher')->where('school_id', $schoolId)->count()],
            ['label' => 'Classes', 'value' => SchoolClass::where('school_id', $schoolId)->count()],
            ['label' => 'Outstanding fees', 'value' => (float) FeeInvoice::where('school_id', $schoolId)->where('status', '!=', 'paid')->sum('balance')],
            ['label' => 'Visitors today', 'value' => Visitor::where('school_id', $schoolId)->whereDate('created_at', today())->count()],
            ['label' => 'Books on loan', 'value' => BookLoan::where('school_id', $schoolId)->where('status', 'borrowed')->count()],
        ];

        if ($user->can('users.view')) {
            $stats[] = ['label' => 'Staff', 'value' => User::where('school_id', $schoolId)->where('can_login', true)->count()];
            $stats[] = ['label' => 'Enrolled', 'value' => Enrollment::where('school_id', $schoolId)->where('status', 'active')->count()];
        }

        return $stats;
    }

    private function parentSummary(User $user): array
    {
        $children = $user->children()
            ->where('users.school_id', $user->school_id)
            ->with(['currentEnrollment.schoolClass', 'currentEnrollment.stream', 'studentProfile'])
            ->get();

        return [
            'children' => $children->map(fn (User $child) => $this->studentSnapshot($child))->all(),
            'outstanding' => (float) FeeInvoice::whereIn('student_id', $children->pluck('users.id'))
                ->where('status', '!=', 'paid')->sum('balance'),
        ];
    }

    private function teacherSummary(User $user): array
    {
        return [
            'department' => $user->teacherProfile?->department?->name,
            'lessons' => Lesson::with(['subject', 'stream.schoolClass'])
                ->where('teacher_id', $user->id)->latest()->limit(12)->get()
                ->map(fn ($lesson) => [
                    'id' => $lesson->id,
                    'subject' => $lesson->subject?->name,
                    'class' => $lesson->stream?->schoolClass?->name,
                    'stream' => $lesson->stream?->name,
                    'day' => $lesson->day_of_week,
                    'start' => $lesson->start_time,
                    'end' => $lesson->end_time,
                    'room' => $lesson->room,
                ])->all(),
            'streams' => Stream::where('school_id', $user->school_id)
                ->whereHas('lessons', fn ($q) => $q->where('teacher_id', $user->id))
                ->with('schoolClass')->get()
                ->map(fn ($stream) => [
                    'id' => $stream->id,
                    'name' => $stream->name,
                    'class' => $stream->schoolClass?->name,
                ])->all(),
        ];
    }

    private function studentSummary(User $user): array
    {
        $snapshot = $this->studentSnapshot($user);

        return [
            'profile' => $snapshot,
            'results' => $snapshot['results'],
            'books' => $snapshot['books'],
            'clubs' => $user->clubs()->get(['clubs.id', 'clubs.name', 'clubs.type'])->all(),
        ];
    }
    private function staffSummary(User $user): array
    {
        $schoolId = $user->school_id;
        $duties = [];

        if ($user->can('library.view')) {
            $duties[] = [
                'key' => 'library',
                'title' => 'Library',
                'stats' => [
                    ['label' => 'Books', 'value' => Book::where('school_id', $schoolId)->count()],
                    ['label' => 'On loan', 'value' => BookLoan::where('school_id', $schoolId)->where('status', 'borrowed')->count()],
                ],
            ];
        }

        if ($user->can('gate.view')) {
            $duties[] = [
                'key' => 'gate',
                'title' => 'Gate visits',
                'stats' => [
                    ['label' => 'Today', 'value' => Visitor::where('school_id', $schoolId)->whereDate('created_at', today())->count()],
                    ['label' => 'On site', 'value' => Visitor::where('school_id', $schoolId)->whereNull('checked_out_at')->count()],
                ],
            ];
        }

        if ($user->can('stores.view')) {
            $duties[] = [
                'key' => 'stores',
                'title' => 'Stores',
                'stats' => [
                    ['label' => 'Items', 'value' => StoreItem::where('school_id', $schoolId)->count()],
                    ['label' => 'Low stock', 'value' => StoreItem::where('school_id', $schoolId)->whereColumn('quantity', '<=', 'reorder_level')->count()],
                ],
            ];
        }

        if ($user->can('labs.view')) {
            $duties[] = [
                'key' => 'labs',
                'title' => 'Laboratories',
                'stats' => [
                    ['label' => 'Items', 'value' => LabItem::where('school_id', $schoolId)->count()],
                    ['label' => 'Issued', 'value' => LabItem::where('school_id', $schoolId)->where('status', 'issued')->count()],
                ],
            ];
        }

        if ($user->can('finance.view')) {
            $duties[] = [
                'key' => 'finance',
                'title' => 'Finance',
                'stats' => [
                    ['label' => 'Outstanding', 'value' => (float) FeeInvoice::where('school_id', $schoolId)->where('status', '!=', 'paid')->sum('balance')],
                    ['label' => 'Received', 'value' => (float) \App\Models\Payment::where('school_id', $schoolId)->where('status', 'confirmed')->sum('amount')],
                ],
            ];
        }

        if ($user->can('clubs.view')) {
            $duties[] = [
                'key' => 'activities',
                'title' => 'Activities',
                'stats' => [
                    ['label' => 'Clubs', 'value' => Club::where('school_id', $schoolId)->count()],
                    ['label' => 'Activities', 'value' => \App\Models\Activity::where('school_id', $schoolId)->count()],
                ],
            ];
        }

        return ['duties' => $duties];
    }

    private function studentSnapshot(User $student): array
    {
        $enrollment = Enrollment::with(['schoolClass', 'stream'])
            ->where('student_id', $student->id)->where('status', 'active')->latest()->first();

        return [
            'id' => $student->id,
            'name' => $student->name,
            'admission_number' => $student->admission_number,
            'photo_path' => $student->photo_path,
            'class' => $enrollment?->schoolClass?->name,
            'stream' => $enrollment?->stream?->name,
            'fees_balance' => (float) FeeInvoice::where('student_id', $student->id)->sum('balance'),
            'results' => ExamResult::with(['exam.term', 'subject'])
                ->where('student_id', $student->id)->latest()->limit(8)->get()
                ->map(fn ($result) => [
                    'id' => $result->id,
                    'exam' => $result->exam?->name,
                    'subject' => $result->subject?->name,
                    'score' => (float) $result->score,
                    'grade' => $result->grade,
                    'remarks' => $result->remarks,
                    'term' => $result->exam?->term?->name,
                ])->all(),
            'books' => BookLoan::with('book')->where('borrower_id', $student->id)
                ->where('status', 'borrowed')->get()
                ->map(fn ($loan) => [
                    'id' => $loan->id,
                    'title' => $loan->book?->title,
                    'due_on' => $loan->due_on?->toDateString(),
                    'reference' => $loan->reference,
                ])->all(),
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

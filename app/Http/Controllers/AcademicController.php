<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Stream;
use App\Models\Term;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Services\TimetableGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AcademicController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request, 'classes.manage');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $termId = $request->integer('term_id');
        $currentTerm = Term::where('school_id', $schoolId)
            ->when($termId, fn (Builder $query) => $query->whereKey($termId), fn (Builder $query) => $query->where('is_current', true))
            ->first();
        abort_if($termId && ! $currentTerm, 404);
        $classes = SchoolClass::where('school_id', $schoolId)
            ->with(['level', 'curriculum', 'academicYear', 'streams.classTeacher'])
            ->withCount(['enrollments as active_learners_count' => fn (Builder $query) => $query->where('status', 'active')])
            ->with(['streams' => function ($query) use ($currentTerm) {
                $query->with('classTeacher')
                    ->withCount(['enrollments as active_learners_count' => fn (Builder $enrollments) => $enrollments->where('status', 'active')])
                    ->with(['lessons' => fn ($lessons) => $lessons
                        ->when($currentTerm, fn ($lessons) => $lessons->where('term_id', $currentTerm->id))
                        ->with('subject')])
                    ->with(['timetableSlots' => fn ($slots) => $slots
                        ->where('term_id', $currentTerm?->id ?? 0)
                        ->with('lesson.subject')
                        ->orderBy('day_of_week')
                        ->orderBy('period')]);
            }])
            ->orderByDesc('academic_year_id')
            ->orderBy('name')
            ->get();

        return Inertia::render('academics/index', [
            'school' => $request->user()->school()->first(['name', 'code', 'county']),
            'modules' => collect([
                ['key' => 'users', 'name' => 'People', 'permission' => 'users.view', 'href' => $request->user()->can('users.update') ? '/users' : null],
                ['key' => 'academics', 'name' => 'Academics', 'permission' => 'classes.view', 'href' => '/academics'],
                ['key' => 'curriculum', 'name' => 'Curriculum', 'permission' => 'classes.view'],
                ['key' => 'finance', 'name' => 'Finance', 'permission' => 'finance.view', 'href' => $request->user()->can('finance.manage') ? '/finance' : null],
                ['key' => 'library', 'name' => 'Library', 'permission' => 'library.view'],
                ['key' => 'gate', 'name' => 'Gate visits', 'permission' => 'gate.view'],
                ['key' => 'stores', 'name' => 'Stores', 'permission' => 'stores.view'],
                ['key' => 'activities', 'name' => 'Activities', 'permission' => 'clubs.view'],
                ['key' => 'labs', 'name' => 'Laboratories', 'permission' => 'labs.view'],
            ])->filter(fn (array $module) => $request->user()->can($module['permission']))
                ->map(fn (array $module) => collect($module)->except('permission')->all())
                ->values(),
            'classes' => $classes,
            'academicYears' => AcademicYear::where('school_id', $schoolId)->orderByDesc('starts_on')->get(['id', 'name', 'is_current']),
            'currentTerm' => $currentTerm?->only(['id', 'name', 'academic_year_id']),
            'terms' => Term::where('school_id', $schoolId)->with('academicYear:id,name')->orderByDesc('starts_on')->get(['id', 'name', 'academic_year_id', 'is_current']),
            'curricula' => School::findOrFail($schoolId)->curricula()
                ->where('is_active', true)
                ->with(['levels' => fn ($levels) => $levels->orderBy('order'), 'subjects' => fn ($subjects) => $subjects->orderBy('name')])
                ->get(['curricula.id', 'curricula.code', 'curricula.name']),
            'students' => User::role('student')->where('school_id', $schoolId)->orderBy('name')->get(['id', 'name', 'admission_number']),
            'teachers' => User::role(['teacher', 'hod_academics'])->where('school_id', $schoolId)->orderBy('name')->get(['id', 'name']),
            'can' => [
                'generate_timetable' => $request->user()->can('timetable.generate'),
                'edit_timetable' => $request->user()->can('timetable.edit'),
            ],
        ]);
    }

    public function storeClass(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'classes.manage');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'curriculum_id' => ['required', Rule::exists('curriculum_school', 'curriculum_id')->where('school_id', $schoolId)],
            'curriculum_level_id' => ['required', Rule::exists('curriculum_levels', 'id')->where('curriculum_id', $request->input('curriculum_id'))],
            'academic_year_id' => ['required', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
        ]);

        SchoolClass::create(['school_id' => $schoolId, ...$data]);

        return back()->with('success', 'Class created. Add streams and enroll learners to complete setup.');
    }

    public function storeAcademicYear(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'classes.manage');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:20', Rule::unique('academic_years', 'name')->where('school_id', $schoolId)],
            'starts_on' => 'required|date',
            'ends_on' => 'required|date|after:starts_on',
            'is_current' => 'required|boolean',
        ]);

        $year = DB::transaction(function () use ($data, $schoolId) {
            if ($data['is_current']) {
                AcademicYear::where('school_id', $schoolId)->update(['is_current' => false]);
                Term::where('school_id', $schoolId)->update(['is_current' => false]);
            }

            return AcademicYear::create(['school_id' => $schoolId, ...$data]);
        });

        return back()->with('success', "Academic year {$year->name} created.");
    }

    public function storeTerm(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'classes.manage');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $data = $request->validate([
            'academic_year_id' => ['required', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
            'name' => 'required|string|max:40',
            'number' => ['required', 'integer', 'between:1,3', Rule::unique('terms', 'number')->where('school_id', $schoolId)->where('academic_year_id', $request->input('academic_year_id'))],
            'starts_on' => 'required|date',
            'ends_on' => 'required|date|after:starts_on',
            'is_current' => 'required|boolean',
        ]);

        $year = AcademicYear::where('school_id', $schoolId)->findOrFail($data['academic_year_id']);
        if ($data['starts_on'] < $year->starts_on->toDateString() || $data['ends_on'] > $year->ends_on->toDateString()) {
            throw ValidationException::withMessages(['starts_on' => 'Term dates must fall within the selected academic year.']);
        }

        DB::transaction(function () use ($data, $schoolId, $year) {
            if ($data['is_current']) {
                Term::where('school_id', $schoolId)->update(['is_current' => false]);
                AcademicYear::where('school_id', $schoolId)->update(['is_current' => false]);
                AcademicYear::whereKey($year->id)->update(['is_current' => true]);
            }

            Term::create(['school_id' => $schoolId, ...$data]);
        });

        return back()->with('success', $data['name'].' created.');
    }

    public function storeStream(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'classes.manage');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $data = $request->validate([
            'school_class_id' => ['required', Rule::exists('school_classes', 'id')->where('school_id', $schoolId)],
            'name' => 'required|string|max:80',
            'class_teacher_id' => ['nullable', Rule::exists('users', 'id')->where('school_id', $schoolId)],
            'capacity' => 'required|integer|min:1|max:200',
        ]);

        if (! empty($data['class_teacher_id']) && ! User::role(['teacher', 'hod_academics'])
            ->where('school_id', $schoolId)
            ->whereKey($data['class_teacher_id'])
            ->exists()) {
            throw ValidationException::withMessages(['class_teacher_id' => 'Choose a teacher from this school.']);
        }

        $duplicate = Stream::where('school_id', $schoolId)
            ->where('school_class_id', $data['school_class_id'])
            ->where('name', $data['name'])
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'This stream already exists in the selected class.']);
        }

        Stream::create(['school_id' => $schoolId, ...$data]);

        return back()->with('success', 'Stream created.');
    }

    public function enrollStudent(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'classes.manage');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $data = $request->validate([
            'student_id' => 'required|integer',
            'school_class_id' => ['required', Rule::exists('school_classes', 'id')->where('school_id', $schoolId)],
            'stream_id' => 'nullable|integer',
        ]);

        $student = User::role('student')->where('school_id', $schoolId)->find($data['student_id']);
        if (! $student) {
            throw ValidationException::withMessages(['student_id' => 'Choose a learner from this school.']);
        }

        $class = SchoolClass::where('school_id', $schoolId)->findOrFail($data['school_class_id']);
        $stream = null;
        if (! empty($data['stream_id'])) {
            $stream = Stream::where('school_id', $schoolId)
                ->where('school_class_id', $class->id)
                ->find($data['stream_id']);
            if (! $stream) {
                throw ValidationException::withMessages(['stream_id' => 'Choose a stream in the selected class.']);
            }
            if ($stream->enrollments()->where('status', 'active')->count() >= $stream->capacity) {
                throw ValidationException::withMessages(['stream_id' => 'This stream has reached its enrollment capacity.']);
            }
        }

        $existing = Enrollment::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->where('academic_year_id', $class->academic_year_id)
            ->where('status', 'active')
            ->exists();
        if ($existing) {
            throw ValidationException::withMessages(['student_id' => 'This learner already has an active enrollment for the selected academic year.']);
        }

        Enrollment::create([
            'school_id' => $schoolId,
            'student_id' => $student->id,
            'school_class_id' => $class->id,
            'stream_id' => $stream?->id,
            'academic_year_id' => $class->academic_year_id,
            'status' => 'active',
            'enrolled_on' => now()->toDateString(),
        ]);

        return back()->with('success', 'Learner enrolled in '.$class->name.'.');
    }

    public function generateTimetable(Request $request, Stream $stream, TimetableGenerator $generator): RedirectResponse
    {
        $this->authorizePermission($request, 'timetable.generate');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $data = $request->validate([
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'replace' => 'sometimes|boolean',
        ]);

        abort_unless($stream->school_id === $schoolId, 404);
        $term = Term::where('school_id', $schoolId)->findOrFail($data['term_id']);
        abort_unless($stream->schoolClass()->where('academic_year_id', $term->academic_year_id)->exists(), 422);

        $existingSlots = $stream->timetableSlots()->where('term_id', $term->id)->exists();
        if ($existingSlots && empty($data['replace'])) {
            throw ValidationException::withMessages(['term_id' => 'A timetable already exists. Confirm replacement before generating again.']);
        }
        if ($existingSlots) {
            $this->authorizePermission($request, 'timetable.edit');
        }

        $created = $generator->generate($stream, $term);

        return back()->with('success', "Generated {$created} timetable periods for {$stream->name}.");
    }

    public function updateTimetableSlot(Request $request, TimetableSlot $slot): RedirectResponse
    {
        $this->authorizePermission($request, 'timetable.edit');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);
        abort_unless($slot->school_id === $schoolId, 404);

        $data = $request->validate([
            'lesson_id' => ['required', Rule::exists('lessons', 'id')
                ->where('school_id', $schoolId)
                ->where('stream_id', $slot->stream_id)
                ->where('term_id', $slot->term_id)],
        ]);

        $slot->update(['lesson_id' => $data['lesson_id']]);

        return back()->with('success', 'Timetable period updated.');
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Lesson;
use App\Models\SchoolClass;
use App\Models\Stream;
use App\Models\Term;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Services\GradeService;
use App\Services\TimetableGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Academics: classes, streams, timetables, assessments and results.
 */
class AcademicsController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'academics');

        abort_unless($this->user($request)->can('classes.view'), 403, 'You do not have access to this resource.');

        return $this->ok([
            'academic_years' => AcademicYear::where('school_id', $school->id)
                ->orderByDesc('starts_on')->get(['id', 'name', 'is_current'])->all(),
            'terms' => Term::where('school_id', $school->id)->with('academicYear:id,name')
                ->orderByDesc('starts_on')->get(['id', 'name', 'academic_year_id', 'is_current'])->all(),
            'current_term' => $this->currentTerm($school->id, $request->integer('term_id') ?: null),
            'curricula' => $school->curricula()->where('is_active', true)
                ->with(['levels' => fn ($levels) => $levels->orderBy('order'),
                    'subjects' => fn ($subjects) => $subjects->orderBy('name')])
                ->get(['curricula.id', 'curricula.code', 'curricula.name'])->all(),
        ]);
    }

    public function classes(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'academics');
        $term = $this->currentTerm($school->id, $request->integer('term_id') ?: null);

        $classes = SchoolClass::where('school_id', $school->id)
            ->with(['level', 'curriculum', 'academicYear'])
            ->withCount(['enrollments as active_learners_count' => fn (Builder $q) => $q->where('status', 'active')])
            ->with(['streams' => function ($streams) use ($term) {
                $streams->with('classTeacher')
                    ->withCount(['enrollments as active_learners_count' => fn (Builder $q) => $q->where('status', 'active')])
                    ->with(['lessons' => fn ($lessons) => $lessons
                        ->when($term, fn ($l) => $l->where('term_id', $term->id))->with('subject')])
                    ->with(['timetableSlots' => fn ($slots) => $slots
                        ->where('term_id', $term->id ?? 0)
                        ->with('lesson.subject')
                        ->orderBy('day_of_week')
                        ->orderBy('period')]);
            }])
            ->orderByDesc('academic_year_id')->orderBy('name')->get()
            ->map(fn (SchoolClass $class) => $this->classPayload($class));

        return $this->ok($classes);
    }

    public function showClass(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'academics');

        abort_unless($schoolClass->school_id === $school->id, 404);

        $schoolClass->load(['level', 'curriculum', 'academicYear', 'streams.classTeacher', 'streams.enrollments.student']);

        return $this->ok($this->classPayload($schoolClass));
    }
    public function storeClass(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'academics');

        abort_unless($this->user($request)->can('classes.manage'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'curriculum_id' => ['required', Rule::exists('curriculum_school', 'curriculum_id')->where('school_id', $school->id)],
            'curriculum_level_id' => ['required', Rule::exists('curriculum_levels', 'id')->where('curriculum_id', $request->input('curriculum_id'))],
            'academic_year_id' => ['required', Rule::exists('academic_years', 'id')->where('school_id', $school->id)],
        ]);

        $class = SchoolClass::create(['school_id' => $school->id, ...$data]);

        return $this->ok($this->classPayload($class), [], 201);
    }

    public function storeStream(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'academics');

        abort_unless($this->user($request)->can('classes.manage'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'school_class_id' => ['required', Rule::exists('school_classes', 'id')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:100'],
            'class_teacher_id' => ['nullable', Rule::exists('users', 'id')->where('school_id', $school->id)],
        ]);

        return $this->ok(Stream::create(['school_id' => $school->id, ...$data])->fresh('classTeacher'), [], 201);
    }

    public function enroll(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'academics');

        abort_unless($this->user($request)->can('classes.manage'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'student_id' => ['required', Rule::exists('users', 'id')->where('school_id', $school->id)],
            'stream_id' => ['required', Rule::exists('streams', 'id')->where('school_id', $school->id)],
        ]);

        $student = User::role('student')->where('school_id', $school->id)->findOrFail($data['student_id']);

        $enrollment = Enrollment::updateOrCreate(
            ['student_id' => $student->id, 'stream_id' => $data['stream_id'], 'status' => 'active'],
            ['school_id' => $school->id, 'enrolled_on' => now()->toDateString()],
        );

        return $this->ok(
            $enrollment->load(['student:id,name,admission_number', 'schoolClass:id,name', 'stream:id,name']),
            [],
            201
        );
    }

    /**
     * Timetable for the whole school, or filtered to one stream.
     */
    public function timetable(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'academics');

        abort_unless($this->user($request)->can('timetable.view'), 403, 'You do not have access to this resource.');

        $term = $this->currentTerm($school->id, $request->integer('term_id') ?: null);

        $slots = TimetableSlot::where('school_id', $school->id)
            ->when($term, fn ($q) => $q->where('term_id', $term->id))
            ->when($request->integer('stream_id'), fn ($q) => $q->where('stream_id', $request->integer('stream_id')))
            ->with(['lesson.subject', 'lesson.teacher:id,name', 'stream.schoolClass:id,name'])
            ->orderBy('day_of_week')->orderBy('period')->get();

        return $this->ok($slots->map(fn ($slot) => $this->slotPayload($slot))->all(), [
            'term' => $term,
            'days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        ]);
    }

    public function updateSlot(Request $request, TimetableSlot $timetableSlot): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'academics');

        abort_unless($this->user($request)->can('timetable.edit'), 403, 'You do not have access to this resource.');
        abort_unless($timetableSlot->school_id === $school->id, 404);

        $data = $request->validate([
            'lesson_id' => ['required', Rule::exists('lessons', 'id')->where('school_id', $school->id)],
            'day_of_week' => ['required', 'integer', 'between:1,5'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'room' => ['nullable', 'string', 'max:80'],
        ]);

        $timetableSlot->update($data);

        return $this->ok($this->slotPayload($timetableSlot->fresh(['lesson.subject', 'lesson.teacher', 'stream.schoolClass'])));
    }
    public function lessons(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'academics');

        abort_unless($this->user($request)->can('timetable.view'), 403, 'You do not have access to this resource.');

        $lessons = Lesson::where('school_id', $school->id)
            ->when($request->integer('teacher_id'), fn ($q) => $q->where('teacher_id', $request->integer('teacher_id')))
            ->when($request->integer('stream_id'), fn ($q) => $q->where('stream_id', $request->integer('stream_id')))
            ->when($request->integer('subject_id'), fn ($q) => $q->where('subject_id', $request->integer('subject_id')))
            ->with(['subject', 'teacher:id,name', 'stream.schoolClass:id,name', 'term:id,name'])
            // ->orderBy('day_of_week')->orderBy('start_time')
            ->get();

        return $this->ok($lessons->map(fn ($lesson) => $this->lessonPayload($lesson))->all());
    }

    public function storeLesson(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'academics');

        abort_unless($this->user($request)->can('timetable.edit'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'stream_id' => ['required', Rule::exists('streams', 'id')->where('school_id', $school->id)],
            'subject_id' => ['required', Rule::exists('subjects', 'id')],
            'teacher_id' => ['required', Rule::exists('users', 'id')->where('school_id', $school->id)],
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $school->id)],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'room' => ['nullable', 'string', 'max:80'],
        ]);

        $lesson = Lesson::create(['school_id' => $school->id, ...$data]);

        return $this->ok($this->lessonPayload($lesson->fresh(['subject', 'teacher', 'stream.schoolClass', 'term'])), [], 201);
    }

    public function generateTimetable(Request $request, Stream $stream): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'academics');

        abort_unless($this->user($request)->can('timetable.generate'), 403, 'You do not have access to this resource.');
        abort_unless($stream->school_id === $school->id, 404);

        $data = $request->validate([
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $school->id)],
        ]);

        $created = app(TimetableGenerator::class)->generate($stream, Term::findOrFail($data['term_id']));

        return $this->ok(['slots_created' => $created], [], 201);
    }

    public function exams(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'academics');

        abort_unless($this->user($request)->can('exams.view'), 403, 'You do not have access to this resource.');

        $exams = Exam::where('school_id', $school->id)
            ->when($request->integer('term_id'), fn ($q) => $q->where('term_id', $request->integer('term_id')))
            ->with(['term:id,name', 'schoolClass:id,name'])
            ->withCount('results')
            ->latest()->get()
            ->map(fn (Exam $exam) => [
                'id' => $exam->id,
                'name' => $exam->name,
                'term' => $exam->term?->name,
                'class' => $exam->schoolClass?->name,
                'held_on' => $exam->held_on?->toDateString(),
                'max_score' => (float) $exam->max_score,
                'is_published' => (bool) $exam->is_published,
                'results_count' => $exam->results_count,
            ]);

        return $this->ok($exams);
    }

    public function storeExam(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'academics');

        abort_unless($this->user($request)->can('exams.manage'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $school->id)],
            'school_class_id' => ['required', Rule::exists('school_classes', 'id')->where('school_id', $school->id)],
            'held_on' => ['nullable', 'date'],
            'max_score' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'is_published' => ['boolean'],
        ]);

        return $this->ok(Exam::create(['school_id' => $school->id, ...$data]), [], 201);
    }
    /**
     * Results, scoped to what the caller is allowed to see.
     */
    public function results(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'academics');
        $user = $this->user($request);

        abort_unless($user->can('exams.view') || $user->hasAnyRole(['parent', 'student']), 403, 'You do not have access to this resource.');

        $studentId = $request->integer('student_id') ?: null;

        if ($user->hasRole('parent')) {
            $allowed = $user->children()->where('users.school_id', $school->id)->pluck('users.id');
            abort_if($studentId && ! $allowed->contains($studentId), 403);
            $studentId = $studentId ?: $allowed->first();
            abort_if(! $studentId, 404);
        }

        if ($user->hasRole('student')) {
            $studentId = $user->id;
        }

        $results = ExamResult::where('school_id', $school->id)
            ->when($studentId, fn ($q) => $q->where('student_id', $studentId))
            ->when($request->integer('exam_id'), fn ($q) => $q->where('exam_id', $request->integer('exam_id')))
            ->when($request->integer('subject_id'), fn ($q) => $q->where('subject_id', $request->integer('subject_id')))
            ->with(['exam:id,name,term_id,school_class_id,max_score,is_published', 'exam.term:id,name', 'subject:id,name', 'student:id,name,admission_number'])
            ->latest()->get()
            ->map(fn (ExamResult $result) => $this->resultPayload($result));

        return $this->ok($results);
    }

    /**
     * Record or update a score. Teachers may only enter results for their
     * own lessons' streams.
     */
    public function storeResult(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'academics');

        abort_unless($this->user($request)->can('results.enter'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'exam_id' => ['required', Rule::exists('exams', 'id')->where('school_id', $school->id)],
            'student_id' => ['required', Rule::exists('users', 'id')->where('school_id', $school->id)],
            'subject_id' => ['required', Rule::exists('subjects', 'id')],
            'score' => ['required', 'numeric', 'min:0', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $exam = Exam::findOrFail($data['exam_id']);

        abort_if($exam->max_score && $data['score'] > (float) $exam->max_score, 422, 'The score exceeds the maximum for this assessment.');

        $grade = app(GradeService::class)->fromScore((float) $data['score'], (int) ($exam->max_score ?: 100));

        $result = ExamResult::updateOrCreate(
            [
                'exam_id' => $data['exam_id'],
                'student_id' => $data['student_id'],
                'subject_id' => $data['subject_id'],
            ],
            [
                'school_id' => $school->id,
                'score' => $data['score'],
                'grade' => $grade['grade'],
                'remarks' => $data['remarks'] ?? $grade['remark'],
            ],
        );

        return $this->ok($this->resultPayload($result->fresh(['exam.term', 'subject', 'student'])), [], 201);
    }

    /**
     * Bulk entry used when a teacher syncs a captured score sheet.
     */
    public function bulkStoreResults(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'academics');

        abort_unless($this->user($request)->can('results.enter'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'results' => ['required', 'array', 'min:1', 'max:200'],
            'results.*.exam_id' => ['required', 'integer', Rule::exists('exams', 'id')->where('school_id', $school->id)],
            'results.*.student_id' => ['required', 'integer', Rule::exists('users', 'id')->where('school_id', $school->id)],
            'results.*.subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')],
            'results.*.score' => ['required', 'numeric', 'min:0', 'max:1000'],
            'results.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $gradeService = app(GradeService::class);
        $stored = [];

        DB::transaction(function () use ($school, $data, $gradeService, &$stored) {
            foreach ($data['results'] as $row) {
                $exam = Exam::findOrFail($row['exam_id']);
                $grade = $gradeService->fromScore((float) $row['score'], (int) ($exam->max_score ?: 100));

                $stored[] = ExamResult::updateOrCreate(
                    ['exam_id' => $row['exam_id'], 'student_id' => $row['student_id'], 'subject_id' => $row['subject_id']],
                    [
                        'school_id' => $school->id,
                        'score' => $row['score'],
                        'grade' => $grade['grade'],
                        'remarks' => $row['remarks'] ?? $grade['remark'],
                    ],
                );
            }
        });

        return $this->ok([
            'saved' => count($stored),
            'results' => $stored->map(fn ($result) => $this->resultPayload($result->fresh(['exam.term', 'subject', 'student'])))->all(),
        ], [], 201);
    }
    private function classPayload(SchoolClass $class): array
    {
        return [
            'id' => $class->id,
            'name' => $class->name,
            'curriculum' => $class->curriculum?->name,
            'level' => $class->level?->name,
            'academic_year' => $class->academicYear?->name,
            'active_learners_count' => $class->active_learners_count ?? null,
            'streams' => $class->relationLoaded('streams')
                ? $class->streams->map(fn ($stream) => [
                    'id' => $stream->id,
                    'name' => $stream->name,
                    'class_teacher' => $this->userPayload($stream->classTeacher),
                    'active_learners_count' => $stream->active_learners_count ?? null,
                    'lessons' => $stream->relationLoaded('lessons')
                        ? $stream->lessons->map(fn ($lesson) => $this->lessonPayload($lesson))->all()
                        : [],
                    'timetable' => $stream->relationLoaded('timetableSlots')
                        ? $stream->timetableSlots->map(fn ($slot) => $this->slotPayload($slot))->all()
                        : [],
                ])->all()
                : [],
        ];
    }

    private function lessonPayload($lesson): array
    {
        return [
            'id' => $lesson->id,
            'subject' => $lesson->subject?->name,
            'subject_id' => $lesson->subject_id,
            'teacher' => $this->userPayload($lesson->teacher),
            'stream_id' => $lesson->stream_id,
            'class' => $lesson->stream?->schoolClass?->name,
            'stream' => $lesson->stream?->name,
            'term' => $lesson->term?->name,
            'day_of_week' => $lesson->day_of_week,
            'start_time' => $lesson->start_time,
            'end_time' => $lesson->end_time,
            'room' => $lesson->room,
        ];
    }

    private function slotPayload(TimetableSlot $slot): array
    {
        return [
            'id' => $slot->id,
            'lesson_id' => $slot->lesson_id,
            'stream_id' => $slot->stream_id,
            'class' => $slot->stream?->schoolClass?->name,
            'stream' => $slot->stream?->name,
            'subject' => $slot->lesson?->subject?->name,
            'teacher' => $slot->lesson?->teacher?->name,
            'day_of_week' => $slot->day_of_week,
            'period' => $slot->period,
            'start_time' => $slot->start_time,
            'end_time' => $slot->end_time,
            'room' => $slot->room,
        ];
    }

    private function resultPayload(ExamResult $result): array
    {
        return [
            'id' => $result->id,
            'exam_id' => $result->exam_id,
            'exam' => $result->exam?->name,
            'term' => $result->exam?->term?->name,
            'class' => $result->exam?->schoolClass?->name,
            'student' => $this->userPayload($result->student),
            'subject' => $result->subject?->name,
            'subject_id' => $result->subject_id,
            'score' => (float) $result->score,
            'max_score' => $result->exam ? (float) ($result->exam->max_score ?: 100) : 100.0,
            'grade' => $result->grade,
            'remarks' => $result->remarks,
            'updated_at' => $this->timestamped($result->updated_at),
        ];
    }
}

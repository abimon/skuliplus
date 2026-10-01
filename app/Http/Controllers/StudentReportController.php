<?php

namespace App\Http\Controllers;

use App\Mail\StudentResultsReport;
use App\Models\ExamResult;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StudentReportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        abort_unless($schoolId, 403);

        if ($user->hasRole('parent')) {
            $students = $user->children()->where('users.school_id', $schoolId)
                ->whereHas('examResults.exam', fn ($exams) => $exams->where('is_published', true))
                ->withCount(['examResults as exam_results_count' => fn ($results) => $results->whereHas('exam', fn ($exams) => $exams->where('is_published', true))])
                ->orderBy('name')->get(['users.id', 'users.name', 'users.admission_number']);
        } elseif ($user->hasRole('student')) {
            $students = User::role('student')->whereKey($user->id)
                ->whereHas('examResults.exam', fn ($exams) => $exams->where('is_published', true))
                ->withCount(['examResults as exam_results_count' => fn ($results) => $results->whereHas('exam', fn ($exams) => $exams->where('is_published', true))])
                ->get(['id', 'name', 'admission_number']);
        } else {
            abort_unless($user->can('exams.view'), 403);
            $students = User::role('student')->where('school_id', $schoolId)
                ->whereHas('examResults.exam', fn ($exams) => $exams->where('is_published', true))
                ->withCount(['examResults as exam_results_count' => fn ($results) => $results->whereHas('exam', fn ($exams) => $exams->where('is_published', true))])
                ->orderBy('name')->get(['id', 'name', 'admission_number']);
        }

        return Inertia::render('academics/reports', [
            'school' => $user->school()->first(['name', 'code', 'county']),
            'students' => $students,
            'canEmail' => $user->can('results.upload'),
            'modules' => collect([
                ['key' => 'users', 'name' => 'People', 'permission' => 'users.view', 'href' => $user->can('users.update') ? '/users' : null],
                ['key' => 'academics', 'name' => 'Academics', 'permission' => 'classes.view', 'href' => '/academics'],
                ['key' => 'finance', 'name' => 'Finance', 'permission' => 'finance.view', 'href' => $user->can('finance.manage') ? '/finance' : null],
            ])->filter(fn (array $module) => $user->can($module['permission']))
                ->map(fn (array $module) => collect($module)->except('permission')->all())->values(),
        ]);
    }

    public function show(Request $request, User $student): Response
    {
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId && $student->school_id === $schoolId && $student->hasRole('student'), 404);
        $this->authorizeStudentReport($request, $student);

        return Inertia::render('academics/student-report', [
            ...$this->reportData($request, $student),
            'canEmail' => $request->user()->can('results.upload'),
        ]);
    }

    public function pdf(Request $request, User $student)
    {
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId && $student->school_id === $schoolId && $student->hasRole('student'), 404);
        $this->authorizeStudentReport($request, $student);
        $data = $this->reportData($request, $student);
        $filename = 'results-'.$student->admission_number.'-'.now()->format('Ymd').'.pdf';

        return Pdf::loadView('academics.student-report', $data)->setPaper('a4')->download($filename);
    }

    public function email(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'results.upload');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);
        $data = $request->validate([
            'student_ids' => 'required|array|min:1|max:100',
            'student_ids.*' => 'required|integer|distinct',
        ]);

        $students = User::role('student')->where('school_id', $schoolId)->whereIn('id', $data['student_ids'])->with(['guardians' => fn ($guardians) => $guardians->where('users.school_id', $schoolId)->whereNotNull('users.email')->orderByPivot('is_primary', 'desc')])->get();
        if ($students->count() !== count($data['student_ids'])) {
            throw ValidationException::withMessages(['student_ids' => 'One or more learners do not belong to this school.']);
        }

        $sent = 0;
        foreach ($students as $student) {
            $recipient = $student->guardians->first();
            if (! $recipient) {
                continue;
            }

            $report = $this->reportData($request, $student);
            $pdf = Pdf::loadView('academics.student-report', $report)->setPaper('a4')->output();
            $filename = 'results-'.$student->admission_number.'-'.now()->format('Ymd').'.pdf';
            Mail::to($recipient->email)->send(new StudentResultsReport($student->name, $report['school']['name'], $pdf, $filename));
            $sent++;
        }

        return back()->with('success', "Emailed {$sent} learner report(s) to their guardians.");
    }

    private function reportData(Request $request, User $student): array
    {
        $school = $request->user()->school;
        $results = ExamResult::where('school_id', $student->school_id)
            ->where('student_id', $student->id)
            ->with(['subject:id,name,code', 'exam:id,name,type,max_score,held_on,term_id', 'exam.term:id,name,academic_year_id', 'exam.term.academicYear:id,name'])
            ->whereHas('exam', fn ($exams) => $exams->where('is_published', true))
            ->get()
            ->sortBy(fn (ExamResult $result) => sprintf('%s-%08d-%08d', $result->exam->held_on?->format('Y-m-d') ?? '0000-00-00', $result->exam->term?->academic_year_id ?? 0, $result->exam_id))
            ->values();

        $groups = $results->groupBy(fn (ExamResult $result) => $result->exam->term?->academicYear?->name.' · '.$result->exam->term?->name.' · '.$result->exam->name);
        $trends = $groups->map(function ($group, $label) {
            $possible = $group->sum(fn (ExamResult $result) => (float) $result->exam->max_score);
            $score = $group->sum(fn (ExamResult $result) => (float) $result->score);

            return ['label' => $label, 'score' => $score, 'possible' => $possible, 'percent' => $possible > 0 ? round(($score / $possible) * 100, 1) : 0];
        })->values();
        $possible = $results->sum(fn (ExamResult $result) => (float) $result->exam->max_score);
        $score = $results->sum(fn (ExamResult $result) => (float) $result->score);

        return [
            'school' => $school->only(['name', 'code', 'county', 'logo_path', 'primary_color', 'secondary_color', 'motto', 'phone', 'email', 'po_box']),
            'student' => $student->only(['id', 'name', 'admission_number', 'gender']),
            'enrollment' => $student->currentEnrollment()->with(['schoolClass:id,name,curriculum_id,curriculum_level_id,academic_year_id', 'stream:id,name', 'academicYear:id,name'])->first(),
            'results' => $results,
            'trends' => $trends,
            'summary' => ['score' => $score, 'possible' => $possible, 'percent' => $possible > 0 ? round(($score / $possible) * 100, 1) : null, 'assessments' => $groups->count()],
            'generatedAt' => now()->format('d M Y'),
        ];
    }

    private function authorizeStudentReport(Request $request, User $student): void
    {
        $user = $request->user();
        if ($user->hasRole('parent')) {
            abort_unless($user->children()->where('users.school_id', $user->school_id)->whereKey($student->id)->exists(), 404);

            return;
        }
        if ($user->hasRole('student')) {
            abort_unless($user->id === $student->id, 404);

            return;
        }
        abort_unless($user->can('exams.view'), 403);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403);
    }
}

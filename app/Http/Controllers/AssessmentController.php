<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\GradeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssessmentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request, 'exams.manage');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        return Inertia::render('academics/assessments', [
            'school' => $request->user()->school()->first(['name', 'code']),
            'exams' => Exam::where('school_id', $schoolId)
                ->whereNotNull('school_class_id')
                ->with(['term:id,name,academic_year_id', 'schoolClass:id,name,academic_year_id,curriculum_id,curriculum_level_id'])
                ->withCount('results')
                ->with(['results' => fn ($results) => $results->with(['student:id,name,admission_number', 'subject:id,name,code'])->orderBy('student_id')->orderBy('subject_id')])
                ->latest('held_on')
                ->get(),
            'terms' => Term::where('school_id', $schoolId)->with('academicYear:id,name')->orderByDesc('starts_on')->get(['id', 'name', 'academic_year_id']),
            'classes' => SchoolClass::where('school_id', $schoolId)
                ->with(['curriculum:id,name,code', 'academicYear:id,name'])
                ->with(['enrollments' => fn ($enrollments) => $enrollments
                    ->where('school_id', $schoolId)
                    ->where('status', 'active')
                    ->with('student:id,name,admission_number')])
                ->orderBy('name')
                ->get(['id', 'name', 'curriculum_id', 'curriculum_level_id', 'academic_year_id']),
            'subjects' => Subject::whereIn('curriculum_id', $request->user()->school->curricula()->pluck('curricula.id'))
                ->orderBy('name')
                ->get(['id', 'curriculum_id', 'curriculum_level_id', 'name', 'code']),
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
            'can' => [
                'record' => $request->user()->can('results.enter'),
                'upload' => $request->user()->can('results.upload'),
            ],
        ]);
    }

    public function storeExam(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'exams.manage');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'school_class_id' => ['required', Rule::exists('school_classes', 'id')->where('school_id', $schoolId)],
            'type' => 'required|string|max:40',
            'max_score' => 'required|integer|min:1|max:1000',
            'held_on' => 'nullable|date',
        ]);

        $schoolClass = SchoolClass::where('school_id', $schoolId)->findOrFail($data['school_class_id']);
        $term = Term::where('school_id', $schoolId)->findOrFail($data['term_id']);
        if ($term->academic_year_id !== $schoolClass->academic_year_id) {
            throw ValidationException::withMessages(['term_id' => 'Choose a term from the class academic year.']);
        }

        Exam::create(['school_id' => $schoolId, ...$data]);

        return back()->with('success', 'Assessment created.');
    }

    public function publishExam(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorizePermission($request, 'exams.manage');
        abort_unless($request->user()->school_id && $exam->school_id === $request->user()->school_id, 404);
        $data = $request->validate(['is_published' => 'required|boolean']);
        $exam->update(['is_published' => $data['is_published']]);

        return back()->with('success', $data['is_published'] ? 'Assessment results published.' : 'Assessment results unpublished.');
    }

    public function storeResult(Request $request, Exam $exam, GradeService $grades): RedirectResponse
    {
        $this->authorizePermission($request, 'results.enter');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);
        abort_unless($exam->school_id === $schoolId, 404);

        $data = $request->validate([
            'student_id' => 'required|integer',
            'subject_id' => 'required|integer',
            'score' => 'required|numeric|min:0|max:'.$exam->max_score,
            'remark' => 'nullable|string|max:255',
        ]);

        $student = $this->enrolledStudent($schoolId, $exam, $data['student_id']);
        $subject = $this->validSubject($exam, $data['subject_id']);
        $grade = $grades->fromScore((float) $data['score'], (int) $exam->max_score);

        ExamResult::updateOrCreate(
            ['exam_id' => $exam->id, 'student_id' => $student->id, 'subject_id' => $subject->id],
            ['school_id' => $schoolId, 'score' => $data['score'], 'grade' => $grade['grade'], 'remark' => $data['remark'] ?? $grade['remark']]
        );

        return back()->with('success', 'Assessment result saved.');
    }

    public function template(Request $request, Exam $exam): StreamedResponse
    {
        $this->authorizePermission($request, 'results.upload');
        abort_unless($exam->school_id === $request->user()->school_id, 404);

        return response()->streamDownload(function () {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['admission_number', 'subject_code', 'score', 'remark']);
            fclose($output);
        }, 'exam-results-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request, Exam $exam, GradeService $grades): RedirectResponse
    {
        $this->authorizePermission($request, 'results.upload');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);
        abort_unless($exam->school_id === $schoolId, 404);

        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $headers = fgetcsv($handle);
        $requiredHeaders = ['admission_number', 'subject_code', 'score'];
        if (! $headers || array_diff($requiredHeaders, $headers)) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'CSV must include admission_number, subject_code, and score columns.']);
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
                throw ValidationException::withMessages(['file' => "CSV row {$rowNumber} does not match the header columns."]);
            }

            $row = array_combine($headers, $values);
            $validator = Validator::make($row, [
                'admission_number' => 'required|string|max:80',
                'subject_code' => 'required|string|max:40',
                'score' => 'required|numeric|min:0|max:'.$exam->max_score,
                'remark' => 'nullable|string|max:255',
            ]);
            if ($validator->fails()) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => "Invalid data on CSV row {$rowNumber}: ".implode(' ', $validator->errors()->all())]);
            }

            $student = User::role('student')->where('school_id', $schoolId)->where('admission_number', $row['admission_number'])->first();
            if (! $student) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => "No learner with admission number {$row['admission_number']} exists in this school (row {$rowNumber})."]);
            }
            $this->enrolledStudent($schoolId, $exam, $student->id, $rowNumber);
            $subject = Subject::where('code', $row['subject_code'])->where('curriculum_id', $exam->schoolClass?->curriculum_id)->first();
            if (! $subject || ($subject->curriculum_level_id && $subject->curriculum_level_id !== $exam->schoolClass?->curriculum_level_id)) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => "Subject {$row['subject_code']} is not available for this class (row {$rowNumber})."]);
            }

            $rows[] = ['student_id' => $student->id, 'subject_id' => $subject->id, 'score' => (float) $row['score'], 'remark' => $row['remark'] ?? null];
            if (count($rows) > 1000) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => 'Import is limited to 1,000 results per file.']);
            }
        }
        fclose($handle);

        DB::transaction(function () use ($rows, $grades, $exam, $schoolId) {
            foreach ($rows as $row) {
                $grade = $grades->fromScore($row['score'], (int) $exam->max_score);
                ExamResult::updateOrCreate(
                    ['exam_id' => $exam->id, 'student_id' => $row['student_id'], 'subject_id' => $row['subject_id']],
                    ['school_id' => $schoolId, 'score' => $row['score'], 'grade' => $grade['grade'], 'remark' => $row['remark'] ?? $grade['remark']]
                );
            }
        });

        return back()->with('success', count($rows).' assessment results imported.');
    }

    private function enrolledStudent(int $schoolId, Exam $exam, int $studentId, ?int $rowNumber = null): User
    {
        $student = User::role('student')->where('school_id', $schoolId)->find($studentId);
        $schoolClass = $exam->schoolClass;
        $enrolled = $student && $schoolClass && Enrollment::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->where('school_class_id', $schoolClass->id)
            ->where('academic_year_id', $schoolClass->academic_year_id)
            ->where('status', 'active')
            ->exists();

        if (! $enrolled) {
            $message = 'Learner must have an active placement in the assessment class'.($rowNumber ? " (CSV row {$rowNumber})." : '.');
            throw ValidationException::withMessages([$rowNumber ? 'file' : 'student_id' => $message]);
        }

        return $student;
    }

    private function validSubject(Exam $exam, int $subjectId): Subject
    {
        $schoolClass = $exam->schoolClass;
        $subject = Subject::where('curriculum_id', $schoolClass?->curriculum_id)->find($subjectId);
        if (! $subject || ($subject->curriculum_level_id && $subject->curriculum_level_id !== $schoolClass?->curriculum_level_id)) {
            throw ValidationException::withMessages(['subject_id' => 'Choose a learning area assigned to this class curriculum.']);
        }

        return $subject;
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403);
    }
}

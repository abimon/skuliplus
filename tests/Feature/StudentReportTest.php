<?php

use App\Mail\StudentResultsReport;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\CurriculumLevel;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function makeReportFixture(): array
{
    $school = School::create(['code' => 'UHS001', 'name' => 'Umoja Heights', 'motto' => 'Elimu ni Nguvu']);
    $curriculum = Curriculum::create(['code' => 'UHS-CBC', 'name' => 'CBC']);
    $level = CurriculumLevel::create(['curriculum_id' => $curriculum->id, 'code' => 'G7', 'name' => 'Grade 7', 'stage' => 'Junior School', 'order' => 1]);
    $school->curricula()->attach($curriculum->id, ['is_primary' => true]);
    $year = AcademicYear::create(['school_id' => $school->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
    $term = Term::create(['school_id' => $school->id, 'academic_year_id' => $year->id, 'name' => 'Term 1', 'number' => 1, 'starts_on' => '2026-01-01', 'ends_on' => '2026-04-30', 'is_current' => true]);
    $schoolClass = SchoolClass::create(['school_id' => $school->id, 'curriculum_id' => $curriculum->id, 'curriculum_level_id' => $level->id, 'academic_year_id' => $year->id, 'name' => 'Grade 7']);
    $student = User::factory()->create(['school_id' => $school->id, 'name' => 'Brian Kamau', 'admission_number' => 'UHS-001', 'email' => null, 'can_login' => false]);
    $student->assignRole(Role::findByName('student', 'web'));
    $parent = User::factory()->create(['school_id' => $school->id, 'email' => 'parent@umoja.ke']);
    $parent->assignRole(Role::findByName('parent', 'web'));
    $parent->children()->attach($student->id, ['relationship' => 'parent', 'is_primary' => true]);
    Enrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'school_class_id' => $schoolClass->id, 'academic_year_id' => $year->id, 'status' => 'active']);
    $subject = Subject::create(['curriculum_id' => $curriculum->id, 'curriculum_level_id' => $level->id, 'code' => 'ENG', 'name' => 'English']);
    $exam = Exam::create(['school_id' => $school->id, 'term_id' => $term->id, 'school_class_id' => $schoolClass->id, 'name' => 'Term 1 Assessment', 'type' => 'end_term', 'max_score' => 100, 'is_published' => true, 'held_on' => '2026-04-01']);
    $result = ExamResult::create(['school_id' => $school->id, 'exam_id' => $exam->id, 'student_id' => $student->id, 'subject_id' => $subject->id, 'score' => 82, 'grade' => 'EE', 'remark' => 'Excellent']);
    $hod = User::factory()->create(['school_id' => $school->id]);
    $hod->assignRole(Role::findByName('hod_academics', 'web'));

    return compact('school', 'student', 'parent', 'hod', 'term', 'exam', 'result');
}

test('parent can view and download only their linked learner history', function () {
    $fixture = makeReportFixture();
    $previousYear = AcademicYear::create(['school_id' => $fixture['school']->id, 'name' => '2025', 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);
    $previousTerm = Term::create(['school_id' => $fixture['school']->id, 'academic_year_id' => $previousYear->id, 'name' => 'Term 3', 'number' => 3, 'starts_on' => '2025-09-01', 'ends_on' => '2025-11-30']);
    $previousExam = Exam::create(['school_id' => $fixture['school']->id, 'term_id' => $previousTerm->id, 'school_class_id' => $fixture['result']->exam->school_class_id, 'name' => 'Term 3 Assessment', 'type' => 'end_term', 'max_score' => 100, 'is_published' => true, 'held_on' => '2025-11-01']);
    ExamResult::create(['school_id' => $fixture['school']->id, 'exam_id' => $previousExam->id, 'student_id' => $fixture['student']->id, 'subject_id' => $fixture['result']->subject_id, 'score' => 62, 'grade' => 'ME', 'remark' => 'Meeting expectation']);
    $draftExam = Exam::create(['school_id' => $fixture['school']->id, 'term_id' => $fixture['term']->id, 'school_class_id' => $fixture['result']->exam->school_class_id, 'name' => 'Unpublished draft', 'type' => 'cat', 'max_score' => 100, 'is_published' => false, 'held_on' => '2026-04-02']);
    ExamResult::create(['school_id' => $fixture['school']->id, 'exam_id' => $draftExam->id, 'student_id' => $fixture['student']->id, 'subject_id' => $fixture['result']->subject_id, 'score' => 99, 'grade' => 'EE', 'remark' => 'Draft only']);
    $other = User::factory()->create(['school_id' => $fixture['school']->id, 'name' => 'Other Learner', 'admission_number' => 'UHS-002']);
    $other->assignRole(Role::findByName('student', 'web'));

    $this->actingAs($fixture['parent'])
        ->get(route('students.results', $fixture['student']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academics/student-report')
            ->where('summary.percent', 72)
            ->has('trends', 2)
            ->has('results', 2));

    $this->get(route('students.results', $other))->assertNotFound();
    $this->get(route('academics.reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('academics/reports')->has('students', 1)->where('students.0.exam_results_count', 2));
    $this->get(route('students.results.pdf', $fixture['student']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('a learner can view their own published report but not another learner report', function () {
    $fixture = makeReportFixture();
    $this->actingAs($fixture['student'])
        ->get(route('students.results', $fixture['student']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('academics/student-report'));

    $other = User::factory()->create(['school_id' => $fixture['school']->id]);
    $other->assignRole(Role::findByName('student', 'web'));
    $this->get(route('students.results', $other))->assertNotFound();
    $this->get(route('academics.reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('academics/reports')->has('students', 1)->where('students.0.id', $fixture['student']->id));
});

test('authorized academics staff can email a historical PDF to each selected guardian', function () {
    Mail::fake();
    $fixture = makeReportFixture();

    $this->actingAs($fixture['hod'])
        ->post(route('academics.results.email'), ['student_ids' => [$fixture['student']->id]])
        ->assertSessionHasNoErrors();

    Mail::assertSent(StudentResultsReport::class, fn (StudentResultsReport $mail) => $mail->hasTo($fixture['parent']->email));
});

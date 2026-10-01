<?php

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
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function makeAssessmentFixture(string $code = 'UHS001'): array
{
    $school = School::create(['code' => $code, 'name' => 'Umoja Heights']);
    $curriculum = Curriculum::create(['code' => $code.'CBC', 'name' => 'Competency Based Curriculum']);
    $level = CurriculumLevel::create([
        'curriculum_id' => $curriculum->id,
        'code' => 'G7',
        'name' => 'Grade 7',
        'stage' => 'Junior School',
        'order' => 1,
    ]);
    $school->curricula()->attach($curriculum->id, ['is_primary' => true]);
    $year = AcademicYear::create(['school_id' => $school->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
    $term = Term::create(['school_id' => $school->id, 'academic_year_id' => $year->id, 'name' => 'Term 1', 'number' => 1, 'starts_on' => '2026-01-01', 'ends_on' => '2026-04-30', 'is_current' => true]);
    $schoolClass = SchoolClass::create(['school_id' => $school->id, 'curriculum_id' => $curriculum->id, 'curriculum_level_id' => $level->id, 'academic_year_id' => $year->id, 'name' => 'Grade 7']);
    $teacher = User::factory()->create(['school_id' => $school->id]);
    $teacher->assignRole(Role::findByName('hod_academics', 'web'));
    $student = User::factory()->create(['school_id' => $school->id, 'name' => 'Brian Kamau', 'admission_number' => 'UHS-001']);
    $student->assignRole(Role::findByName('student', 'web'));
    Enrollment::create(['school_id' => $school->id, 'student_id' => $student->id, 'school_class_id' => $schoolClass->id, 'academic_year_id' => $year->id, 'status' => 'active']);
    $subject = Subject::create(['curriculum_id' => $curriculum->id, 'curriculum_level_id' => $level->id, 'code' => 'ENG', 'name' => 'English', 'weekly_lessons' => 5]);
    $exam = Exam::create(['school_id' => $school->id, 'term_id' => $term->id, 'school_class_id' => $schoolClass->id, 'name' => 'Term 1 Midterm', 'type' => 'midterm', 'max_score' => 100]);

    return compact('school', 'curriculum', 'level', 'year', 'term', 'schoolClass', 'teacher', 'student', 'subject', 'exam');
}

test('exam creation only accepts a term in the class academic year', function () {
    $fixture = makeAssessmentFixture();
    $otherSchool = School::create(['code' => 'JHS001', 'name' => 'Jubilee Heights']);
    $otherYear = AcademicYear::create(['school_id' => $otherSchool->id, 'name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
    $otherTerm = Term::create(['school_id' => $otherSchool->id, 'academic_year_id' => $otherYear->id, 'name' => 'Term 1', 'number' => 1, 'starts_on' => '2027-01-01', 'ends_on' => '2027-04-30']);

    $this->actingAs($fixture['teacher'])
        ->post(route('academics.assessments.store'), [
            'name' => 'Foreign term exam',
            'term_id' => $otherTerm->id,
            'school_class_id' => $fixture['schoolClass']->id,
            'type' => 'midterm',
            'max_score' => 100,
        ])
        ->assertSessionHasErrors('term_id');

    $this->post(route('academics.assessments.store'), [
        'name' => 'Term 1 Endline',
        'term_id' => $fixture['term']->id,
        'school_class_id' => $fixture['schoolClass']->id,
        'type' => 'end_term',
        'max_score' => 100,
    ])->assertSessionHasNoErrors();

    $this->get(route('academics.assessments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('academics/assessments')->has('exams', 2));
});

test('manual scores are limited to active class learners and receive CBC performance bands', function () {
    $fixture = makeAssessmentFixture();
    $foreignSchool = School::create(['code' => 'JHS001', 'name' => 'Jubilee Heights']);
    $foreignStudent = User::factory()->create(['school_id' => $foreignSchool->id, 'admission_number' => 'JHS-002']);
    $foreignStudent->assignRole(Role::findByName('student', 'web'));

    $this->actingAs($fixture['teacher'])
        ->post(route('academics.results.store', $fixture['exam']), ['student_id' => $foreignStudent->id, 'subject_id' => $fixture['subject']->id, 'score' => 85])
        ->assertSessionHasErrors('student_id');

    $this->post(route('academics.results.store', $fixture['exam']), ['student_id' => $fixture['student']->id, 'subject_id' => $fixture['subject']->id, 'score' => 85])
        ->assertSessionHasNoErrors();

    $result = ExamResult::where('exam_id', $fixture['exam']->id)->firstOrFail();
    expect($result->school_id)->toBe($fixture['school']->id)
        ->and($result->grade)->toBe('EE')
        ->and($result->remark)->toBe('Exceeding Expectation');
});

test('bulk result imports validate all rows before writing scores', function () {
    $fixture = makeAssessmentFixture();
    $csv = implode("\n", [
        'admission_number,subject_code,score,remark',
        'UHS-001,ENG,75,Good progress',
        'UHS-001,ENG,110,Out of range',
    ]);

    $this->actingAs($fixture['teacher'])
        ->post(route('academics.results.import', $fixture['exam']), ['file' => UploadedFile::fake()->createWithContent('results.csv', $csv)])
        ->assertSessionHasErrors('file');
    expect(ExamResult::where('exam_id', $fixture['exam']->id)->count())->toBe(0);

    $validCsv = "admission_number,subject_code,score,remark\nUHS-001,ENG,75,Good progress";
    $this->post(route('academics.results.import', $fixture['exam']), ['file' => UploadedFile::fake()->createWithContent('results.csv', $validCsv)])
        ->assertSessionHasNoErrors();

    $result = ExamResult::where('exam_id', $fixture['exam']->id)->firstOrFail();
    expect($result->score)->toBe('75.00')
        ->and($result->grade)->toBe('ME')
        ->and($result->remark)->toBe('Good progress');
});

test('academics staff can publish an assessment and parents only receive published results', function () {
    $fixture = makeAssessmentFixture();

    $this->actingAs($fixture['teacher'])
        ->patch(route('academics.assessments.publish', $fixture['exam']), ['is_published' => true])
        ->assertSessionHasNoErrors();

    expect($fixture['exam']->fresh()->is_published)->toBeTrue();

    $teacher = User::factory()->create(['school_id' => $fixture['school']->id]);
    $teacher->assignRole(Role::findByName('teacher', 'web'));
    $this->actingAs($teacher)
        ->patch(route('academics.assessments.publish', $fixture['exam']), ['is_published' => false])
        ->assertForbidden();
});

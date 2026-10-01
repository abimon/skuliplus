<?php

use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\CurriculumLevel;
use App\Models\Enrollment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Stream;
use App\Models\Subject;
use App\Models\Term;
use App\Models\TimetableSlot;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function academicSchool(string $code, string $name): array
{
    $school = School::create(['code' => $code, 'name' => $name]);
    $curriculum = Curriculum::create(['code' => $code.'CBC', 'name' => 'Competency Based Curriculum']);
    $level = CurriculumLevel::create([
        'curriculum_id' => $curriculum->id,
        'code' => 'G7',
        'name' => 'Grade 7',
        'stage' => 'Junior School',
        'order' => 1,
    ]);
    $school->curricula()->attach($curriculum->id, ['is_primary' => true]);
    $year = AcademicYear::create([
        'school_id' => $school->id,
        'name' => '2026',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-12-31',
        'is_current' => true,
    ]);
    $term = Term::create([
        'school_id' => $school->id,
        'academic_year_id' => $year->id,
        'name' => 'Term 1',
        'number' => 1,
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-04-30',
        'is_current' => true,
    ]);

    return [$school, $curriculum, $level, $year, $term];
}

test('class creation requires an assigned curriculum level and local academic year', function () {
    [$school, $curriculum, $level, $year] = academicSchool('UHS001', 'Umoja Heights');
    [, $foreignCurriculum, $foreignLevel, $foreignYear] = academicSchool('JHS001', 'Jubilee Heights');
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole(Role::findByName('school_admin', 'web'));

    $this->actingAs($admin)
        ->post(route('academics.classes.store'), [
            'name' => 'Grade 7',
            'curriculum_id' => $foreignCurriculum->id,
            'curriculum_level_id' => $foreignLevel->id,
            'academic_year_id' => $year->id,
        ])
        ->assertSessionHasErrors('curriculum_id');

    $this->post(route('academics.classes.store'), [
        'name' => 'Grade 7',
        'curriculum_id' => $curriculum->id,
        'curriculum_level_id' => $level->id,
        'academic_year_id' => $foreignYear->id,
    ])->assertSessionHasErrors('academic_year_id');

    $this->post(route('academics.classes.store'), [
        'name' => 'Grade 7',
        'curriculum_id' => $curriculum->id,
        'curriculum_level_id' => $level->id,
        'academic_year_id' => $year->id,
    ])->assertSessionHasNoErrors();

    expect(SchoolClass::where('school_id', $school->id)->where('name', 'Grade 7')->exists())->toBeTrue();

    $this->get(route('academics.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('academics/index')
            ->where('classes.0.name', 'Grade 7')
            ->has('curricula', 1));
});

test('stream and learner enrollment relations cannot cross classes or schools', function () {
    [$school, $curriculum, $level, $year] = academicSchool('UHS001', 'Umoja Heights');
    [$otherSchool, $otherCurriculum, $otherLevel, $otherYear] = academicSchool('JHS001', 'Jubilee Heights');
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole(Role::findByName('school_admin', 'web'));
    $class = SchoolClass::create([
        'school_id' => $school->id,
        'curriculum_id' => $curriculum->id,
        'curriculum_level_id' => $level->id,
        'academic_year_id' => $year->id,
        'name' => 'Grade 7',
    ]);
    $otherClass = SchoolClass::create([
        'school_id' => $otherSchool->id,
        'curriculum_id' => $otherCurriculum->id,
        'curriculum_level_id' => $otherLevel->id,
        'academic_year_id' => $otherYear->id,
        'name' => 'Grade 7',
    ]);
    $teacher = User::factory()->create(['school_id' => $school->id]);
    $teacher->assignRole(Role::findByName('teacher', 'web'));
    $foreignStudent = User::factory()->create(['school_id' => $otherSchool->id, 'admission_number' => 'JHS-001']);
    $foreignStudent->assignRole(Role::findByName('student', 'web'));

    $this->actingAs($admin)
        ->post(route('academics.streams.store'), [
            'school_class_id' => $otherClass->id,
            'name' => 'East',
            'class_teacher_id' => $teacher->id,
            'capacity' => 40,
        ])
        ->assertSessionHasErrors('school_class_id');

    $stream = Stream::create(['school_id' => $school->id, 'school_class_id' => $class->id, 'name' => 'East', 'capacity' => 40]);

    $this->post(route('academics.enrollments.store'), [
        'student_id' => $foreignStudent->id,
        'school_class_id' => $class->id,
        'stream_id' => $stream->id,
    ])->assertSessionHasErrors('student_id');

    expect(Enrollment::where('student_id', $foreignStudent->id)->exists())->toBeFalse();
});

test('learners have one active class per academic year and timetable replacement is explicit', function () {
    [$school, $curriculum, $level, $year, $term] = academicSchool('UHS001', 'Umoja Heights');
    $hod = User::factory()->create(['school_id' => $school->id]);
    $hod->assignRole(Role::findByName('hod_academics', 'web'));
    $student = User::factory()->create(['school_id' => $school->id, 'admission_number' => 'UHS-001']);
    $student->assignRole(Role::findByName('student', 'web'));
    $class = SchoolClass::create([
        'school_id' => $school->id,
        'curriculum_id' => $curriculum->id,
        'curriculum_level_id' => $level->id,
        'academic_year_id' => $year->id,
        'name' => 'Grade 7',
    ]);
    $stream = Stream::create(['school_id' => $school->id, 'school_class_id' => $class->id, 'name' => 'East', 'capacity' => 40]);
    $subject = Subject::create(['curriculum_id' => $curriculum->id, 'code' => 'ENG', 'name' => 'English', 'weekly_lessons' => 5]);

    $this->actingAs($hod)
        ->post(route('academics.enrollments.store'), [
            'student_id' => $student->id,
            'school_class_id' => $class->id,
            'stream_id' => $stream->id,
        ])
        ->assertSessionHasNoErrors();

    $this->post(route('academics.enrollments.store'), [
        'student_id' => $student->id,
        'school_class_id' => $class->id,
        'stream_id' => $stream->id,
    ])->assertSessionHasErrors('student_id');

    $slot = TimetableSlot::create([
        'school_id' => $school->id,
        'stream_id' => $stream->id,
        'term_id' => $term->id,
        'day_of_week' => 1,
        'period' => 1,
    ]);

    $this->post(route('academics.timetable.generate', $stream), ['term_id' => $term->id])
        ->assertSessionHasErrors('term_id');
    expect(TimetableSlot::whereKey($slot->id)->exists())->toBeTrue();

    $this->post(route('academics.timetable.generate', $stream), ['term_id' => $term->id, 'replace' => true])
        ->assertSessionHasNoErrors();

    expect(TimetableSlot::where('stream_id', $stream->id)->where('term_id', $term->id)->count())->toBe(40);
});

test('academic year and term setup keeps exactly one current period', function () {
    [$school, , , $year, $term] = academicSchool('UHS001', 'Umoja Heights');
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole(Role::findByName('school_admin', 'web'));

    $this->actingAs($admin)
        ->post(route('academics.years.store'), [
            'name' => '2027',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
            'is_current' => true,
        ])
        ->assertSessionHasNoErrors();

    $newYear = AcademicYear::where('school_id', $school->id)->where('name', '2027')->firstOrFail();
    expect($newYear->is_current)->toBeTrue()
        ->and($year->fresh()->is_current)->toBeFalse()
        ->and($term->fresh()->is_current)->toBeFalse();

    $this->post(route('academics.terms.store'), [
        'academic_year_id' => $newYear->id,
        'name' => 'Term 1',
        'number' => 1,
        'starts_on' => '2027-01-04',
        'ends_on' => '2027-04-10',
        'is_current' => true,
    ])->assertSessionHasNoErrors();

    expect(Term::where('school_id', $school->id)->where('is_current', true)->count())->toBe(1)
        ->and($newYear->fresh()->is_current)->toBeTrue();
});

test('parents cannot open or mutate the school academic workspace', function () {
    [$school] = academicSchool('UHS001', 'Umoja Heights');
    $parent = User::factory()->create(['school_id' => $school->id]);
    $parent->assignRole(Role::findByName('parent', 'web'));

    $this->actingAs($parent)
        ->get(route('academics.index'))
        ->assertForbidden();

    $this->post(route('academics.years.store'), [
        'name' => '2027',
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-12-31',
        'is_current' => true,
    ])->assertForbidden();

    expect(AcademicYear::where('school_id', $school->id)->count())->toBe(1);
});

test('timetable generation preserves the previous schedule when a curriculum has no subjects', function () {
    [$school, $curriculum, $level, $year, $term] = academicSchool('UHS001', 'Umoja Heights');
    $hod = User::factory()->create(['school_id' => $school->id]);
    $hod->assignRole(Role::findByName('hod_academics', 'web'));
    $class = SchoolClass::create(['school_id' => $school->id, 'curriculum_id' => $curriculum->id, 'curriculum_level_id' => $level->id, 'academic_year_id' => $year->id, 'name' => 'Grade 7']);
    $stream = Stream::create(['school_id' => $school->id, 'school_class_id' => $class->id, 'name' => 'East', 'capacity' => 40]);
    $slot = TimetableSlot::create(['school_id' => $school->id, 'stream_id' => $stream->id, 'term_id' => $term->id, 'day_of_week' => 1, 'period' => 1]);

    $this->actingAs($hod)
        ->post(route('academics.timetable.generate', $stream), ['term_id' => $term->id, 'replace' => true])
        ->assertSessionHasErrors('term_id');

    expect(TimetableSlot::whereKey($slot->id)->exists())->toBeTrue();
});

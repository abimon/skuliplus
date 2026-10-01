<?php

use App\Models\School;
use App\Models\StudentTransfer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('school users are tenant scoped and student registration requires a local guardian', function () {
    $school = School::create(['code' => 'UHS001', 'name' => 'Umoja Heights']);
    $otherSchool = School::create(['code' => 'JHS001', 'name' => 'Jubilee Heights']);
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole(Role::findByName('school_admin', 'web'));

    $localGuardian = User::factory()->create(['school_id' => $school->id]);
    $localGuardian->assignRole(Role::findByName('parent', 'web'));
    $localGuardian->parentProfile()->create(['school_id' => $school->id]);

    $foreignGuardian = User::factory()->create(['school_id' => $otherSchool->id]);
    $foreignGuardian->assignRole(Role::findByName('parent', 'web'));

    $this->actingAs($admin)
        ->post(route('users.store'), [
            'first_name' => 'Brian',
            'last_name' => 'Kamau',
            'role' => 'student',
            'admission_number' => 'UHS-2026-019',
            'guardian_id' => $foreignGuardian->id,
            'previous_school' => 'Jubilee Primary',
        ])
        ->assertSessionHasErrors('guardian_id');

    expect(User::where('admission_number', 'UHS-2026-019')->exists())->toBeFalse();

    $this->post(route('users.store'), [
        'first_name' => 'Brian',
        'last_name' => 'Kamau',
        'role' => 'student',
        'admission_number' => 'UHS-2026-019',
        'guardian_id' => $localGuardian->id,
        'boarding_status' => 'day',
        'previous_school' => 'Jubilee Primary',
    ])->assertSessionHasNoErrors();

    $student = User::where('admission_number', 'UHS-2026-019')->firstOrFail();
    expect($student->school_id)->toBe($school->id)
        ->and($student->can_login)->toBeFalse()
        ->and($student->email)->toBeNull()
        ->and($student->guardians()->whereKey($localGuardian->id)->exists())->toBeTrue()
        ->and($student->studentProfile->previous_school)->toBe('Jubilee Primary')
        ->and(StudentTransfer::where('student_id', $student->id)->where('direction', 'in')->value('other_school'))->toBe('Jubilee Primary');
});

test('school user listings and id cards cannot expose another tenant user', function () {
    $school = School::create(['code' => 'UHS001', 'name' => 'Umoja Heights']);
    $otherSchool = School::create(['code' => 'JHS001', 'name' => 'Jubilee Heights']);
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole(Role::findByName('school_admin', 'web'));
    $foreignStudent = User::factory()->create([
        'school_id' => $otherSchool->id,
        'name' => 'Private Learner',
        'admission_number' => 'JHS-001',
    ]);
    $foreignStudent->assignRole(Role::findByName('student', 'web'));

    $this->actingAs($admin)
        ->get(route('users.index'))
        ->assertOk()
        ->assertDontSee('Private Learner');

    $this->get(route('users.id-card', $foreignStudent))->assertNotFound();
});

test('a parent cannot open the school-wide user directory', function () {
    $school = School::create(['code' => 'UHS001', 'name' => 'Umoja Heights']);
    $parent = User::factory()->create(['school_id' => $school->id]);
    $parent->assignRole(Role::findByName('parent', 'web'));

    $this->actingAs($parent)
        ->get(route('users.index'))
        ->assertForbidden();
});

test('school managers can import login-enabled people from the csv template', function () {
    $school = School::create(['code' => 'UHS001', 'name' => 'Umoja Heights']);
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole(Role::findByName('school_admin', 'web'));
    $csv = implode("\n", [
        'first_name,last_name,role,email,phone,gender,date_of_birth,admission_number,employee_number,guardian_email,relationship,boarding_status,previous_school,rank,staff_category,tsc_number,password',
        'Grace,Njeri,parent,grace@example.ke,0712345678,female,1988-02-10,,,,mother,,,,,,parent-pass-123',
    ]);

    $this->actingAs($admin)
        ->get(route('users.template'))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $this->post(route('users.import'), ['file' => UploadedFile::fake()->createWithContent('users.csv', $csv)])
        ->assertSessionHasNoErrors();

    $parent = User::where('email', 'grace@example.ke')->firstOrFail();
    expect($parent->school_id)->toBe($school->id)
        ->and($parent->can_login)->toBeTrue()
        ->and($parent->hasRole('parent'))->toBeTrue()
        ->and($parent->parentProfile->relationship)->toBe('mother');
});

test('school managers can render a school-branded qr identity card', function () {
    $school = School::create(['code' => 'UHS001', 'name' => 'Umoja Heights', 'motto' => 'Elimu ni Nguvu']);
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole(Role::findByName('school_admin', 'web'));
    $student = User::factory()->create([
        'school_id' => $school->id,
        'name' => 'Brian Kamau',
        'admission_number' => 'UHS-2026-019',
        'can_login' => false,
    ]);
    $student->assignRole(Role::findByName('student', 'web'));

    $this->actingAs($admin)
        ->get(route('users.id-card', $student))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/id-card')
            ->where('identifier', 'UHS-2026-019')
            ->where('role', 'student')
            ->where('school.name', 'Umoja Heights')
            ->where('qrCode', fn (string $code) => str_starts_with($code, 'data:image/svg+xml;base64,')));
});

test('school managers can edit profiles without replacing the login password', function () {
    $school = School::create(['code' => 'UHS001', 'name' => 'Umoja Heights']);
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole(Role::findByName('school_admin', 'web'));
    $teacher = User::factory()->create([
        'school_id' => $school->id,
        'first_name' => 'Jane',
        'last_name' => 'Njeri',
        'name' => 'Jane Njeri',
        'email' => 'jane@example.ke',
    ]);
    $teacher->assignRole(Role::findByName('teacher', 'web'));
    $teacher->teacherProfile()->create(['school_id' => $school->id, 'rank' => 'teacher']);
    $passwordHash = $teacher->password;

    $this->actingAs($admin)
        ->put(route('users.update', $teacher), [
            'first_name' => 'Jane',
            'last_name' => 'Wanjiku',
            'role' => 'teacher',
            'email' => 'jane@example.ke',
            'phone' => '0712345678',
            'rank' => 'senior_teacher',
        ])
        ->assertSessionHasNoErrors();

    expect($teacher->fresh()->name)->toBe('Jane Wanjiku')
        ->and($teacher->fresh()->password)->toBe($passwordHash)
        ->and($teacher->fresh()->teacherProfile->rank)->toBe('senior_teacher');
});

test('converting a non-login learner into staff requires a password', function () {
    $school = School::create(['code' => 'UHS001', 'name' => 'Umoja Heights']);
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole(Role::findByName('school_admin', 'web'));
    $student = User::factory()->create([
        'school_id' => $school->id,
        'name' => 'Brian Kamau',
        'email' => null,
        'admission_number' => 'UHS-2026-019',
        'can_login' => false,
        'password' => null,
    ]);
    $student->assignRole(Role::findByName('student', 'web'));

    $payload = [
        'first_name' => 'Brian',
        'last_name' => 'Kamau',
        'role' => 'teacher',
        'email' => 'brian.kamau@example.ke',
    ];

    $this->actingAs($admin)
        ->put(route('users.update', $student), $payload)
        ->assertSessionHasErrors('password');

    $this->put(route('users.update', $student), $payload + ['password' => 'school-password-123'])
        ->assertSessionHasNoErrors();

    expect($student->fresh()->can_login)->toBeTrue()
        ->and($student->fresh()->hasRole('teacher'))->toBeTrue()
        ->and($student->fresh()->must_change_password)->toBeTrue();
});

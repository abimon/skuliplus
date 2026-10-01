<?php

use App\Models\Curriculum;
use App\Models\CurriculumLevel;
use App\Models\School;
use App\Models\SchoolModule;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('a super admin can register a school with multiple curricula and an admin account', function () {
    $superAdminRole = Role::findOrCreate('super_admin', 'web');
    $schoolAdminRole = Role::findOrCreate('school_admin', 'web');
    $permissions = collect(['schools.create', 'schools.view', 'curricula.manage'])
        ->map(fn (string $name) => Permission::findOrCreate($name, 'web'));
    $superAdminRole->givePermissionTo($permissions);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole($superAdminRole);

    $cbc = Curriculum::create(['code' => 'CBC', 'name' => 'Competency Based Curriculum']);
    $legacy = Curriculum::create(['code' => '844', 'name' => '8-4-4 System']);

    $this->actingAs($superAdmin)
        ->post(route('admin.schools.store'), [
            'name' => 'Umoja Heights School',
            'code' => 'UHS001',
            'type' => 'mixed',
            'level' => 'comprehensive',
            'county' => 'Nairobi',
            'enabled_modules' => ['users', 'academics', 'library'],
            'curriculum_ids' => [$cbc->id, $legacy->id],
            'admin_name' => 'Amina Otieno',
            'admin_email' => 'admin@umojaheights.ke',
            'admin_password' => 'school-secret-123',
        ])
        ->assertRedirect();

    $school = School::where('code', 'UHS001')->firstOrFail();
    $admin = User::where('email', 'admin@umojaheights.ke')->firstOrFail();

    expect($school->curricula()->count())->toBe(2)
        ->and($school->enabled_modules)->toBe(['users', 'academics', 'library'])
        ->and($school->curricula()->wherePivot('is_primary', true)->value('curricula.id'))->toBe($cbc->id)
        ->and($admin->school_id)->toBe($school->id)
        ->and($admin->hasRole($schoolAdminRole))->toBeTrue();

    $this->get(route('admin.schools.index'))->assertOk();
    $this->get(route('admin.curricula.index'))->assertOk();
});

test('school registration requires the People module and rejects centrally inactive modules', function () {
    $this->seed(RolePermissionSeeder::class);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(Role::findByName('super_admin', 'web'));
    $curriculum = Curriculum::create(['code' => 'CBC', 'name' => 'Competency Based Curriculum']);
    SchoolModule::where('key', 'labs')->update(['is_active' => false]);
    $payload = [
        'name' => 'Module Test School',
        'code' => 'MTS001',
        'type' => 'mixed',
        'level' => 'comprehensive',
        'enabled_modules' => ['academics', 'labs'],
        'curriculum_ids' => [$curriculum->id],
        'admin_name' => 'Test Admin',
        'admin_email' => 'module-admin@example.test',
        'admin_password' => 'school-secret-123',
    ];

    $this->actingAs($superAdmin)
        ->post(route('admin.schools.store'), $payload)
        ->assertSessionHasErrors(['enabled_modules.1']);

    $payload['enabled_modules'] = ['academics'];
    $payload['admin_email'] = 'module-admin-2@example.test';
    $this->post(route('admin.schools.store'), $payload)
        ->assertSessionHasErrors('enabled_modules');

    expect(School::where('code', 'MTS001')->exists())->toBeFalse();
});

test('school admins cannot access the national school registry', function () {
    $this->seed(RolePermissionSeeder::class);

    $school = School::create(['code' => 'SCH001', 'name' => 'Umoja School']);
    $schoolAdmin = User::factory()->create(['school_id' => $school->id]);
    $schoolAdmin->assignRole(Role::findByName('school_admin', 'web'));

    $this->actingAs($schoolAdmin)
        ->get(route('admin.schools.index'))
        ->assertForbidden();

    $this->put(route('admin.schools.update', $school), [
        'name' => 'Changed School',
        'is_active' => false,
    ])->assertForbidden();

    expect($school->fresh()->name)->toBe('Umoja School');
});

test('a super admin can update all school details and disable selected modules', function () {
    $this->seed(RolePermissionSeeder::class);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(Role::findByName('super_admin', 'web'));
    $school = School::create(['code' => 'SCH001', 'name' => 'Old School']);
    $cbc = Curriculum::create(['code' => 'CBC', 'name' => 'Competency Based Curriculum']);

    $this->actingAs($superAdmin)
        ->put(route('admin.schools.update', $school), [
            'name' => 'New School Name',
            'code' => 'SCH002',
            'type' => 'mixed',
            'level' => 'junior_senior',
            'moe_code' => 'MOE-22',
            'knec_code' => 'KNEC-33',
            'county' => 'Nairobi',
            'sub_county' => 'Westlands',
            'ward' => 'Parklands',
            'address' => 'P.O. Box 100, Nairobi',
            'phone' => '0712345678',
            'email' => 'office@example.ke',
            'website' => 'https://school.example.ke',
            'po_box' => 'P.O. Box 100',
            'motto' => 'Learn with purpose',
            'mission' => 'A mission statement',
            'vision' => 'A vision statement',
            'aim' => 'An institutional aim',
            'primary_color' => '#234567',
            'secondary_color' => '#abcdef',
            'accent_color' => '#cc5522',
            'is_active' => true,
            'enabled_modules' => ['users', 'academics'],
            'curriculum_ids' => [$cbc->id],
        ])
        ->assertSessionHasNoErrors();

    $school->refresh();
    expect($school->code)->toBe('SCH002')
        ->and($school->sub_county)->toBe('Westlands')
        ->and($school->ward)->toBe('Parklands')
        ->and($school->website)->toBe('https://school.example.ke')
        ->and($school->mission)->toBe('A mission statement')
        ->and($school->primary_color)->toBe('#234567')
        ->and($school->enabled_modules)->toBe(['users', 'academics'])
        ->and($school->curricula()->wherePivot('is_primary', true)->value('curricula.id'))->toBe($cbc->id);
});

test('a super admin can activate an inactive school from the registry action', function () {
    $this->seed(RolePermissionSeeder::class);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(Role::findByName('super_admin', 'web'));
    $school = School::create(['code' => 'SUSP003', 'name' => 'Inactive School', 'is_active' => false]);

    $this->actingAs($superAdmin)
        ->patch(route('admin.schools.status', $school), ['is_active' => true])
        ->assertSessionHasNoErrors();

    expect($school->fresh()->is_active)->toBeTrue();
});

test('a super admin can create and modify curriculum metadata without losing assignments', function () {
    $this->seed(RolePermissionSeeder::class);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(Role::findByName('super_admin', 'web'));
    $school = School::create(['code' => 'CUR001', 'name' => 'Curriculum School']);
    $curriculum = Curriculum::create([
        'code' => 'LOCAL',
        'name' => 'Local Pathway',
        'authority' => 'County Board',
        'description' => 'Initial description',
        'is_active' => false,
    ]);
    $school->curricula()->attach($curriculum->id, ['is_primary' => true]);

    $this->actingAs($superAdmin)->post(route('admin.curricula.store'), [
        'code' => 'ALT-CBC',
        'name' => 'Alternative CBC',
        'authority' => 'KICD',
        'description' => 'A new curriculum route.',
    ])->assertSessionHasNoErrors();

    $created = Curriculum::where('code', 'ALT-CBC')->firstOrFail();
    expect($created->is_active)->toBeTrue();

    $this->put(route('admin.curricula.update', $curriculum), [
        'code' => 'LOCAL-UPDATED',
        'name' => 'Updated Local Pathway',
        'authority' => 'County Education Board',
        'description' => 'Updated description',
    ])->assertSessionHasNoErrors();

    expect($curriculum->fresh()->name)->toBe('Updated Local Pathway')
        ->and($curriculum->fresh()->authority)->toBe('County Education Board')
        ->and($curriculum->fresh()->is_active)->toBeFalse()
        ->and($school->curricula()->whereKey($curriculum->id)->exists())->toBeTrue();

    $this->put(route('admin.curricula.update', $curriculum), [
        'code' => 'ALT-CBC',
        'name' => 'Duplicate Code',
        'authority' => 'KICD',
        'description' => '',
    ])->assertSessionHasErrors('code');
});

test('school admins cannot create or modify national curricula', function () {
    $this->seed(RolePermissionSeeder::class);
    $school = School::create(['code' => 'CUR002', 'name' => 'Tenant School']);
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole(Role::findByName('school_admin', 'web'));
    $curriculum = Curriculum::create(['code' => 'CBCX', 'name' => 'CBC X']);

    $this->actingAs($admin)
        ->post(route('admin.curricula.store'), ['code' => 'NOPE', 'name' => 'Nope', 'authority' => 'KICD'])
        ->assertForbidden();
    $this->put(route('admin.curricula.update', $curriculum), [
        'code' => 'CBCX',
        'name' => 'Changed',
        'authority' => 'KICD',
    ])->assertForbidden();

    expect($curriculum->fresh()->name)->toBe('CBC X');
});

test('a super admin can create and edit curriculum levels without losing linked subjects', function () {
    $this->seed(RolePermissionSeeder::class);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(Role::findByName('super_admin', 'web'));
    $curriculum = Curriculum::create(['code' => 'LEVEL-CBC', 'name' => 'Level Test CBC']);

    $this->actingAs($superAdmin)->post(route('admin.curricula.levels.store', $curriculum), [
        'code' => 'G7',
        'name' => 'Grade 7',
        'stage' => 'Junior School',
        'order' => 9,
    ])->assertSessionHasNoErrors();

    $level = CurriculumLevel::where('curriculum_id', $curriculum->id)->where('code', 'G7')->firstOrFail();
    $subject = Subject::create([
        'curriculum_id' => $curriculum->id,
        'curriculum_level_id' => $level->id,
        'code' => 'MATH7',
        'name' => 'Mathematics',
    ]);

    $this->put(route('admin.curricula.levels.update', [$curriculum, $level]), [
        'code' => 'G7A',
        'name' => 'Grade Seven',
        'stage' => 'Junior Secondary',
        'order' => 10,
    ])->assertSessionHasNoErrors();

    expect($level->fresh()->name)->toBe('Grade Seven')
        ->and($level->fresh()->order)->toBe(10)
        ->and($subject->fresh()->curriculum_level_id)->toBe($level->id);

    $this->post(route('admin.curricula.levels.store', $curriculum), [
        'code' => 'G7A',
        'name' => 'Duplicate Grade',
        'stage' => 'Junior Secondary',
        'order' => 11,
    ])->assertSessionHasErrors('code');
});

test('curriculum levels cannot be edited through a different curriculum route', function () {
    $this->seed(RolePermissionSeeder::class);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(Role::findByName('super_admin', 'web'));
    $first = Curriculum::create(['code' => 'CUR-A', 'name' => 'Curriculum A']);
    $second = Curriculum::create(['code' => 'CUR-B', 'name' => 'Curriculum B']);
    $level = CurriculumLevel::create(['curriculum_id' => $first->id, 'code' => 'P1', 'name' => 'Primary 1', 'stage' => 'Primary', 'order' => 1]);

    $this->actingAs($superAdmin)
        ->put(route('admin.curricula.levels.update', [$second, $level]), [
            'code' => 'P1',
            'name' => 'Changed',
            'stage' => 'Primary',
            'order' => 1,
        ])->assertNotFound();

    expect($level->fresh()->name)->toBe('Primary 1');
});

test('disabled modules are hidden from tenant routes while enabled modules remain available', function () {
    $this->seed(RolePermissionSeeder::class);
    $school = School::create([
        'code' => 'SCH001',
        'name' => 'Umoja School',
        'enabled_modules' => ['users', 'academics'],
    ]);
    $schoolAdmin = User::factory()->create(['school_id' => $school->id]);
    $schoolAdmin->assignRole(Role::findByName('school_admin', 'web'));

    $this->actingAs($schoolAdmin)
        ->get(route('finance.index'))
        ->assertRedirect(route('module.unavailable'));
    $this->withoutVite()->get(route('module.unavailable'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/module-unavailable')
            ->where('module.name', 'Finance')
            ->where('module.schoolName', 'Umoja School'));
    $this->get(route('operations.index', 'library'))->assertRedirect(route('module.unavailable'));
    $this->get(route('academics.index'))->assertOk();
    $this->get(route('users.index'))->assertOk();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('modules', fn ($modules) => collect($modules)->pluck('key')->all() === ['users', 'academics', 'curriculum'])
            ->where('stats', fn ($stats) => collect($stats)->pluck('label')->all() === ['Learners', 'Staff']));
});

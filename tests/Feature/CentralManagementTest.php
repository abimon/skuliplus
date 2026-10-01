<?php

use App\Models\CentralRequest;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\SchoolModule;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('school admins can request a disabled module and central approval enables it for that school', function () {
    $school = School::create(['code' => 'CENT001', 'name' => 'Central Test School', 'enabled_modules' => ['users', 'academics']]);
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole(Role::findByName('school_admin', 'web'));
    $module = SchoolModule::where('key', 'labs')->firstOrFail();
    $module->update(['is_active' => false]);

    $this->actingAs($admin)->post(route('school.requests.store'), [
        'type' => 'module_activation',
        'module_key' => 'labs',
        'subject' => 'Enable laboratories',
        'message' => 'Our science program needs lab inventory.',
    ])->assertSessionHasNoErrors();

    $request = CentralRequest::where('school_id', $school->id)->firstOrFail();
    expect($request->type)->toBe('module_activation')->and($request->status)->toBe('pending');

    $central = User::factory()->create();
    $central->assignRole(Role::findByName('super_admin', 'web'));
    $this->withoutVite()->actingAs($central)
        ->get(route('admin.management.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/management')->has('requests.data', 1));

    $this->patch(route('admin.management.requests.update', $request), ['decision' => 'approve'])
        ->assertSessionHasNoErrors();

    expect($request->fresh()->status)->toBe('approved')
        ->and($school->fresh()->hasModuleEnabled('labs'))->toBeTrue()
        ->and($module->fresh()->is_active)->toBeTrue();
});

test('central inbox can filter activation requests separately from other inquiries', function () {
    $central = User::factory()->create();
    $central->assignRole(Role::findByName('super_admin', 'web'));
    CentralRequest::create(['type' => 'module_activation', 'subject' => 'Enable labs', 'status' => 'pending']);
    CentralRequest::create(['type' => 'demo', 'subject' => 'Demo booking', 'status' => 'pending']);

    $this->withoutVite()->actingAs($central)
        ->get(route('admin.management.index', ['type' => 'module_activation']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/management')
            ->where('requestFilter', 'module_activation')
            ->has('requests.data', 1)
            ->where('requests.data.0.type', 'module_activation'));
});

test('school admins can request curriculum access and central approval activates and assigns it', function () {
    $school = School::create(['code' => 'CENT002', 'name' => 'Pathway School', 'enabled_modules' => ['users', 'academics']]);
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole(Role::findByName('school_admin', 'web'));
    $curriculum = Curriculum::create(['code' => 'ALT', 'name' => 'Alternative Pathway', 'is_active' => false]);

    $this->actingAs($admin)->post(route('school.requests.store'), [
        'type' => 'curriculum_activation',
        'curriculum_id' => $curriculum->id,
        'subject' => 'Request alternative pathway',
        'message' => 'Please assign this pathway to our school.',
    ])->assertSessionHasNoErrors();
    $request = CentralRequest::where('school_id', $school->id)->firstOrFail();

    $central = User::factory()->create();
    $central->assignRole(Role::findByName('super_admin', 'web'));
    $this->actingAs($central)->patch(route('admin.management.requests.update', $request), ['decision' => 'approve']);

    expect($curriculum->fresh()->is_active)->toBeTrue()
        ->and($school->curricula()->whereKey($curriculum->id)->exists())->toBeTrue();
});

test('central management can set paid module prices but users remains unpriced', function () {
    $central = User::factory()->create();
    $central->assignRole(Role::findByName('super_admin', 'web'));
    $finance = SchoolModule::where('key', 'finance')->firstOrFail();
    $users = SchoolModule::where('key', 'users')->firstOrFail();

    $this->actingAs($central)->put(route('admin.management.modules.update', $finance), [
        'price_kes' => '2500.00',
        'is_active' => true,
    ])->assertSessionHasNoErrors();
    $this->put(route('admin.management.modules.update', $users), [
        'is_active' => true,
    ])->assertSessionHasNoErrors();
    $this->put(route('admin.management.modules.update', $users), [
        'price_kes' => '100.00',
        'is_active' => true,
    ])->assertSessionHasErrors('price_kes');

    expect((float) $finance->fresh()->price_kes)->toBe(2500.0)
        ->and($users->fresh()->price_kes)->toBeNull();
});

test('central management appears in national navigation and paid prices appear on school dashboards', function () {
    $central = User::factory()->create();
    $central->assignRole(Role::findByName('super_admin', 'web'));
    $this->withoutVite()->actingAs($central)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('modules', fn ($modules) => collect($modules)->pluck('key')->contains('management'))
            ->where('navigation', fn ($items) => collect($items)->pluck('key')->contains('management')));

    $school = School::create(['code' => 'PRICE01', 'name' => 'Pricing School', 'enabled_modules' => ['users', 'finance']]);
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole(Role::findByName('school_admin', 'web'));
    SchoolModule::where('key', 'finance')->update(['price_kes' => 1800]);

    $this->withoutVite()->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('modules', fn ($modules) => collect($modules)->firstWhere('key', 'finance')['price_kes'] == 1800));
});

test('school dashboard excludes the curriculum pseudo-module and marks submitted activation requests', function () {
    $school = School::create([
        'code' => 'PENDING01',
        'name' => 'Pending Request School',
        'enabled_modules' => ['users'],
    ]);
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole(Role::findByName('school_admin', 'web'));
    CentralRequest::create([
        'school_id' => $school->id,
        'user_id' => $admin->id,
        'type' => 'module_activation',
        'subject' => 'Enable laboratories',
        'message' => 'Please enable labs.',
        'payload' => ['module_key' => 'labs'],
        'status' => 'pending',
    ]);

    $this->withoutVite()->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unavailableModules', fn ($modules) => ! collect($modules)->pluck('key')->contains('curriculum'))
            ->where('pendingModuleRequests', ['labs'])
            ->where('pendingCurriculumRequests', []));
});

test('school requests and central pricing are not available to ordinary school staff', function () {
    $school = School::create(['code' => 'CENT003', 'name' => 'Staff School']);
    $teacher = User::factory()->create(['school_id' => $school->id]);
    $teacher->assignRole(Role::findByName('teacher', 'web'));

    $this->actingAs($teacher)->post(route('school.requests.store'), [
        'type' => 'feedback',
        'subject' => 'Feedback',
        'message' => 'Please review this.',
    ])->assertForbidden();

    $this->put(route('admin.management.modules.update', SchoolModule::where('key', 'finance')->first()), [
        'price_kes' => '20',
        'is_active' => true,
    ])->assertForbidden();
});

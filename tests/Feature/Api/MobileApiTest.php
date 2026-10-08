<?php

use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * Authenticate as a mobile client holding a token with the `mobile` ability.
 */
function mobileAs($user): void
{
    Sanctum::actingAs($user, ['mobile']);
}

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->school = School::create([
        'code' => 'APITEST',
        'name' => 'Api Test School',
        'enabled_modules' => ['users', 'academics', 'finance', 'library', 'gate', 'stores', 'activities', 'labs'],
    ]);

    $this->makeUser = function (string $role, array $attributes = []) {
        $user = User::factory()->create(array_merge([
            'school_id' => $this->school->id,
            'can_login' => true,
            'status' => 'active',
            'password' => Hash::make('password123'),
        ], $attributes));

        $user->assignRole($role);

        return $user;
    };
});

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

test('login issues a sanctum token and returns the profile payload', function () {
    ($this->makeUser)('school_admin', ['email' => 'admin@apitest.test']);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'admin@apitest.test',
        'password' => 'password123',
        'device_name' => 'test-device',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'admin@apitest.test')
        ->assertJsonPath('data.user.primary_role', 'school_admin')
        ->assertJsonPath('data.user.school.name', 'Api Test School');

    expect($response->json('data.token'))->toBeString();
});

test('login rejects bad credentials', function () {
    ($this->makeUser)('school_admin', ['email' => 'admin@apitest.test']);

    $this->postJson('/api/auth/login', [
        'email' => 'admin@apitest.test',
        'password' => 'wrong-password',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

test('a suspended school cannot sign in', function () {
    $this->school->update(['is_active' => false]);
    ($this->makeUser)('school_admin', ['email' => 'admin@apitest.test']);

    $this->postJson('/api/auth/login', [
        'email' => 'admin@apitest.test',
        'password' => 'password123',
    ])->assertStatus(403);
});

test('protected endpoints reject missing tokens', function () {
    $this->getJson('/api/dashboard')->assertUnauthorized();
});

test('logout revokes only the current device token', function () {
    $user = ($this->makeUser)('school_admin');

    $first = $user->createToken('phone')->plainTextToken;
    $second = $user->createToken('tablet')->plainTextToken;

    $this->withToken($first)->postJson('/api/auth/logout')->assertOk();

    expect($user->fresh()->tokens()->count())->toBe(1);

    // The guard caches the resolved user for the life of the test, so clear it
    // to prove the revoked token really is rejected.
    $this->app['auth']->forgetGuards();

    $this->withToken($first)->getJson('/api/auth/me')->assertUnauthorized();
    $this->withToken($second)->getJson('/api/auth/me')->assertOk();
});

/*
|--------------------------------------------------------------------------
| Profile
|--------------------------------------------------------------------------
*/

test('a user can read and update their own profile', function () {
    $user = ($this->makeUser)('parent', ['first_name' => 'Jane', 'last_name' => 'Doe']);

    mobileAs($user);

    $this->getJson('/api/profile')
        ->assertOk()
        ->assertJsonPath('data.primary_role', 'parent');

    $this->putJson('/api/profile', [
        'first_name' => 'Janet',
        'last_name' => 'Doe-Smith',
        'phone' => '+254700000000',
    ])->assertOk()->assertJsonPath('data.name', 'Janet Doe-Smith');

    expect($user->fresh()->name)->toBe('Janet Doe-Smith');
});

test('changing a password requires the current one', function () {
    $user = ($this->makeUser)('teacher', ['must_change_password' => true]);

    mobileAs($user);

    $this->postJson('/api/profile/password', [
        'current_password' => 'not-the-password',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertStatus(422)->assertJsonValidationErrors('current_password');

    $this->postJson('/api/profile/password', [
        'current_password' => 'password123',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertOk();

    expect($user->fresh()->must_change_password)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Role interfaces
|--------------------------------------------------------------------------
*/

test('the dashboard adapts its shape to each interface', function () {
    $central = User::factory()->create(['school_id' => null]);
    $central->assignRole('super_admin');

    mobileAs($central);

    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('data.primary_role', 'super_admin')
        ->assertJsonStructure(['data' => ['central' => ['stats', 'modules', 'recent_requests']]]);

    $this->app['auth']->forgetGuards();
    mobileAs(($this->makeUser)('school_admin'));

    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('data.primary_role', 'school_admin')
        ->assertJsonStructure(['data' => ['school', 'modules', 'stats']]);

    $this->app['auth']->forgetGuards();
    mobileAs(($this->makeUser)('librarian'));

    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('data.primary_role', 'librarian')
        ->assertJsonStructure(['data' => ['staff' => ['duties']]]);
});

test('central management endpoints are closed to school staff', function () {
    mobileAs(($this->makeUser)('school_admin'));

    $this->getJson('/api/central')->assertForbidden();

    $this->app['auth']->forgetGuards();

    $central = User::factory()->create(['school_id' => null]);
    $central->assignRole('super_admin');
    mobileAs($central);

    $this->getJson('/api/central')
        ->assertOk()
        ->assertJsonStructure(['data' => ['stats', 'module_catalog', 'curricula']]);
});

test('a school admin can register people but only within their own school', function () {
    mobileAs($admin = ($this->makeUser)('school_admin'));

    $response = $this->postJson('/api/school/people', [
        'first_name' => 'Amina',
        'last_name' => 'Wanjiru',
        'role' => 'teacher',
        'email' => 'amina@apitest.test',
        'password' => 'teacher-pass-1',
    ])->assertCreated();

    expect($response->json('data.person.roles'))->toContain('teacher');
    expect($response->json('data.person.school_id'))->toBe($this->school->id);

    $otherSchool = School::create(['code' => 'OTHER', 'name' => 'Other School', 'enabled_modules' => ['users']]);
    $outsider = User::factory()->create(['school_id' => $otherSchool->id]);
    $outsider->assignRole('teacher');

    $this->getJson("/api/school/people/{$outsider->id}")->assertNotFound();
});

test('a generated password is returned once when staff are created without one', function () {
    mobileAs(($this->makeUser)('school_admin'));

    $response = $this->postJson('/api/school/people', [
        'first_name' => 'Peter',
        'last_name' => 'Mwangi',
        'role' => 'gatekeeper',
        'email' => 'peter@apitest.test',
    ])->assertCreated();

    expect($response->json('data.generated_password'))->toBeString();
});

test('parents only see their own children', function () {
    mobileAs($parent = ($this->makeUser)('parent'));

    $child = User::factory()->create(['school_id' => $this->school->id, 'name' => 'My Child']);
    $child->assignRole('student');
    $parent->children()->attach($child->id, ['relationship' => 'parent', 'is_primary' => true]);

    $otherChild = User::factory()->create(['school_id' => $this->school->id, 'name' => 'Someone Elses Child']);
    $otherChild->assignRole('student');

    $this->getJson('/api/family/children')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $child->id);

    $this->getJson("/api/family/children/{$child->id}")
        ->assertOk()
        ->assertJsonPath('data.child.name', 'My Child')
        ->assertJsonStructure(['data' => ['results', 'invoices', 'payments', 'books', 'clubs', 'timetable', 'totals']]);

    $this->getJson("/api/family/children/{$otherChild->id}")->assertForbidden();
});

test('support staff cannot record results', function () {
    mobileAs(($this->makeUser)('support_staff'));

    $this->postJson('/api/academics/results', [
        'exam_id' => 1,
        'student_id' => 1,
        'subject_id' => 1,
        'score' => 70,
    ])->assertForbidden();
});

test('operation modules reject those the school has not enabled', function () {
    $this->school->update(['enabled_modules' => ['users']]);

    mobileAs(($this->makeUser)('librarian'));

    $this->getJson('/api/operations/library')->assertForbidden();
});

test('the gate module records a visitor and checks them out', function () {
    mobileAs($gatekeeper = ($this->makeUser)('gatekeeper'));

    $response = $this->postJson('/api/operations/gate/check-in', [
        'full_name' => 'Visitor One',
        'purpose' => 'Meeting the principal',
        'phone' => '+254700111222',
    ])->assertCreated();

    $code = $response->json('data.checkout_code');
    expect($code)->toBeString();
    expect($response->json('data.on_site'))->toBeTrue();

    $this->postJson('/api/operations/gate/checkout', ['checkout_code' => $code])
        ->assertOk()
        ->assertJsonPath('data.on_site', false);
});

/*
|--------------------------------------------------------------------------
| Offline sync
|--------------------------------------------------------------------------
*/

test('sync status reports only the resources the user may pull', function () {
    mobileAs($teacher = ($this->makeUser)('teacher'));

    $this->getJson('/api/sync/status')
        ->assertOk()
        ->assertJsonPath('data.primary_role', 'teacher')
        ->assertJsonStructure(['data' => ['available_resources']]);

    $resources = $this->getJson('/api/sync/status')->json('data.available_resources');

    expect($resources)->toContain('people', 'lessons');
    expect($resources)->not->toContain('finance_accounts');
});

test('sync pull returns a cursor and per-resource upserts', function () {
    mobileAs($admin = ($this->makeUser)('school_admin'));

    $learner = User::factory()->create(['school_id' => $this->school->id, 'name' => 'Sync Learner']);
    $learner->assignRole('student');

    $since = now()->subHour()->toIso8601String();

    $response = $this->getJson("/api/sync/pull?since={$since}&resources=people")
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['people' => ['upserts', 'deleted']],
            'meta' => ['cursor', 'requested', 'skipped'],
        ]);

    $ids = collect($response->json('data.people.upserts'))->pluck('id');
    expect($ids)->toContain($learner->id);
    expect($response->json('meta.skipped'))->toBe([]);
});

test('sync pull refuses resources the user is not entitled to', function () {
    $learner = User::factory()->create(['school_id' => $this->school->id, 'can_login' => true]);
    $learner->assignRole('student');

    mobileAs($learner);

    $response = $this->getJson('/api/sync/pull?resources=finance_accounts,visitors,results')->assertOk();

    expect($response->json('meta.skipped'))->toContain('finance_accounts', 'visitors');
    expect(array_keys($response->json('data')))->toContain('results');
});

test('sync pull rejects an unknown resource', function () {
    mobileAs(($this->makeUser)('school_admin'));

    $this->getJson('/api/sync/pull?resources=not_a_resource')->assertStatus(422);
});

test('sync push replays a queued mutation', function () {
    mobileAs(($this->makeUser)('gatekeeper'));

    $response = $this->postJson('/api/sync/push', [
        'operations' => [[
            'id' => 'op-001',
            'action' => 'check_in_visitor',
            'payload' => [
                'full_name' => 'Offline Visitor',
                'purpose' => 'Collected a report',
            ],
        ]],
    ])->assertOk()->assertJsonPath('meta.applied', 1);

    expect($response->json('data.0.status'))->toBe('applied');
    expect($response->json('data.0.data.checkout_code'))->toBeString();
});

test('sync push reports invalid queued records as rejected rather than failing', function () {
    mobileAs(($this->makeUser)('gatekeeper'));

    $this->postJson('/api/sync/push', [
        'operations' => [
            ['id' => 'op-bad', 'action' => 'check_in_visitor', 'payload' => ['purpose' => 'Missing name']],
            ['id' => 'op-good', 'action' => 'check_in_visitor', 'payload' => [
                'full_name' => 'Valid Visitor',
                'purpose' => 'Attendance',
            ]],
        ],
    ])->assertOk()
        ->assertJsonPath('meta.applied', 1)
        ->assertJsonPath('meta.rejected', 1);

    expect($this->json('data.0.status'))->toBe('rejected');
    expect($this->json('data.1.status'))->toBe('applied');
});
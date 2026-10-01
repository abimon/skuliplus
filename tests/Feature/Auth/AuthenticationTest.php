<?php

use App\Models\School;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('school admins cannot sign in when their school is inactive', function () {
    $school = School::create(['code' => 'SUSP001', 'name' => 'Suspended Heights', 'is_active' => false]);
    $user = User::factory()->create(['school_id' => $school->id, 'email' => 'admin@suspended.test']);
    $user->assignRole(Role::findOrCreate('school_admin', 'web'));

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('school.inactive'));

    $this->assertGuest();
    $this->withoutVite()->get(route('school.inactive'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/school-inactive')
            ->where('schoolName', 'Suspended Heights'));
});

test('users signed in before school suspension are logged out on their next request', function () {
    $school = School::create(['code' => 'SUSP002', 'name' => 'Paused School', 'is_active' => true]);
    $user = User::factory()->create(['school_id' => $school->id]);
    $user->assignRole(Role::findOrCreate('teacher', 'web'));
    $this->actingAs($user);

    $school->update(['is_active' => false]);

    $this->get(route('dashboard'))->assertRedirect(route('school.inactive'));
    $this->assertGuest();
});

test('super admins without a school can still sign in', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));

    $this->post('/login', ['email' => $superAdmin->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($superAdmin);
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});

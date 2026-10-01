<?php

use App\Models\School;
use App\Models\User;
use Spatie\Permission\Models\Role;

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get('/dashboard')->assertOk();
});

test('parents can switch to their child only within the same school', function () {
    $school = School::create(['code' => 'SCH001', 'name' => 'Umoja School']);
    $otherSchool = School::create(['code' => 'SCH002', 'name' => 'Jubilee School']);
    $parent = User::factory()->create(['school_id' => $school->id]);
    $child = User::factory()->create(['school_id' => $school->id]);
    $otherChild = User::factory()->create(['school_id' => $otherSchool->id]);

    $parent->assignRole(Role::findOrCreate('parent', 'web'));
    $parent->children()->attach([$child->id, $otherChild->id]);

    $this->actingAs($parent)
        ->post(route('dashboard.child'), ['child_id' => $child->id])
        ->assertRedirect();

    $this->assertSame($child->id, session('active_child_id'));

    $this->post(route('dashboard.child'), ['child_id' => $otherChild->id])
        ->assertNotFound();
});

<?php

use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('a school admin can edit only their school setup and upload a logo', function () {
    Storage::fake('public');
    $school = School::create(['code' => 'SCH001', 'name' => 'Umoja School']);
    $otherSchool = School::create(['code' => 'SCH002', 'name' => 'Other School']);
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole(Role::findByName('school_admin', 'web'));

    $this->withoutVite()->actingAs($admin)
        ->get(route('branding.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('branding/edit')
            ->where('school.id', $school->id));

    $this->withoutVite()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('canManageSchoolSetup', true));

    $this->put(route('branding.update'), [
        'name' => 'Umoja Heights School',
        'county' => 'Nairobi',
        'sub_county' => 'Westlands',
        'ward' => 'Parklands',
        'address' => 'School Road',
        'phone' => '0712345678',
        'email' => 'office@umoja.example.ke',
        'website' => 'https://umoja.example.ke',
        'po_box' => 'P.O. Box 123',
        'motto' => 'Elimu ni Nguvu',
        'mission' => 'Nurture confident learners.',
        'vision' => 'A thriving community school.',
        'aim' => 'Learning for life.',
        'primary_color' => '#235533',
        'secondary_color' => '#dda544',
        'accent_color' => '#457a9a',
        'logo' => UploadedFile::fake()->createWithContent(
            'school-logo.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jIowAAAAASUVORK5CYII=')
        ),
    ])->assertSessionHasNoErrors();

    $school->refresh();
    expect($school->name)->toBe('Umoja Heights School')
        ->and($school->motto)->toBe('Elimu ni Nguvu')
        ->and($school->mission)->toBe('Nurture confident learners.')
        ->and($school->aim)->toBe('Learning for life.')
        ->and($school->primary_color)->toBe('#235533')
        ->and($school->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($school->logo_path);
    expect($otherSchool->fresh()->name)->toBe('Other School');
});

test('a non-school-admin cannot edit school branding', function () {
    $school = School::create(['code' => 'SCH001', 'name' => 'Umoja School']);
    $parent = User::factory()->create(['school_id' => $school->id]);
    $parent->assignRole(Role::findByName('parent', 'web'));

    $this->actingAs($parent)
        ->get(route('branding.edit'))
        ->assertForbidden();
});

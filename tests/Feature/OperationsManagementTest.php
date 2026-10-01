<?php

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\BookLoan;
use App\Models\Club;
use App\Models\Curriculum;
use App\Models\CurriculumLevel;
use App\Models\Enrollment;
use App\Models\Lab;
use App\Models\LabItem;
use App\Models\Promotion;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\StoreItem;
use App\Models\StoreMovement;
use App\Models\Stream;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\VisitorCheckoutCode;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function operationsSchool(string $code = 'UHS001'): array
{
    $school = School::create(['code' => $code, 'name' => 'Umoja Heights']);
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole(Role::findByName('school_admin', 'web'));
    $student = User::factory()->create(['school_id' => $school->id, 'admission_number' => $code.'-001']);
    $student->assignRole(Role::findByName('student', 'web'));

    return compact('school', 'admin', 'student');
}

test('library loans conserve copies and returns are idempotent', function () {
    $fixture = operationsSchool();
    $librarian = User::factory()->create(['school_id' => $fixture['school']->id]);
    $librarian->assignRole(Role::findByName('librarian', 'web'));
    $book = Book::create(['school_id' => $fixture['school']->id, 'title' => 'The River Between', 'copies' => 1, 'available' => 1, 'status' => 'available']);

    $this->actingAs($librarian)->post(route('operations.store', 'library'), ['title' => 'New book', 'copies' => 2])->assertSessionHasNoErrors();
    $this->post(route('library.borrow'), ['book_id' => $book->id, 'borrower_id' => $fixture['student']->id, 'due_on' => now()->addDays(14)->toDateString()])->assertSessionHasNoErrors();
    expect($book->fresh()->available)->toBe(0);

    $loan = BookLoan::where('book_id', $book->id)->firstOrFail();
    $this->post(route('library.return', $loan))->assertSessionHasNoErrors();
    $this->post(route('library.return', $loan))->assertSessionHasNoErrors();
    expect($book->fresh()->available)->toBe(1)
        ->and($loan->fresh()->status)->toBe('returned');
});

test('gate visitor check-in sends a checkout code and code checks out once', function () {
    Notification::fake();
    $fixture = operationsSchool();
    $gatekeeper = User::factory()->create(['school_id' => $fixture['school']->id]);
    $gatekeeper->assignRole(Role::findByName('gatekeeper', 'web'));

    $this->actingAs($gatekeeper)->post(route('operations.store', 'gate'), [
        'full_name' => 'Jane Visitor',
        'email' => 'visitor@example.ke',
        'purpose' => 'Meeting',
        'person_to_see' => 'Headteacher',
    ])->assertSessionHasNoErrors();

    $visitor = Visitor::where('school_id', $fixture['school']->id)->firstOrFail();
    Notification::assertSentOnDemand(VisitorCheckoutCode::class);
    expect(strlen($visitor->checkout_code))->toBe(6)->and($visitor->checked_out_at)->toBeNull();

    $this->post(route('gate.checkout'), ['checkout_code' => $visitor->checkout_code])->assertSessionHasNoErrors();
    expect($visitor->fresh()->checked_out_at)->not->toBeNull();
});

test('store and lab movements reject issuing more stock than available', function () {
    $fixture = operationsSchool();
    $this->actingAs($fixture['admin'])->post(route('operations.store', 'stores'), [
        'name' => 'Maize flour',
        'sku' => 'KITCHEN-MF',
        'store_type' => 'kitchen',
        'unit' => 'bags',
        'quantity' => 5,
        'reorder_level' => 2,
        'unit_cost' => 3200,
    ])->assertSessionHasNoErrors();
    $openingItem = StoreItem::where('school_id', $fixture['school']->id)->where('sku', 'KITCHEN-MF')->firstOrFail();
    expect(StoreMovement::where('store_item_id', $openingItem->id)->where('reference', 'Opening stock')->value('quantity'))->toBe(5);

    $item = StoreItem::create(['school_id' => $fixture['school']->id, 'name' => 'Rice', 'store_type' => 'kitchen', 'quantity' => 4, 'reorder_level' => 1]);
    $lab = Lab::create(['school_id' => $fixture['school']->id, 'name' => 'Science Lab', 'discipline' => 'science']);
    $labItem = LabItem::create(['school_id' => $fixture['school']->id, 'lab_id' => $lab->id, 'name' => 'Beaker', 'quantity' => 2]);

    $this->actingAs($fixture['admin'])->post(route('stores.movement'), ['store_item_id' => $item->id, 'direction' => 'issue', 'quantity' => 5])->assertSessionHasErrors('quantity');
    $this->post(route('stores.movement'), ['store_item_id' => $item->id, 'direction' => 'receive', 'quantity' => 3])->assertSessionHasNoErrors();
    $this->post(route('labs.movement'), ['lab_item_id' => $labItem->id, 'direction' => 'issue', 'quantity' => 3])->assertSessionHasErrors('quantity');

    expect($item->fresh()->quantity)->toBe(7)
        ->and($labItem->fresh()->quantity)->toBe(2)
        ->and(StoreMovement::where('store_item_id', $item->id)->count())->toBe(1);

    $this->post(route('labs.movement'), ['lab_item_id' => $labItem->id, 'direction' => 'receive', 'quantity' => 4, 'notes' => 'Delivery'])->assertSessionHasNoErrors();
    expect($labItem->fresh()->quantity)->toBe(6);
});

test('school staff can create clubs and enroll same-school learners', function () {
    $fixture = operationsSchool();
    $club = Club::create(['school_id' => $fixture['school']->id, 'name' => 'Wildlife Club', 'type' => 'club']);

    $this->actingAs($fixture['admin'])->post(route('operations.store', 'activities'), [
        'entity' => 'member',
        'club_id' => $club->id,
        'user_id' => $fixture['student']->id,
        'role' => 'member',
    ])->assertSessionHasNoErrors();

    expect($club->members()->whereKey($fixture['student']->id)->exists())->toBeTrue();
});

test('module workflows deny users without their role permission', function () {
    $fixture = operationsSchool();

    $this->actingAs($fixture['student'])
        ->get(route('operations.index', 'library'))
        ->assertForbidden();

    $this->post(route('operations.store', 'gate'), [
        'full_name' => 'Visitor',
        'purpose' => 'Meeting',
    ])->assertForbidden();
});

test('promotion records old enrollment and creates a later-year placement atomically', function () {
    $fixture = operationsSchool();
    $curriculum = Curriculum::create(['code' => 'UHS-CBC', 'name' => 'CBC']);
    $level = CurriculumLevel::create(['curriculum_id' => $curriculum->id, 'code' => 'G7', 'name' => 'Grade 7', 'stage' => 'Junior School', 'order' => 1]);
    $oldYear = AcademicYear::create(['school_id' => $fixture['school']->id, 'name' => '2025', 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);
    $newYear = AcademicYear::create(['school_id' => $fixture['school']->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    $fromClass = SchoolClass::create(['school_id' => $fixture['school']->id, 'curriculum_id' => $curriculum->id, 'curriculum_level_id' => $level->id, 'academic_year_id' => $oldYear->id, 'name' => 'Grade 6']);
    $toClass = SchoolClass::create(['school_id' => $fixture['school']->id, 'curriculum_id' => $curriculum->id, 'curriculum_level_id' => $level->id, 'academic_year_id' => $newYear->id, 'name' => 'Grade 7']);
    $fromStream = Stream::create(['school_id' => $fixture['school']->id, 'school_class_id' => $fromClass->id, 'name' => 'East', 'capacity' => 40]);
    $toStream = Stream::create(['school_id' => $fixture['school']->id, 'school_class_id' => $toClass->id, 'name' => 'West', 'capacity' => 40]);
    $oldEnrollment = Enrollment::create(['school_id' => $fixture['school']->id, 'student_id' => $fixture['student']->id, 'school_class_id' => $fromClass->id, 'stream_id' => $fromStream->id, 'academic_year_id' => $oldYear->id, 'status' => 'active']);

    $this->actingAs($fixture['admin'])->post(route('promotions.process'), [
        'from_stream_id' => $fromStream->id,
        'to_class_id' => $toClass->id,
        'to_stream_id' => $toStream->id,
        'student_ids' => [$fixture['student']->id],
    ])->assertSessionHasNoErrors();

    expect($oldEnrollment->fresh()->status)->toBe('promoted')
        ->and(Enrollment::where('student_id', $fixture['student']->id)->where('academic_year_id', $newYear->id)->where('status', 'active')->exists())->toBeTrue()
        ->and(Promotion::where('student_id', $fixture['student']->id)->count())->toBe(1);
});

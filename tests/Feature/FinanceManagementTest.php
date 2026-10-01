<?php

use App\Models\AcademicYear;
use App\Models\FeeInvoice;
use App\Models\FinanceAccount;
use App\Models\Payment;
use App\Models\School;
use App\Models\Term;
use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\PaymentAcknowledged;
use App\Services\FeeProvisioningService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function makeFinanceFixture(): array
{
    $school = School::create(['code' => 'UHS001', 'name' => 'Umoja Heights', 'motto' => 'Elimu ni Nguvu']);
    $year = AcademicYear::create(['school_id' => $school->id, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true]);
    $term = Term::create(['school_id' => $school->id, 'academic_year_id' => $year->id, 'name' => 'Term 1', 'number' => 1, 'starts_on' => '2026-01-01', 'ends_on' => '2026-04-30', 'is_current' => true]);
    $admin = User::factory()->create(['school_id' => $school->id]);
    $admin->assignRole(Role::findByName('school_admin', 'web'));
    $parent = User::factory()->create(['school_id' => $school->id, 'email' => 'parent@umoja.ke']);
    $parent->assignRole(Role::findByName('parent', 'web'));
    $parent->parentProfile()->create(['school_id' => $school->id, 'relationship' => 'parent']);
    $student = User::factory()->create(['school_id' => $school->id, 'name' => 'Brian Kamau', 'admission_number' => 'UHS-001', 'email' => null, 'can_login' => false]);
    $student->assignRole(Role::findByName('student', 'web'));
    $student->studentProfile()->create(['school_id' => $school->id, 'boarding_status' => 'day']);
    $parent->children()->attach($student->id, ['relationship' => 'parent', 'is_primary' => true]);
    $account = FinanceAccount::create(['school_id' => $school->id, 'name' => 'Tuition', 'code' => 'TUITION26', 'type' => 'fee', 'amount' => 50000, 'term_scope' => 'term', 'is_active' => true]);

    return compact('school', 'year', 'term', 'admin', 'parent', 'student', 'account');
}

test('creating a fee account provisions current learners with pending invoices', function () {
    $fixture = makeFinanceFixture();
    $otherSchool = School::create(['code' => 'JHS001', 'name' => 'Jubilee Heights']);
    $otherStudent = User::factory()->create(['school_id' => $otherSchool->id]);
    $otherStudent->assignRole(Role::findByName('student', 'web'));

    $this->actingAs($fixture['admin'])
        ->post(route('finance.accounts.store'), [
            'name' => 'Boarding',
            'code' => 'BOARD26',
            'type' => 'boarding',
            'amount' => '12000.00',
            'term_scope' => 'term',
        ])
        ->assertSessionHasNoErrors();

    $account = FinanceAccount::where('school_id', $fixture['school']->id)->where('code', 'BOARD26')->firstOrFail();
    $invoice = FeeInvoice::where('finance_account_id', $account->id)->firstOrFail();
    expect($invoice->student_id)->toBe($fixture['student']->id)
        ->and($invoice->term_id)->toBe($fixture['term']->id)
        ->and($invoice->status)->toBe('pending')
        ->and($invoice->balance)->toBe('12000.00')
        ->and(FeeInvoice::where('student_id', $otherStudent->id)->exists())->toBeFalse();
});

test('payment allocation creates a receipt and updates invoice balance without overpayment', function () {
    Notification::fake();
    $fixture = makeFinanceFixture();
    $invoice = FeeInvoice::create([
        'school_id' => $fixture['school']->id,
        'student_id' => $fixture['student']->id,
        'finance_account_id' => $fixture['account']->id,
        'term_id' => $fixture['term']->id,
        'amount' => 50000,
        'balance' => 50000,
        'status' => 'pending',
    ]);

    $this->actingAs($fixture['admin'])
        ->post(route('finance.payments.store'), [
            'finance_account_id' => $fixture['account']->id,
            'fee_invoice_id' => $invoice->id,
            'student_id' => $fixture['student']->id,
            'amount' => '15000.00',
            'method' => 'cash',
        ])
        ->assertSessionHasNoErrors();

    $payment = Payment::where('fee_invoice_id', $invoice->id)->firstOrFail();
    expect($payment->receipt_number)->toStartWith('RCP-')
        ->and($payment->student_id)->toBe($fixture['student']->id)
        ->and($invoice->fresh()->balance)->toBe('35000.00')
        ->and($invoice->fresh()->status)->toBe('partial');
    Notification::assertSentTo($fixture['parent'], PaymentAcknowledged::class);

    $this->get(route('finance.receipt.download', $payment))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->post(route('finance.payments.store'), [
        'finance_account_id' => $fixture['account']->id,
        'fee_invoice_id' => $invoice->id,
        'amount' => '40000.00',
        'method' => 'cash',
    ])->assertSessionHasErrors('amount');

    expect(Payment::where('fee_invoice_id', $invoice->id)->count())->toBe(1);
});

test('withdrawals cannot exceed cleared account funds and parents only see their own receipts', function () {
    $fixture = makeFinanceFixture();
    $payment = Payment::create([
        'school_id' => $fixture['school']->id,
        'student_id' => $fixture['student']->id,
        'finance_account_id' => $fixture['account']->id,
        'receipt_number' => 'RCP-TEST-0001',
        'amount' => 10000,
        'method' => 'cash',
        'status' => 'confirmed',
        'paid_at' => now(),
    ]);
    FeeInvoice::create([
        'school_id' => $fixture['school']->id,
        'student_id' => $fixture['student']->id,
        'finance_account_id' => $fixture['account']->id,
        'term_id' => $fixture['term']->id,
        'amount' => 10000,
        'balance' => 10000,
        'status' => 'pending',
    ]);

    $this->actingAs($fixture['admin'])
        ->post(route('finance.withdrawals.store'), [
            'finance_account_id' => $fixture['account']->id,
            'amount' => '7500.00',
            'payee' => 'School supplier',
            'purpose' => 'Learning materials',
        ])
        ->assertSessionHasNoErrors();

    $this->post(route('finance.withdrawals.store'), [
        'finance_account_id' => $fixture['account']->id,
        'amount' => '3000.00',
        'payee' => 'Second supplier',
        'purpose' => 'Supplies',
    ])->assertSessionHasErrors('amount');

    expect(Withdrawal::where('finance_account_id', $fixture['account']->id)->count())->toBe(1);

    $this->actingAs($fixture['parent'])
        ->get(route('finance.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/index')
            ->has('accounts', 1)
            ->missing('accounts.0.available_balance')
            ->where('can.manage', false))
        ->assertDontSee('Recent withdrawals')
        ->assertDontSee('Available funds');

    $this->get(route('finance.receipt', $payment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/receipt')
            ->where('payment.receipt_number', 'RCP-TEST-0001'));
});

test('annual fee accounts create one invoice without a term reference', function () {
    $fixture = makeFinanceFixture();

    $this->actingAs($fixture['admin'])
        ->post(route('finance.accounts.store'), [
            'name' => 'Annual levy',
            'code' => 'ANNUAL26',
            'type' => 'other',
            'amount' => 1800,
            'term_scope' => 'annual',
        ])
        ->assertSessionHasNoErrors();

    $account = FinanceAccount::where('school_id', $fixture['school']->id)->where('code', 'ANNUAL26')->firstOrFail();
    expect(FeeInvoice::where('finance_account_id', $account->id)->value('term_id'))->toBeNull()
        ->and(app(FeeProvisioningService::class)->provisionAccount($account))->toBe(0);
});

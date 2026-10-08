<?php

namespace App\Http\Controllers\Api;

use App\Models\FeeInvoice;
use App\Models\FinanceAccount;
use App\Models\Payment;
use App\Models\School;
use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\PaymentAcknowledged;
use App\Services\FeeProvisioningService;
use App\Services\ReceiptNumberService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Finance: fee accounts, invoices, payments and withdrawals. Managers see
 * the whole school; parents see only their own children.
 */
class FinanceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'finance');
        $user = $this->user($request);

        abort_unless($user->can('finance.view'), 403, 'You do not have access to this resource.');

        $canManage = $user->can('finance.manage');
        $childIds = $canManage
            ? collect()
            : $user->children()->where('users.school_id', $school->id)->pluck('users.id');

        $invoices = $this->scopedInvoices($school->id, $childIds, $request);
        $payments = $this->scopedPayments($school->id, $childIds, $request);

        return $this->ok([
            'stats' => [
                'outstanding' => (float) (clone $invoices)->where('status', '!=', 'paid')->sum('balance'),
                'received' => (float) (clone $payments)->where('status', 'confirmed')->sum('amount'),
                'withdrawn' => $canManage ? (float) Withdrawal::where('school_id', $school->id)->sum('amount') : 0.0,
            ],
            'can' => [
                'manage' => $canManage,
                'record_payment' => $user->can('payments.record'),
            ],
            'accounts' => $this->accounts($school->id, $childIds, $canManage),
            'invoices' => $invoices->get()->map(fn (FeeInvoice $invoice) => $this->invoicePayload($invoice))->all(),
            'payments' => $payments->get()->map(fn (Payment $payment) => $this->paymentPayload($payment))->all(),
            'withdrawals' => $canManage
                ? Withdrawal::where('school_id', $school->id)->with('account:id,name,code')
                    ->latest('withdrawn_at')->limit(30)->get()
                    ->map(fn (Withdrawal $withdrawal) => $this->withdrawalPayload($withdrawal))->all()
                : [],
            'students' => $canManage
                ? User::role('student')->where('school_id', $school->id)->where('status', 'active')
                    ->orderBy('name')->get(['id', 'name', 'admission_number'])->all()
                : [],
        ]);
    }
    /**
     * Invoices for a single learner, used by the parent and learner views.
     */
    public function learnerStatement(Request $request, User $student): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'finance');
        $user = $this->user($request);

        abort_unless($student->school_id === $school->id, 404);

        if ($user->hasRole('parent')) {
            abort_unless(
                $user->children()->where('users.school_id', $school->id)->whereKey($student->id)->exists(),
                403
            );
        } else {
            abort_unless($user->id === $student->id || $user->can('finance.view'), 403, 'You do not have access to this resource.');
        }

        return $this->ok([
            'student' => $this->userPayload($student),
            'invoices' => FeeInvoice::where('student_id', $student->id)
                ->with(['account:id,name,code', 'term:id,name'])
                ->latest()->get()->map(fn (FeeInvoice $invoice) => $this->invoicePayload($invoice))->all(),
            'payments' => Payment::where('student_id', $student->id)
                ->with(['account:id,name,code', 'invoice:id,amount,balance'])
                ->latest('paid_at')->get()->map(fn (Payment $payment) => $this->paymentPayload($payment))->all(),
            'totals' => [
                'invoiced' => (float) FeeInvoice::where('student_id', $student->id)->sum('amount'),
                'balance' => (float) FeeInvoice::where('student_id', $student->id)->sum('balance'),
                'paid' => (float) Payment::where('student_id', $student->id)->where('status', 'confirmed')->sum('amount'),
            ],
        ]);
    }

    public function storeAccount(Request $request, FeeProvisioningService $provisioner): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'finance');

        abort_unless($this->user($request)->can('finance.manage'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40', Rule::unique('finance_accounts', 'code')->where('school_id', $school->id)],
            'type' => ['required', 'in:fee,boarding,transport,other'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'term_scope' => ['required', 'in:term,annual'],
        ]);

        $account = DB::transaction(function () use ($data, $school, $provisioner) {
            $account = FinanceAccount::create(['school_id' => $school->id, ...$data, 'is_active' => true]);
            $provisioner->provisionAccount($account);

            return $account;
        });

        return $this->ok($this->accountPayload($account), [], 201);
    }
    public function storePayment(Request $request, ReceiptNumberService $receipts): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'finance');

        abort_unless($this->user($request)->can('payments.record'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'finance_account_id' => ['required', Rule::exists('finance_accounts', 'id')->where('school_id', $school->id)->where('is_active', true)],
            'fee_invoice_id' => ['nullable', Rule::exists('fee_invoices', 'id')->where('school_id', $school->id)],
            'student_id' => ['nullable', Rule::exists('users', 'id')->where('school_id', $school->id)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'method' => ['required', 'in:cash,mpesa,bank,cheque,online'],
            'reference' => [Rule::requiredIf(fn () => in_array($request->input('method'), ['mpesa', 'bank', 'cheque', 'online'], true)), 'nullable', 'string', 'max:120'],
            'payer_name' => ['nullable', 'string', 'max:120'],
        ]);

        if (! empty($data['student_id']) && ! User::role('student')->where('school_id', $school->id)->whereKey($data['student_id'])->exists()) {
            throw ValidationException::withMessages(['student_id' => 'Choose a learner in this school.']);
        }

        $payment = DB::transaction(function () use ($data, $school, $request, $receipts) {
            School::whereKey($school->id)->lockForUpdate()->firstOrFail();
            $account = FinanceAccount::where('school_id', $school->id)->lockForUpdate()->findOrFail($data['finance_account_id']);
            $invoice = null;

            if (! empty($data['fee_invoice_id'])) {
                $invoice = FeeInvoice::where('school_id', $school->id)->lockForUpdate()->findOrFail($data['fee_invoice_id']);

                if ($invoice->finance_account_id !== $account->id) {
                    throw ValidationException::withMessages(['fee_invoice_id' => 'The invoice belongs to a different finance account.']);
                }

                if (! empty($data['student_id']) && (int) $data['student_id'] !== $invoice->student_id) {
                    throw ValidationException::withMessages(['student_id' => 'The selected learner does not own this invoice.']);
                }

                if ((float) $data['amount'] > (float) $invoice->balance) {
                    throw ValidationException::withMessages(['amount' => 'Payment cannot exceed the invoice balance.']);
                }

                $data['student_id'] = $invoice->student_id;
            }

            $payment = Payment::create([
                'school_id' => $school->id,
                'student_id' => $data['student_id'] ?? null,
                'finance_account_id' => $account->id,
                'fee_invoice_id' => $invoice?->id,
                'receipt_number' => $receipts->next($school->id),
                'amount' => $data['amount'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'payer_name' => $data['payer_name'] ?? $invoice?->student?->name,
                'status' => 'confirmed',
                'paid_at' => now(),
                'received_by' => $request->user()->id,
            ]);

            if ($invoice) {
                $invoice->balance = round((float) $invoice->balance - (float) $data['amount'], 2);
                $invoice->status = $invoice->balance <= 0 ? 'paid' : 'partial';
                $invoice->save();
            }

            return $payment;
        });

        $this->notifyPayer($payment);

        return $this->ok($this->paymentPayload($payment->fresh(['student', 'account', 'invoice'])), [], 201);
    }
    public function storeWithdrawal(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'finance');

        abort_unless($this->user($request)->can('finance.manage'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'finance_account_id' => ['required', Rule::exists('finance_accounts', 'id')->where('school_id', $school->id)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'payee' => ['required', 'string', 'max:160'],
            'purpose' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:120'],
            'withdrawn_on' => ['nullable', 'date'],
        ]);

        $withdrawal = Withdrawal::create([
            'school_id' => $school->id,
            'finance_account_id' => $data['finance_account_id'],
            'amount' => $data['amount'],
            'payee' => $data['payee'],
            'purpose' => $data['purpose'],
            'reference' => $data['reference'] ?? null,
            'withdrawn_on' => $data['withdrawn_on'] ?? now()->toDateString(),
            'approved_by' => $request->user()->id,
        ]);

        return $this->ok($this->withdrawalPayload($withdrawal->load('account:id,name,code')), [], 201);
    }

    /**
     * Notify the payer (and guardians) that a payment was received.
     */
    private function notifyPayer(Payment $payment): void
    {
        $recipients = collect();

        if ($payment->student_id) {
            $student = User::find($payment->student_id);

            if ($student) {
                $recipients->push($student);
                $recipients = $recipients->merge($student->guardians()->get());
            }
        }

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send($recipients->unique('id'), new PaymentAcknowledged($payment->fresh(['account', 'student'])));
        } catch (\Throwable) {
            // Notifications must never block a payment from being recorded.
        }
    }

    private function scopedInvoices(int $schoolId, $childIds, Request $request): Builder
    {
        return FeeInvoice::where('school_id', $schoolId)
            ->when($childIds->isNotEmpty(), fn ($q) => $q->whereIn('student_id', $childIds))
            ->when($request->integer('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with(['student:id,name,admission_number', 'account:id,name,code', 'term:id,name'])
            ->latest();
    }

    private function scopedPayments(int $schoolId, $childIds, Request $request): Builder
    {
        return Payment::where('school_id', $schoolId)
            ->when($childIds->isNotEmpty(), fn ($q) => $q->whereIn('student_id', $childIds))
            ->when($request->integer('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->with(['student:id,name,admission_number', 'account:id,name,code', 'invoice:id,amount,balance'])
            ->latest('paid_at');
    }
    /**
     * Accounts with the running balance managers need to see.
     */
    private function accounts(int $schoolId, $childIds, bool $canManage): array
    {
        $accounts = FinanceAccount::where('school_id', $schoolId)
            ->when(! $canManage, fn ($q) => $q->whereHas('invoices', fn ($invoices) => $invoices->whereIn('student_id', $childIds)))
            ->withCount('invoices')
            ->orderBy('name')
            ->get();

        if (! $canManage) {
            return $accounts->map(fn (FinanceAccount $account) => $this->accountPayload($account))->all();
        }

        $receipts = Payment::where('school_id', $schoolId)->where('status', 'confirmed')
            ->selectRaw('finance_account_id, SUM(amount) as total')->groupBy('finance_account_id')->pluck('total', 'finance_account_id');
        $withdrawals = Withdrawal::where('school_id', $schoolId)
            ->selectRaw('finance_account_id, SUM(amount) as total')->groupBy('finance_account_id')->pluck('total', 'finance_account_id');

        return $accounts->map(fn (FinanceAccount $account) => $this->accountPayload($account, [
            'available_balance' => round((float) ($receipts[$account->id] ?? 0) - (float) ($withdrawals[$account->id] ?? 0), 2),
        ]))->all();
    }

    private function accountPayload(FinanceAccount $account, array $extra = []): array
    {
        return array_merge([
            'id' => $account->id,
            'name' => $account->name,
            'code' => $account->code,
            'type' => $account->type,
            'amount' => (float) $account->amount,
            'term_scope' => $account->term_scope,
            'is_active' => (bool) $account->is_active,
            'invoices_count' => $account->invoices_count,
        ], $extra);
    }

    private function invoicePayload(FeeInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'student' => $this->userPayload($invoice->student),
            'account' => $invoice->account ? [
                'id' => $invoice->account->id,
                'name' => $invoice->account->name,
                'code' => $invoice->account->code,
            ] : null,
            'term' => $invoice->term?->name,
            'amount' => (float) $invoice->amount,
            'balance' => (float) $invoice->balance,
            'status' => $invoice->status,
            'due_on' => $invoice->due_on?->toDateString(),
            'updated_at' => $this->timestamped($invoice->updated_at),
        ];
    }

    private function paymentPayload(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'receipt_number' => $payment->receipt_number,
            'student' => $this->userPayload($payment->student),
            'account' => $payment->account ? [
                'id' => $payment->account->id,
                'name' => $payment->account->name,
                'code' => $payment->account->code,
            ] : null,
            'amount' => (float) $payment->amount,
            'method' => $payment->method,
            'reference' => $payment->reference,
            'payer_name' => $payment->payer_name,
            'status' => $payment->status,
            'paid_at' => $this->timestamped($payment->paid_at),
            'invoice_balance' => $payment->invoice ? (float) $payment->invoice->balance : null,
        ];
    }

    private function withdrawalPayload(Withdrawal $withdrawal): array
    {
        return [
            'id' => $withdrawal->id,
            'account' => $withdrawal->account ? [
                'id' => $withdrawal->account->id,
                'name' => $withdrawal->account->name,
                'code' => $withdrawal->account->code,
            ] : null,
            'amount' => (float) $withdrawal->amount,
            'payee' => $withdrawal->payee,
            'purpose' => $withdrawal->purpose,
            'reference' => $withdrawal->reference,
            'withdrawn_on' => $withdrawal->withdrawn_on?->toDateString(),
        ];
    }
}

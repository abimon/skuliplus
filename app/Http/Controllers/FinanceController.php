<?php

namespace App\Http\Controllers;

use App\Models\FeeInvoice;
use App\Models\FinanceAccount;
use App\Models\Payment;
use App\Models\School;
use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\PaymentAcknowledged;
use App\Services\FeeProvisioningService;
use App\Services\ReceiptNumberService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

class FinanceController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request, 'finance.view');
        $user = $request->user();
        $schoolId = $user->school_id;
        abort_unless($schoolId, 403);
        $canManage = $user->can('finance.manage');
        $childIds = $canManage ? collect() : $user->children()->where('users.school_id', $schoolId)->pluck('users.id');

        $invoiceQuery = FeeInvoice::where('school_id', $schoolId)
            ->when(! $canManage, fn (Builder $query) => $query->whereIn('student_id', $childIds))
            ->with(['student:id,name,admission_number', 'account:id,name,code', 'term:id,name']);
        $paymentQuery = Payment::where('school_id', $schoolId)
            ->when(! $canManage, fn (Builder $query) => $query->whereIn('student_id', $childIds))
            ->with(['student:id,name,admission_number', 'account:id,name,code', 'invoice:id,amount,balance']);
        $outstanding = (clone $invoiceQuery)->where('status', '!=', 'paid')->sum('balance');
        $received = (clone $paymentQuery)->where('status', 'confirmed')->sum('amount');
        $accounts = FinanceAccount::where('school_id', $schoolId)
            ->when(! $canManage, fn (Builder $query) => $query->whereHas('invoices', fn (Builder $invoices) => $invoices->whereIn('student_id', $childIds)))
            ->withCount('invoices')
            ->orderBy('name')
            ->get();
        if ($canManage) {
            $accountReceipts = Payment::where('school_id', $schoolId)->where('status', 'confirmed')
                ->selectRaw('finance_account_id, SUM(amount) as total')->groupBy('finance_account_id')->pluck('total', 'finance_account_id');
            $accountWithdrawals = Withdrawal::where('school_id', $schoolId)
                ->selectRaw('finance_account_id, SUM(amount) as total')->groupBy('finance_account_id')->pluck('total', 'finance_account_id');
            $accounts->each(fn (FinanceAccount $account) => $account->setAttribute(
                'available_balance',
                round((float) ($accountReceipts[$account->id] ?? 0) - (float) ($accountWithdrawals[$account->id] ?? 0), 2)
            ));
        }

        return Inertia::render('finance/index', [
            'school' => $user->school()->first(['id', 'name', 'code', 'county', 'logo_path', 'primary_color', 'secondary_color', 'motto', 'po_box', 'phone', 'email']),
            'accounts' => $accounts,
            'invoices' => $invoiceQuery->latest()->limit(100)->get(),
            'payments' => $paymentQuery->latest('paid_at')->limit(50)->get(),
            'withdrawals' => $canManage
                ? Withdrawal::where('school_id', $schoolId)->with('account:id,name,code')->latest('withdrawn_at')->limit(30)->get()
                : collect(),
            'students' => $canManage
                ? User::role('student')->where('school_id', $schoolId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'admission_number'])
                : collect(),
            'stats' => [
                'outstanding' => $outstanding,
                'received' => $received,
                'withdrawn' => $canManage ? Withdrawal::where('school_id', $schoolId)->sum('amount') : 0,
            ],
            'can' => [
                'manage' => $canManage,
                'record_payment' => $user->can('payments.record'),
            ],
            'modules' => collect([
                ['key' => 'users', 'name' => 'People', 'permission' => 'users.view', 'href' => $user->can('users.update') ? '/users' : null],
                ['key' => 'academics', 'name' => 'Academics', 'permission' => 'classes.view', 'href' => '/academics'],
                ['key' => 'curriculum', 'name' => 'Curriculum', 'permission' => 'classes.view'],
                ['key' => 'finance', 'name' => 'Finance', 'permission' => 'finance.view', 'href' => $user->can('finance.manage') ? '/finance' : null],
                ['key' => 'library', 'name' => 'Library', 'permission' => 'library.view'],
                ['key' => 'gate', 'name' => 'Gate visits', 'permission' => 'gate.view'],
                ['key' => 'stores', 'name' => 'Stores', 'permission' => 'stores.view'],
                ['key' => 'activities', 'name' => 'Activities', 'permission' => 'clubs.view'],
                ['key' => 'labs', 'name' => 'Laboratories', 'permission' => 'labs.view'],
            ])->filter(fn (array $module) => $user->can($module['permission']))
                ->map(fn (array $module) => collect($module)->except('permission')->all())
                ->values(),
        ]);
    }

    public function storeAccount(Request $request, FeeProvisioningService $provisioner): RedirectResponse
    {
        $this->authorizePermission($request, 'finance.manage');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'code' => ['required', 'string', 'max:40', Rule::unique('finance_accounts', 'code')->where('school_id', $schoolId)],
            'type' => 'required|in:fee,boarding,transport,other',
            'amount' => 'required|numeric|gt:0|max:999999999',
            'term_scope' => 'required|in:term,annual',
        ]);

        $count = DB::transaction(function () use ($data, $schoolId, $provisioner) {
            $account = FinanceAccount::create(['school_id' => $schoolId, ...$data, 'is_active' => true]);

            return $provisioner->provisionAccount($account);
        });

        return back()->with('success', "Account created. {$count} pending learner invoices were provisioned.");
    }

    public function storePayment(Request $request, ReceiptNumberService $receipts): RedirectResponse
    {
        $this->authorizePermission($request, 'payments.record');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $data = $request->validate([
            'finance_account_id' => ['required', Rule::exists('finance_accounts', 'id')->where('school_id', $schoolId)->where('is_active', true)],
            'fee_invoice_id' => ['nullable', Rule::exists('fee_invoices', 'id')->where('school_id', $schoolId)],
            'student_id' => ['nullable', Rule::exists('users', 'id')->where('school_id', $schoolId)],
            'amount' => 'required|numeric|gt:0|max:999999999',
            'method' => 'required|in:cash,mpesa,bank,cheque,online',
            'reference' => [Rule::requiredIf(fn () => in_array($request->input('method'), ['mpesa', 'bank', 'cheque', 'online'], true)), 'nullable', 'string', 'max:120'],
            'payer_name' => 'nullable|string|max:120',
        ]);

        if (! empty($data['student_id']) && ! User::role('student')->where('school_id', $schoolId)->whereKey($data['student_id'])->exists()) {
            throw ValidationException::withMessages(['student_id' => 'Choose a learner in this school.']);
        }

        $payment = DB::transaction(function () use ($data, $schoolId, $request, $receipts) {
            School::whereKey($schoolId)->lockForUpdate()->firstOrFail();
            $account = FinanceAccount::where('school_id', $schoolId)->lockForUpdate()->findOrFail($data['finance_account_id']);
            $invoice = null;

            if (! empty($data['fee_invoice_id'])) {
                $invoice = FeeInvoice::where('school_id', $schoolId)->lockForUpdate()->findOrFail($data['fee_invoice_id']);
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
                'school_id' => $schoolId,
                'student_id' => $data['student_id'] ?? null,
                'finance_account_id' => $account->id,
                'fee_invoice_id' => $invoice?->id,
                'receipt_number' => $receipts->next($schoolId),
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

        return back()->with('success', "Payment recorded. Receipt {$payment->receipt_number}.");
    }

    public function storeWithdrawal(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'finance.manage');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $data = $request->validate([
            'finance_account_id' => ['required', Rule::exists('finance_accounts', 'id')->where('school_id', $schoolId)],
            'amount' => 'required|numeric|gt:0|max:999999999',
            'payee' => 'required|string|max:160',
            'purpose' => 'required|string|max:255',
            'reference' => 'nullable|string|max:120',
        ]);

        DB::transaction(function () use ($data, $schoolId, $request) {
            $account = FinanceAccount::where('school_id', $schoolId)->lockForUpdate()->findOrFail($data['finance_account_id']);
            $received = (float) Payment::where('school_id', $schoolId)->where('finance_account_id', $account->id)->where('status', 'confirmed')->sum('amount');
            $withdrawn = (float) Withdrawal::where('school_id', $schoolId)->where('finance_account_id', $account->id)->sum('amount');
            if ((float) $data['amount'] > round($received - $withdrawn, 2)) {
                throw ValidationException::withMessages(['amount' => 'Withdrawal exceeds the available cleared balance in this account.']);
            }

            Withdrawal::create([
                'school_id' => $schoolId,
                'finance_account_id' => $account->id,
                'amount' => $data['amount'],
                'payee' => $data['payee'],
                'purpose' => $data['purpose'],
                'reference' => $data['reference'] ?? null,
                'processed_by' => $request->user()->id,
                'withdrawn_at' => now(),
            ]);
        });

        return back()->with('success', 'Withdrawal recorded.');
    }

    public function receipt(Request $request, Payment $payment): Response
    {
        $payment = $this->authorizedPayment($request, $payment);

        return Inertia::render('finance/receipt', [
            'payment' => $payment->only(['id', 'receipt_number', 'amount', 'method', 'reference', 'payer_name', 'paid_at']),
            'student' => $payment->student?->only(['id', 'name', 'admission_number']),
            'account' => $payment->account->only(['name', 'code']),
            'invoice' => $payment->invoice?->only(['amount', 'balance', 'status']),
            'school' => $payment->account->school->only(['name', 'code', 'logo_path', 'primary_color', 'secondary_color', 'motto', 'county', 'phone', 'email', 'po_box']),
        ]);
    }

    public function downloadReceipt(Request $request, Payment $payment): HttpResponse
    {
        $payment = $this->authorizedPayment($request, $payment);
        $school = $payment->account->school;

        return Pdf::loadView('finance.receipt', [
            'payment' => $payment,
            'school' => $school,
        ])->setPaper('a4')->download('receipt-'.$payment->receipt_number.'.pdf');
    }

    private function authorizedPayment(Request $request, Payment $payment): Payment
    {
        $user = $request->user();
        abort_unless($payment->school_id === $user->school_id, 404);

        if (! $user->can('finance.manage') && ! $user->hasRole('finance_officer')) {
            abort_unless($user->hasRole('parent') && $payment->student_id && $user->children()
                ->where('users.school_id', $user->school_id)
                ->whereKey($payment->student_id)
                ->exists(), 404);
        }

        return $payment->load(['student', 'invoice', 'account.school']);
    }

    private function notifyPayer(Payment $payment): void
    {
        $student = $payment->student;
        $recipient = $student?->guardians()
            ->where('users.school_id', $payment->school_id)
            ->whereNotNull('users.email')
            ->orderByPivot('is_primary', 'desc')
            ->first();

        if (! $recipient) {
            return;
        }

        try {
            $recipient->notify(new PaymentAcknowledged($payment->load('account.school')));
        } catch (Throwable $exception) {
            Log::warning('Payment was recorded but its acknowledgement could not be delivered.', [
                'receipt_number' => $payment->receipt_number,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403);
    }
}

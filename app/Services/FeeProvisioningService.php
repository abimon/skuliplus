<?php

namespace App\Services;

use App\Models\FeeInvoice;
use App\Models\FinanceAccount;
use App\Models\Term;
use App\Models\User;

class FeeProvisioningService
{
    public function provisionAccount(FinanceAccount $account): int
    {
        $term = $account->term_scope === 'annual'
            ? null
            : Term::where('school_id', $account->school_id)->where('is_current', true)->first();

        $students = User::role('student')
            ->where('school_id', $account->school_id)
            ->where('status', 'active')
            ->get();

        $count = 0;

        foreach ($students as $student) {
            $exists = FeeInvoice::where('student_id', $student->id)
                ->where('finance_account_id', $account->id)
                ->when($term, fn ($q) => $q->where('term_id', $term->id), fn ($q) => $q->whereNull('term_id'))
                ->exists();

            if ($exists) {
                continue;
            }

            FeeInvoice::create([
                'school_id' => $account->school_id,
                'student_id' => $student->id,
                'finance_account_id' => $account->id,
                'term_id' => $term?->id,
                'amount' => $account->amount,
                'balance' => $account->amount,
                'status' => 'pending',
            ]);
            $count++;
        }

        return $count;
    }
}

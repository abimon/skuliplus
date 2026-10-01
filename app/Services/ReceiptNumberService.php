<?php

namespace App\Services;

use App\Models\Payment;

class ReceiptNumberService
{
    public function next(int $schoolId): string
    {
        $prefix = 'RCP-'.now()->format('Ymd').'-'.$schoolId.'-';
        $last = Payment::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('receipt_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('receipt_number');

        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}

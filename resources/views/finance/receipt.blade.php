<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $payment->receipt_number }}</title>
    <style>
        @page { margin: 34px; }
        body { color: #26392d; font-family: DejaVu Sans, sans-serif; font-size: 11px; }
        .topbar { background: {{ $school->primary_color }}; height: 7px; margin-bottom: 26px; }
        .footerbar { background: {{ $school->secondary_color }}; height: 4px; margin-top: 28px; }
        .header { border-bottom: 1px solid #e0e6de; padding-bottom: 18px; width: 100%; }
        .logo { height: 54px; margin-right: 14px; max-width: 54px; object-fit: contain; vertical-align: middle; }
        .school { display: inline-block; vertical-align: middle; }
        .school h1 { color: {{ $school->primary_color }}; font-size: 18px; margin: 0 0 6px; }
        .muted { color: #718076; font-size: 9px; line-height: 1.6; }
        .receipt-meta { float: right; text-align: right; }
        .receipt-title { color: #718076; font-size: 9px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        .receipt-number { color: #314c37; font-family: DejaVu Sans Mono, monospace; font-size: 14px; font-weight: bold; margin-top: 7px; }
        .section { margin-top: 24px; width: 100%; }
        .section td { vertical-align: top; width: 50%; }
        .label { color: #7b887e; font-size: 9px; font-weight: bold; letter-spacing: .6px; text-transform: uppercase; }
        .value { color: #35483a; font-size: 12px; font-weight: bold; margin-top: 8px; }
        .items { border: 1px solid #e2e8e0; border-collapse: collapse; margin-top: 28px; width: 100%; }
        .items th { background: #f3f6f0; color: #718076; font-size: 9px; letter-spacing: .5px; padding: 11px; text-align: left; text-transform: uppercase; }
        .items td { border-top: 1px solid #e8ece5; padding: 13px 11px; }
        .right { text-align: right !important; }
        .total { color: #294a34; font-size: 14px; font-weight: bold; }
        .footer { border-top: 1px solid #e0e6de; margin-top: 44px; padding-top: 15px; width: 100%; }
        .sign { border-top: 1px solid #aab6aa; color: #829087; padding-top: 6px; text-align: center; width: 150px; }
    </style>
</head>
<body>
    @php
        $logo = $school->logo_path ? public_path('storage/'.$school->logo_path) : null;
        $student = $payment->student;
        $invoice = $payment->invoice;
    @endphp
    <div class="topbar"></div>
    <div class="header">
        @if ($logo && file_exists($logo))
            <img class="logo" src="{{ $logo }}" alt="">
        @endif
        <div class="school">
            <h1>{{ $school->name }}</h1>
            <div class="muted">{{ $school->county }} County · {{ $school->code }}</div>
            <div class="muted">{{ collect([$school->phone, $school->email, $school->po_box])->filter()->implode(' · ') }}</div>
        </div>
        <div class="receipt-meta">
            <div class="receipt-title">Official receipt</div>
            <div class="receipt-number">{{ $payment->receipt_number }}</div>
            <div class="muted">{{ optional($payment->paid_at)->format('d M Y, H:i') }}</div>
        </div>
    </div>

    <table class="section">
        <tr>
            <td><div class="label">Received from</div><div class="value">{{ $student?->name ?? $payment->payer_name ?? 'Walk-in payer' }}</div><div class="muted">{{ $student?->admission_number }}</div></td>
            <td><div class="label">Paid into</div><div class="value">{{ $payment->account->name }}</div><div class="muted">Account {{ $payment->account->code }}</div></td>
        </tr>
    </table>

    <table class="items">
        <thead><tr><th>Description</th><th class="right">Amount</th></tr></thead>
        <tbody>
            <tr>
                <td>{{ $invoice ? 'Fee payment · '.$invoice->status : 'School payment' }}<div class="muted">{{ strtoupper($payment->method) }}{{ $payment->reference ? ' · Reference '.$payment->reference : '' }}</div></td>
                <td class="right">KES {{ number_format((float) $payment->amount, 2) }}</td>
            </tr>
            <tr><td class="right total">Total paid</td><td class="right total">KES {{ number_format((float) $payment->amount, 2) }}</td></tr>
        </tbody>
    </table>

    @if ($invoice)
        <p class="right muted">Invoice amount KES {{ number_format((float) $invoice->amount, 2) }}<br>Balance remaining KES {{ number_format((float) $invoice->balance, 2) }}</p>
    @endif

    <table class="footer">
        <tr>
            <td><strong>{{ $school->motto ?? 'Thank you for your payment.' }}</strong><div class="muted">Receipt reference: {{ $payment->receipt_number }}</div></td>
            <td><div class="sign">Authorized by</div></td>
        </tr>
    </table>
    <div class="footerbar"></div>
</body>
</html>
*** End Patch
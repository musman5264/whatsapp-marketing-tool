<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 13px; color: #1a1a1a; padding: 40px; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; }
        .company-name { font-size: 22px; font-weight: bold; color: #4f46e5; }
        .invoice-title { font-size: 28px; font-weight: bold; color: #374151; text-align: right; }
        .invoice-meta { text-align: right; margin-top: 6px; color: #6b7280; font-size: 12px; }
        .section { margin-bottom: 30px; }
        .section-title { font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; margin-bottom: 8px; }
        .bill-to { font-size: 14px; }
        table { width: 100%; border-collapse: collapse; }
        thead tr { background-color: #f3f4f6; }
        thead th { padding: 10px 12px; text-align: left; font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; }
        tbody td { padding: 12px; border-bottom: 1px solid #e5e7eb; }
        .total-section { margin-top: 20px; text-align: right; }
        .total-row { display: flex; justify-content: flex-end; margin-bottom: 6px; }
        .total-label { width: 160px; color: #6b7280; }
        .total-value { width: 100px; text-align: right; font-weight: bold; }
        .grand-total { font-size: 16px; color: #1a1a1a; border-top: 2px solid #e5e7eb; padding-top: 10px; margin-top: 10px; }
        .status-badge { display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: bold; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-refunded { background: #fee2e2; color: #991b1b; }
        .footer { margin-top: 60px; border-top: 1px solid #e5e7eb; padding-top: 20px; text-align: center; color: #9ca3af; font-size: 11px; }
    </style>
</head>
<body>

<div class="header">
    <div>
        <div class="company-name">{{ $app_name }}</div>
        @if($support_email)
            <div style="color:#6b7280; font-size:12px; margin-top:4px;">{{ $support_email }}</div>
        @endif
    </div>
    <div>
        <div class="invoice-title">INVOICE</div>
        <div class="invoice-meta">
            <div>{{ $invoice_number }}</div>
            <div>Issued: {{ $issued_at }}</div>
        </div>
    </div>
</div>

<div class="section">
    <div class="section-title">Billed To</div>
    <div class="bill-to">
        <div>{{ $user->name }}</div>
        <div>{{ $user->email }}</div>
    </div>
</div>

<div class="section">
    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th style="text-align:right;">Amount</th>
                <th style="text-align:center;">Status</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    {{ $plan ? $plan->name . ' Plan' : 'Subscription' }}
                    @if($coupon)
                        <br><small style="color:#6b7280;">Coupon: {{ $coupon->code }}</small>
                    @endif
                </td>
                <td style="text-align:right;">
                    {{ strtoupper($transaction->currency ?? $transaction->currency_code ?? 'USD') }}
                    {{ number_format($transaction->amount_cents / 100, 2) }}
                </td>
                <td style="text-align:center;">
                    @if($transaction->status === 'refunded')
                        <span class="status-badge status-refunded">Refunded</span>
                    @else
                        <span class="status-badge status-paid">Paid</span>
                    @endif
                </td>
            </tr>
        </tbody>
    </table>
</div>

<div class="total-section">
    @if($transaction->tax_amount_cents)
        <div class="total-row">
            <div class="total-label">Subtotal</div>
            <div class="total-value">{{ number_format(($transaction->amount_cents - $transaction->tax_amount_cents) / 100, 2) }}</div>
        </div>
        <div class="total-row">
            <div class="total-label">Tax</div>
            <div class="total-value">{{ number_format($transaction->tax_amount_cents / 100, 2) }}</div>
        </div>
    @endif
    @if($transaction->refunded_cents)
        <div class="total-row">
            <div class="total-label">Refunded</div>
            <div class="total-value" style="color:#dc2626;">- {{ number_format($transaction->refunded_cents / 100, 2) }}</div>
        </div>
    @endif
    <div class="total-row grand-total">
        <div class="total-label" style="font-weight:bold;">Total</div>
        <div class="total-value" style="font-size:16px;">
            {{ strtoupper($transaction->currency ?? $transaction->currency_code ?? 'USD') }}
            {{ number_format($transaction->amount_cents / 100, 2) }}
        </div>
    </div>
</div>

<div class="footer">
    Thank you for your business &mdash; {{ $app_name }}
    @if($support_email)
        &bull; {{ $support_email }}
    @endif
</div>

</body>
</html>

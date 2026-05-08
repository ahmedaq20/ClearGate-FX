<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body { direction: rtl; text-align: right; color: #111827; font-size: 13px; }
        .page { width: 100%; }
        .header { text-align: center; border-bottom: 1px solid #d1d5db; padding-bottom: 14px; margin-bottom: 18px; }
        .title { font-size: 22px; font-weight: bold; margin-bottom: 6px; }
        .muted { color: #6b7280; }
        .section { margin-top: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e5e7eb; padding: 8px; vertical-align: top; }
        th { background: #f3f4f6; font-weight: bold; }
        .total { font-size: 16px; font-weight: bold; }
        .footer { border-top: 1px solid #d1d5db; margin-top: 20px; padding-top: 12px; text-align: center; color: #6b7280; }
    </style>
</head>
<body>
@php
    $transaction = $report['transaction'];
    $settings = $report['settings'] ?? [];
@endphp
<div class="page">
    <div class="header">
        <div class="title">إيصال عملية</div>
        @if(($settings['receipt_show_phone'] ?? '1') === '1')
            <div class="muted">هاتف المحل</div>
        @endif
    </div>

    <table>
        <tr>
            <th>رقم الإيصال</th>
            <td>{{ $transaction->reference_number ?? 'TXN-'.$transaction->id }}</td>
            <th>التاريخ</th>
            <td>{{ $transaction->transaction_date?->format('Y-m-d') }}</td>
        </tr>
        <tr>
            <th>المستخدم</th>
            <td>{{ $transaction->user?->name }}</td>
            <th>العميل</th>
            <td>{{ $transaction->customer?->name ?? 'عملية عامة' }}</td>
        </tr>
        <tr>
            <th>نوع العملية</th>
            <td>{{ $transaction->type === 'receive' ? 'استلام' : 'تسليم' }}</td>
            <th>الدولة</th>
            <td>{{ $transaction->country ?? '-' }}</td>
        </tr>
    </table>

    <div class="section">
        <table>
            <tr>
                <th>المبلغ</th>
                <td>{{ number_format((float) $transaction->amount, 4) }} {{ $transaction->currency_code }}</td>
            </tr>
            <tr>
                <th>سعر الصرف</th>
                <td>{{ number_format((float) $transaction->exchange_rate, 6) }}</td>
            </tr>
            <tr>
                <th>القيمة بالدولار</th>
                <td>{{ number_format((float) $transaction->usd_value, 4) }} USD</td>
            </tr>
            <tr>
                <th>العمولة</th>
                <td>{{ number_format((float) $transaction->commission_usd, 4) }} USD</td>
            </tr>
            <tr>
                <th class="total">الصافي</th>
                <td class="total">{{ number_format((float) $transaction->net_usd_value, 4) }} USD</td>
            </tr>
        </table>
    </div>

    @if($transaction->note)
        <div class="section">
            <table>
                <tr>
                    <th>ملاحظة</th>
                    <td>{{ $transaction->note }}</td>
                </tr>
            </table>
        </div>
    @endif

    <div class="footer">
        {{ $settings['receipt_footer_text'] ?? 'شكراً لتعاملكم معنا' }}
    </div>
</div>
</body>
</html>

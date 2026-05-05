<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: "DejaVu Sans", sans-serif; direction: rtl; text-align: right; color: #111827; font-size: 12px; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        .meta, .totals { margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e5e7eb; padding: 7px; }
        th { background: #f3f4f6; }
        .num { direction: ltr; text-align: left; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
<h1>{{ $report['title'] }}</h1>
<table class="meta">
    <tr>
        <th>العميل</th>
        <td>{{ $report['customer']->name }}</td>
        <th>الفترة</th>
        <td class="num">{{ $report['date_from'] }} - {{ $report['date_to'] }}</td>
    </tr>
    <tr>
        <th>المستخدم</th>
        <td>{{ $report['customer']->user?->name }}</td>
        <th>تاريخ الإنشاء</th>
        <td class="num">{{ $report['generated_at']->format('Y-m-d H:i') }}</td>
    </tr>
</table>

<table class="totals">
    <tr>
        <th>الرصيد الافتتاحي</th>
        <th>إجمالي الاستلام</th>
        <th>إجمالي التسليم</th>
        <th>الصافي</th>
        <th>الرصيد الختامي</th>
    </tr>
    <tr>
        <td class="num">{{ number_format($report['opening_balance_usd'], 4) }}</td>
        <td class="num">{{ number_format($report['totals']['receive'], 4) }}</td>
        <td class="num">{{ number_format($report['totals']['send'], 4) }}</td>
        <td class="num">{{ number_format($report['totals']['net'], 4) }}</td>
        <td class="num">{{ number_format($report['closing_balance_usd'], 4) }}</td>
    </tr>
</table>

<table>
    <thead>
        <tr>
            <th>التاريخ</th>
            <th>النوع</th>
            <th>العملة</th>
            <th>المبلغ</th>
            <th>القيمة USD</th>
            <th>الصافي USD</th>
            <th>المرجع</th>
        </tr>
    </thead>
    <tbody>
        @forelse($report['transactions'] as $transaction)
            <tr>
                <td class="num">{{ $transaction->transaction_date?->format('Y-m-d') }}</td>
                <td>{{ $transaction->type === 'receive' ? 'استلام' : 'تسليم' }}</td>
                <td>{{ $transaction->currency_code }}</td>
                <td class="num">{{ number_format((float) $transaction->amount, 4) }}</td>
                <td class="num">{{ number_format((float) $transaction->usd_value, 4) }}</td>
                <td class="num">{{ number_format((float) $transaction->net_usd_value, 4) }}</td>
                <td>{{ $transaction->reference_number ?? '-' }} @if($transaction->trashed()) <span class="muted">(محذوف)</span> @endif</td>
            </tr>
        @empty
            <tr><td colspan="7">لا توجد عمليات ضمن الفترة.</td></tr>
        @endforelse
    </tbody>
</table>
</body>
</html>

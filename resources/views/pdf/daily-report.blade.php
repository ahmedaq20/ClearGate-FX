<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: "DejaVu Sans", sans-serif; direction: rtl; text-align: right; color: #111827; font-size: 12px; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        th, td { border: 1px solid #e5e7eb; padding: 7px; }
        th { background: #f3f4f6; }
        .num { direction: ltr; text-align: left; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
<h1>{{ $report['title'] }}</h1>
<table>
    <tr>
        <th>التاريخ</th>
        <td class="num">{{ $report['date'] }}</td>
        <th>المستخدم</th>
        <td>{{ $report['user']?->name ?? 'كل المستخدمين' }}</td>
        <th>تاريخ الإنشاء</th>
        <td class="num">{{ $report['generated_at']->format('Y-m-d H:i') }}</td>
    </tr>
</table>

<table>
    <tr>
        <th>إجمالي الاستلام</th>
        <th>إجمالي التسليم</th>
        <th>الصافي</th>
        <th>عدد العمليات</th>
    </tr>
    <tr>
        <td class="num">{{ number_format($report['totals']['receive'], 4) }}</td>
        <td class="num">{{ number_format($report['totals']['send'], 4) }}</td>
        <td class="num">{{ number_format($report['totals']['net'], 4) }}</td>
        <td class="num">{{ $report['totals']['count'] }}</td>
    </tr>
</table>

<table>
    <thead>
        <tr>
            <th>المرجع</th>
            <th>النوع</th>
            <th>العميل</th>
            <th>العملة</th>
            <th>المبلغ</th>
            <th>الصافي USD</th>
        </tr>
    </thead>
    <tbody>
        @forelse($report['transactions'] as $transaction)
            <tr>
                <td>{{ $transaction->reference_number ?? 'TXN-'.$transaction->id }} @if($transaction->trashed()) <span class="muted">(محذوف)</span> @endif</td>
                <td>{{ $transaction->type === 'receive' ? 'استلام' : 'تسليم' }}</td>
                <td>{{ $transaction->customer?->name ?? '-' }}</td>
                <td>{{ $transaction->currency_code }}</td>
                <td class="num">{{ number_format((float) $transaction->amount, 4) }}</td>
                <td class="num">{{ number_format((float) $transaction->net_usd_value, 4) }}</td>
            </tr>
        @empty
            <tr><td colspan="6">لا توجد عمليات في هذا التاريخ.</td></tr>
        @endforelse
    </tbody>
</table>
</body>
</html>

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
    </style>
</head>
<body>
<h1>{{ $report['title'] }}</h1>
<table>
    <tr>
        <th>الفترة</th>
        <td class="num">{{ $report['date_from'] }} - {{ $report['date_to'] }}</td>
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

@if(($report['type'] ?? null) === 'comparison')
    <table>
        <thead>
            <tr>
                <th>المستخدم</th>
                <th>الاستلام</th>
                <th>التسليم</th>
                <th>الصافي</th>
                <th>عدد العمليات</th>
            </tr>
        </thead>
        <tbody>
            @forelse($report['rows'] as $row)
                <tr>
                    <td>{{ $row['user_name'] }}</td>
                    <td class="num">{{ number_format($row['receive'], 4) }}</td>
                    <td class="num">{{ number_format($row['send'], 4) }}</td>
                    <td class="num">{{ number_format($row['net'], 4) }}</td>
                    <td class="num">{{ $row['count'] }}</td>
                </tr>
            @empty
                <tr><td colspan="5">لا توجد بيانات ضمن الفترة.</td></tr>
            @endforelse
        </tbody>
    </table>
@else
    <table>
        <thead>
            <tr>
                <th>التاريخ</th>
                <th>الاستلام</th>
                <th>التسليم</th>
                <th>الصافي</th>
                <th>عدد العمليات</th>
            </tr>
        </thead>
        <tbody>
            @forelse($report['daily_totals'] as $row)
                <tr>
                    <td class="num">{{ $row['date'] }}</td>
                    <td class="num">{{ number_format($row['receive'], 4) }}</td>
                    <td class="num">{{ number_format($row['send'], 4) }}</td>
                    <td class="num">{{ number_format($row['net'], 4) }}</td>
                    <td class="num">{{ $row['count'] }}</td>
                </tr>
            @empty
                <tr><td colspan="5">لا توجد عمليات ضمن الشهر.</td></tr>
            @endforelse
        </tbody>
    </table>
@endif
</body>
</html>

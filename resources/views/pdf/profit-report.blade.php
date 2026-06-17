<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body { direction: rtl; text-align: right; color: #111827; font-size: 12px; }
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
        <th>من تاريخ</th>
        <td class="num">{{ $report['date_from'] ?? '-' }}</td>
        <th>إلى تاريخ</th>
        <td class="num">{{ $report['date_to'] ?? '-' }}</td>
        <th>تاريخ الإنشاء</th>
        <td class="num">{{ $report['generated_at']->format('Y-m-d H:i') }}</td>
    </tr>
</table>

@if(($report['type'] ?? null) === 'profit-summary')
    <table>
        <tr>
            <th>إجمالي العمليات</th>
            <th>المكتملة</th>
            <th>المعلقة</th>
            <th>الملغاة</th>
            <th>إجمالي الربح USD</th>
        </tr>
        <tr>
            <td class="num">{{ $report['total_operations'] }}</td>
            <td class="num">{{ $report['completed_operations'] }}</td>
            <td class="num">{{ $report['pending_operations'] }}</td>
            <td class="num">{{ $report['cancelled_operations'] }}</td>
            <td class="num">{{ number_format($report['total_profit_usd'], 4) }}</td>
        </tr>
    </table>
@else
    <table>
        <thead>
            <tr>
                @if(($report['type'] ?? null) === 'daily-profit')
                    <th>التاريخ</th>
                @elseif(($report['type'] ?? null) === 'monthly-profit')
                    <th>الشهر</th>
                @elseif(($report['type'] ?? null) === 'profit-by-supplier')
                    <th>المورد</th>
                @else
                    <th>الموظف</th>
                @endif
                <th>عدد العمليات</th>
                <th>إجمالي الربح USD</th>
            </tr>
        </thead>
        <tbody>
            @forelse($report['rows'] as $row)
                <tr>
                    <td>
                        {{ $row['date'] ?? $row['month'] ?? $row['supplier'] ?? $row['employee'] ?? '-' }}
                    </td>
                    <td class="num">{{ $row['operations_count'] }}</td>
                    <td class="num">{{ number_format($row['total_profit_usd'], 4) }}</td>
                </tr>
            @empty
                <tr><td colspan="3">لا توجد بيانات ضمن الفترة.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table>
        <tr>
            <th>إجمالي الربح USD</th>
            <td class="num">{{ number_format($report['total_profit_usd'], 4) }}</td>
        </tr>
    </table>
@endif
</body>
</html>

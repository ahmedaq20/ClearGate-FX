<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        body { direction: rtl; text-align: right; color: #111827; font-size: 12px; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        th, td { border: 1px solid #e5e7eb; padding: 7px; vertical-align: top; }
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

@if(($report['type'] ?? null) === 'operations')
    <table>
        <tr>
            <th>إجمالي العمليات</th>
            <th>المكتملة</th>
            <th>المعلقة</th>
            <th>الملغاة</th>
            <th>إجمالي المبلغ المحول</th>
        </tr>
        <tr>
            <td class="num">{{ $report['total_operations'] }}</td>
            <td class="num">{{ $report['completed'] }}</td>
            <td class="num">{{ $report['pending'] }}</td>
            <td class="num">{{ $report['cancelled'] }}</td>
            <td class="num">{{ number_format($report['total_transferred_amount'], 4) }}</td>
        </tr>
    </table>
@elseif(($report['type'] ?? null) === 'commissions')
    <table>
        <tr>
            <th>إجمالي العمولة USD</th>
            <th>متوسط العمولة USD</th>
            <th>عدد العمليات</th>
            <th>الفترة</th>
        </tr>
        <tr>
            <td class="num">{{ number_format($report['total_commission'], 4) }}</td>
            <td class="num">{{ number_format($report['average_commission'], 4) }}</td>
            <td class="num">{{ $report['operation_count'] }}</td>
            <td>{{ $report['period'] }}</td>
        </tr>
    </table>
@elseif(in_array(($report['type'] ?? null), ['pending', 'cancelled'], true))
    <table>
        <thead>
            <tr>
                <th>المرجع</th>
                <th>المورد</th>
                <th>العميل</th>
                <th>المبلغ</th>
                <th>العمولة</th>
                @if(($report['type'] ?? null) === 'cancelled')
                    <th>سبب الإلغاء</th>
                    <th>تاريخ الإلغاء</th>
                @else
                    <th>تاريخ الإنشاء</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse($report['operations'] as $operation)
                <tr>
                    <td>{{ $operation['reference_number'] }}</td>
                    <td>{{ $operation['supplier'] ?? '-' }}</td>
                    <td>{{ $operation['customer'] ?? '-' }}</td>
                    <td class="num">{{ number_format($operation['amount'], 4) }}</td>
                    <td class="num">{{ number_format($operation['commission'], 4) }}</td>
                    @if(($report['type'] ?? null) === 'cancelled')
                        <td>{{ $operation['cancellation_reason'] ?? '-' }}</td>
                        <td class="num">{{ $operation['cancelled_at'] ?? '-' }}</td>
                    @else
                        <td class="num">{{ $operation['created_at'] ?? '-' }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="7">لا توجد بيانات ضمن الفترة.</td></tr>
            @endforelse
        </tbody>
    </table>
@else
    <table>
        <thead>
            <tr>
                @foreach(array_keys($report['rows'][0] ?? []) as $heading)
                    <th>{{ str_replace('_', ' ', $heading) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($report['rows'] ?? [] as $row)
                <tr>
                    @foreach($row as $value)
                        @php($displayValue = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $value)
                        <td class="{{ is_numeric($displayValue) ? 'num' : '' }}">{{ is_numeric($displayValue) ? number_format((float) $displayValue, 4) : ($displayValue ?? '-') }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td>لا توجد بيانات ضمن الفترة.</td></tr>
            @endforelse
        </tbody>
    </table>
@endif
</body>
</html>

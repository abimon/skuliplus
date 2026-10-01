<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $student['name'] }} results</title>
    <style>
        @page { margin: 30px; }
        body { color:#293a2e; font:10px DejaVu Sans,sans-serif; }
        .top,.bottom { height:6px; background:{{ $school['primary_color'] }}; }
        .bottom { height:4px; background:{{ $school['secondary_color'] }}; margin-top:24px; }
        .head { border-bottom:1px solid #dfe6dd; padding:16px 0; width:100%; }
        .brand { color:{{ $school['primary_color'] }}; font-size:17px; font-weight:bold; }
        .muted { color:#718076; font-size:8px; line-height:1.6; }
        .right { text-align:right; }
        .student { margin:20px 0; }
        h2 { color:#294833; font-size:13px; margin:20px 0 8px; }
        .stats { border-collapse:separate; border-spacing:5px; width:100%; }
        .stats td { background:#f1f5ef; padding:10px; width:25%; }
        .label { color:#7d897f; font-size:7px; text-transform:uppercase; }
        .value { color:#294833; font-size:13px; font-weight:bold; margin-top:5px; }
        .trend { border-collapse:collapse; width:100%; }
        .trend td { padding:5px; }
        .track { background:#e8eee6; height:8px; width:100%; }
        .bar { background:#54805e; height:8px; }
        table.results { border-collapse:collapse; width:100%; }
        .results th { background:#f2f5f0; color:#718076; font-size:7px; padding:8px; text-align:left; text-transform:uppercase; }
        .results td { border-bottom:1px solid #e7ece5; padding:7px 8px; }
    </style>
</head>
<body>
    <div class="top"></div>
    <table class="head"><tr><td><div class="brand">{{ $school['name'] }}</div><div class="muted">{{ $school['county'] }} County · {{ $school['code'] }}</div><div class="muted">{{ collect([$school['phone'], $school['email'], $school['po_box']])->filter()->implode(' · ') }}</div></td><td class="right"><strong>LEARNER RESULTS REPORT</strong><div class="muted">Generated {{ $generatedAt }}</div></td></tr></table>
    <div class="student"><strong>{{ $student['name'] }}</strong><div class="muted">{{ $student['admission_number'] }} · {{ $enrollment?->schoolClass?->name }} {{ $enrollment?->stream?->name ? '· '.$enrollment->stream->name : '' }} · {{ $enrollment?->academicYear?->name }}</div></div>
    <table class="stats"><tr><td><div class="label">Overall</div><div class="value">{{ $summary['percent'] === null ? '—' : $summary['percent'].'%' }}</div></td><td><div class="label">Marks earned</div><div class="value">{{ $summary['score'] }} / {{ $summary['possible'] }}</div></td><td><div class="label">Assessments</div><div class="value">{{ $summary['assessments'] }}</div></td><td><div class="label">Entries</div><div class="value">{{ $results->count() }}</div></td></tr></table>
    <h2>Performance trend</h2>
    @if ($trends->isEmpty())
        <p class="muted">No published assessment history yet.</p>
    @else
        <table class="trend">@foreach ($trends as $trend)<tr><td width="34%">{{ $trend['label'] }}</td><td><div class="track"><div class="bar" style="width:{{ min(100, $trend['percent']) }}%"></div></div></td><td class="right" width="8%">{{ $trend['percent'] }}%</td></tr>@endforeach</table>
    @endif
    <h2>Assessment detail</h2>
    <table class="results"><thead><tr><th>Year / term</th><th>Assessment</th><th>Learning area</th><th>Score</th><th>Band</th><th>Remark</th></tr></thead><tbody>@forelse ($results as $result)<tr><td>{{ $result->exam->term?->academicYear?->name }} · {{ $result->exam->term?->name }}</td><td>{{ $result->exam->name }}</td><td>{{ $result->subject->name }}</td><td>{{ $result->score }} / {{ $result->exam->max_score }}</td><td>{{ $result->grade }}</td><td>{{ $result->remark }}</td></tr>@empty<tr><td colspan="6">No published results available.</td></tr>@endforelse</tbody></table>
    <div class="bottom"></div><p class="muted">{{ $school['motto'] ?? 'Learn, grow, achieve.' }} · {{ $school['code'] }}</p>
</body>
</html>

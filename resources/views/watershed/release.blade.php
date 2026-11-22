<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#173e3b}
h1{font-size:23px}h2{font-size:15px}
td{padding:8px;border-bottom:1px solid #ddd;vertical-align:top;word-wrap:break-word}
table{width:100%;table-layout:fixed;border-collapse:collapse}tr{page-break-inside:avoid}
.label{width:30%}p{line-height:1.6}
</style>
</head>
<body>
@php
    $labels = [
        'parentName' => 'Parent / guardian', 'phone' => 'Primary phone',
        'child1Name' => 'Child name', 'child1Birthday' => 'Birthday',
        'child1Age' => 'Age at school-year cutoff', 'child1Grade' => 'Requested grade',
        'child1Gender' => 'Gender', 'epipen' => 'EpiPen required',
        'guardianSignature' => 'Guardian permission signature',
        'releaseSignature' => 'Medical release signature',
        'permission_text' => 'Guardian permission',
        'medical_release_text' => 'Medical consent and release',
    ];
    $signedAt = \Carbon\CarbonImmutable::parse($release->signed_at, 'UTC')->setTimezone('America/New_York')->format('M j, Y g:ia T');
@endphp
<h1>The Watershed</h1>
<h2>Semester medical release and permissions</h2>
<p>Signed {{ $signedAt }}</p>
<table>
@foreach($answers as $key => $value)
    @php
        $display = is_bool($value) ? ($value ? 'Acknowledged' : 'No') : $value;
        if ($key === 'child1Grade') {
            $display = match ((int) $value) {
                -2 => 'Nursery', -1 => 'Preschool', 0 => 'Kindergarten', default => 'Grade '.$value,
            };
        }
    @endphp
    <tr>
        <td class="label">{{ $labels[$key] ?? ucwords(preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace('_', ' ', $key))) }}</td>
        <td>{{ $display }}</td>
    </tr>
@endforeach
</table>
<p>Signed record reference: {{ $release->id }}</p>
</body>
</html>

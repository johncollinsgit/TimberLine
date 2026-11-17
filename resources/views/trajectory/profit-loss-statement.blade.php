<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profit &amp; Loss - {{ $statement['name'] }}</title>
    <style>
        @page { margin: 38px; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #193a32; background: #f3f5f2; font: 13px DejaVu Sans, sans-serif; line-height: 1.5; }
        main { max-width: 940px; margin: 28px auto; padding: 36px; background: white; border: 1px solid #dce5df; }
        h1 { margin: 5px 0; font-size: 29px; letter-spacing: -1px; }
        h2 { font-size: 17px; margin: 0 0 16px; font-weight: normal; }
        .eyebrow { color: #597d6c; font-size: 10px; text-transform: uppercase; letter-spacing: 2px; }
        .meta { color: #51675f; margin: 4px 0; font-size: 11px; overflow-wrap: anywhere; }
        .coverage { margin: 20px 0; padding: 12px 16px; background: #f1f5ef; border-left: 3px solid #72967e; font-size: 11px; }
        .coverage p { margin: 5px 0; }
        table { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 11px; }
        th:first-child { width: 50%; }
        th { color: #536e62; font-size: 10px; text-align: left; padding: 10px 8px; border-bottom: 2px solid #aac0af; }
        td { padding: 7px 8px; border-bottom: 1px solid #e7ece8; vertical-align: top; overflow-wrap: anywhere; word-break: break-word; }
        .number { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .section { background: #f0f5f1; font-weight: bold; }
        .total { font-weight: bold; border-top: 2px solid #8aaa97; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        .foot { margin-top: 22px; font-size: 10px; color: #60756b; }
        .actions { text-align: right; }
        button { padding: 10px 18px; background: #255945; color: white; border: 0; border-radius: 6px; cursor: pointer; }
        @media (max-width: 600px) { main { margin: 0; padding: 18px 12px; border: 0; } h1 { font-size: 25px; } table { font-size: 9px; } td, th { padding: 6px 3px; } .number { white-space: normal; } }
        @media print { body { background: white; } main { margin: 0; padding: 0; border: 0; } .actions { display: none; } }
        @if($pdf) body { background: white; } main { margin: 0; padding: 0; border: 0; } @endif
    </style>
</head>
<body>
@php($report = $statement['report'])
<main>
    @unless($pdf)<div class="actions"><button type="button" onclick="window.print()">Print statement</button></div>@endunless
    <div class="eyebrow">Everbranch / Financial statement</div>
    <h1>Profit &amp; Loss</h1>
    <h2>{{ $statement['name'] }}</h2>
    <p class="meta">{{ $report['period']['start'] }} through {{ $report['period']['end'] }} · {{ $report['basis'] ? ucfirst($report['basis']).' basis' : 'Accounting basis unknown' }} · {{ $report['currency'] ?? 'Currency unknown' }}</p>
    @unless($report['basis'])<p class="meta">Requested accounting basis: {{ ucfirst($report['requested_basis']) }}</p>@endunless
    <p class="meta">{{ $report['source'] }}</p>
    @if($statement['comparison_period'])<p class="meta">{{ $statement['comparison_label'] }}: {{ $statement['comparison_period']['start'] }} through {{ $statement['comparison_period']['end'] }}</p>@endif
    <div class="coverage">
        <strong>{{ ucfirst($report['coverage']['status']) }} accounting coverage</strong>
        @foreach($report['coverage']['notes'] as $note)<p>{{ $note }}</p>@endforeach
    </div>
    <table aria-label="Profit and loss statement">
        <thead><tr><th scope="col">Account / subtotal</th><th scope="col" class="number">Selected period</th><th scope="col" class="number">{{ $statement['comparison_label'] }}</th></tr></thead>
        <tbody>
        @foreach($statement['rows'] as $row)
            <tr class="{{ $row['kind'] }}"><td style="padding-left: {{ min($row['depth'], 6) * 12 + 8 }}px">{{ $row['label'] }}</td><td class="number">{{ $exporter->amount($row['amount_cents'], $report['currency']) }}</td><td class="number">{{ $exporter->amount($row['previous_cents'], $report['currency']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <p class="foot">Unavailable means source evidence is missing, never zero. Account comparisons and transaction detail require corresponding authorized source records. Group rows and their children are a hierarchy, not additional expenses. This statement reports accounting profitability; cash availability is a separate measure.</p>
</main>
</body>
</html>

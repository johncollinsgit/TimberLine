<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $payload['title'] }}</title>
<style>
@page{margin:48pt 42pt 56pt}body{font-family:'DejaVu Sans',sans-serif;font-size:10pt;line-height:1.6;color:#243c34}h1,h2,h3{color:#173f35;line-height:1.22}h1{font-family:'DejaVu Serif',serif;font-size:28pt;margin:18pt 0}h2{font-size:20pt;margin:0 0 16pt}h3{font-size:12pt;margin:18pt 0 6pt}p{margin:0 0 11pt;white-space:pre-line;overflow-wrap:break-word}.eyebrow{font-size:8pt;color:#587265;letter-spacing:1.1pt;text-transform:uppercase}.wordmark{width:100pt;height:auto;margin-bottom:12pt}.notice{padding:12pt;background:#edf1e8;border-left:3pt solid #77976b}.muted{font-size:8.5pt;color:#567064}.section{page-break-before:always}.cover{padding-top:8pt}.cover .lead{font-size:11pt;line-height:1.45}.rule{border:0;border-top:1pt solid #d2ddce;margin:24pt 0}.card{padding:12pt;border:1pt solid #cad8c6;margin-bottom:14pt;page-break-inside:avoid}.card p{margin-bottom:6pt;line-height:1.4}.card h3{margin:0 0 7pt}table{width:100%;border-collapse:collapse;table-layout:fixed;margin:12pt 0 18pt;font-size:9pt}caption{text-align:left;font-weight:bold;color:#173f35;padding-bottom:7pt}th,td{padding:8pt 7pt;border-bottom:1pt solid #d6dfd1;text-align:left;vertical-align:top;overflow-wrap:break-word}th{background:#edf1e8}thead{display:table-header-group}tr{page-break-inside:avoid}caption{page-break-after:avoid}li{margin-bottom:7pt}ul{padding-left:16pt}.price{font-size:14pt;font-weight:bold;color:#173f35}.hash{font-size:7pt;word-break:break-all;color:#567064}h2,h3{page-break-after:avoid}
</style></head><body>
<div class="cover">
<img class="wordmark" src="{{ $wordmark }}" alt="Evergrove Software">
<p class="eyebrow">{{ $payload['client']['name'] ?? '' }} / {{ $edition }}</p>
<h1>{{ $payload['title'] }}</h1>
<p class="lead">{{ $payload['intro'] }}</p>
<p>Prepared by {{ $payload['prepared_by'] }}</p>
<div class="notice"><strong>For discussion · nonbinding</strong><p>{{ $payload['legal_notice'] }}</p></div>
<hr class="rule"><p class="muted">{{ $edition }}<br>Saved {{ $savedAt }}<br>This PDF records the saved proposal. It does not activate services, authorize spending, select a plan or change access. Review comments and interactive planning scenarios are separate.</p>
@if($contentHash)<p class="hash">Published content reference<br>{{ $contentHash }}</p>@endif
</div>
@if(!empty($payload['journey']))
<section class="section"><p class="eyebrow">Our approach</p><h2>The Evergrove Difference</h2>
@foreach($payload['journey'] as $index => $phase)
<div class="card"><p class="eyebrow">Phase {{ $index + 1 }}</p><h3>{{ $phase['title'] }}</h3><p>{{ $phase['body'] }}</p><p><strong>What comes out</strong><br>{{ $phase['deliverable'] }}</p><p><strong>Working together</strong><br>{{ $phase['participation'] }}</p><p><strong>Before moving on</strong><br>{{ $phase['gate'] }}</p></div>
@endforeach</section>
@endif
<section class="section"><p class="eyebrow">Financial</p><h2>Offered scope and pricing</h2>
@if(!empty($payload['scope_notes']))<p>{{ $payload['scope_notes'] }}</p>@endif
<p class="muted">All amounts are USD. Alternative scopes are separate choices. Monthly or yearly billing does not determine the contract duration.</p>
@foreach($payload['items'] as $item)
<div class="card"><h3>{{ $item['name'] }}</h3><p>{{ $item['description'] ?? '' }}</p>
@foreach($item['charges'] as $charge)<p><strong>{{ $charge['label'] }}</strong><br><span class="price">${{ number_format($charge['amount_cents'] / 100, 2) }}</span> {{ $charge['cadence'] === 'one_time' ? 'once' : 'per '.$charge['cadence'] }}</p>@endforeach
@if(!empty($item['terms_months']))<p>Offered contract duration: {{ implode(', ', $item['terms_months']) }} months.</p>@else<p>Contract duration: to be agreed. No term or discount has been assumed.</p>@endif
@if(is_string($payload['client_details']['pricing_note'] ?? null))<p class="muted">{{ $payload['client_details']['pricing_note'] }}</p>@endif
</div>@endforeach
</section>
@if($sections !== null)
@foreach($sections as $section)
<section style="margin-top:24pt"><p class="eyebrow">Saved proposal terms</p><h2>{{ $section['heading'] }}</h2>
@foreach(($section['tables'] ?? []) as $table)
<table><caption>{{ $table['caption'] }}</caption><thead><tr>@foreach($table['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr></thead><tbody>@foreach($table['rows'] as $row)<tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody></table>
@endforeach
@foreach(($section['paragraphs'] ?? []) as $paragraph)<p>{{ $paragraph }}</p>@endforeach
@if(!empty($section['bullets']))<ul>@foreach($section['bullets'] as $bullet)<li>{{ $bullet }}</li>@endforeach</ul>@endif
</section>
@endforeach
@elseif(!empty($payload['client_details']))
<section class="section"><p class="eyebrow">Saved proposal terms</p><h2>Working together</h2>
@foreach(['rollout'=>'A department at a time','activation'=>'Setup and acceptance','support'=>'Human support','travel'=>'Travel','usage'=>'Usage without markup'] as $key => $label)
@if(is_string($payload['client_details'][$key] ?? null))<h3>{{ $label }}</h3><p>{{ $payload['client_details'][$key] }}</p>@endif
@endforeach
@if(is_string($payload['client_details']['implementation_timeline_heading'] ?? null) && is_string($payload['client_details']['implementation_timeline'] ?? null))<h3>{{ $payload['client_details']['implementation_timeline_heading'] }}</h3><p>{{ $payload['client_details']['implementation_timeline'] }}</p>@endif
</section>
@endif
<section class="section"><p class="eyebrow">Decisions and review</p><h2>Details to work through together</h2>
@foreach($payload['unresolved'] as $unknown)<p>{{ $unknown }}</p>@endforeach
<p>Usage approach under discussion: {{ ['unconfirmed'=>'not yet selected','separately_billed'=>'separately billed usage','workspace_allowance'=>'monthly allowance per workspace','pooled_bundle_allowance'=>'monthly allowance pooled across the bundle'][$payload['usage_plan'] ?? 'unconfirmed'] ?? 'not yet selected' }}. Prices, allowances and any additional consumption require approval.</p>
<p>Final agreement review, signing and payment are separate later steps.</p>
@if(!empty($payload['template']))<h3>Draft master language · unreviewed / not attorney approved</h3><p>{{ $payload['template']['text'] }}</p>@endif
<div class="notice"><p>{{ $payload['legal_notice'] }}</p></div>
</section></body></html>

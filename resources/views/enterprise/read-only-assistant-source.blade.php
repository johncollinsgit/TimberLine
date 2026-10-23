<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>{{ $title }} · Everbranch source</title>
@include('enterprise.campus-styles')
<style>.source-page{max-width:900px;margin:0 auto;padding:24px}.source-page h1{overflow-wrap:anywhere}.source-page dl{display:grid;grid-template-columns:minmax(100px,1fr) minmax(0,2fr);gap:16px}.source-page dt{font-weight:600}.source-page dd{margin:0;overflow-wrap:anywhere}.source-page a{display:inline-flex;min-height:44px;align-items:center}.source-page .caption{overflow-wrap:anywhere}@media(max-width:550px){.source-page dl{grid-template-columns:1fr;gap:8px}.source-page dd{margin-bottom:12px}}</style></head>
<body><main class="source-page"><p class="eyebrow">Everbranch · Current source record</p><p class="caption">{{ $sampleData ? 'Sample data · saved workspace record' : 'Saved workspace record' }} · {{ $areaName }}</p><h1>{{ $title }}</h1>
<dl>@foreach($facts as $label => $value)<dt>{{ $label }}</dt><dd>{{ $value === null || $value === '' ? 'Not recorded' : $value }}</dd>@endforeach</dl>
<p class="caption">Source: {{ $kind }}:{{ $sourceId }} · Checked {{ $checkedAt }}. This exact record was checked with your current permissions. Workspace lists remain bounded.</p>
@if($kind === 'cost')<p class="caption">An estimate is not an approved budget. This record is not an accounting total or evidence of payment.</p>@endif
<a href="{{ $workspaceUrl }}">Return to workspace</a></main></body></html>

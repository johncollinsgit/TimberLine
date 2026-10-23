@extends('service-proposals.layout')
@section('title', 'Review activity')
@section('content')
<header><p><a href="{{ route('service-proposals.owner.index', $tenant->slug) }}">← Proposals</a></p><p class="eyebrow">Owner workspace · private</p><h1>Review activity</h1><p>{{ $version->payload['title'] }} · version {{ $version->number }}</p></header>
<p class="notice">Activity starts when tracking is available; earlier visits cannot be reconstructed. Names are self-selected. A browser visit is not a verified person, and an opened page does not prove it was read. Tour progress is reported by the browser.</p>
@if($journal['unavailable'])<p class="notice" role="alert">Some activity could not be read. This view may be incomplete.</p>@endif
@if($journal['limited'])<p class="notice" role="alert">This publication reached its activity storage limit. Later activity may be missing.</p>@endif
<p>{{ $visits->count() }} recorded browser {{ \Illuminate\Support\Str::plural('visit', $visits->count()) }} · {{ $visits->where('unlocked', true)->count() }} with a recorded password unlock · {{ $testCount }} owner {{ \Illuminate\Support\Str::plural('test', $testCount) }} {{ $includeTests ? 'included' : 'hidden' }}</p>
<p><a href="{{ route('service-proposals.owner.activity', [$tenant->slug, $version->id, 'include_tests' => $includeTests ? 0 : 1]) }}">{{ $includeTests ? 'Hide owner tests' : 'Include owner tests' }}</a> · <a href="{{ request()->fullUrl() }}">Refresh activity</a></p>
@forelse($visits as $visit)
<article class="card"><h2>{{ $visit['name'] }} @if($visit['owner_test'])<span class="pill">Owner test</span>@endif</h2>
<p>{{ $visit['unlocked'] ? 'Password unlocked' : 'No password unlock recorded' }} · self-selected name</p>
<p>First recorded: {{ \Carbon\Carbon::parse($visit['first'])->timezone('America/New_York')->format('M j, g:i:s a T') }}<br>Last recorded: {{ \Carbon\Carbon::parse($visit['last'])->timezone('America/New_York')->format('M j, g:i:s a T') }}</p>
<p>Pages opened: {{ $visit['pages']->map(fn($page) => \App\Proposals\CampusReviewManifest::PAGES[$page] ?? ['scope'=>'Financial','terms'=>'Terms & support','review'=>'Review & comments','overview'=>'Proposal overview','approach'=>'Our approach'][$page] ?? $page)->join(', ') ?: 'No unlocked page recorded' }}</p>
<p>Tour: {{ $visit['scene'] ? 'reached scene '.$visit['scene'].' of 7' : 'no tour progress recorded' }}{{ $visit['completed'] ? ' · playback finished (browser-reported)' : '' }}</p>
<details><summary>Activity details</summary><ol>@foreach($visit['events'] as $event)<li>{{ \Carbon\Carbon::parse($event['at'])->timezone('America/New_York')->format('g:i:s a T') }} · {{ str_replace('_', ' ', $event['event']) }}@if($event['page']) · {{ $event['page'] }}@endif @if($event['scene']) · scene {{ $event['scene'] }}@endif</li>@endforeach</ol></details></article>
@empty<p class="card">No reviewer activity recorded yet. This does not establish that nobody visited.</p>@endforelse
@endsection

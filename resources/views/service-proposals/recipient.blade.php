@extends('service-proposals.layout')
@section('title', $payload['title'])
@section('body-class', 'proposal-reader')
@section('presentation-styles')@include('service-proposals.presentation-styles')@endsection
@section('content')
<header class="proposal-hero"><img class="proposal-corner" src="/images/proposals/evergrove-mountain-corner.svg" alt="" aria-hidden="true" width="218" height="168"><div class="topline"><span class="brand"><img class="proposal-company-wordmark" src="/images/proposals/evergrove-software-wordmark.jpg" alt="Evergrove Software" width="899" height="458"></span><span class="pill">{{ $preview ? 'Owner preview · draft' : 'Private · version '.$version->number }}</span></div><p class="eyebrow">A thoughtful starting point</p><h1>{{ $payload['title'] }}</h1><p class="muted">Prepared by {{ $payload['prepared_by'] }}</p></header>
@include('service-proposals.pdf-download')
@unless($preview)@include('service-proposals.activity-bridge')@endunless
@php
    $proposalSections = ['overview' => 'Overview'];
    if (!empty($payload['journey'])) $proposalSections['approach'] = 'Our approach';
    $proposalSections['scope'] = 'Financial';
    $proposalSections['terms'] = 'Terms & support';
    if (!$preview && !empty($payload['reviewer_names'])) $proposalSections['review'] = 'Review & comments';
    $defaultSection = 'overview';
    foreach (array_keys($errors->messages()) as $errorField) {
        if ($errorField === 'selected' || str_starts_with($errorField, 'selected.') || $errorField === 'terms' || str_starts_with($errorField, 'terms.')) $defaultSection = 'scope';
        if ($errorField === 'financial' || in_array($errorField, ['departments', 'low', 'high'], true)) $defaultSection = 'scope';
        if (in_array($errorField, ['reviewer_name', 'body', 'page', 'comment_key'], true) && isset($proposalSections['review'])) $defaultSection = 'review';
    }
@endphp
@component('service-proposals.section-tabs', ['sections' => $proposalSections, 'defaultSection' => $defaultSection, 'forcedSection' => $errors->any() ? $defaultSection : null])
@slot('overview')
@if(($payload['client']['reference'] ?? null) === 'lees-mcrae-college')<div class="proposal-college-reveal">@include('service-proposals.branding.lees-mcrae-reveal')</div>@endif
@if(!$preview && $version && app(\App\Proposals\CampusReviewManifest::class)->available($version))
<section class="card campus-review-entry"><h2>Review the campus experience.</h2><p>Explore the same fictional campus screens and leave shared feedback on each page of this exact reviewed build. Display names do not grant campus access.</p><p><a class="button" href="{{ route('service-proposals.recipient.campus', [$token, 'home']) }}">Open campus review</a></p></section>
@endif

<section class="proposal-introduction"><h2>A clearer way forward.</h2><p class="body-copy">{{ $payload['intro'] }}</p></section>
<div class="notice"><strong>For discussion · non-binding</strong><p>{{ $payload['legal_notice'] }}</p></div>

@endslot
@if(!empty($payload['journey']))
@slot('approach')
@if(!empty($payload['journey']))@include('service-proposals.journey', ['journey' => $payload['journey'], 'rolloutNote' => \App\Proposals\CommercialSections::from($payload['client_details'] ?? []) !== null ? ($payload['client_details']['rollout'] ?? null) : null])@endif
@endslot
@endif
@slot('scope')
@if(!empty($payload['scope_notes']))<section class="card"><h2>Included and separately scoped work</h2><p class="body-copy">{{ $payload['scope_notes'] }}</p></section>@endif<section><h2>Choose the scope that fits.</h2><p class="muted">Review the offered services and configured duration. Monthly or yearly billing does not determine the contract length. All amounts below are USD.</p>
@if(!$preview && ($payload['audience'] ?? '') === 'client')<form method="post" action="{{ route('service-proposals.recipient.review', $token) }}">@csrf<input type="hidden" name="version_hash" value="{{ $version->content_hash }}"><input type="hidden" name="request_key" value="{{ $requestKey }}">@endif
<div class="grid">@foreach($payload['items'] as $item)<article class="card"><p class="eyebrow">{{ !empty($item['exclusive_group']) ? 'Alternative scope · choose one' : 'Optional service' }}</p><h3>{{ $item['name'] }}</h3>@if(is_string($payload['client_details']['pricing_note'] ?? null) && $payload['client_details']['pricing_note'] !== '')<p class="muted">{{ $payload['client_details']['pricing_note'] }}</p>@elseif(!empty($payload['client_details']))<p class="muted">Prices are TOTAL for this option. The three-workspace monthly total applies when all three are fully active; phased billing remains to be agreed.</p>@endif<p>{{ $item['description'] ?? '' }}</p>@foreach($item['charges'] as $charge)<div class="row"><span>{{ $charge['label'] }}</span><strong class="price">${{ number_format($charge['amount_cents']/100, 2) }}<small>{{ $charge['cadence'] === 'one_time' ? ' once' : '/'.$charge['cadence'] }}</small></strong></div>@endforeach
@if(!$preview && ($payload['audience'] ?? '') === 'client')<label><input type="checkbox" name="selected[]" value="{{ $item['id'] }}" @checked(in_array($item['id'], old('selected', [])))>Select {{ $item['name'] }}</label>@endif
@if($item['terms_months'])<label for="term-{{ $item['id'] }}">Contract duration</label><span class="proposal-select"><select id="term-{{ $item['id'] }}" name="terms[{{ $item['id'] }}]" @disabled($preview || ($payload['audience'] ?? '') === 'reviewer')><option value="">Choose if selecting this service</option>@foreach($item['terms_months'] as $term)<option value="{{ $term }}" @selected((string)old('terms.'.$item['id']) === (string)$term)>{{ $term/12 }} {{ $term === 12 ? 'year' : 'years' }}</option>@endforeach</select></span>@else<p class="muted">Contract duration: to be agreed. No term or discount has been assumed.</p>@endif</article>@endforeach</div>
@if(!$preview && ($payload['audience'] ?? '') === 'client')<p><button>Review selected services</button></p></form>@elseif($preview)<p class="notice">Owner preview only. Selections and agreement requests become available on an explicitly published client version.</p>@else<p class="notice">Reviewer access · read only. Review does not approve clauses or authorize an agreement.</p>@endif</section>
@if(!empty($payload['client_details']))@include('service-proposals.usage-estimator')@endif
@endslot
@slot('terms')
<section class="card"><h2>Details to work through together</h2>@foreach($payload['unresolved'] as $unknown)<p>{{ $unknown }}</p>@endforeach<p>Usage approach under discussion: {{ ['unconfirmed'=>'not yet selected','separately_billed'=>'separately billed usage','workspace_allowance'=>'monthly allowance per workspace','pooled_bundle_allowance'=>'monthly allowance pooled across the bundle'][$payload['usage_plan'] ?? 'unconfirmed'] }}. Prices, allowances and any additional consumption require approval.</p><p>Final agreement review, signing and payment are separate later steps.</p></section>
@if($payload['template'])<details class="card proposal-template"><summary>Draft master language · unreviewed / not attorney approved</summary><p class="body-copy">{{ $payload['template']['text'] }}</p></details><section class="card proposal-print-copy"><h3>Draft master language · unreviewed / not attorney approved</h3><p class="body-copy">{{ $payload['template']['text'] }}</p></section>@endif
@if(!empty($payload['client_details']))@include('service-proposals.commercial-terms', ['details' => $payload['client_details']])@endif
@endslot
@if(!$preview && !empty($payload['reviewer_names']))
@slot('review')
@if(!$preview && !empty($payload['reviewer_names']))@include('service-proposals.comments')@endif
@endslot
@endif
@endcomponent
@if($version)<p class="hash">Published content reference {{ $version->content_hash }}</p>@endif
@endsection

@php($p = $asset?->payload ?? [])
@php($key = $asset?->id ?? 'new-'.$kind)
<form method="post" action="{{ route('service-proposals.owner.asset', [$tenant->slug, $kind, $asset?->id]) }}">@csrf
@if($asset)<input type="hidden" name="revision" value="{{ $asset->revision }}">@endif
<label for="name-{{ $key }}">Name</label><input id="name-{{ $key }}" name="name" maxlength="180" value="{{ $p['name'] ?? '' }}" required>
<label for="description-{{ $key }}">Scope / description</label><textarea id="description-{{ $key }}" name="description" rows="3">{{ $p['description'] ?? '' }}</textarea>
@if($kind === 'client')<label for="reference-{{ $key }}">Your client reference</label><input id="reference-{{ $key }}" name="reference" value="{{ $p['reference'] ?? '' }}" maxlength="100" required>@endif
@if($kind === 'template')<label for="text-{{ $key }}">Draft master text · unreviewed</label><textarea id="text-{{ $key }}" name="text" rows="9" required>{{ $p['text'] ?? '' }}</textarea>@endif
@if($kind === 'catalog')
<label for="client-{{ $key }}">Available to</label><select id="client-{{ $key }}" name="client_id"><option value="">All clients in this workspace</option>@foreach($assets->where('kind', 'client') as $client)<option value="{{ $client->id }}" @selected(($p['client_id'] ?? '') === $client->id)>{{ $client->payload['name'] }} only</option>@endforeach</select>
@php($setup = collect($p['charges'] ?? [])->firstWhere('cadence', 'one_time'))
@php($recurring = collect($p['charges'] ?? [])->first(fn ($charge) => $charge['cadence'] !== 'one_time'))
<div class="grid"><div><label for="setup-label-{{ $key }}">One-time charge label</label><input id="setup-label-{{ $key }}" name="setup_label" value="{{ $setup['label'] ?? 'One-time service' }}" maxlength="180"></div><div><label for="recurring-label-{{ $key }}">Recurring charge label</label><input id="recurring-label-{{ $key }}" name="recurring_label" value="{{ $recurring['label'] ?? 'Recurring service' }}" maxlength="180"></div></div>
<div class="grid"><div><label for="setup-{{ $key }}">One-time amount · USD</label><input id="setup-{{ $key }}" name="setup_amount" inputmode="decimal" placeholder="Leave blank if none" value="{{ $setup ? number_format($setup['amount_cents']/100, 2, '.', '') : '' }}"></div><div><label for="recurring-{{ $key }}">Recurring amount · USD</label><input id="recurring-{{ $key }}" name="recurring_amount" inputmode="decimal" placeholder="Leave blank if none" value="{{ $recurring ? number_format($recurring['amount_cents']/100, 2, '.', '') : '' }}"></div></div>
<label for="cadence-{{ $key }}">Recurring billing cadence</label><select id="cadence-{{ $key }}" name="cadence"><option value="month" @selected(($recurring['cadence'] ?? '') === 'month')>Monthly</option><option value="year" @selected(($recurring['cadence'] ?? '') === 'year')>Yearly</option></select>
<label>Configured contract durations</label><p class="muted">Independent of billing cadence. Leave all unchecked while terms are undecided. No discount is implied.</p><div class="term-options">@foreach([12=>1,24=>2,36=>3] as $months=>$years)<label><input type="checkbox" name="terms_months[]" value="{{ $months }}" @checked(in_array($months, $p['terms_months'] ?? []))>{{ $years }} {{ $years === 1 ? 'year' : 'years' }}</label>@endforeach</div>
<label for="group-{{ $key }}">Alternative group · optional</label><input id="group-{{ $key }}" name="exclusive_group" value="{{ $p['exclusive_group'] ?? '' }}" maxlength="80"><p class="muted">Items with the same group are alternatives: recipients may choose one.</p>
@endif
<p><button>Save {{ $kind }}</button></p></form>

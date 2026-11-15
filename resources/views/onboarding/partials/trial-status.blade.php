@php
    $trialState = data_get($trial ?? [], 'state', 'none');
    $trialEndsAt = data_get($trial ?? [], 'ends_at');
    $trialOffer = (array) config('module_catalog.offers.standard', []);
@endphp
@if(in_array($trialState, ['active', 'expired'], true) && $trialEndsAt)
    <section class="eb-trial-status" aria-label="Trial status" data-trial-state="{{ $trialState }}">
        <div>
            <h2>{{ $trialState === 'active' ? 'Your private trial' : 'Your trial ended' }}</h2>
            <p>{{ $trialState === 'active' ? 'Ends' : 'Ended' }} <time datetime="{{ $trialEndsAt }}">{{ \Illuminate\Support\Carbon::parse($trialEndsAt)->utc()->format('M j, Y, H:i') }} UTC</time></p>
            <p>{{ $trialState === 'active' ? 'No card. No automatic charge.' : 'Your drafts are saved. Activate to continue.' }}</p>
            @if(!empty($trial['limits']))
                <details class="eb-trial-details">
                    <summary>Trial limits</summary>
                    <p>{{ data_get($trial, 'limits.owner_seats') }} owner · {{ data_get($trial, 'limits.customers') }} customers · {{ data_get($trial, 'limits.jobs') }} jobs · {{ data_get($trial, 'limits.documents') }} files</p>
                    <p>{{ data_get($trial, 'limits.website_sites') }} website · {{ data_get($trial, 'limits.website_pages') }} pages · {{ data_get($trial, 'limits.website_photos') }} photos · {{ number_format(data_get($trial, 'limits.website_media_bytes', 0) / 1048576) }} MB</p>
                </details>
            @endif
        </div>
        <div class="eb-trial-actions">
            @if($showTrialPreview ?? false)
                <a class="fb-btn fb-btn-secondary" href="{{ route('managed-website.index', ['tenant' => $tenant->slug]) }}"><x-everbranch.action-leaf />{{ $trialState === 'active' ? 'Build website' : 'View saved drafts' }}</a>
            @endif
            @if($showTrialAction ?? true)
                <a class="fb-btn fb-btn-primary" href="{{ route('self-service.purchase', ['tenant' => $tenant->slug]) }}#activate-plan"><x-everbranch.action-leaf />Activate plan</a>
                <small>${{ number_format(data_get($quote ?? [], 'setup_cents', $trialOffer['setup_cents'] ?? 0) / 100) }} setup + ${{ number_format(data_get($quote ?? [], 'monthly_cents', $trialOffer['monthly_cents'] ?? 0) / 100) }}/month<br>Before tax and usage.</small>
            @endif
        </div>
    </section>
@endif

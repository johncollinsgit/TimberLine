@php
    $content = is_array($surface ?? null) ? $surface : [];
    $intentValue = in_array(($intent ?? ''), ['demo', 'production'], true) ? (string) $intent : 'production';
    $planCards = is_array($plan_cards ?? null) ? $plan_cards : [];
    $addonCards = is_array($addon_cards ?? null) ? $addon_cards : [];
    $recommendedPlanKey = (string) ($recommended_plan_key ?? 'growth');
    $selectedPlanKey = old('preferred_plan_key', $recommendedPlanKey);
    $selectedAddons = (array) old('addons_interest', []);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', ['title' => ($content['headline'] ?? 'Request access')])
</head>
<body class="fb-public-body" data-premium-motion="public">
    @include('platform.partials.premium-motion')

    <main class="fb-public-shell fb-contact-shell">
        <a href="{{ route('platform.promo') }}" class="fb-btn fb-btn-secondary fb-contact-back">Back to homepage</a>

        <section class="fb-card fb-contact-overview" aria-label="Request overview" data-reveal data-premium-surface>
            <p class="fb-section-kicker">{{ $content['eyebrow'] ?? 'Access' }}</p>
            <h1 class="fb-contact-title">{{ $content['headline'] ?? 'Request access' }}</h1>
            <p class="fb-contact-summary">{{ $content['summary'] ?? 'Submit your details and we will follow up shortly.' }}</p>
        </section>

        <section class="fb-section" aria-label="Access request form" data-reveal>
            <div class="fb-card p-6" data-premium-surface>
                @if (session('status'))
                    <div class="fb-state fb-state--success mb-4">{{ session('status') }}</div>
                @endif

                <form method="POST" action="{{ route('platform.access-request') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="intent" value="{{ $intentValue }}" />

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="fb-form-label" for="name">Name</label>
                            <input id="name" name="name" type="text" class="fb-input mt-2" value="{{ old('name') }}" required />
                            @error('name') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="fb-form-label" for="email">Email</label>
                            <input id="email" name="email" type="email" class="fb-input mt-2" value="{{ old('email') }}" required />
                            @error('email') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="fb-form-label" for="company">Company (optional)</label>
                            <input id="company" name="company" type="text" class="fb-input mt-2" value="{{ old('company') }}" />
                            @error('company') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="fb-form-label" for="requested_tenant_slug">Tenant slug (optional)</label>
                            <input
                                id="requested_tenant_slug"
                                name="requested_tenant_slug"
                                type="text"
                                class="fb-input mt-2"
                                value="{{ old('requested_tenant_slug') }}"
                                @if($intentValue === 'demo') disabled @endif
                            />
                            <div class="fb-help">Used to route you to `&lt;slug&gt;.forestrybackstage.com` after approval.</div>
                            @error('requested_tenant_slug') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="fb-form-label" for="message">Notes (optional)</label>
                        <textarea id="message" name="message" rows="4" class="fb-input mt-2">{{ old('message') }}</textarea>
                        @error('message') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                    </div>

                    @if($intentValue === 'production')
                        <div class="rounded-2xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4">
                            <div class="text-sm font-semibold text-[var(--fb-text-primary)]">Commercial interest (optional)</div>
                            <div class="mt-1 text-xs text-[var(--fb-text-secondary)]">This is not a billing action. It helps us route the right plan + add-on conversation after approval.</div>

                            <div class="mt-4 grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="fb-form-label" for="preferred_plan_key">Preferred tier</label>
                                    <select id="preferred_plan_key" name="preferred_plan_key" class="fb-input mt-2">
                                        <option value="">No preference</option>
                                        @foreach($planCards as $plan)
                                            @php
                                                $key = (string) ($plan['plan_key'] ?? '');
                                                $label = (string) ($plan['label'] ?? $key);
                                            @endphp
                                            @if($key !== '')
                                                <option value="{{ $key }}" @selected($selectedPlanKey === $key)>
                                                    {{ $label }}{{ $key === $recommendedPlanKey ? ' (recommended)' : '' }}
                                                </option>
                                            @endif
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <div class="fb-form-label">Add-ons of interest</div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach($addonCards as $addon)
                                            @php
                                                $addonKey = (string) ($addon['addon_key'] ?? '');
                                                $addonLabel = (string) ($addon['label'] ?? $addonKey);
                                            @endphp
                                            @if($addonKey !== '')
                                                <label class="fb-module-pill cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        class="mr-2"
                                                        name="addons_interest[]"
                                                        value="{{ $addonKey }}"
                                                        @checked(in_array($addonKey, $selectedAddons, true))
                                                    />
                                                    {{ $addonLabel }}
                                                </label>
                                            @endif
                                        @endforeach
                                    </div>
                                    @error('addons_interest') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        <button type="submit" class="fb-btn fb-btn-primary">
                            {{ $content['submit_label'] ?? 'Submit request' }}
                        </button>
                        <a href="{{ route('login') }}" class="fb-btn fb-btn-secondary">Already have access? Sign in</a>
                    </div>

                    @if(filled($content['footnote'] ?? null))
                        <p class="mt-4 text-xs text-[var(--fb-text-secondary)]">{{ $content['footnote'] }}</p>
                    @endif
                </form>
            </div>
        </section>
    </main>
</body>
</html>

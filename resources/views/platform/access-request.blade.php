@php
    $content = is_array($surface ?? null) ? $surface : [];
    $intentValue = in_array(($intent ?? ''), ['demo', 'production'], true) ? (string) $intent : 'production';
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


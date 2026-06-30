<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :store-label="$storeLabel"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :page-subnav="$pageSubnav ?? []"
    :page-actions="$pageActions"
>
    @php
        /** @var \App\Services\Shopify\ShopifyEmbeddedUrlGenerator $embeddedUrls */
        $embeddedUrls = app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class);
        $embeddedContext = $embeddedUrls->contextQuery(
            request(),
            filled($host) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => $embeddedUrls->append($url, $embeddedContext);
    @endphp

    <div class="space-y-6">
        @if (session('status'))
            <section class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-800">
                {{ session('status') }}
            </section>
        @endif

        @if (session('error'))
            <section class="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800">
                {{ session('error') }}
            </section>
        @endif

        <section class="fb-page-surface fb-page-surface--subtle p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="fb-kpi-label">Application status</div>
                    <h2 class="mt-2 text-3xl font-semibold text-zinc-950">{{ $accessRequest->name ?: $accessRequest->email }}</h2>
                    <p class="mt-2 text-sm text-zinc-600">
                        Submitted {{ optional($accessRequest->created_at)->format('F j, Y \a\t g:i A') ?: '—' }}
                        for {{ $accessRequest->tenant?->name ?? 'Modern Forestry Wholesale' }}.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @php
                        $badgeClasses = match ($accessRequest->status) {
                            'approved' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                            'rejected' => 'border-rose-200 bg-rose-50 text-rose-700',
                            default => 'border-amber-200 bg-amber-50 text-amber-700',
                        };
                    @endphp
                    <span class="inline-flex rounded-full border px-3 py-1.5 text-xs font-semibold {{ $badgeClasses }}">
                        {{ \Illuminate\Support\Str::headline((string) $accessRequest->status) }}
                    </span>
                    @if ($accessRequest->user)
                        <span class="inline-flex rounded-full border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700">
                            User record: {{ $accessRequest->user->is_active ? 'active' : 'inactive' }}
                        </span>
                    @endif
                </div>
            </div>
        </section>

        <section class="flex items-center justify-between gap-3">
            <a href="{{ $embeddedUrl(route('shopify.app.wholesale', ['store_key' => 'wholesale'], false)) }}" class="inline-flex rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                Back to applications
            </a>
            <div class="text-sm text-zinc-600">
                @if ($canManageApproval)
                    Signed in as {{ $actor?->email }}.
                @else
                    Read-only mode until your Shopify admin email matches a wholesale operator.
                @endif
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_360px]">
            <div class="fb-page-surface p-6">
                <div class="text-sm font-semibold text-zinc-950">Application details</div>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    @foreach ($detailRows as $row)
                        <div class="rounded-2xl border border-zinc-200 bg-white p-4">
                            <div class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">{{ $row['label'] }}</div>
                            <div class="mt-2 whitespace-pre-wrap break-words text-sm text-zinc-900">{{ $row['value'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="space-y-6">
                <section class="fb-page-surface p-6">
                    <div class="text-sm font-semibold text-zinc-950">Review actions</div>
                    @if ($canManageApproval && filled($contextToken))
                        <div class="mt-3 space-y-4">
                            <form method="POST" action="{{ $embeddedUrl(route('shopify.app.wholesale.applications.approve', ['accessRequest' => $accessRequest, 'store_key' => 'wholesale'], false)) }}" class="space-y-3" data-embedded-auth-form>
                                @csrf
                                <input type="hidden" name="context_token" value="{{ $contextToken }}">
                                <label class="block space-y-2">
                                    <span class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Approval note</span>
                                    <textarea
                                        name="decision_note"
                                        rows="3"
                                        class="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-sm text-zinc-900 shadow-sm focus:border-zinc-500 focus:outline-none focus:ring-2 focus:ring-zinc-200"
                                        placeholder="Optional note for the record"
                                    >{{ old('decision_note', (string) ($accessRequest->decision_note ?? '')) }}</textarea>
                                </label>
                                <div class="flex flex-wrap gap-2">
                                    @if ($accessRequest->status !== 'approved')
                                        <button type="submit" class="rounded-full bg-emerald-700 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-600">
                                            Approve application
                                        </button>
                                    @endif
                                    @if ($accessRequest->status === 'approved')
                                        <button type="submit" formaction="{{ $embeddedUrl(route('shopify.app.wholesale.applications.resend-activation', ['accessRequest' => $accessRequest, 'store_key' => 'wholesale'], false)) }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                                            Resend activation
                                        </button>
                                    @endif
                                </div>
                            </form>

                            @if ($accessRequest->status !== 'approved')
                                <form method="POST" action="{{ $embeddedUrl(route('shopify.app.wholesale.applications.reject', ['accessRequest' => $accessRequest, 'store_key' => 'wholesale'], false)) }}" class="space-y-3" data-embedded-auth-form>
                                    @csrf
                                    <input type="hidden" name="context_token" value="{{ $contextToken }}">
                                    <label class="block space-y-2">
                                        <span class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Rejection note</span>
                                        <textarea
                                            name="rejection_note"
                                            rows="3"
                                            class="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-sm text-zinc-900 shadow-sm focus:border-zinc-500 focus:outline-none focus:ring-2 focus:ring-zinc-200"
                                            placeholder="Optional reason for rejection"
                                        >{{ old('rejection_note', (string) ($accessRequest->rejection_note ?? '')) }}</textarea>
                                    </label>
                                    <button type="submit" class="rounded-full bg-rose-700 px-4 py-2 text-xs font-semibold text-white hover:bg-rose-600">
                                        Reject application
                                    </button>
                                </form>
                            @endif
                        </div>
                    @else
                        <div class="mt-3 space-y-3 text-sm text-zinc-600">
                            <p>Applications are viewable here, but approval actions stay locked until your Shopify admin email maps to a backstage operator account.</p>
                        </div>
                    @endif
                </section>

                <section class="fb-page-surface p-6">
                    <div class="text-sm font-semibold text-zinc-950">Capture health</div>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-zinc-500">Access request ID</dt>
                            <dd class="font-medium text-zinc-900">{{ $accessRequest->id }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-zinc-500">Form submission</dt>
                            <dd class="font-medium text-zinc-900">{{ $accessRequest->formSubmission?->id ? 'Captured' : 'Missing' }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-zinc-500">Shopify user record</dt>
                            <dd class="font-medium text-zinc-900">{{ $accessRequest->user?->email ?? 'Not linked yet' }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-zinc-500">Tenant slug</dt>
                            <dd class="font-mono text-xs text-zinc-900">{{ $accessRequest->requested_tenant_slug ?: ($accessRequest->tenant?->slug ?? '—') }}</dd>
                        </div>
                    </dl>
                </section>

                @if (filled($accessRequest->message))
                    <section class="fb-page-surface p-6">
                        <div class="text-sm font-semibold text-zinc-950">Applicant note</div>
                        <div class="mt-3 whitespace-pre-wrap text-sm text-zinc-700">{{ $accessRequest->message }}</div>
                    </section>
                @endif
            </div>
        </section>
    </div>

    <script>
        (function () {
            const forms = Array.from(document.querySelectorAll('[data-embedded-auth-form]'));
            if (forms.length === 0) {
                return;
            }

            forms.forEach((form) => {
                form.addEventListener('submit', async (event) => {
                    if (!window.ForestryEmbeddedApp || typeof window.ForestryEmbeddedApp.resolveEmbeddedAuthHeaders !== 'function') {
                        return;
                    }

                    event.preventDefault();

                    const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
                    const originalText = submitter ? submitter.textContent : null;
                    if (submitter) {
                        submitter.setAttribute('disabled', 'disabled');
                    }

                    try {
                        const headers = await window.ForestryEmbeddedApp.resolveEmbeddedAuthHeaders({
                            includeJsonContentType: false,
                        });
                        headers['Accept'] = 'application/json';
                        headers['X-Requested-With'] = 'XMLHttpRequest';
                        const submitUrl = submitter && 'formAction' in submitter && typeof submitter.formAction === 'string' && submitter.formAction !== ''
                            ? submitter.formAction
                            : form.action;

                        const response = await fetch(submitUrl, {
                            method: 'POST',
                            headers,
                            body: new FormData(form),
                            credentials: 'same-origin',
                        });

                        const payload = await response.json().catch(() => ({}));
                        const redirectUrl = typeof payload.redirect_url === 'string' && payload.redirect_url !== ''
                            ? payload.redirect_url
                            : window.location.href;

                        if (!response.ok || payload.ok === false) {
                            if (payload.message && window.ForestryEmbeddedApp.showToast) {
                                window.ForestryEmbeddedApp.showToast(payload.message, 'error');
                            }
                            window.location.assign(redirectUrl);
                            return;
                        }

                        if (payload.message && window.ForestryEmbeddedApp.showToast) {
                            window.ForestryEmbeddedApp.showToast(payload.message, 'success');
                        }
                        window.location.assign(redirectUrl);
                    } catch (error) {
                        const message = error?.message || 'We could not process that application action right now.';
                        if (window.ForestryEmbeddedApp.showToast) {
                            window.ForestryEmbeddedApp.showToast(message, 'error');
                        }
                        form.submit();
                    } finally {
                        if (submitter) {
                            submitter.removeAttribute('disabled');
                            if (originalText !== null) {
                                submitter.textContent = originalText;
                            }
                        }
                    }
                });
            });
        })();
    </script>
</x-shopify-embedded-shell>

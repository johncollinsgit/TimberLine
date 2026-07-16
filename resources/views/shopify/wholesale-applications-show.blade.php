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
            <section
                class="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800"
                data-embedded-session-error
            >
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
            <div class="text-sm text-zinc-600" data-embedded-identity-label aria-live="polite">
                @if ($canManageApproval)
                    Signed in as {{ $actor?->email }}.
                @elseif (filled($contextToken))
                    Shopify will verify your admin identity when you choose an action.
                @else
                    Read-only mode until your Shopify admin email matches a wholesale operator.
                @endif
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_360px]">
            <div class="space-y-6">
                <section class="fb-page-surface p-6">
                    <div class="text-sm font-semibold text-zinc-950">Application summary</div>
                    <div class="mt-4 grid gap-6 lg:grid-cols-2">
                        @foreach (($detailSections['summary'] ?? []) as $row)
                            <div class="border-b border-zinc-100 pb-3 last:border-b-0 last:pb-0">
                                <div class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">{{ $row['label'] }}</div>
                                <div class="mt-1 whitespace-pre-wrap break-words text-base text-zinc-900">{{ $row['value'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="fb-page-surface p-6">
                    <div class="text-sm font-semibold text-zinc-950">Business overview</div>
                    <dl class="mt-4 grid gap-x-8 gap-y-4 md:grid-cols-2">
                        @foreach (($detailSections['business'] ?? []) as $row)
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">{{ $row['label'] }}</dt>
                                <dd class="mt-1 whitespace-pre-wrap break-words text-sm text-zinc-900">{{ $row['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                <section class="fb-page-surface p-6">
                    <div class="text-sm font-semibold text-zinc-950">Store location</div>
                    <dl class="mt-4 grid gap-x-8 gap-y-4 md:grid-cols-2">
                        @foreach (($detailSections['location'] ?? []) as $row)
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">{{ $row['label'] }}</dt>
                                <dd class="mt-1 whitespace-pre-wrap break-words text-sm text-zinc-900">{{ $row['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                @if (!empty($detailNarratives))
                    <section class="fb-page-surface p-6">
                        <div class="text-sm font-semibold text-zinc-950">Wholesale notes</div>
                        <div class="mt-4 space-y-5">
                            @foreach ($detailNarratives as $row)
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">{{ $row['label'] }}</div>
                                    <div class="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-zinc-800">{{ $row['value'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                <section class="fb-page-surface p-6">
                    <div class="text-sm font-semibold text-zinc-950">Compliance</div>
                    <dl class="mt-4 grid gap-x-8 gap-y-4 md:grid-cols-2">
                        @foreach (($detailSections['compliance'] ?? []) as $row)
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">{{ $row['label'] }}</dt>
                                <dd class="mt-1 whitespace-pre-wrap break-words text-sm text-zinc-900">{{ $row['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            </div>

            <div class="space-y-6">
                <section class="fb-page-surface p-6">
                    <div class="text-sm font-semibold text-zinc-950">Review actions</div>
                    @if (filled($contextToken))
                        <div class="mt-3 space-y-4">
                            <p class="text-sm text-zinc-600" data-embedded-approval-help aria-live="polite">
                                @if ($canManageApproval)
                                    Approval actions are ready.
                                @else
                                    Choose Approve or Reject to verify your Shopify admin identity.
                                @endif
                            </p>

                            <form method="POST" action="{{ $embeddedUrl(route('shopify.app.wholesale.applications.approve', ['accessRequest' => $accessRequest, 'store_key' => 'wholesale'], false)) }}" class="space-y-3" data-embedded-approval-form>
                                @csrf
                                <input type="hidden" name="context_token" value="{{ $contextToken }}">
                                <input type="hidden" name="shopify_session_token" value="" data-embedded-session-token-input>
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
                                        <button type="submit" class="rounded-full bg-emerald-700 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-60" data-embedded-approval-button data-embedded-pending-label="Approving…">
                                            Approve application
                                        </button>
                                    @endif
                                    @if ($accessRequest->status === 'approved')
                                        <button type="submit" formaction="{{ $embeddedUrl(route('shopify.app.wholesale.applications.resend-activation', ['accessRequest' => $accessRequest, 'store_key' => 'wholesale'], false)) }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100 disabled:cursor-not-allowed disabled:opacity-60" data-embedded-approval-button data-embedded-pending-label="Sending…">
                                            Resend activation
                                        </button>
                                    @endif
                                </div>
                            </form>

                            @if ($accessRequest->status !== 'approved')
                                <form method="POST" action="{{ $embeddedUrl(route('shopify.app.wholesale.applications.reject', ['accessRequest' => $accessRequest, 'store_key' => 'wholesale'], false)) }}" class="space-y-3" data-embedded-approval-form>
                                    @csrf
                                    <input type="hidden" name="context_token" value="{{ $contextToken }}">
                                    <input type="hidden" name="shopify_session_token" value="" data-embedded-session-token-input>
                                    <label class="block space-y-2">
                                        <span class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Rejection note</span>
                                        <textarea
                                            name="rejection_note"
                                            rows="3"
                                            class="w-full rounded-2xl border border-zinc-300 px-4 py-3 text-sm text-zinc-900 shadow-sm focus:border-zinc-500 focus:outline-none focus:ring-2 focus:ring-zinc-200"
                                            placeholder="Optional reason for rejection"
                                        >{{ old('rejection_note', (string) ($accessRequest->rejection_note ?? '')) }}</textarea>
                                    </label>
                                    <button type="submit" class="rounded-full bg-rose-700 px-4 py-2 text-xs font-semibold text-white hover:bg-rose-600 disabled:cursor-not-allowed disabled:opacity-60" data-embedded-approval-button data-embedded-pending-label="Rejecting…">
                                        Reject application
                                    </button>
                                </form>
                            @endif
                        </div>
                    @else
                        <div class="mt-3 space-y-3 text-sm text-zinc-600">
                            <p>Applications are viewable here, but approval actions stay locked until your Shopify admin email maps to an Everbranch operator account.</p>
                        </div>
                    @endif
                </section>

                <section class="fb-page-surface p-6">
                    <div class="text-sm font-semibold text-zinc-950">System record</div>
                    <dl class="mt-4 space-y-3 text-sm">
                        @foreach (($detailSections['system'] ?? []) as $row)
                            <div class="flex items-start justify-between gap-4">
                                <dt class="text-zinc-500">{{ $row['label'] }}</dt>
                                <dd class="text-right font-medium text-zinc-900">{{ $row['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            </div>
        </section>
    </div>

    <script>
        (function () {
            const tokenInputs = Array.from(document.querySelectorAll('[data-embedded-session-token-input]'));
            const actionForms = Array.from(document.querySelectorAll('[data-embedded-approval-form]'));
            const actionButtons = Array.from(document.querySelectorAll('[data-embedded-approval-button]'));
            const identityLabel = document.querySelector('[data-embedded-identity-label]');
            const approvalHelp = document.querySelector('[data-embedded-approval-help]');
            const sessionErrorBanner = document.querySelector('[data-embedded-session-error]');

            if (tokenInputs.length === 0) {
                return;
            }

            function decodePayload(token) {
                try {
                    const parts = String(token || '').split('.');
                    if (parts.length !== 3) {
                        return {};
                    }

                    const normalized = parts[1].replace(/-/g, '+').replace(/_/g, '/');
                    const padded = normalized + '='.repeat((4 - (normalized.length % 4)) % 4);
                    return JSON.parse(window.atob(padded));
                } catch (error) {
                    return {};
                }
            }

            function resolveSessionTokenHelper(timeoutMs = 10000) {
                return new Promise((resolve, reject) => {
                    const startedAt = Date.now();

                    const tick = () => {
                        const resolver = window.ForestryEmbeddedApp?.getShopifySessionToken;
                        if (typeof resolver === 'function') {
                            resolve(resolver.bind(window.ForestryEmbeddedApp));
                            return;
                        }

                        if ((Date.now() - startedAt) >= timeoutMs) {
                            reject(new Error('Shopify admin verification helper did not become available.'));
                            return;
                        }

                        window.setTimeout(tick, 120);
                    };

                    tick();
                });
            }

            let verificationFinished = false;

            function applyVerifiedToken(token) {
                tokenInputs.forEach((input) => {
                    input.value = token;
                });

                actionButtons.forEach((button) => {
                    button.removeAttribute('disabled');
                });

                const payload = decodePayload(token);
                const email = typeof payload.email === 'string' && payload.email.trim() !== ''
                    ? payload.email.trim().toLowerCase()
                    : null;

                if (identityLabel) {
                    identityLabel.textContent = email
                        ? `Signed in as ${email}.`
                        : 'Shopify admin identity verified.';
                }

                if (approvalHelp) {
                    approvalHelp.textContent = 'Approval actions are ready.';
                }

                if (sessionErrorBanner) {
                    const bannerMessage = sessionErrorBanner.textContent || '';
                    if (bannerMessage.toLowerCase().includes('verification')) {
                        sessionErrorBanner.remove();
                    }
                }

                verificationFinished = true;
            }

            function setButtonsDisabled(disabled) {
                actionButtons.forEach((button) => {
                    if (disabled) {
                        button.setAttribute('disabled', 'disabled');
                    } else {
                        button.removeAttribute('disabled');
                    }
                });
            }

            async function acquireSessionToken() {
                const resolver = await resolveSessionTokenHelper();
                const token = await resolver({
                    minTtlMs: 5000,
                    timeoutMs: 10000,
                    requestTimeoutMs: 10000,
                });

                if (typeof token !== 'string' || token.trim() === '') {
                    throw new Error('Missing Shopify admin session token.');
                }

                applyVerifiedToken(token.trim());

                return token.trim();
            }

            async function responsePayload(response) {
                const body = await response.text();
                if (body.trim() === '') {
                    return {};
                }

                try {
                    return JSON.parse(body);
                } catch (error) {
                    return {};
                }
            }

            actionForms.forEach((form) => {
                form.addEventListener('submit', async (event) => {
                    event.preventDefault();

                    if (form.dataset.embeddedSubmitting === 'true') {
                        return;
                    }

                    const submitter = event.submitter instanceof HTMLButtonElement
                        ? event.submitter
                        : form.querySelector('[data-embedded-approval-button]');
                    const actionUrl = submitter?.formAction || form.action;
                    const originalLabel = submitter?.textContent || '';

                    form.dataset.embeddedSubmitting = 'true';
                    setButtonsDisabled(true);
                    if (submitter) {
                        submitter.textContent = submitter.dataset.embeddedPendingLabel || 'Working…';
                    }
                    if (approvalHelp) {
                        approvalHelp.textContent = 'Verifying Shopify admin identity…';
                    }

                    try {
                        const token = await acquireSessionToken();
                        const formData = new FormData(form);
                        formData.set('shopify_session_token', token);

                        const response = await fetch(actionUrl, {
                            method: 'POST',
                            headers: {
                                Accept: 'application/json',
                                Authorization: `Bearer ${token}`,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: formData,
                            credentials: 'same-origin',
                        });
                        const payload = await responsePayload(response);

                        if (!response.ok || payload.ok !== true) {
                            throw new Error(payload.message || 'This application action could not be completed.');
                        }

                        if (approvalHelp) {
                            approvalHelp.textContent = payload.message || 'Application updated.';
                        }

                        if (typeof payload.redirect_url === 'string' && payload.redirect_url.trim() !== '') {
                            window.location.assign(payload.redirect_url);
                            return;
                        }

                        window.location.reload();
                    } catch (error) {
                        form.dataset.embeddedSubmitting = 'false';
                        setButtonsDisabled(false);
                        if (submitter) {
                            submitter.textContent = originalLabel;
                        }
                        if (approvalHelp) {
                            approvalHelp.textContent = error instanceof Error && error.message.trim() !== ''
                                ? error.message
                                : 'Shopify admin verification did not load. Reopen this app from Shopify Admin and try again.';
                        }
                    }
                });
            });

            function bootstrapEmbeddedIdentity() {
                if (verificationFinished) {
                    return;
                }

                acquireSessionToken()
                    .catch(() => {
                        if (approvalHelp) {
                            approvalHelp.textContent = 'Choose an action to retry Shopify admin verification.';
                        }
                    });
            }

            bootstrapEmbeddedIdentity();
            window.addEventListener('pageshow', bootstrapEmbeddedIdentity, { once: true });
            document.addEventListener('visibilitychange', () => {
                if (!verificationFinished && document.visibilityState === 'visible') {
                    bootstrapEmbeddedIdentity();
                }
            });
        })();
    </script>
</x-shopify-embedded-shell>

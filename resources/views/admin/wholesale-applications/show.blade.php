<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Wholesale Application Review</h1>
                <p class="mt-1 text-sm text-zinc-600">Captured submission details for {{ $accessRequest->email }}.</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('admin.wholesale.applications') }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                    Back to inbox
                </a>
                <a href="{{ route('admin.users', ['search' => $accessRequest->email]) }}" class="rounded-full bg-zinc-950 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800">
                    Open approval workspace
                </a>
            </div>
        </div>
    </x-slot>

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
                    @if ($canManageApproval)
                        <div class="mt-3 space-y-4">
                            <form method="POST" action="{{ route('admin.wholesale.applications.approve', $accessRequest) }}" class="space-y-3">
                                @csrf
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
                                        <button type="submit" formaction="{{ route('admin.wholesale.applications.resend-activation', $accessRequest) }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                                            Resend activation
                                        </button>
                                    @endif
                                </div>
                            </form>

                            @if ($accessRequest->status !== 'approved')
                                <form method="POST" action="{{ route('admin.wholesale.applications.reject', $accessRequest) }}" class="space-y-3">
                                    @csrf
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

                            <a href="{{ route('admin.users', ['search' => $accessRequest->email]) }}" class="inline-flex rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                                Open approval workspace
                            </a>
                        </div>
                    @else
                        <div class="mt-3 space-y-3 text-sm text-zinc-600">
                            <p>Your role can review the application here, but approval actions are reserved for landlord operators.</p>
                            <a href="{{ route('admin.users', ['search' => $accessRequest->email]) }}" class="inline-flex rounded-full bg-zinc-950 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800">
                                Review in approvals
                            </a>
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
</x-app-layout>

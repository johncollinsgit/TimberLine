@php
    $customerName = trim((string) ($document->customer?->first_name.' '.$document->customer?->last_name));
    $linkStatus = (string) data_get($document->metadata, 'quickbooks.job_link_status', 'needs_review');
@endphp

<x-layouts::app.sidebar title="QuickBooks document">
    <flux:main>
        <div class="fb-workflow-shell">
            <header class="fb-workflow-header">
                <div class="fb-eyebrow">{{ $tenant->name }} · QuickBooks Branch</div>
                <h1 class="fb-title-xl">{{ ucfirst($document->document_type) }} {{ $document->document_number ?: $document->external_id }}</h1>
                <p class="fb-subtitle">{{ $customerName ?: 'Customer not named' }} @if($document->transaction_date) · {{ $document->transaction_date->format('M j, Y') }} @endif</p>
                <div class="mt-4 flex flex-wrap gap-3">
                    <a href="{{ route('field-service.index') }}" class="fb-btn fb-btn-secondary">Back to work</a>
                    @if($document->job)
                        <a href="{{ route('field-service.jobs.show', ['job' => $document->job]) }}" class="fb-btn fb-btn-primary">Open linked job</a>
                    @endif
                </div>
            </header>

            <div class="grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
                <section class="fb-panel">
                    <div class="fb-panel-head">
                        <div>
                            <div class="fb-panel-title">Accounting summary</div>
                            <div class="fb-panel-copy">Owner/admin only. Imported read-only from QuickBooks.</div>
                        </div>
                    </div>
                    <div class="fb-panel-body space-y-3">
                        <dl class="grid gap-3 sm:grid-cols-2">
                            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3"><dt class="text-xs font-semibold uppercase text-zinc-500">Total</dt><dd class="mt-1 font-semibold">{{ $document->total_amount !== null ? '$'.number_format((float) $document->total_amount, 2) : 'Not provided' }}</dd></div>
                            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3"><dt class="text-xs font-semibold uppercase text-zinc-500">Balance</dt><dd class="mt-1 font-semibold">{{ $document->balance !== null ? '$'.number_format((float) $document->balance, 2) : 'Not provided' }}</dd></div>
                            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3"><dt class="text-xs font-semibold uppercase text-zinc-500">Status</dt><dd class="mt-1 font-semibold">{{ $document->status ?: 'Unknown' }}</dd></div>
                            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3"><dt class="text-xs font-semibold uppercase text-zinc-500">Job link</dt><dd class="mt-1 font-semibold">{{ $linkStatus === 'linked' ? 'Linked' : 'Needs review' }}</dd></div>
                        </dl>

                        @if($document->customer_memo)
                            <div class="rounded-xl border border-zinc-200 bg-white p-4"><div class="text-xs font-semibold uppercase text-zinc-500">Customer memo</div><p class="mt-2 whitespace-pre-line text-sm text-zinc-700">{{ $document->customer_memo }}</p></div>
                        @endif
                        @if($document->private_note)
                            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4"><div class="text-xs font-semibold uppercase text-amber-700">Private QuickBooks note</div><p class="mt-2 whitespace-pre-line text-sm text-amber-950">{{ $document->private_note }}</p></div>
                        @endif
                    </div>
                </section>

                <section class="fb-panel">
                    <div class="fb-panel-head">
                        <div>
                            <div class="fb-panel-title">Line items</div>
                            <div class="fb-panel-copy">Descriptions remain searchable even when this document is not yet linked to a field job.</div>
                        </div>
                    </div>
                    <div class="fb-panel-body space-y-2">
                        @forelse($document->lines as $line)
                            <div class="grid gap-2 rounded-xl border border-zinc-200 bg-white p-4 md:grid-cols-[1fr_auto]">
                                <div>
                                    <div class="font-semibold text-zinc-950">{{ $line->item_name ?: 'Description' }}</div>
                                    @if($line->description)<div class="mt-1 whitespace-pre-line text-sm text-zinc-600">{{ $line->description }}</div>@endif
                                    @if($line->quantity !== null)<div class="mt-2 text-xs text-zinc-500">Quantity {{ number_format((float) $line->quantity, 2) }} @if($line->unit_price !== null) · {{ '$'.number_format((float) $line->unit_price, 2) }} each @endif</div>@endif
                                </div>
                                <div class="font-semibold text-zinc-950">{{ $line->amount !== null ? '$'.number_format((float) $line->amount, 2) : '' }}</div>
                            </div>
                        @empty
                            <div class="fb-state">No line items were included in this QuickBooks document.</div>
                        @endforelse
                    </div>
                </section>
            </div>
        </div>
    </flux:main>
</x-layouts::app.sidebar>

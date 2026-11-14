<x-layouts::app :title="'Customer sources & reconciliation'">
    <main class="mx-auto max-w-6xl space-y-6 px-4 py-6 sm:px-6">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div><p class="text-sm text-zinc-500">{{ $tenant->name }} · Customers &amp; Marketing</p><h1 class="text-3xl font-semibold">Sources &amp; reconciliation</h1><p class="mt-2 max-w-2xl text-zinc-600">Bring older and recent purchases into the same customer history. Original records, group membership, permissions and rewards stay attached to their source.</p></div>
            <nav class="flex flex-wrap gap-3" aria-label="Customer sources"><a class="inline-flex min-h-11 items-center rounded-xl border px-4" href="{{ route('customers.directory', ['tenant'=>$tenant->slug]) }}">Customers</a><a class="inline-flex min-h-11 items-center rounded-xl border px-4" href="{{ route('marketing.event-audiences', ['tenant'=>$tenant->slug]) }}">Event audiences</a><a class="inline-flex min-h-11 items-center rounded-xl border px-4" href="{{ route('customers.square.index', ['tenant'=>$tenant->slug]) }}">Refresh Square</a></nav>
        </header>
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="space-y-4 rounded-2xl border bg-white p-5 dark:bg-zinc-900">
                <h2 class="text-xl font-semibold">Linked original sources</h2>
                <dl class="space-y-3">@forelse($sources as $source)<div class="flex justify-between gap-4"><dt>{{ ucwords(str_replace('_',' ',$source->source_type)) }}</dt><dd class="font-semibold tabular-nums">{{ number_format($source->linked) }}</dd></div>@empty<p class="text-zinc-500">No source associations have been reconciled in this workspace yet.</p>@endforelse</dl>
                <p class="text-sm text-zinc-500">Counts are source links, not unique customers or revenue. Confirmed orders and payments are deduplicated by their original provider IDs.</p>
            </section>
            <section class="space-y-4 rounded-2xl border bg-white p-5 dark:bg-zinc-900">
                <h2 class="text-xl font-semibold">Retained historical associations</h2><p><strong>{{ number_format($retained) }}</strong> original links on this workspace’s customers retain older ownership metadata.</p>
                <p class="text-sm text-zinc-500">A link is restored only when the existing customer and original source both prove this workspace and all original associations agree. Contact or card similarity does not merge customers. Already represented links are retained without duplication.</p>
                <dl class="grid grid-cols-3 gap-3"><div><dt class="text-sm text-zinc-500">In this batch</dt><dd class="text-2xl font-semibold">{{ $batch['scanned'] }}</dd></div><div><dt class="text-sm text-zinc-500">Proven to restore</dt><dd class="text-2xl font-semibold">{{ $batch['restored'] }}</dd></div><div><dt class="text-sm text-zinc-500">Needs review</dt><dd class="text-2xl font-semibold">{{ $batch['review_required'] }}</dd></div></dl>
                @if($batch['restored'])<form method="POST" action="{{ route('customers.reconciliation.restore', ['tenant'=>$tenant->slug]) }}">@csrf<input type="hidden" name="after_id" value="{{ $after }}"><input type="hidden" name="fingerprint" value="{{ $batch['fingerprint'] }}"><button class="min-h-12 rounded-xl bg-emerald-900 px-5 py-3 font-semibold text-white">Restore {{ $batch['restored'] }} proven links</button></form>@endif
                @if($batch['has_more'])<a class="inline-flex min-h-11 items-center rounded-xl border px-4" href="{{ route('customers.reconciliation', ['tenant'=>$tenant->slug, 'after_id'=>$batch['after_id']]) }}">Review next batch</a>@endif
                @if($batch['issues'])<details><summary class="min-h-11 cursor-pointer py-3">Retained records needing review</summary><ul class="max-h-64 space-y-2 overflow-y-auto text-sm">@foreach($batch['issues'] as $issue)<li>Link {{ $issue['link_id'] }} · {{ str_replace('_',' ',$issue['source_type']) }} · {{ str_replace('_',' ',$issue['reason']) }}</li>@endforeach</ul></details>@endif
            </section>
        </div>
        <section class="space-y-4 rounded-2xl border bg-white p-5 dark:bg-zinc-900">
            <h2 class="text-xl font-semibold">Recognized purchase places</h2>
            <p class="text-sm text-zinc-500">{{ count($purchasePlaces['labels'] ?? []) }} original tax-place labels are recognized for geographic audiences. They describe where a confirmed purchase happened, separately from the customer's saved home address or attendance at a specific show.</p>
            <form method="POST" action="{{ route('customers.reconciliation.purchase-place', ['tenant'=>$tenant->slug]) }}" class="grid gap-3 sm:grid-cols-2">@csrf
                <input type="hidden" name="expected_version" value="{{ $purchasePlaces['version'] }}">
                <label class="space-y-2"><span class="block">Original tax place label</span><input name="label" required maxlength="500" class="min-h-11 w-full rounded-lg border px-3" placeholder="City, county or venue name"></label>
                <label class="space-y-2"><span class="block">Purchase-place ZIPs</span><input name="postal_codes" required maxlength="100" class="min-h-11 w-full rounded-lg border px-3" placeholder="29201 or comma-separated ZIPs"></label>
                <button class="min-h-11 rounded-xl border px-4 py-3 sm:col-span-2">Recognize purchase place</button>
            </form>
            <details><summary class="min-h-11 cursor-pointer py-3">Review recognized tax labels and place ZIPs</summary><dl class="max-h-72 space-y-2 overflow-y-auto">@foreach($purchasePlaces['labels'] ?? [] as $place)<div class="flex flex-wrap justify-between gap-3"><dt>{{ $place['source_label'] }}</dt><dd>{{ implode(', ', $place['postal_codes']) }}</dd></div>@endforeach</dl></details>
        </section>
        <section class="space-y-4 rounded-2xl border bg-white p-5 dark:bg-zinc-900">
            <h2 class="text-xl font-semibold">Older starter audiences</h2><p class="text-sm text-zinc-500">These unchanged published starter definitions can be copied into this workspace as drafts. Original legacy definitions and saved group memberships are retained. Custom definitions without proven ownership need a separate review.</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@forelse($templates as $template)<form method="POST" action="{{ route('customers.reconciliation.template', ['tenant'=>$tenant->slug]) }}" class="rounded-xl border p-4">@csrf<input type="hidden" name="source_id" value="{{ $template['id'] }}"><input type="hidden" name="fingerprint" value="{{ $template['fingerprint'] }}"><p class="font-semibold">{{ $template['name'] }}</p><button class="mt-2 min-h-11 underline">Copy as a workspace draft</button></form>@empty<p class="text-sm text-zinc-500">No unchanged starter definitions are available to copy.</p>@endforelse</div>
        </section>
        <section class="space-y-4 rounded-2xl border bg-white p-5 dark:bg-zinc-900">
            <h2 class="text-xl font-semibold">Recent import &amp; reconciliation runs</h2><p class="text-sm text-zinc-500">A completed customer-directory run is separate from completed order and payment coverage. Refresh recent purchases as well as customers after an event. Historical source imports do not award rewards or send campaigns.</p>
            @forelse($runs as $run)<article class="rounded-xl border p-4"><div class="flex flex-wrap justify-between gap-3"><h3 class="font-semibold">{{ ucwords(str_replace('_',' ',$run->type)) }}</h3><span>{{ ucfirst($run->status) }} · {{ $run->created_at?->format('M j, Y g:i A') }}</span></div><p class="mt-2 text-sm text-zinc-500">@if(isset($run->summary['processed'])){{ number_format($run->summary['processed']) }} processed · @endif@if(isset($run->summary['restored'])){{ number_format($run->summary['restored']) }} restored · @endif{{ number_format($run->summary['review_required'] ?? $run->summary['reviews_created'] ?? 0) }} for review · {{ number_format($run->summary['errors'] ?? 0) }} errors</p>@if(data_get($run->summary,'checkpoint.cursor'))<p class="mt-2 text-sm">More provider pages remain. Resume the original run from Square source history.</p>@endif</article>@empty<p class="text-zinc-500">No workspace-owned imports have been recorded yet.</p>@endforelse
        </section>
    </main>
</x-layouts::app>

<x-layouts::app :title="'Square customer sources'">
<main class="mx-auto max-w-6xl space-y-6 p-6">
    <h1 class="text-2xl font-semibold">Square customers and source refresh</h1>
    <p>Keep Square as checkout, or use another reviewed checkout later. This connection reads original Square customers, card references, purchases and locations into the existing source records. It does not activate Everbranch Pay, change historical amounts or grant rewards.</p>
    @if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <p>Cached sources: {{ number_format($counts['customers']) }} customers · {{ number_format($counts['orders']) }} orders · {{ number_format($counts['payments']) }} payments. Cached coverage is separate from current provider availability.</p>
    @if(\Illuminate\Support\Facades\Route::has('customers.square.connect') && $oauth)
        <section class="rounded border p-4 space-y-3">
            <h2 class="font-semibold">Existing or new Square source connection</h2>
            <p>Owner-authorized read access only: customers, orders, payments and merchant locations. Keeping Square does not require Everbranch Pay.</p>
            @if($oauth['connection'])<p>Source status {{ $oauth['connection']['status'] ?? 'unknown' }} · connected {{ $oauth['connection']['connected_at'] ?? 'unknown' }} · authorization expires {{ $oauth['connection']['expires_at'] ?? 'unknown' }}. Original merchant/environment and history remain pinned across reconnects.</p>@endif
            <form method="POST" action="{{ route('customers.square.connect', ['tenant' => $tenant->slug]) }}">@csrf
                <input type="hidden" name="expected_generation" value="{{ $oauth['connection']['generation'] ?? 0 }}">
                <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <button type="submit" @disabled(!($oauth['configured'] ?? false))>{{ ($oauth['connection'] ?? null) ? 'Reconnect the original Square account' : 'Connect Square for source reads' }}</button>
            </form>
            @if(!($oauth['configured'] ?? false))<p>Square application and callback setup is required before connecting.</p>@endif
            @if($oauth['connection'] && \Illuminate\Support\Facades\Route::has('customers.square.disconnect'))
                <form method="POST" action="{{ route('customers.square.disconnect', ['tenant' => $tenant->slug]) }}">@csrf
                    <input type="hidden" name="expected_generation" value="{{ $oauth['connection']['generation'] ?? 0 }}"><input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <button type="submit">Disconnect source reads and retain original history</button>
                </form>
            @endif
        </section>
    @endif
    <section class="space-y-4" aria-label="Original Square connections">
        <h2 class="text-xl font-semibold">Refresh an original connection</h2>
        <p>Manual refresh preserves scheduled settings. Imports never earn or consume historical rewards, send messages, infer an event from a Square location, or link a wallet. Preview writes only the existing import audit.</p>
        @forelse($connections as $connection)
            @php($latest = $runs->first(fn($run) => data_get($run->summary, 'source_binding.connection_id') === $connection['id']))
            <article class="rounded border p-4 space-y-3">
                <h3 class="font-semibold">{{ str_replace('_', ' ', $connection['kind']) }} · {{ $connection['environment'] ?? 'environment needs review' }}</h3>
                <p>Original merchant {{ $connection['merchant_id'] ?? 'verified when read' }} · {{ $connection['id'] }}</p>
                @if($connection['kind'] === 'original_merchant_verified_legacy_reader')<p>The existing reader is used only after it proves access to this exact original merchant. Other companies cannot inherit it.</p>@endif
                <p>Last recorded batch: {{ $latest?->status ?? 'Not refreshed here' }} · {{ $latest?->finished_at ?? $latest?->started_at ?? 'Unknown' }} · {{ (int) data_get($latest?->summary, 'processed', 0) }} processed · {{ (int) data_get($latest?->summary, 'errors', 0) }} errors.</p>
                @if(data_get($latest?->summary, 'provider_error'))<p role="status">Provider read status {{ data_get($latest->summary, 'provider_error.http_status') }} · {{ implode(', ', data_get($latest->summary, 'provider_error.codes', [])) }}. Original checkpoint retained.</p>@endif
                <form method="POST" action="{{ route('customers.square.refresh', ['tenant' => $tenant->slug]) }}" class="space-y-3">@csrf
                    <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="connection_id" value="{{ $connection['id'] }}">
                    <input type="hidden" name="expected_connection_generation" value="{{ $connection['connection_generation'] ?? '' }}">
                    <label>Source <select name="sync_type"><option value="customers">Customers and declared provider preferences</option><option value="cards">Card references only</option><option value="orders">Historical order sources</option><option value="payments">Historical payment sources and recorded locations</option></select></label>
                    <label>Action <select name="mode"><option value="preview">Preview source changes</option><option value="import">Import source batch and stage identity review</option></select></label>
                    <label>Batch target <input name="limit" type="number" min="1" max="500" value="100"></label>
                    <label>Changed or paid after (optional) <input name="since" type="datetime-local"></label>
                    <label><input type="checkbox" name="create_unmatched" value="1">I authorize separate new Everbranch customer records for Square customer IDs with no current tenant contact, name or card candidate. Stage all collisions for review; do not create a portal account, wallet, marketing permission or reward.</label>
                    <p>Customer refresh filters provider updated dates; order/payment windows keep their original source dates. Complete provider pages are processed so a resume does not skip leftover page rows.</p>
                    <button type="submit" @disabled(($connection['status'] ?? 'active') !== 'active')>Refresh selected Square source</button>
                </form>
            </article>
        @empty<p>No tenant-owned Square source connection is recorded yet. Connect one above; no global company token is substituted.</p>@endforelse
    </section>
    <section class="space-y-3" aria-label="Square refresh runs">
        <h2 class="text-xl font-semibold">Progress and original checkpoints</h2>
        @forelse($runs as $run)
            <article class="rounded border p-4">
                <p>Run {{ $run->id }} · {{ $run->type }} · {{ $run->status }} · {{ (int) data_get($run->summary, 'processed', 0) }} processed · {{ (int) data_get($run->summary, 'reviews_created', 0) }} reviews · {{ (int) data_get($run->summary, 'errors', 0) }} errors</p>
                <p>{{ $run->notes }}</p>
                @if((data_get($run->summary, 'checkpoint.cursor') || data_get($run->summary, 'retry_page')) && data_get($run->summary, 'source_binding.connection_id'))
                    <p>More provider pages remain or the original page needs retry. Earlier source rows may already be saved; replay uses the same source IDs. This is a bounded batch, not complete customer/history coverage.</p>
                    <form method="POST" action="{{ route('customers.square.refresh', ['tenant' => $tenant->slug]) }}">@csrf
                        <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="resume_run_id" value="{{ $run->id }}">
                        <input type="hidden" name="connection_id" value="{{ data_get($run->summary, 'source_binding.connection_id') }}"><input type="hidden" name="expected_connection_generation" value="{{ data_get($run->summary, 'source_binding.connection_generation') }}">
                        <input type="hidden" name="sync_type" value="{{ str_replace(['square_', '_sync'], '', $run->type) }}"><input type="hidden" name="mode" value="{{ data_get($run->summary, 'mode') === 'dry-run' ? 'preview' : 'import' }}"><input type="hidden" name="limit" value="100">
                        <input type="hidden" name="create_unmatched" value="{{ data_get($run->summary, 'create_unmatched') ? '1' : '0' }}">
                        <button type="submit">Resume original run {{ $run->id }}</button>
                    </form>
                @endif
            </article>
        @empty<p>No refresh runs recorded for this tenant.</p>@endforelse
    </section>
    <section class="space-y-4" aria-label="Square identity reviews">
        <h2 class="text-xl font-semibold">Find an existing customer or keep a new source customer separate</h2>
        <p>Email, phone, names and card similarities are review suggestions. They never establish account ownership, wallet access or marketing permission. A Square unsubscribe suppresses that channel; a receipt address, visible phone or “not unsubscribed” state is not opt-in.</p>
        @foreach($reviews as $review)
            <article class="rounded border p-4 space-y-3">
                <h3 class="font-semibold">{{ $review->raw_first_name }} {{ $review->raw_last_name }} · Square {{ $review->source_id }}</h3>
                <p>{{ $review->raw_email }} · {{ $review->raw_phone }}</p>
                <p>{{ implode(', ', $review->conflict_reasons ?? []) }}</p>
                <p>Candidate tenant customer IDs: {{ implode(', ', data_get($review->payload, 'candidate_profile_ids', [])) ?: 'None found in this bounded check' }}. Search Core Customers to verify the person independently.</p>
                <form method="POST" action="{{ route('customers.square.review', ['tenant' => $tenant->slug, 'review' => $review->id]) }}" class="space-y-3">@csrf
                    <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="expected_version" value="{{ $identities->version($review) }}">
                    <label>Decision <select name="decision"><option value="skip">Keep pending sources separate</option><option value="link_existing">Associate with a verified existing tenant customer</option><option value="create_new">Create a separate new tenant customer</option></select></label>
                    <label>Existing canonical customer ID <input name="profile_id" type="number" min="1"></label>
                    <label>Review reason <textarea name="reason" required minlength="12" maxlength="1000"></textarea></label>
                    <label><input type="checkbox" name="identity_confirmed" value="1" required>I independently verified this source/customer association; contact or card similarity alone is insufficient.</label>
                    <button type="submit">Record identity decision</button>
                </form>
            </article>
        @endforeach
        {{ $reviews->withQueryString()->links() }}
    </section>
</main>
</x-layouts::app>

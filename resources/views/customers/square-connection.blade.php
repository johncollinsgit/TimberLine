<x-layouts::app :title="'Square customer sources'">
@once
<style>
    .eb-square-connection { max-width: 1152px; margin: 0 auto; padding: 28px; color: var(--eb-text-primary, #0f1c1f); font: 400 14px/1.6 var(--eb-font-ui, Inter, ui-sans-serif, system-ui, sans-serif); }
    .eb-square-connection, .eb-square-connection * { box-sizing: border-box; min-width: 0; }
    .eb-square-connection h1, .eb-square-connection h2, .eb-square-connection h3 { margin: 0; color: inherit; font-family: inherit; font-weight: 650; letter-spacing: -.025em; line-height: 1.3; }
    .eb-square-connection h1 { font-size: clamp(24px, 3vw, 30px); }
    .eb-square-connection h2 { font-size: 20px; }
    .eb-square-connection h3 { font-size: 16px; }
    .eb-square-connection p { margin: 0; color: var(--eb-text-secondary, #32464b); overflow-wrap: anywhere; }
    .eb-square-connection section, .eb-square-connection header { display: grid; gap: 16px; }
    .eb-square-connection > * + * { margin-top: 28px; }
    .eb-square-connection .eb-square-card { display: grid; gap: 18px; padding: 24px; border: 1px solid var(--eb-border, #dbe4e3); border-radius: 16px; background: var(--eb-surface, #fff); box-shadow: var(--eb-shadow-sm, 0 8px 24px -20px #0a1b1f2e); }
    .eb-square-connection .eb-square-copy { max-width: 84ch; }
    .eb-square-connection .eb-square-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; padding: 12px 16px; border-radius: 10px; background: var(--eb-surface-muted, #f1f5f4); }
    .eb-square-connection .eb-square-status { display: inline-block; padding: 3px 10px; border: 1px solid var(--eb-border, #dbe4e3); border-radius: 999px; background: var(--eb-surface, #fff); color: var(--eb-text-primary, #0f1c1f); font-weight: 600; overflow-wrap: anywhere; }
    .eb-square-connection .eb-square-id { display: block; margin-top: 4px; color: var(--eb-text-primary, #0f1c1f); font-size: 14px; font-weight: 400; line-height: 1.6; overflow-wrap: anywhere; }
    .eb-square-connection .eb-square-details { padding-top: 4px; border-top: 1px solid var(--eb-border, #dbe4e3); }
    .eb-square-connection .eb-square-details summary { min-height: 44px; padding: 10px 0; color: var(--eb-brand, #123c43); font-weight: 600; cursor: pointer; }
    .eb-square-connection .eb-square-details dl { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 24px; margin: 8px 0 0; }
    .eb-square-connection .eb-square-details dt { color: var(--eb-text-muted, #5d6b6a); }
    .eb-square-connection .eb-square-details dd { margin: 3px 0 0; color: var(--eb-text-secondary, #32464b); overflow-wrap: anywhere; }
    .eb-square-connection .eb-square-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; gap: 20px; }
    .eb-square-connection .eb-square-field { display: grid; gap: 8px; font-weight: 600; color: var(--eb-text-primary, #0f1c1f); }
    .eb-square-connection .eb-square-wide { grid-column: 1 / -1; }
    .eb-square-connection input:not([type=hidden]):not([type=checkbox]), .eb-square-connection select, .eb-square-connection textarea { display: block; width: 100%; max-width: 100%; min-height: 44px; margin: 0; padding: 10px 12px; border: 1px solid var(--eb-border, #dbe4e3); border-radius: 9px; background: var(--eb-surface, #fff); color: var(--eb-text-primary, #0f1c1f); font: 400 14px/1.5 var(--eb-font-ui, Inter, ui-sans-serif, system-ui, sans-serif); }
    .eb-square-connection select { overflow: hidden; padding-right: 30px; text-overflow: ellipsis; }
    .eb-square-connection textarea { min-height: 112px; resize: vertical; }
    .eb-square-connection .eb-square-check { display: flex; align-items: flex-start; gap: 12px; min-height: 44px; padding: 16px; border: 1px solid var(--eb-border, #dbe4e3); border-radius: 10px; background: var(--eb-surface-subtle, #f8fbfa); font-weight: 400; }
    .eb-square-connection input[type=checkbox] { flex: 0 0 18px; width: 18px; height: 18px; margin: 3px 0 0; accent-color: var(--eb-brand, #123c43); }
    .eb-square-connection .eb-square-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
    .eb-square-connection .eb-square-clear { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 10px 12px; color: var(--eb-brand, #123c43); font-weight: 600; }
    .eb-square-connection button { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; max-width: 100%; padding: 11px 18px; border: 1px solid var(--eb-brand, #123c43); border-radius: 9px; background: var(--eb-brand, #123c43); color: #fff; font: 600 14px/1.5 var(--eb-font-ui, Inter, ui-sans-serif, system-ui, sans-serif); text-align: center; white-space: normal; cursor: pointer; }
    .eb-square-connection .eb-square-secondary { border-color: var(--eb-border, #dbe4e3); background: var(--eb-surface, #fff); color: var(--eb-text-primary, #0f1c1f); }
    .eb-square-connection button:disabled { opacity: .5; cursor: not-allowed; }
    .eb-square-connection :is(input, select, textarea, button, a, summary):focus-visible { outline: 3px solid var(--eb-accent, #1e5a63); outline-offset: 3px; }
    .eb-square-connection .eb-square-notice { display: grid; gap: 6px; padding: 14px 16px; border: 1px solid #ead7ad; border-radius: 10px; background: #fffbef; color: #714f16; }
    .eb-square-connection .eb-square-notice p { color: inherit; }
    @media (max-width: 639px) {
        .eb-square-connection { padding: 20px 12px; }
        .eb-square-connection .eb-square-card { padding: 18px 16px; }
        .eb-square-connection .eb-square-grid { grid-template-columns: minmax(0, 1fr); gap: 16px; }
        .eb-square-connection .eb-square-details dl { grid-template-columns: minmax(0, 1fr); }
        .eb-square-connection .eb-square-actions { display: grid; grid-template-columns: minmax(0, 1fr); }
        .eb-square-connection button, .eb-square-connection .eb-square-actions form { width: 100%; }
    }
</style>
@endonce
@php
    $squareConnectionDate = static function ($value): string {
        if (!$value) return 'date unknown';
        try { return \Carbon\CarbonImmutable::parse($value)->format('M j, Y'); }
        catch (\Throwable) { return 'date unknown'; }
    };
@endphp
<main class="eb-square-connection">
    <header>
        <h1>Square customers and source refresh</h1>
        <p class="eb-square-copy">Keep Square as checkout, or use another reviewed checkout later. This connection reads original Square customers, card references, purchases and locations into the existing source records. It does not activate Everbranch Pay, change historical amounts or grant rewards.</p>
        <p class="eb-square-meta">Cached sources: {{ number_format($counts['customers']) }} customers · {{ number_format($counts['orders']) }} orders · {{ number_format($counts['payments']) }} payments. Cached coverage is separate from current provider availability.</p>
    </header>
    @if($errors->any())<div class="eb-square-notice" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @if(isset($sourceRefresh))
        <section class="eb-square-card">
            <h2>Keep recent customers and purchases current</h2>
            <p>Choose whether this workspace should refresh its original Square customers, orders and payments after events. A connection alone leaves this off. Confirmed event allocation and customer attribution retain their existing review rules.</p>
            <form method="POST" action="{{ route('customers.square.refresh-settings', ['tenant'=>$tenant->slug]) }}" class="eb-square-grid">
                @csrf<input type="hidden" name="expected_version" value="{{ $sourceRefresh['version'] }}"><input type="hidden" name="enabled" value="0"><input type="hidden" name="create_unmatched" value="0">
                <label class="eb-square-field">Original Square reader<select name="connection_id">@foreach($connections as $connection)<option value="{{ $connection['id'] }}" @selected(($sourceRefresh['binding']['connection_id'] ?? '') === $connection['id'])>{{ $connection['kind'] }} · {{ $connection['environment'] }}</option>@endforeach</select></label>
                <label class="eb-square-field">Recent history window in days<input name="lookback_days" type="number" min="1" max="90" value="{{ $sourceRefresh['lookback_days'] }}"></label>
                <label class="eb-square-check eb-square-wide"><input type="checkbox" name="enabled" value="1" @checked($sourceRefresh['enabled'])><span>Automatically refresh this reviewed original reader. Customer and purchase source reads only; no campaigns or historical reward awards.</span></label>
                <label class="eb-square-check eb-square-wide"><input type="checkbox" name="create_unmatched" value="1" @checked($sourceRefresh['create_unmatched'])><span>Create contact-only customer records for genuinely unmatched original Square customer IDs. Conflicting identities stay in review; marketing permission is not inferred.</span></label>
                <div class="eb-square-actions eb-square-wide"><button>Save source refresh settings</button><span class="eb-square-status">{{ $sourceRefresh['enabled'] ? 'Reviewed refresh enabled' : 'Automatic source refresh off' }}</span></div>
            </form>
            <p>Background refresh resumes saved provider pages. Supported verified webhook updates use the same original source policy when enabled; final delivery and source freshness remain visible in import runs.</p>
        </section>
    @endif
    @if(\Illuminate\Support\Facades\Route::has('customers.square.connect') && $oauth)
        <section class="eb-square-card">
            <h2>Existing or new Square source connection</h2>
            <p>Owner-authorized read access only: customers, orders, payments and merchant locations. Keeping Square does not require Everbranch Pay.</p>
            @if($oauth['connection'])
                <p class="eb-square-meta"><span class="eb-square-status">{{ str_replace('_', ' ', $oauth['connection']['status'] ?? 'unknown') }}</span><span>Connected {{ $squareConnectionDate($oauth['connection']['connected_at'] ?? null) }}</span><span>Authorization expires {{ $squareConnectionDate($oauth['connection']['expires_at'] ?? null) }}</span></p>
                <p>Original merchant/environment and history remain pinned across reconnects.</p>
                <details class="eb-square-details">
                    <summary>Source details</summary>
                    <dl>
                        <div><dt>Original merchant</dt><dd>{{ $oauth['connection']['merchant_id'] ?? 'unknown' }}</dd></div>
                        <div><dt>Environment</dt><dd>{{ $oauth['connection']['environment'] ?? 'environment unknown' }}</dd></div>
                        <div><dt>Connection ID</dt><dd>{{ $oauth['connection']['connection_id'] }}</dd></div>
                        <div><dt>Connection generation</dt><dd>{{ $oauth['connection']['generation'] }}</dd></div>
                        <div><dt>Exact connected timestamp</dt><dd>{{ $oauth['connection']['connected_at'] ?? 'unknown' }}</dd></div>
                        <div><dt>Exact authorization expiry</dt><dd>{{ $oauth['connection']['expires_at'] ?? 'unknown' }}</dd></div>
                    </dl>
                </details>
            @endif
            <div class="eb-square-actions">
            <form method="POST" action="{{ route('customers.square.connect', ['tenant' => $tenant->slug]) }}">@csrf
                <input type="hidden" name="expected_generation" value="{{ $oauth['connection']['generation'] ?? 0 }}">
                <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <button type="submit" @disabled(!($oauth['configured'] ?? false))>{{ ($oauth['connection'] ?? null) ? 'Reconnect the original Square account' : 'Connect Square for source reads' }}</button>
            </form>
            @if($oauth['connection'] && \Illuminate\Support\Facades\Route::has('customers.square.disconnect'))
                <form method="POST" action="{{ route('customers.square.disconnect', ['tenant' => $tenant->slug]) }}">@csrf
                    <input type="hidden" name="expected_generation" value="{{ $oauth['connection']['generation'] ?? 0 }}"><input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <button class="eb-square-secondary" type="submit">Disconnect source reads and retain original history</button>
                </form>
            @endif
            </div>
            @if(!($oauth['configured'] ?? false))<p>Square application and callback setup is required before connecting.</p>@endif
        </section>
    @endif
    <section aria-label="Original Square connections">
        <h2>Refresh an original connection</h2>
        <p>Manual refresh preserves scheduled settings. Imports never earn or consume historical rewards, send messages, infer an event from a Square location, or link a wallet. Preview writes only the existing import audit.</p>
        @forelse($connections as $connection)
            @php
                $latest = $runs->first(fn($run) => data_get($run->summary, 'source_binding.connection_id') === $connection['id']);
            @endphp
            <article class="eb-square-card">
                <h3>Square source · {{ $connection['environment'] ?? 'environment needs review' }}</h3>
                @if($connection['kind'] === 'original_merchant_verified_legacy_reader')<p>The existing reader is used only after it proves access to this exact original merchant. Other companies cannot inherit it.</p>@endif
                <p class="eb-square-meta">Last recorded batch: <span class="eb-square-status">{{ $latest?->status ?? 'Not refreshed here' }}</span><span>{{ $squareConnectionDate($latest?->finished_at ?? $latest?->started_at) }} · {{ (int) data_get($latest?->summary, 'processed', 0) }} processed · {{ (int) data_get($latest?->summary, 'errors', 0) }} errors.</span></p>
                <details class="eb-square-details">
                    <summary>Source details</summary>
                    <dl>
                        <div><dt>Original merchant</dt><dd>{{ $connection['merchant_id'] ?? 'verified when read' }}</dd></div>
                        <div><dt>Connection ID</dt><dd>{{ $connection['id'] }}</dd></div>
                        <div><dt>Connection kind</dt><dd>{{ str_replace('_', ' ', $connection['kind']) }}</dd></div>
                        <div><dt>Connection generation</dt><dd>{{ $connection['connection_generation'] ?? 'unknown' }}</dd></div>
                        <div><dt>Exact last batch timestamp</dt><dd>{{ $latest?->finished_at ?? $latest?->started_at ?? 'Unknown' }}</dd></div>
                    </dl>
                </details>
                @if(data_get($latest?->summary, 'provider_error'))<p class="eb-square-notice" role="status">Provider read status {{ data_get($latest->summary, 'provider_error.http_status') }} · {{ implode(', ', data_get($latest->summary, 'provider_error.codes', [])) }}. Original checkpoint retained.</p>@endif
                <form method="POST" action="{{ route('customers.square.refresh', ['tenant' => $tenant->slug]) }}" class="eb-square-grid">@csrf
                    <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="connection_id" value="{{ $connection['id'] }}">
                    <input type="hidden" name="expected_connection_generation" value="{{ $connection['connection_generation'] ?? '' }}">
                    <label class="eb-square-field">Source <select name="sync_type"><option value="customers">Customers and declared provider preferences</option><option value="cards">Card references only</option><option value="orders">Historical order sources</option><option value="payments">Historical payment sources and recorded locations</option></select></label>
                    <label class="eb-square-field">Action <select name="mode"><option value="preview">Preview source changes</option><option value="import">Import source batch and stage identity review</option></select></label>
                    <label class="eb-square-field">Batch target <input name="limit" type="number" min="1" max="500" value="100"></label>
                    <label class="eb-square-field">Changed or paid after (optional) <input name="since" type="datetime-local"></label>
                    <label class="eb-square-check eb-square-wide"><input type="checkbox" name="create_unmatched" value="1"><span>I authorize separate new Everbranch customer records for Square customer IDs with no current tenant contact, name or card candidate. Stage all collisions for review; do not create a portal account, wallet, marketing permission or reward.</span></label>
                    <p class="eb-square-wide">Customer refresh filters provider updated dates; order/payment windows keep their original source dates. Complete provider pages are processed so a resume does not skip leftover page rows.</p>
                    <div class="eb-square-actions eb-square-wide"><button type="submit" @disabled(($connection['status'] ?? 'active') !== 'active')>Refresh selected Square source</button></div>
                </form>
            </article>
        @empty<p>No tenant-owned Square source connection is recorded yet. Connect one above; no global company token is substituted.</p>@endforelse
    </section>
    <section aria-label="Square refresh runs">
        <h2>Progress and original checkpoints</h2>
        @forelse($runs as $run)
            @php
                $runLabel = match($run->type) { 'square_customers_sync' => 'Customer source refresh', 'square_orders_sync' => 'Order source refresh', 'square_payments_sync' => 'Payment source refresh', 'square_cards_sync' => 'Card reference refresh', default => 'Square source refresh' };
            @endphp
            <article class="eb-square-card">
                <h3>{{ $runLabel }}</h3>
                <p class="eb-square-meta"><span class="eb-square-status">{{ $run->status }}</span><span>{{ $squareConnectionDate($run->finished_at ?? $run->started_at) }} · {{ (int) data_get($run->summary, 'processed', 0) }} processed · {{ (int) data_get($run->summary, 'reviews_created', 0) }} reviews · {{ (int) data_get($run->summary, 'errors', 0) }} errors</span></p>
                <p>{{ $run->notes }}</p>
                <details class="eb-square-details">
                    <summary>Source details</summary>
                    <dl>
                        <div><dt>Original run ID</dt><dd>{{ $run->id }}</dd></div>
                        <div><dt>Original source type</dt><dd>{{ $run->type }}</dd></div>
                        <div><dt>Connection ID</dt><dd>{{ data_get($run->summary, 'source_binding.connection_id') ?? 'unknown' }}</dd></div>
                        <div><dt>Exact batch timestamp</dt><dd>{{ $run->finished_at ?? $run->started_at ?? 'Unknown' }}</dd></div>
                    </dl>
                </details>
                @if((data_get($run->summary, 'checkpoint.cursor') || data_get($run->summary, 'retry_page')) && data_get($run->summary, 'source_binding.connection_id'))
                    <p>More provider pages remain or the original page needs retry. Earlier source rows may already be saved; replay uses the same source IDs. This is a bounded batch, not complete customer/history coverage.</p>
                    <form method="POST" action="{{ route('customers.square.refresh', ['tenant' => $tenant->slug]) }}">@csrf
                        <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="resume_run_id" value="{{ $run->id }}">
                        <input type="hidden" name="connection_id" value="{{ data_get($run->summary, 'source_binding.connection_id') }}"><input type="hidden" name="expected_connection_generation" value="{{ data_get($run->summary, 'source_binding.connection_generation') }}">
                        <input type="hidden" name="sync_type" value="{{ str_replace(['square_', '_sync'], '', $run->type) }}"><input type="hidden" name="mode" value="{{ data_get($run->summary, 'mode') === 'dry-run' ? 'preview' : 'import' }}"><input type="hidden" name="limit" value="100">
                        <input type="hidden" name="create_unmatched" value="{{ data_get($run->summary, 'create_unmatched') ? '1' : '0' }}">
                        <button type="submit">Resume original source batch</button>
                    </form>
                @endif
            </article>
        @empty<p>No refresh runs recorded for this tenant.</p>@endforelse
    </section>
    <section aria-label="Square identity reviews">
        <h2>Find an existing customer or keep a new source customer separate</h2>
        <p>Email, phone, names and card similarities are review suggestions. They never establish account ownership, wallet access or marketing permission. A Square unsubscribe suppresses that channel; a receipt address, visible phone or “not unsubscribed” state is not opt-in.</p>
        <form method="GET" action="{{ route('customers.square.index') }}" class="eb-square-card">
            <input type="hidden" name="tenant" value="{{ $tenant->slug }}">
            <label class="eb-square-field">Find a customer by name, phone or email<input type="search" name="customer_query" value="{{ $customerQuery }}" minlength="2" maxlength="120" required autocomplete="off"></label>
            <div class="eb-square-actions"><button type="submit">Find customers</button><a class="eb-square-clear" href="{{ route('customers.square.index', ['tenant' => $tenant->slug]) }}">Clear search</a></div>
            @if($customerQuery !== '')
                <p>{{ count($customerSearch['customers']) }} matching customers shown for “{{ $customerQuery }}”. Search results are available in each review's customer selector.</p>
                @if($customerSearch['has_more'])<p>More records match. Refine your search to see a smaller set.</p>@endif
            @endif
        </form>
        @foreach($reviews as $review)
            @php
                $suggestedCustomers = $reviewCustomerChoices[$review->id] ?? [];
                $suggestedCustomerIds = array_column($suggestedCustomers, 'id');
                $searchedCustomers = array_values(array_filter($customerSearch['customers'], fn ($customer) => !in_array($customer['id'], $suggestedCustomerIds, true)));
            @endphp
            <article class="eb-square-card">
                <h3>{{ $review->raw_first_name }} {{ $review->raw_last_name }}</h3>
                <p>{{ $review->raw_email }} · {{ $review->raw_phone }}</p>
                <p>{{ str_replace('_', ' ', implode(', ', $review->conflict_reasons ?? [])) }}</p>
                <p>Choose a suggested customer or search for another existing customer above. Verify the person independently before associating records.</p>
                <details class="eb-square-details">
                    <summary>Source details</summary>
                    <dl>
                        <div><dt>Original Square customer ID</dt><dd>{{ $review->source_id }}</dd></div>
                        <div><dt>Recorded review reasons</dt><dd>{{ implode(', ', $review->conflict_reasons ?? []) }}</dd></div>
                        <div><dt>Original suggested customer IDs</dt><dd>{{ implode(', ', data_get($review->payload, 'candidate_profile_ids', [])) ?: 'None found in this bounded check' }}</dd></div>
                    </dl>
                </details>
                <form method="POST" action="{{ route('customers.square.review', ['tenant' => $tenant->slug, 'review' => $review->id]) }}" class="eb-square-grid" x-data="{ decision: 'skip' }">@csrf
                    <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="expected_version" value="{{ $identities->version($review) }}">
                    <label class="eb-square-field">Decision <select name="decision" x-model="decision"><option value="skip">Keep pending sources separate</option><option value="link_existing">Associate with a verified existing tenant customer</option><option value="create_new">Create a separate new tenant customer</option></select></label>
                    <label class="eb-square-field">Existing customer
                        <select name="profile_id" x-bind:required="decision === 'link_existing'">
                            <option value="" selected>Choose a customer</option>
                            @if($suggestedCustomers)
                                <optgroup label="Suggested from this source">
                                    @foreach($suggestedCustomers as $customer)<option value="{{ $customer['id'] }}">{{ $customer['name'] ?: 'Name not recorded' }}{{ $customer['phone'] ? ' · '.$customer['phone'] : '' }}{{ $customer['email'] ? ' · '.$customer['email'] : '' }}</option>@endforeach
                                </optgroup>
                            @endif
                            @if($searchedCustomers)
                                <optgroup label="Other matching customers">
                                    @foreach($searchedCustomers as $customer)<option value="{{ $customer['id'] }}">{{ $customer['name'] ?: 'Name not recorded' }}{{ $customer['phone'] ? ' · '.$customer['phone'] : '' }}{{ $customer['email'] ? ' · '.$customer['email'] : '' }}</option>@endforeach
                                </optgroup>
                            @endif
                        </select>
                    </label>
                    <label class="eb-square-field eb-square-wide">Review reason <textarea name="reason" rows="4" required minlength="12" maxlength="1000"></textarea></label>
                    <label class="eb-square-check eb-square-wide"><input type="checkbox" name="identity_confirmed" value="1" required><span>I independently verified this source/customer association; contact or card similarity alone is insufficient.</span></label>
                    <div class="eb-square-actions eb-square-wide"><button type="submit">Record identity decision</button></div>
                </form>
            </article>
        @endforeach
        {{ $reviews->withQueryString()->links() }}
    </section>
</main>
</x-layouts::app>

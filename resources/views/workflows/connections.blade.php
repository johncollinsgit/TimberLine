<x-layouts::app :title="'Automation connections'">
    <div class="min-h-full bg-stone-50"><div class="mx-auto max-w-7xl space-y-6 px-4 py-7 sm:px-6 lg:px-8">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><span class="text-xs font-black uppercase tracking-[0.2em] text-emerald-700">Connected apps</span><h1 class="mt-2 text-3xl font-black text-zinc-950">Connections</h1><p class="mt-2 max-w-2xl text-sm text-zinc-600">Connect once, reuse the account in multiple workflows. Everbranch never shows stored tokens or app secrets here.</p></div></header>
        <x-workflows.partials.nav />
        <div class="grid gap-5 lg:grid-cols-2">
            @foreach($providers as $key => $provider)
                @php
                    $legacy = $key === 'asana' ? $asanaConnection : ($key === 'google_calendar' ? $googleConnection : []);
                    $normalized = $connections->firstWhere('provider', $key);
                    $commerceStatus = (array) ($commerceStatuses[$key] ?? []);
                    $providerConnections = $connections->where('provider', $key);
                    $connectionStatus = (string) ($legacy['connection_status'] ?? $commerceStatus['connection_status'] ?? $normalized?->status ?? 'disconnected');
                    $reconnectRequired = $connectionStatus === 'error' || $normalized?->last_error_code === 'reconnect_required';
                    $connected = !$reconnectRequired && ($key === 'asana' ? (bool)($legacy['oauth_connected'] ?? false) : ($key === 'google_calendar' ? (bool)($legacy['connected'] ?? false) : (bool)($commerceStatus['connected'] ?? false)));
                    $ready = in_array($key, ['asana','google_calendar'], true) || (bool)($commerceStatus['configured'] ?? false);
                @endphp
                <article class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start gap-4"><x-workflows.partials.provider-icon :provider="$key" :providers="$providers" size="lg" /><div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><h2 class="text-lg font-black text-zinc-950">{{ $provider['label'] }}</h2><span class="rounded-full px-2 py-0.5 text-[10px] font-black {{ $connected ? 'bg-emerald-100 text-emerald-800' : ($reconnectRequired ? 'bg-rose-100 text-rose-800' : ($ready ? 'bg-amber-100 text-amber-800' : 'bg-zinc-100 text-zinc-500')) }}">{{ $connected ? 'Active' : ($reconnectRequired ? 'Reconnect required' : ($ready ? 'Not connected' : 'Connector beta')) }}</span></div><p class="mt-1 text-sm text-zinc-500">{{ $normalized?->external_account_label ?: ($connected ? 'Connected for this workspace' : 'No account connected') }}</p></div></div>
                    <div class="mt-5 grid gap-2 rounded-2xl bg-zinc-50 p-4 text-xs text-zinc-600">
                        <div><span class="font-black text-zinc-900">Account:</span> {{ $legacy['account_label'] ?? $normalized?->external_account_label ?? ($connected ? 'Connected workspace account' : 'Not connected') }}</div>
                        <div><span class="font-black text-zinc-900">Last checked:</span> {{ filled($legacy['last_checked_at'] ?? $normalized?->last_synced_at) ? \Illuminate\Support\Carbon::parse($legacy['last_checked_at'] ?? $normalized?->last_synced_at)->diffForHumans() : 'Never' }}</div>
                        <div><span class="font-black text-zinc-900">Used by:</span>
                            @forelse(data_get($usage, $key, []) as $usedBy)
                                <a href="{{ route('workflows.show', $usedBy['id']) }}" class="font-bold text-emerald-800 underline decoration-emerald-300 underline-offset-2">{{ $usedBy['name'] }}</a>{{ $loop->last ? '' : ', ' }}
                            @empty
                                No workflows yet
                            @endforelse
                        </div>
                    </div>
                    @if(in_array($key, ['asana','google_calendar'], true) && $ready)<div class="mt-4 flex flex-wrap gap-2">@if($connected)<form method="POST" action="{{ $key === 'asana' ? route('workflows.connections.asana.test') : route('workflows.connections.google.test') }}">@csrf<button class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-bold text-emerald-800">Test connection</button></form><form method="POST" action="{{ $key === 'asana' ? route('workflows.connections.asana.disconnect') : route('workflows.connections.google.disconnect') }}" onsubmit="return confirm('Disconnect this account? Active workflows that use it will stop working until reconnected.')">@csrf<button class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-bold text-rose-800">Disconnect</button></form><form method="POST" action="{{ $key === 'asana' ? route('workflows.connections.asana.connect') : route('workflows.connections.google.connect') }}">@csrf<button class="rounded-xl border border-zinc-300 bg-white px-4 py-2 text-sm font-bold text-zinc-800">Reconnect</button></form>@else<form method="POST" action="{{ $key === 'asana' ? route('workflows.connections.asana.connect') : route('workflows.connections.google.connect') }}">@csrf<button class="rounded-xl bg-zinc-950 px-4 py-2 text-sm font-bold text-white">{{ $reconnectRequired ? 'Reconnect' : 'Connect' }} {{ $provider['label'] }}</button></form>@endif</div>
                    @elseif($ready)
                        <div class="mt-4 space-y-3">
                            @foreach($providerConnections as $providerConnection)
                                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-zinc-200 p-3"><div class="text-xs"><strong class="text-zinc-900">{{ $providerConnection->external_account_label ?: $provider['label'].' account' }}</strong><span class="ml-2 text-zinc-500">{{ str($providerConnection->status)->headline() }}</span></div><div class="flex gap-2"><form method="POST" action="{{ route('workflows.connections.commerce.test', [$key, $providerConnection]) }}">@csrf<button class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-800">Test</button></form><form method="POST" action="{{ route('workflows.connections.commerce.disconnect', [$key, $providerConnection]) }}" onsubmit="return confirm('Disconnect this account and pause workflows that use it?')">@csrf<button class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-bold text-rose-800">Disconnect</button></form></div></div>
                            @endforeach
                            <form method="POST" action="{{ route('workflows.connections.commerce.connect', $key) }}" class="flex flex-col gap-2 sm:flex-row">
                                @csrf
                                @if($key === 'shopify')
                                    <label class="sr-only" for="shop-domain">Shopify store domain</label><input id="shop-domain" name="shop_domain" placeholder="store-name.myshopify.com" required class="min-w-0 flex-1 rounded-xl border-zinc-200 bg-zinc-50 text-sm" />
                                @elseif($key === 'woocommerce')
                                    <label class="sr-only" for="woocommerce-store-url">WooCommerce store URL</label><input id="woocommerce-store-url" name="store_url" placeholder="https://store.example.com" required class="min-w-0 flex-1 rounded-xl border-zinc-200 bg-zinc-50 text-sm" />
                                @endif
                                <button class="rounded-xl bg-zinc-950 px-4 py-2 text-sm font-bold text-white">{{ $connected ? 'Connect another' : 'Connect' }} {{ $provider['label'] }}</button>
                            </form>
                        </div>
                    @else<p class="mt-4 text-xs leading-5 text-zinc-500">The workflow registry recognizes this provider, but publishing stays fail-closed until Everbranch’s provider app registration and callback are production-ready.</p>@endif
                </article>
            @endforeach
        </div>
    </div></div>
</x-layouts::app>

<x-layouts::app.sidebar title="Shop Pay">
<flux:main><div class="mx-auto max-w-4xl space-y-6 pb-10">
    <x-ui.operational-header title="Shop Pay" :description="'Checkout options for '.$tenant->name" />
    @if(session('status'))<p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-950">{{ session('status') }}</p>@endif
    @if($errors->any())<p role="alert" class="rounded-xl bg-rose-50 p-4 text-sm text-rose-950">{{ $errors->first() }}</p>@endif
    <section class="space-y-4 rounded-2xl border bg-white p-6">
        <h2 class="text-xl font-semibold">Choose how your store checks out</h2>
        <p class="text-sm leading-6 text-zinc-600">Shop Pay setup is available to every retail business. Payments go to your own merchant account. Your Everbranch subscription is separate.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border p-4"><h3 class="font-semibold">My store uses Shopify checkout</h3><p class="mt-2 text-sm leading-6">Turn on Shop Pay in that store’s Shopify Payments settings. Keep your existing catalog, orders, shipping and checkout in Shopify.</p><a class="mt-3 inline-block underline" href="https://help.shopify.com/en/manual/payments/shop-pay" target="_blank" rel="noopener noreferrer">Shopify setup instructions ↗</a></div>
            <div class="rounded-xl border p-4"><h3 class="font-semibold">My store uses an Everbranch website</h3><p class="mt-2 text-sm leading-6">Use the wizard below to connect a dedicated Shop Pay Wallet store. Everbranch keeps your native products, cart and fulfillment.</p></div>
        </div>
        @if(in_array($profile['channel_type'] ?? '', ['shopify','hybrid']))<p class="rounded-lg bg-blue-50 p-3 text-sm">Your workspace has a Shopify channel. These Wallet settings do not change that channel’s checkout or use its credentials.</p>@endif
    </section>
    <section class="space-y-5 rounded-2xl border bg-white p-6">
        <h2 class="text-xl font-semibold">1. Prepare your Wallet store</h2>
        <ol class="list-decimal space-y-3 pl-5 text-sm leading-6"><li>Create a separate Shopify store for Wallet payments. Use your business’s legal details and payout bank.</li><li>Complete Shopify Payments setup and install the Shop sales channel. Start in test mode.</li><li>Add your website origin to the Shop channel’s allowed origins: <strong>{{ $origin ?: 'Finish your Everbranch website to see its origin here.' }}</strong></li><li>Copy the Shop ID, client ID, Admin API token, Storefront API token and app signing secret from that dedicated store. Enable order read access so Everbranch can verify payments.</li><li>Configure signed orders/paid, orders/updated and refunds/create webhooks at <code>{{ rtrim(config('app.url'), '/') }}/webhooks/website-shop-pay</code>.</li></ol>
        <a class="inline-block text-sm underline" href="https://shopify.dev/docs/api/commerce-components/pay" target="_blank" rel="noopener noreferrer">Wallet setup guide ↗</a>
        <h2 class="text-xl font-semibold">2. Connect your business</h2>
        <p class="text-sm">{{ $connection ? 'Status: '.str($connection->connection_status)->headline().' · '.strtoupper($connection->environment) : 'Not connected' }}. Saved secrets are encrypted and never displayed. Changing settings turns off new Shop Pay checkouts until verified again.</p>
        <form method="post" action="{{ route('tenant-payments.shop-pay.save', ['tenant'=>$tenant->slug]) }}" class="grid gap-4 sm:grid-cols-2">@csrf
            <label class="text-sm">Wallet store domain<input required name="shop_domain" value="{{ old('shop_domain', $connection?->shop_domain) }}" placeholder="your-wallet.myshopify.com" class="mt-1 block min-h-12 w-full rounded-lg border p-3"></label>
            <label class="text-sm">Shop ID<input required inputmode="numeric" name="shop_id" value="{{ old('shop_id', $connection?->shop_id) }}" class="mt-1 block min-h-12 w-full rounded-lg border p-3"></label>
            <label class="text-sm">Client ID<input required name="client_id" value="{{ old('client_id', $connection?->client_id) }}" class="mt-1 block min-h-12 w-full rounded-lg border p-3"></label>
            <label class="text-sm">Payment environment<select name="environment" class="mt-1 block min-h-12 w-full rounded-lg border p-3"><option value="test" @selected(old('environment', $connection?->environment)==='test')>Test · no real payments</option><option value="live" @selected(old('environment', $connection?->environment)==='live')>Live · requires launch verification</option></select></label>
            @foreach(['admin_access_token'=>'Admin API token','storefront_access_token'=>'Storefront API token','webhook_secret'=>'App webhook signing secret'] as $name=>$label)<label class="text-sm">{{ $label }}<input type="password" autocomplete="new-password" name="{{ $name }}" @required(!$connection) placeholder="{{ $connection ? 'Leave blank to keep the saved secret' : '' }}" class="mt-1 block min-h-12 w-full rounded-lg border p-3"></label>@endforeach
            <label class="flex gap-3 text-sm leading-6 sm:col-span-2"><input type="checkbox" name="dedicated_store_confirmed" value="1" required class="mt-1"><span>This is my business’s dedicated Wallet store. Shopify Payments and the Shop channel are set up for the selected environment.</span></label>
            <button class="min-h-12 rounded-lg bg-emerald-900 px-5 py-3 font-semibold text-white sm:col-span-2">Save connection settings</button>
        </form>
    </section>
    <section class="space-y-4 rounded-2xl border bg-white p-6"><h2 class="text-xl font-semibold">3. Verify and choose availability</h2>
        <p class="text-sm leading-6">Verification checks the merchant identity and creates a payment-free session. It does not charge a card or prove live payment acceptance.</p>
        @if($connection && $site)<form method="post" action="{{ route('tenant-payments.shop-pay.verify', ['tenant'=>$tenant->slug]) }}" class="space-y-3">@csrf<label class="flex gap-3 text-sm"><input type="checkbox" name="origin_confirmed" value="1" required><span>I added {{ $origin }} to this store’s allowed origins.</span></label><button class="min-h-12 rounded-lg border px-5 py-3">Verify connection</button></form>@endif
        @if($blockers)<ul class="list-disc space-y-2 rounded-xl bg-amber-50 p-5 pl-9 text-sm text-amber-950">@foreach($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>@endif
        @if($connection && $site)<form method="post" action="{{ route('tenant-payments.shop-pay.availability', ['tenant'=>$tenant->slug]) }}">@csrf<input type="hidden" name="enabled" value="{{ $connection->enabled ? 0 : 1 }}"><button class="min-h-12 rounded-lg border px-5 py-3" @disabled(!$connection->enabled && count($blockers))>{{ $connection->enabled ? 'Hide Shop Pay from new checkouts' : 'Offer Shop Pay at checkout' }}</button></form>@endif
        <p class="text-xs leading-5 text-zinc-500">Shop Pay receives checkout, delivery and payment information to process this purchase. Card and bank details stay with the payment provider. Disabling Shop Pay preserves existing orders and their payment recovery.</p>
    </section>
</div></flux:main>
</x-layouts::app.sidebar>

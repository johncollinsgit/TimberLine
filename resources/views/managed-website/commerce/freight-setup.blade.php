<x-layouts::app.sidebar :title="'Freight setup'">
    <flux:main>
        <div class="mx-auto max-w-5xl space-y-6 pb-12">
            <header><a class="text-sm font-semibold text-emerald-800 underline" href="{{ route('managed-website.commerce.shipping') }}">← Pickup and shipping</a><h1 class="mt-4 text-3xl font-bold">Set up pallet freight</h1><p class="mt-2 text-sm text-zinc-600">Connect your freight account before your first order. Your saved setup is shared by this store’s native orders, quotes and invoices.</p></header>
            @if(session('status'))<p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm">{{ session('status') }}</p>@endif
            @if($errors->any())<div role="alert" class="rounded-xl bg-amber-50 p-4 text-sm">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
            <section class="rounded-2xl border bg-white p-6">
                <h2 class="text-xl font-bold">Your setup progress</h2>
                <ol class="mt-4 list-decimal space-y-3 pl-5 text-sm">
                    <li><strong>Ship-from location: {{ $hasOrigin ? 'Saved' : 'Needed' }}.</strong> <a class="text-emerald-800 underline" href="{{ route('managed-website.commerce.shipping') }}#shipping-origin">Review your location and pickup phone</a>.</li>
                    <li><strong>Freight account: {{ $freightConnection?->isConnected() ? 'Verified at the last account check' : 'Needs setup' }}.</strong> Complete the account steps below. Progress is saved when you verify the account.</li>
                    <li><strong>Booking: {{ $bookingEnabled ? 'Enabled for this workspace' : 'Awaiting launch approval' }}.</strong> Account setup and rate comparison remain available while booking is paused. Ask your Everbranch administrator to review account readiness and the first shipment before enabling booking.</li>
                    <li><strong>First shipment:</strong> <a class="text-emerald-800 underline" href="{{ route('managed-website.orders.index') }}">Open a native physical order or quote</a>, choose Freight, review packed measurements and accessorials, then request rates. Booking requires confirmed payment and approval of the exact current cost. Download the BOL and pallet labels after booking, print them, then record the carrier handoff.</li>
                </ol>
            </section>
            <section class="rounded-2xl border bg-white p-6">
                <h2 class="text-xl font-bold">Connect the right provider account</h2>
                <ol class="mt-4 list-decimal space-y-3 pl-5 text-sm">
                    <li>Use a US ShipStation account with access to its v2 freight API. Check your plan’s API eligibility before purchasing. The separate ShipStation API / ShipEngine product has different accounts and keys; an inventory-only add-on or a parcel account does not establish freight access.</li>
                    <li>In ShipStation, go to Settings → Shipping → Carriers → Freight Providers. Connect Worldwide Express (WWEX) or Unishippers with the Client ID and Client Secret from SpeedShip. Keep these broker credentials in ShipStation.</li>
                    <li>If you do not have an account, review <a class="text-emerald-800 underline" href="https://help.shipstation.com/hc/en-us/articles/54549682761371-Create-a-Worldwide-Express-WWEX-Freight-Account" target="_blank" rel="noopener noreferrer">Activate Freight Shipping and the WWEX account requirements</a>. The account owner must review business/billing details, payment method, automatic charge authorization and terms. Do not disconnect an existing provider with open shipments to create another account.</li>
                    <li>The ShipStation account owner verifies their email and generates a v2 API key under Settings → Account → API Settings. Enter that key in the protected form below. Everbranch checks the active freight accounts and lets you select one. Never send keys in chat or email.</li>
                </ol>
                <p class="mt-4 text-sm text-zinc-600">Freight invoices are separate from customer shipping charges. Reweighing, classification and added services can change the final bill. Compare current all-in quotes before booking; provider availability does not establish the lowest price.</p>
                <a class="mt-3 inline-block text-sm font-semibold text-emerald-800 underline" href="https://help.shipstation.com/hc/en-us/articles/53777198753563-Worldwide-Express-WWEX" target="_blank" rel="noopener noreferrer">Existing WWEX / Unishippers connection instructions</a>
            </section>
            @include('managed-website.commerce.freight-account', ['accountAction' => route('managed-website.commerce.freight.store')])
            <section class="rounded-2xl border bg-white p-6">
                <h2 class="text-xl font-bold">Keep freight running</h2>
                <ul class="mt-4 list-disc space-y-3 pl-5 text-sm">
                    <li>Before each pickup, check the original booking status, local pickup window, BOL, pallet count and required services. Reprint the existing documents when printing fails.</li>
                    <li>Check open shipments daily using Check original booking in the order’s Freight screen. Tracking is checked on demand; no freight webhook or automatic background tracking is assumed. An uncertain booking must be resolved with its original provider before another booking.</li>
                    <li>Match every final provider invoice and adjustment to the approved quote. Record the invoice in the order’s freight records and reconcile carrier charges separately from customer receipts and refunds.</li>
                    <li>After key, account or billing changes, verify setup again. Older bookings keep their original credentials. Keep original-account recovery available until open shipments, cancellations, invoices and claims are resolved.</li>
                    <li>Remeasure pallets and confirm freight class/NMFC when goods or packing change. Mixed-size pallets, hazardous or international freight, special insurance and claims require provider review.</li>
                </ul>
            </section>
        </div>
    </flux:main>
</x-layouts::app.sidebar>

@php
    $editing = $invoice->exists;
    $storedLines = old('lines', $editing ? $invoice->line_items : [['category' => 'evergrove_implementation', 'description' => '', 'quantity' => 1, 'unit_amount' => '']]);
    $address = old('billing_address', $editing ? $invoice->billing_address : ['country' => 'US']);
@endphp
<x-app-layout><x-slot name="header"><h1 class="text-xl font-semibold text-zinc-900">{{ $editing ? 'Edit invoice draft' : 'New direct invoice' }} — {{ $tenant->name }}</h1></x-slot>
<form method="post" action="{{ $editing ? route('landlord.invoices.update', [$tenant, $invoice]) : route('landlord.invoices.store', $tenant) }}" class="mx-auto max-w-4xl space-y-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">@csrf @if($editing)@method('put')@endif
    @if($errors->any())<div class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">Only Everbranch service and Evergrove implementation work belong here. Shopify plans, Shopify fees, and paid third-party apps must be billed by their own providers.</div>
    <div class="grid gap-4 sm:grid-cols-2"><label class="text-sm font-medium">Customer name<input name="customer_name" required maxlength="190" value="{{ old('customer_name', $invoice->customer_name) }}" class="mt-1 block w-full rounded-lg border-zinc-300"></label><label class="text-sm font-medium">Billing email<input name="customer_email" type="email" required value="{{ old('customer_email', $invoice->customer_email) }}" class="mt-1 block w-full rounded-lg border-zinc-300"></label></div>
    <div class="grid gap-4 sm:grid-cols-2"><label class="text-sm font-medium">Address<input name="billing_address[line1]" required value="{{ data_get($address, 'line1') }}" class="mt-1 block w-full rounded-lg border-zinc-300"></label><label class="text-sm font-medium">Address line 2<input name="billing_address[line2]" value="{{ data_get($address, 'line2') }}" class="mt-1 block w-full rounded-lg border-zinc-300"></label><label class="text-sm font-medium">City<input name="billing_address[city]" required value="{{ data_get($address, 'city') }}" class="mt-1 block w-full rounded-lg border-zinc-300"></label><label class="text-sm font-medium">State<input name="billing_address[state]" required value="{{ data_get($address, 'state') }}" class="mt-1 block w-full rounded-lg border-zinc-300"></label><label class="text-sm font-medium">Postal code<input name="billing_address[postal_code]" required value="{{ data_get($address, 'postal_code') }}" class="mt-1 block w-full rounded-lg border-zinc-300"></label><label class="text-sm font-medium">Country code<input name="billing_address[country]" required maxlength="2" value="{{ data_get($address, 'country', 'US') }}" class="mt-1 block w-full rounded-lg border-zinc-300"></label></div>
    <div class="grid gap-4 sm:grid-cols-2"><label class="text-sm font-medium">Payment due in days<input name="days_until_due" type="number" required min="1" max="90" value="{{ old('days_until_due', $invoice->days_until_due ?: 30) }}" class="mt-1 block w-full rounded-lg border-zinc-300"></label><label class="text-sm font-medium">Authorization reference<input name="authorization_reference" required maxlength="255" placeholder="Accepted agreement, milestone, or written approval" value="{{ old('authorization_reference', $invoice->authorization_reference) }}" class="mt-1 block w-full rounded-lg border-zinc-300"></label></div>
    <div class="space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold">Charge lines</h2>
            <button type="button" id="add-invoice-line" class="rounded-lg border border-zinc-300 px-3 py-2 text-sm font-semibold">Add line</button>
        </div>
        <div id="invoice-lines" class="space-y-3">
            @foreach($storedLines as $index => $line)
                <div class="grid gap-3 rounded-xl border border-zinc-200 p-4 sm:grid-cols-12" data-invoice-line>
                    <label class="text-sm sm:col-span-3">Category<select name="lines[{{ $index }}][category]" class="mt-1 block w-full rounded-lg border-zinc-300">@foreach(\App\Models\TenantDirectInvoice::LINE_CATEGORIES as $category)<option value="{{ $category }}" @selected(data_get($line, 'category') === $category)>{{ str_replace('_', ' ', ucfirst($category)) }}</option>@endforeach</select></label>
                    <label class="text-sm sm:col-span-5">Description<input name="lines[{{ $index }}][description]" required maxlength="250" value="{{ data_get($line, 'description') }}" class="mt-1 block w-full rounded-lg border-zinc-300"></label>
                    <label class="text-sm sm:col-span-2">Quantity<input name="lines[{{ $index }}][quantity]" type="number" required min="1" max="1000" value="{{ data_get($line, 'quantity', 1) }}" class="mt-1 block w-full rounded-lg border-zinc-300"></label>
                    <label class="text-sm sm:col-span-2">Unit price ($)<input name="lines[{{ $index }}][unit_amount]" required inputmode="decimal" value="{{ data_get($line, 'unit_amount', isset($line['unit_amount_cents']) ? number_format($line['unit_amount_cents'] / 100, 2, '.', '') : '') }}" class="mt-1 block w-full rounded-lg border-zinc-300"></label>
                </div>
            @endforeach
        </div>
    </div>
    <p class="text-xs text-zinc-500">Stripe receives only the saved server-side snapshot. Shopify plans, Shopify processing fees, and third-party app subscriptions stay out of these lines.</p>
    <label class="block text-sm font-medium">Invoice memo<textarea name="memo" rows="3" maxlength="1000" class="mt-1 block w-full rounded-lg border-zinc-300">{{ old('memo', $invoice->memo) }}</textarea></label><label class="block text-sm font-medium">Invoice footer<textarea name="footer" rows="2" maxlength="1000" class="mt-1 block w-full rounded-lg border-zinc-300">{{ old('footer', $invoice->footer) }}</textarea></label>
    <div class="flex justify-end gap-3"><a href="{{ route('landlord.invoices.index', ['tenant_id' => $tenant->id]) }}" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold">Cancel</a><button class="rounded-lg bg-zinc-950 px-4 py-2 text-sm font-semibold text-white">Save draft</button></div>
</form>
<script>
    document.getElementById('add-invoice-line')?.addEventListener('click', () => {
        const container = document.getElementById('invoice-lines');
        const template = container?.querySelector('[data-invoice-line]');

        if (!container || !template || container.children.length >= 20) {
            return;
        }

        const nextIndex = container.children.length;
        const clone = template.cloneNode(true);

        clone.querySelectorAll('[name]').forEach((field) => {
            field.name = field.name.replace(/lines\[\d+]/, `lines[${nextIndex}]`);

            if (field.tagName === 'INPUT') {
                field.value = field.name.includes('[quantity]') ? '1' : '';
            }
        });

        container.appendChild(clone);
    });
</script>
</x-app-layout>

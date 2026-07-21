<x-app-layout>
    <x-slot name="header"><div class="flex items-center justify-between gap-4"><div><h1 class="text-xl font-semibold text-zinc-900">Invoice desk</h1><p class="mt-1 text-sm text-zinc-600">Create, send, and track Everbranch invoices to your workspace clients.</p></div><a href="{{ route('landlord.agreements.index') }}" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold">Agreements</a></div></x-slot>
    <div class="space-y-5">
        @if(session('status'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>@endif
        @if(session('status_error'))<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">{{ session('status_error') }}</div>@endif
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div><h2 class="font-semibold text-zinc-950">Create an invoice</h2><p class="mt-1 text-sm text-zinc-600">Choose the workspace client. Their saved billing contact fills in automatically.</p></div>
                <div class="flex w-full gap-2 sm:w-auto"><select id="new-invoice-tenant" class="min-w-0 flex-1 rounded-lg border-zinc-300 text-sm sm:w-72"><option value="">Choose a workspace client…</option>@foreach($tenants as $tenant)<option value="{{ $tenant->id }}" @selected($tenantId === (int) $tenant->id)>{{ $tenant->name }}</option>@endforeach</select><button type="button" id="new-invoice-button" class="shrink-0 rounded-lg bg-zinc-950 px-4 py-2 text-sm font-semibold text-white">New invoice</button></div>
            </div>
        </section>
        <div class="grid gap-3 sm:grid-cols-3">
            <a href="{{ route('landlord.invoices.index', array_filter(['tenant_id' => $tenantId ?: null])) }}" class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm hover:border-zinc-300"><div class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Invoices sent</div><div class="mt-1 text-2xl font-semibold text-zinc-950">{{ $summary['sent'] }}</div><div class="mt-1 text-sm text-zinc-500">All email-delivered invoices</div></a>
            <a href="{{ route('landlord.invoices.index', array_filter(['tenant_id' => $tenantId ?: null, 'status' => 'open'])) }}" class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm hover:border-zinc-300"><div class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Awaiting payment</div><div class="mt-1 text-2xl font-semibold text-amber-700">{{ $summary['awaiting'] }}</div><div class="mt-1 text-sm text-zinc-500">Open or payment issue</div></a>
            <a href="{{ route('landlord.invoices.index', array_filter(['tenant_id' => $tenantId ?: null, 'status' => 'paid'])) }}" class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm hover:border-zinc-300"><div class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Paid</div><div class="mt-1 text-2xl font-semibold text-emerald-700">{{ $summary['paid'] }}</div><div class="mt-1 text-sm text-zinc-500">Payment confirmed by Stripe</div></a>
        </div>
        <form class="grid gap-3 rounded-2xl border border-zinc-200 bg-white p-4 sm:grid-cols-3" method="get">
            <select name="tenant_id" class="rounded-lg border-zinc-300 text-sm"><option value="">All workspaces</option>@foreach($tenants as $tenant)<option value="{{ $tenant->id }}" @selected($tenantId === (int) $tenant->id)>{{ $tenant->name }}</option>@endforeach</select>
            <select name="status" class="rounded-lg border-zinc-300 text-sm"><option value="">All statuses</option>@foreach(\App\Models\TenantDirectInvoice::STATUSES as $option)<option value="{{ $option }}" @selected($status === $option)>{{ str_replace('_', ' ', ucfirst($option)) }}</option>@endforeach</select>
            <button class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold">Filter</button>
        </form>
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm"><div class="border-b border-zinc-100 px-4 py-3"><h2 class="font-semibold text-zinc-950">Past invoices</h2></div><div class="overflow-x-auto"><table class="min-w-full divide-y divide-zinc-200 text-sm"><thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500"><tr><th class="px-4 py-3">Invoice</th><th class="px-4 py-3">Workspace client</th><th class="px-4 py-3">Recipient</th><th class="px-4 py-3">Total</th><th class="px-4 py-3">Status</th><th class="px-4 py-3"><span class="sr-only">Open</span></th></tr></thead><tbody class="divide-y divide-zinc-100">@forelse($invoices as $invoice)<tr class="hover:bg-zinc-50"><td class="px-4 py-4"><a class="font-semibold text-emerald-800 hover:underline" href="{{ route('landlord.invoices.show', [$invoice->tenant_id, $invoice]) }}">{{ $invoice->provider_invoice_number ?: 'Draft #'.$invoice->id }}</a></td><td class="px-4 py-4 text-zinc-600">{{ $invoice->tenant->name }}</td><td class="px-4 py-4"><div>{{ $invoice->customer_name }}</div><div class="text-zinc-500">{{ $invoice->customer_email }}</div>@if($invoice->customer_phone)<div class="text-zinc-500">••• {{ substr($invoice->customer_phone, -4) }}</div>@endif</td><td class="px-4 py-4 font-medium">${{ number_format(($invoice->provider_total_cents ?: $invoice->authorized_subtotal_cents) / 100, 2) }}</td><td class="px-4 py-4">{{ str_replace('_', ' ', ucfirst($invoice->status)) }}</td><td class="px-4 py-4 text-right"><a class="font-semibold text-emerald-800 hover:underline" href="{{ route('landlord.invoices.show', [$invoice->tenant_id, $invoice]) }}">View invoice <span aria-hidden="true">→</span></a></td></tr>@empty<tr><td colspan="6" class="px-4 py-10 text-center text-zinc-500">No direct invoices match these filters.</td></tr>@endforelse</tbody></table></div></div>
        {{ $invoices->links() }}
    </div>
    <script>
        document.getElementById('new-invoice-button')?.addEventListener('click', () => {
            const tenantId = document.getElementById('new-invoice-tenant')?.value;
            if (!tenantId) return;
            window.location.assign(@json(route('landlord.invoices.create', ['tenant' => '__TENANT__'])).replace('__TENANT__', tenantId));
        });
    </script>
</x-app-layout>

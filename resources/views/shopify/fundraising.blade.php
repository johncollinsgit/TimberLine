<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    headline="Fundraising"
    subheadline="BSF Shopify orders, purchased-label costs, and QuickBooks invoices."
    :app-navigation="$appNavigation"
    :page-subnav="[]"
    :page-actions="[]"
>
    <div class="mx-auto max-w-7xl space-y-5 px-4 py-5 sm:px-6">
        @if (! $authorized)
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-5 text-sm text-amber-950">Fundraising is available only in the verified Modern Forestry retail Shopify app.</div>
        @else
            @php
                $monthly = (array) ($desk['monthly'] ?? []);
                $maxMonthly = max(1, ...array_map(fn ($row) => (int) ($row['candle_proceeds_cents'] ?? 0), $monthly));
                $money = static fn ($cents) => '$'.number_format(((int) $cents) / 100, 2);
            @endphp
            <div id="fundraising-alert" class="hidden rounded-xl border p-4 text-sm" role="status"></div>
            <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-950">BSF invoice desk</h2>
                        <p class="mt-1 max-w-3xl text-sm text-zinc-600">{{ $desk['note'] }}</p>
                    </div>
                    <button type="button" data-fundraising-action="detect" class="rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white">Detect Shopify orders</button>
                </div>
                <p class="mt-3 text-sm text-amber-900">The first-of-month job queues reviewed orders. Creating and sending a QuickBooks invoice are separate staff actions. No Shopify checkout shipping amount is treated as a label cost.</p>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-950">Existing August QuickBooks invoice</h2>
                <p class="mt-1 text-sm text-zinc-600">BSF-AUG-2026 was created before this queue. Load it read-only from the connected Modern Forestry QuickBooks company. Do not recreate it.</p>
                <button type="button" data-fundraising-action="existing-august" class="mt-3 rounded-lg border border-emerald-700 px-3 py-2 text-sm font-semibold text-emerald-900">Load August invoice and payment link</button>
                <div id="existing-august-invoice" class="mt-3 text-sm text-zinc-700"></div>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-950">Candle proceeds by order month</h2>
                <p class="mt-1 text-sm text-zinc-600">Shopify order totals, not invoice periods or net profit. Label costs are shown only after verification. Recent 250 BSF orders.</p>
                <div class="mt-4 space-y-3">
                    @forelse($monthly as $row)
                        <div class="grid grid-cols-[85px_1fr_120px] items-center gap-3 text-sm">
                            <span class="font-medium text-zinc-700">{{ $row['month'] }}</span>
                            <div class="h-5 rounded bg-zinc-100"><div class="h-5 rounded bg-emerald-700" style="width:{{ max(2, round(((int) $row['candle_proceeds_cents'] / $maxMonthly) * 100)) }}%"></div></div>
                            <span class="text-right font-semibold text-zinc-900">{{ $money($row['candle_proceeds_cents']) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-600">No BSF-tagged Shopify orders are available yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm">
                <div class="border-b border-zinc-200 px-5 py-4"><h2 class="font-semibold text-zinc-950">Linked Shopify orders</h2><p class="mt-1 text-sm text-zinc-600">The BSF tag and Shopify order ID determine the match.</p></div>
                <div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500"><tr><th class="px-4 py-3">Order</th><th class="px-4 py-3">Order date</th><th class="px-4 py-3 text-right">Candle proceeds</th><th class="px-4 py-3 text-right">Verified label</th><th class="px-4 py-3">Queue</th></tr></thead><tbody class="divide-y divide-zinc-100">
                    @forelse($desk['orders'] as $order)
                        <tr><td class="px-4 py-3 font-medium">{{ $order['reference'] }}</td><td class="px-4 py-3">{{ $order['ordered_at'] }}</td><td class="px-4 py-3 text-right">{{ $money($order['candle_proceeds_cents']) }}</td><td class="px-4 py-3 text-right">{{ $order['label_cost_cents'] === null ? 'Needs receipt' : $money($order['label_cost_cents']) }}</td><td class="px-4 py-3">{{ $order['queue_status'] ?: 'Not queued' }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-zinc-500">No BSF orders found.</td></tr>
                    @endforelse
                </tbody></table></div>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-950">Review queue</h2>
                <p class="mt-1 text-sm text-zinc-600">Shopify label costs are pulled from purchased-label reports when available. Review each live order, then approve. If a cost is missing, enter the actual amount and receipt reference. August's existing QuickBooks invoice is excluded from automatic backfill.</p>
                <div class="mt-4 space-y-3">
                    @forelse($desk['queue'] as $row)
                        <article class="rounded-lg border border-zinc-200 p-4" data-queue-id="{{ $row['id'] }}">
                            <div class="flex flex-wrap items-center justify-between gap-2"><div><strong>{{ $row['reference'] }}</strong><span class="ml-2 text-xs uppercase text-zinc-500">{{ $row['source'] }} / {{ str_replace('_', ' ', $row['status']) }}</span></div><strong>{{ $money($row['total_cents']) }}</strong></div>
                            <div class="mt-1 text-sm text-zinc-600">Candle proceeds {{ $money($row['candle_proceeds_cents']) }}. Label cost {{ $row['shipping_verified'] ? $money($row['label_cost_cents']) : 'not verified' }}.</div>
                            @if($row['source'] === 'shopify' && $row['status'] !== 'packaged')
                                <div class="mt-3 flex flex-wrap items-end gap-2">
                                    <label class="text-xs text-zinc-600">Actual label cost ($)<input data-shipping-amount="{{ $row['id'] }}" type="number" min="0" step="0.01" value="{{ number_format($row['label_cost_cents'] / 100, 2, '.', '') }}" class="mt-1 block w-32 rounded border-zinc-300 text-sm"></label>
                                    <label class="text-xs text-zinc-600">Label receipt or reference<input data-shipping-evidence="{{ $row['id'] }}" type="text" maxlength="500" placeholder="Shopify label receipt" class="mt-1 block w-56 rounded border-zinc-300 text-sm"></label>
                                    <button type="button" data-fundraising-action="shipping" data-id="{{ $row['id'] }}" class="rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium">Verify label cost</button>
                                </div>
                            @endif
                            @if($row['status'] === 'needs_review' && $row['shipping_verified'])
                                <button type="button" data-fundraising-action="approve" data-id="{{ $row['id'] }}" class="mt-3 rounded-lg bg-emerald-900 px-3 py-2 text-sm font-semibold text-white">Approve order</button>
                            @endif
                            @if($row['status'] === 'approved')
                                <label class="mt-3 inline-flex items-center gap-2 text-sm"><input type="checkbox" data-package-order="{{ $row['id'] }}"> Include in invoice package</label>
                            @endif
                        </article>
                    @empty
                        <p class="text-sm text-zinc-600">No orders are queued. Detect Shopify orders to bring new BSF orders into review.</p>
                    @endforelse
                </div>
                <button type="button" data-fundraising-action="prepare" class="mt-4 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white">Prepare invoice package from selected orders</button>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-950">Invoice packages</h2>
                <p class="mt-1 text-sm text-zinc-600">Create in QuickBooks only after reviewing every line. Send is a separate confirmation. The customer payment link comes directly from the verified QuickBooks invoice.</p>
                <div class="mt-4 space-y-3">
                    @forelse($desk['packages'] as $invoice)
                        <article class="rounded-lg border border-zinc-200 p-4">
                            <div class="flex flex-wrap justify-between gap-2"><div><strong>{{ $invoice['reference'] }}</strong><span class="ml-2 text-xs uppercase text-zinc-500">{{ str_replace('_', ' ', $invoice['status']) }}</span></div><strong>{{ $money($invoice['total_cents']) }}</strong></div>
                            <div class="mt-1 text-sm text-zinc-600">{{ $invoice['payer_name'] }} ({{ $invoice['payer_email'] }}). Shipping {{ $money($invoice['shipping_cents']) }}. {{ $invoice['quickbooks_doc_number'] ? 'QuickBooks '.$invoice['quickbooks_doc_number'] : 'Not yet in QuickBooks' }}.</div>
                            <details class="mt-3 text-sm"><summary class="cursor-pointer font-medium">Invoice line items</summary><ul class="mt-2 space-y-1 pl-4">@foreach($invoice['invoice_lines'] as $line)<li>{{ $line['description'] ?? 'Item' }}: {{ $money($line['amount_cents'] ?? 0) }}</li>@endforeach</ul></details>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @if(!$invoice['quickbooks_invoice_id'])<button type="button" data-fundraising-action="create" data-id="{{ $invoice['id'] }}" class="rounded-lg bg-emerald-900 px-3 py-2 text-sm font-semibold text-white">Create QuickBooks invoice</button>@endif
                                @if($invoice['quickbooks_invoice_id'])<button type="button" data-fundraising-action="link" data-id="{{ $invoice['id'] }}" class="rounded-lg border border-emerald-700 px-3 py-2 text-sm font-semibold text-emerald-900">Open customer payment link</button>@endif
                                @if($invoice['quickbooks_invoice_id'] && $invoice['status'] !== 'sent')<button type="button" data-fundraising-action="send" data-id="{{ $invoice['id'] }}" data-reference="{{ $invoice['reference'] }}" class="rounded-lg bg-zinc-900 px-3 py-2 text-sm font-semibold text-white">Send to {{ $invoice['payer_email'] }}</button>@endif
                            </div>
                        </article>
                    @empty
                        <p class="text-sm text-zinc-600">No invoice packages have been prepared.</p>
                    @endforelse
                </div>
            </section>
        @endif
    </div>
    @if($authorized)
        <script>
            (() => {
                const alert = document.getElementById('fundraising-alert');
                const endpoint = @json(route('shopify.app.api.fundraising.detect', [], false));
                const base = @json(route('shopify.app.api.settings.fundraiser-invoicing.desk', [], false));
                const show = (message, error = false) => { alert.textContent = message; alert.className = `rounded-xl border p-4 text-sm ${error ? 'border-red-300 bg-red-50 text-red-900' : 'border-emerald-300 bg-emerald-50 text-emerald-900'}`; };
                const request = async (url, method = 'POST', body = null) => {
                    const resolver = window.ForestryEmbeddedApp?.resolveEmbeddedAuthHeaders;
                    if (typeof resolver !== 'function') throw new Error('Shopify Admin verification is unavailable. Reload this page from Shopify Admin.');
                    const headers = await resolver();
                    const response = await fetch(url, { method, credentials: 'same-origin', headers: { ...headers, Accept: 'application/json', 'Content-Type': 'application/json' }, body: body === null ? null : JSON.stringify(body) });
                    const data = await response.json().catch(() => ({}));
                    if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat().join(' ') || 'Request failed.');
                    return data;
                };
                const url = (suffix) => base.replace('/settings/fundraiser-invoicing/desk', suffix);
                document.addEventListener('click', async (event) => {
                    const button = event.target.closest('[data-fundraising-action]');
                    if (!button) return;
                    const action = button.dataset.fundraisingAction;
                    const id = Number(button.dataset.id || 0);
                    button.disabled = true;
                    try {
                        if (action === 'detect') await request(endpoint);
                        if (action === 'shipping') {
                            const amount = document.querySelector(`[data-shipping-amount="${id}"]`)?.value;
                            const evidence = document.querySelector(`[data-shipping-evidence="${id}"]`)?.value;
                            if (!/^\d+(\.\d{1,2})?$/.test(String(amount || ''))) throw new Error('Enter the actual label cost in dollars and cents.');
                            await request(url(`/fundraising/orders/${id}/shipping`), 'POST', { shipping_cents: Math.round(Number(amount) * 100), evidence });
                        }
                        if (action === 'approve') await request(url(`/settings/fundraiser-invoicing/orders/${id}/approve`));
                        if (action === 'prepare') {
                            const order_ids = [...document.querySelectorAll('[data-package-order]:checked')].map((input) => Number(input.dataset.packageOrder));
                            if (!order_ids.length) throw new Error('Select approved orders first.');
                            await request(url('/settings/fundraiser-invoicing/invoice-packages'), 'POST', { order_ids });
                        }
                        if (action === 'create') await request(url(`/fundraising/invoices/${id}/create`));
                        if (action === 'existing-august') {
                            const data = await request(url('/fundraising/invoices/existing-august'), 'GET');
                            const invoice = data.invoice || {};
                            const result = document.getElementById('existing-august-invoice');
                            const total = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(invoice.total_cents || 0) / 100);
                            const balance = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(invoice.balance_cents || 0) / 100);
                            result.textContent = `QuickBooks total ${total}. Balance ${balance}. `;
                            const link = document.createElement('a');
                            link.href = invoice.payment_link;
                            link.target = '_blank';
                            link.rel = 'noopener noreferrer';
                            link.className = 'font-semibold text-emerald-800 underline';
                            link.textContent = 'Open customer payment page';
                            result.appendChild(link);
                            show('Existing August invoice loaded from QuickBooks.');
                            return;
                        }
                        if (action === 'send') {
                            const reference = button.dataset.reference;
                            if (!confirm(`Send ${reference} to the payer through QuickBooks?`)) return;
                            await request(url(`/fundraising/invoices/${id}/send`), 'POST', { confirmation: `SEND ${reference}` });
                        }
                        if (action === 'link') {
                            const data = await request(url(`/fundraising/invoices/${id}/payment-link`), 'GET');
                            const link = document.createElement('a');
                            link.href = data.payment_link;
                            link.target = '_blank';
                            link.rel = 'noopener noreferrer';
                            link.className = 'rounded-lg border border-emerald-700 px-3 py-2 text-sm font-semibold text-emerald-900';
                            link.textContent = 'Open verified QuickBooks payment page';
                            button.replaceWith(link);
                            show('Payment link verified. Click the link to open the customer invoice.');
                            return;
                        }
                        show('Updated. Refreshing the Fundraising desk.');
                        window.location.reload();
                    } catch (error) { show(error.message || 'Action failed.', true); }
                    finally { button.disabled = false; }
                });
            })();
        </script>
    @endif
</x-shopify-embedded-shell>

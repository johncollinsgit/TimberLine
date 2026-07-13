<x-layouts::app.sidebar :title="$estimate->estimate_number">
    <div class="mx-auto w-full max-w-5xl px-4 py-6 sm:px-6">
        @if(session('status'))
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900 print:hidden">{{ session('status') }}</div>
        @endif

        <article class="rounded-lg border border-zinc-200 bg-white p-6 sm:p-8" data-estimate-editor>
            <header class="flex flex-col gap-4 border-b border-zinc-200 pb-5 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="text-xs font-semibold uppercase text-emerald-800">Estimate draft</div>
                    <h1 class="mt-2 text-3xl font-semibold">{{ $estimate->estimate_number }}</h1>
                    <p class="mt-1 text-sm text-zinc-600">Private Everbranch draft. Nothing on this page writes to QuickBooks.</p>
                </div>
                <div class="flex flex-wrap gap-2 print:hidden">
                    <button type="button" onclick="window.print()" class="rounded-lg border border-zinc-300 px-3 py-2 text-sm font-semibold">Print / PDF</button>
                    <form method="POST" action="{{ route('estimator.duplicate', [$tenant, $estimate]) }}">@csrf<button class="rounded-lg border border-zinc-300 px-3 py-2 text-sm font-semibold">Duplicate</button></form>
                </div>
            </header>

            <form method="POST" action="{{ route('estimator.update', [$tenant, $estimate]) }}" class="mt-6 print:hidden" data-editor-form>
                @csrf @method('PUT')
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-semibold">Customer
                        <select name="marketing_profile_id" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2">
                            <option value="">No customer selected</option>
                            @foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((int)$estimate->marketing_profile_id === (int)$customer->id)>{{ trim($customer->first_name.' '.$customer->last_name) ?: $customer->email }}</option>@endforeach
                        </select>
                    </label>
                    <label class="text-sm font-semibold">Job
                        <select name="field_service_job_id" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2">
                            <option value="">No job selected</option>
                            @foreach($jobs as $job)<option value="{{ $job->id }}" @selected((int)$estimate->field_service_job_id === (int)$job->id)>{{ $job->title }}</option>@endforeach
                        </select>
                    </label>
                </div>
                <label class="mt-4 block text-sm font-semibold">Title<input name="title" value="{{ old('title', $estimate->title) }}" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2"></label>

                <div class="mt-5 flex items-center justify-between gap-3"><h2 class="font-semibold">Line items</h2><button type="button" class="rounded-lg border border-zinc-300 px-3 py-2 text-xs font-semibold" data-add-line>Add line</button></div>
                <div class="mt-2 space-y-2" data-lines>
                    @foreach($estimate->lines as $index => $line)
                        <div class="grid grid-cols-[minmax(0,1fr)_5rem_7rem_2.5rem] gap-2" data-line>
                            <input type="hidden" name="lines[{{ $index }}][price_book_item_id]" value="{{ $line->field_service_price_book_item_id }}">
                            <input name="lines[{{ $index }}][description]" required value="{{ $line->description }}" aria-label="Description" class="rounded-lg border border-zinc-300 px-2 py-2 text-sm">
                            <input name="lines[{{ $index }}][quantity]" type="number" step="0.01" min="0.01" value="{{ number_format((float)$line->quantity, 2, '.', '') }}" aria-label="Quantity" class="rounded-lg border border-zinc-300 px-2 py-2 text-sm">
                            <input name="lines[{{ $index }}][unit_price]" type="number" step="0.01" min="0" value="{{ number_format((float)$line->unit_price, 2, '.', '') }}" aria-label="Unit price" class="rounded-lg border border-zinc-300 px-2 py-2 text-sm">
                            <button type="button" class="rounded-lg border border-zinc-300 text-zinc-500" data-remove-line aria-label="Remove line">&times;</button>
                        </div>
                    @endforeach
                </div>
                @if($items->isNotEmpty())
                    <div class="mt-4"><div class="text-xs font-semibold uppercase text-zinc-500">Approved price book</div><div class="mt-2 flex flex-wrap gap-2">@foreach($items as $item)<button type="button" class="rounded-lg border border-zinc-300 px-2 py-1 text-xs" data-price-item data-id="{{ $item->id }}" data-description="{{ $item->name }}" data-price="{{ $item->unit_price }}">{{ $item->name }} · ${{ number_format((float)$item->unit_price, 2) }}</button>@endforeach</div></div>
                @endif
                <div class="mt-4 grid gap-4 sm:grid-cols-2"><label class="text-sm font-semibold">Discount<input name="discount_amount" type="number" min="0" step="0.01" value="{{ number_format((float)$estimate->discount_amount, 2, '.', '') }}" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2"></label><label class="text-sm font-semibold">Tax<input name="tax_amount" type="number" min="0" step="0.01" value="{{ number_format((float)$estimate->tax_amount, 2, '.', '') }}" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2"></label></div>
                <label class="mt-4 block text-sm font-semibold">Notes<textarea name="notes" rows="4" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2">{{ old('notes', $estimate->notes) }}</textarea></label>
                <button class="mt-5 rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white">Save draft</button>
            </form>

            <section class="mt-8 hidden print:block">
                <div class="grid gap-4 sm:grid-cols-2"><div><div class="text-xs uppercase text-zinc-500">Customer</div><div class="mt-1 font-semibold">{{ trim(($estimate->customer?->first_name ?? '').' '.($estimate->customer?->last_name ?? '')) ?: 'Not selected' }}</div></div><div><div class="text-xs uppercase text-zinc-500">Job</div><div class="mt-1 font-semibold">{{ $estimate->job?->title ?: 'Not selected' }}</div></div></div>
                <table class="mt-6 w-full text-left text-sm"><thead><tr class="border-b border-zinc-200 text-xs uppercase text-zinc-500"><th class="py-2">Description</th><th class="py-2 text-right">Qty</th><th class="py-2 text-right">Price</th><th class="py-2 text-right">Total</th></tr></thead><tbody>@foreach($estimate->lines as $line)<tr class="border-b border-zinc-100"><td class="py-3">{{ $line->description }}</td><td class="py-3 text-right">{{ number_format((float)$line->quantity, 2) }}</td><td class="py-3 text-right">${{ number_format((float)$line->unit_price, 2) }}</td><td class="py-3 text-right font-semibold">${{ number_format((float)$line->line_total, 2) }}</td></tr>@endforeach</tbody><tfoot><tr><td colspan="3" class="pt-5 text-right font-semibold">Total</td><td class="pt-5 text-right text-xl font-semibold">${{ number_format((float)$estimate->total_amount, 2) }}</td></tr></tfoot></table>
                @if($estimate->notes)<div class="mt-6 rounded-lg bg-zinc-50 p-4 text-sm whitespace-pre-line">{{ $estimate->notes }}</div>@endif
            </section>
        </article>
    </div>
    <script>
        (() => {
            const root = document.querySelector('[data-estimate-editor]');
            if (!root) return;
            const lines = root.querySelector('[data-lines]');
            let index = {{ $estimate->lines->count() }};
            const add = (id = '', description = '', price = '0') => {
                const row = document.createElement('div');
                row.className = 'grid grid-cols-[minmax(0,1fr)_5rem_7rem_2.5rem] gap-2';
                row.dataset.line = '';
                row.innerHTML = `<input type="hidden" name="lines[${index}][price_book_item_id]"><input name="lines[${index}][description]" required aria-label="Description" class="rounded-lg border border-zinc-300 px-2 py-2 text-sm"><input name="lines[${index}][quantity]" type="number" step="0.01" min="0.01" value="1" aria-label="Quantity" class="rounded-lg border border-zinc-300 px-2 py-2 text-sm"><input name="lines[${index}][unit_price]" type="number" step="0.01" min="0" aria-label="Unit price" class="rounded-lg border border-zinc-300 px-2 py-2 text-sm"><button type="button" class="rounded-lg border border-zinc-300 text-zinc-500" data-remove-line aria-label="Remove line">&times;</button>`;
                row.children[0].value = id; row.children[1].value = description; row.children[3].value = price; lines.append(row); index++;
            };
            root.addEventListener('click', (event) => { const remove = event.target.closest('[data-remove-line]'); if (remove && lines.children.length > 1) remove.closest('[data-line]').remove(); });
            root.querySelector('[data-add-line]').addEventListener('click', () => add());
            root.querySelectorAll('[data-price-item]').forEach((button) => button.addEventListener('click', () => add(button.dataset.id, button.dataset.description, button.dataset.price)));
        })();
    </script>
</x-layouts::app.sidebar>

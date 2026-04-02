<x-layouts::app :title="'Message Deliveries'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Direct Message Deliveries"
            description="Delivery audit log for internal wizard sends."
            hint-title="How to use this page"
            hint-text="Filter by status, phone, provider message ID, or batch ID. Twilio callbacks update statuses as events arrive."
        />

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6">
            <form method="GET" action="{{ route('marketing.messages.deliveries') }}" class="grid gap-3 md:grid-cols-12">
                <div class="md:col-span-6">
                    <label for="search" class="text-xs uppercase tracking-[0.2em] text-zinc-500">Search</label>
                    <input
                        id="search"
                        type="text"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Phone, provider id, or message text"
                        class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950 placeholder:text-zinc-500"
                    />
                </div>
                <div class="md:col-span-2">
                    <label for="status" class="text-xs uppercase tracking-[0.2em] text-zinc-500">Status</label>
                    <select id="status" name="status" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                        <option value="">All</option>
                        @foreach(['queued', 'sending', 'sent', 'delivered', 'undelivered', 'failed', 'canceled'] as $value)
                            <option value="{{ $value }}" @selected($status === $value)>{{ ucfirst($value) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-3">
                    <label for="batch" class="text-xs uppercase tracking-[0.2em] text-zinc-500">Batch ID</label>
                    <input
                        id="batch"
                        type="text"
                        name="batch"
                        value="{{ $batch }}"
                        placeholder="UUID"
                        class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950"
                    />
                </div>
                <div class="md:col-span-1 flex items-end">
                    <button type="submit" class="w-full rounded-xl border border-zinc-300 bg-emerald-100 px-3 py-2 text-sm font-semibold text-zinc-950">
                        Apply
                    </button>
                </div>
            </form>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-2 sm:p-3">
            <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                <table class="min-w-[1400px] w-full text-sm">
                    <thead class="bg-zinc-50 text-zinc-600">
                        <tr>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Recipient</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Phone</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Status</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Failure Reason</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Provider ID</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Batch</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Message</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Sent</th>
                            <th class="px-4 py-3 text-left whitespace-nowrap">Updated</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse($deliveries as $delivery)
                            @php
                                $profile = $delivery->profile;
                                $name = $profile ? trim((string) ($profile->first_name . ' ' . $profile->last_name)) : '';
                                $payload = is_array($delivery->provider_payload) ? $delivery->provider_payload : [];
                                $reasonCode = trim((string) ($delivery->error_code ?? ''));
                                $reasonMessage = trim((string) ($delivery->error_message ?? data_get($payload, 'twilio_response.message', '')));
                                $reason = trim($reasonCode . ($reasonMessage !== '' ? ': ' . $reasonMessage : ''));
                            @endphp
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-zinc-800">{{ $name !== '' ? $name : 'Manual / Test Recipient' }}</div>
                                    <div class="text-xs text-zinc-500">{{ $profile?->email ?: '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-zinc-700 whitespace-nowrap">{{ $delivery->to_phone ?: '—' }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] {{ in_array($delivery->send_status, ['sent', 'delivered'], true) ? 'bg-emerald-100 text-emerald-900' : (in_array($delivery->send_status, ['failed', 'undelivered', 'canceled'], true) ? 'bg-rose-100 text-rose-900' : 'bg-zinc-100 text-zinc-600') }}">
                                        {{ $delivery->send_status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-zinc-600 max-w-[360px]">
                                    @if($reason !== '')
                                        <div class="line-clamp-2">{{ $reason }}</div>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-zinc-600 whitespace-nowrap">{{ $delivery->provider_message_id ?: '—' }}</td>
                                <td class="px-4 py-3 text-zinc-600 whitespace-nowrap">{{ $payload['batch_id'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-zinc-700 max-w-[500px]">
                                    <div class="line-clamp-2">{{ $delivery->rendered_message }}</div>
                                </td>
                                <td class="px-4 py-3 text-zinc-600 whitespace-nowrap">{{ optional($delivery->sent_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                <td class="px-4 py-3 text-zinc-600 whitespace-nowrap">{{ optional($delivery->updated_at)->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-8 text-center text-zinc-500">
                                    No direct message deliveries found for the current filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-2 pt-4">
                {{ $deliveries->links() }}
            </div>
        </section>
    </div>
</x-layouts::app>

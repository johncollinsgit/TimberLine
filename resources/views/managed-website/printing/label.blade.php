<x-layouts::app.sidebar title="Print shipping label">
    <flux:main>
        <div class="mx-auto max-w-4xl space-y-5 pb-10">
            @include('managed-website.catalog.nav')
            <header><h1 class="text-2xl font-semibold text-zinc-950">Shipping label</h1><p class="mt-2 text-sm text-zinc-600">{{ $shipment->carrier }} {{ $shipment->service }} · {{ $shipment->tracking_number }}</p></header>
            @if(session('status'))<p role="status" class="rounded-lg bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</p>@endif
            @if($errors->any())<div role="alert" class="rounded-lg bg-rose-50 p-4 text-sm text-rose-900">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
            @if(!$printable)<p role="alert" class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">This label is not active for printing. Review its purchase, refund, or replacement status before using it.</p>@else
                <section class="rounded-xl border border-zinc-200 bg-white p-5"><h2 class="text-lg font-semibold text-zinc-950">{{ $history->isEmpty() ? 'Print label' : 'Reprint the existing label' }}</h2><p class="mt-2 text-sm leading-6 text-zinc-600">Printing and reprinting use the purchased label and tracking number for this same package. They never purchase another label or confirm carrier handoff.</p>
                    @if($profiles === [])<a class="mt-4 inline-block text-sm font-medium text-emerald-800 hover:underline" href="{{ route('managed-website.printing.settings') }}">Set up a workstation profile</a>@else
                        <form id="label-print-request" method="POST" action="{{ route('managed-website.labels.print', $shipment) }}" class="mt-4 flex flex-col gap-3 sm:flex-row">@csrf<input type="hidden" name="request_id" value="{{ $requestId }}"><label class="min-w-0 flex-1 text-sm font-medium text-zinc-800">Workstation profile<select required name="profile_id" class="mt-1 block w-full rounded-md border-zinc-300"><option value="">Choose the profile for this computer</option>@foreach($profiles as $profile)<option value="{{ $profile['id'] }}" data-workstation="{{ $profile['workstation_id'] }}">{{ $profile['name'] }} · {{ $profile['printer_name'] }} · {{ $profile['layout'] === 'thermal_4x6' ? '4 x 6' : strtoupper($profile['layout']) }}</option>@endforeach</select></label><button type="submit" class="fb-btn fb-btn-primary self-end">{{ $history->isEmpty() ? 'Prepare and print' : 'Reprint existing label' }}</button></form>
                    @endif
                    <a href="{{ route('managed-website.printing.settings') }}" class="mt-4 inline-block text-sm text-emerald-800 hover:underline">Printer profiles and no-postage test</a>
                </section>
                <section class="rounded-xl border border-zinc-200 bg-white p-5"><h2 class="text-lg font-semibold text-zinc-950">Download PDF</h2><p class="mt-2 text-sm text-zinc-600">The label stays 4 x 6 inches on thermal, Letter, and A4 documents. Use Actual Size / 100% in your PDF reader.</p><form method="POST" action="{{ route('managed-website.labels.prepare', $shipment) }}" class="mt-4 flex flex-wrap items-end gap-3">@csrf<label class="text-sm font-medium text-zinc-800">Paper orientation<select name="orientation" class="mt-1 block rounded-md border-zinc-300"><option value="portrait">Portrait</option><option value="landscape">Landscape</option></select></label><button type="submit" class="fb-btn fb-btn-secondary">Prepare PDFs</button></form>
                    @foreach(['portrait' => 'Portrait', 'landscape' => 'Landscape'] as $orientation => $name)
                        @if(app(\App\Services\ManagedWebsite\WebsiteLabelDocumentService::class)->manifest($shipment, $orientation))<div class="mt-4 flex flex-wrap gap-3 text-sm"><span class="font-medium text-zinc-700">{{ $name }}</span>@foreach(['thermal_4x6' => '4 x 6', 'letter' => 'Letter', 'a4' => 'A4'] as $layout => $label)<a class="text-emerald-800 hover:underline" href="{{ route('managed-website.labels.download', ['shipment' => $shipment, 'layout' => $layout, 'orientation' => $orientation]) }}">{{ $label }} PDF</a>@endforeach</div>@endif
                    @endforeach
                </section>
            @endif
            <section class="rounded-xl border border-zinc-200 bg-white p-5"><h2 class="text-lg font-semibold text-zinc-950">Print history</h2><p class="mt-2 text-xs text-zinc-500">A request or closed print dialog does not prove that a label reached paper.</p><div class="mt-4 divide-y divide-zinc-100">@forelse($history as $event)<article class="py-3"><div class="flex flex-wrap justify-between gap-2"><p class="text-sm font-medium text-zinc-900">{{ str($event->status ?: $event->event_type)->headline() }}</p><time class="text-xs text-zinc-500">{{ $event->occurred_at?->format('M j, Y g:i a') }}</time></div><p class="mt-1 text-sm text-zinc-600">{{ $event->message }}</p><p class="mt-1 text-xs text-zinc-500">{{ data_get($event->payload, 'profile.name') }} · {{ data_get($event->payload, 'profile.printer_name') }}</p></article>@empty<p class="text-sm text-zinc-500">No print requests have been recorded.</p>@endforelse</div></section>
        </div>
        <script>
            (() => {
                const form = document.getElementById('label-print-request');
                if (!form) return;
                try {
                    const workstation = localStorage.getItem('everbranch-print-workstation-v1');
                    const key = 'everbranch-print-profile-{{ $tenant->id }}';
                    const selected = localStorage.getItem(key);
                    const options = [...form.elements.profile_id.options];
                    const match = options.find(option => option.value === selected && option.dataset.workstation === workstation) || options.find(option => option.dataset.workstation === workstation);
                    if (match) form.elements.profile_id.value = match.value;
                    form.addEventListener('submit', () => localStorage.setItem(key, form.elements.profile_id.value));
                } catch {}
            })();
        </script>
    </flux:main>
</x-layouts::app.sidebar>

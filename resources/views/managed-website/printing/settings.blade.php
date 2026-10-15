<x-layouts::app.sidebar title="Label printing">
    <flux:main>
        <div class="mx-auto max-w-5xl space-y-6 pb-10">
            @include('managed-website.catalog.nav')
            <header><h1 class="text-2xl font-semibold text-zinc-950">Label printing</h1><p class="mt-2 max-w-3xl text-sm text-zinc-600">Save the paper and driver settings for each shipping computer. Print through your Mac or Windows driver, or download an actual-size PDF.</p></header>
            @if(session('status'))<p role="status" class="rounded-lg bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</p>@endif
            @if($errors->any())<div role="alert" class="rounded-lg bg-rose-50 p-4 text-sm text-rose-900">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
            <div class="grid gap-6 lg:grid-cols-2">
                <section class="rounded-xl border border-zinc-200 bg-white p-5">
                    <h2 class="text-lg font-semibold text-zinc-950">Workstation profile</h2>
                    <form id="print-profile" method="POST" action="{{ route('managed-website.printing.profiles.save') }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                        @csrf<input type="hidden" name="id" value="{{ old('id') }}"><input type="hidden" name="workstation_id" value="{{ old('workstation_id') }}"><input type="hidden" name="adapter" value="browser_pdf">
                        <label class="sm:col-span-2 text-sm font-medium text-zinc-800">Profile name<input required name="name" maxlength="80" value="{{ old('name') }}" placeholder="Packing desk - 4 x 6" class="mt-1 block w-full rounded-md border-zinc-300"></label>
                        <label class="text-sm font-medium text-zinc-800">Printer family<select required name="printer_family" class="mt-1 block w-full rounded-md border-zinc-300">@foreach(['generic' => 'Other / generic', 'dymo' => 'DYMO', 'rollo' => 'Rollo', 'zebra' => 'Zebra', 'brother' => 'Brother', 'desktop' => 'Laser / inkjet'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                        <label class="text-sm font-medium text-zinc-800">Printer / model name<input required name="printer_name" maxlength="120" value="{{ old('printer_name') }}" placeholder="DYMO LabelWriter 4XL" class="mt-1 block w-full rounded-md border-zinc-300"></label>
                        <label class="text-sm font-medium text-zinc-800">Computer<select required name="os" class="mt-1 block w-full rounded-md border-zinc-300"><option value="mac">Mac</option><option value="windows">Windows</option></select></label>
                        <label class="text-sm font-medium text-zinc-800">Connection<select required name="connection" class="mt-1 block w-full rounded-md border-zinc-300"><option value="usb">USB</option><option value="network">Network</option><option value="other">Other OS driver connection</option></select></label>
                        <label class="text-sm font-medium text-zinc-800">Browser<select required name="browser" class="mt-1 block w-full rounded-md border-zinc-300"><option value="chrome">Chrome</option><option value="edge">Edge</option><option value="safari">Safari</option><option value="firefox">Firefox</option><option value="other">Other / external PDF reader</option></select></label>
                        <label class="text-sm font-medium text-zinc-800">Paper<select required name="layout" class="mt-1 block w-full rounded-md border-zinc-300"><option value="thermal_4x6">4 x 6 inch thermal</option><option value="letter">Letter sheet</option><option value="a4">A4 sheet</option></select></label>
                        <label class="text-sm font-medium text-zinc-800">Paper orientation<select required name="orientation" class="mt-1 block w-full rounded-md border-zinc-300"><option value="portrait">Portrait</option><option value="landscape">Landscape</option></select></label>
                        <label class="sm:col-span-2 flex gap-2 text-sm text-zinc-700"><input required type="checkbox" name="media_confirmed" value="1" class="mt-1 rounded border-zinc-300">My printer and its installed driver support this paper size.</label>
                        <p class="sm:col-span-2 text-xs leading-5 text-zinc-500">Choose the saved printer again in the print dialog. A profile records your settings; the browser cannot connect to or detect your printer.</p>
                        <button class="fb-btn fb-btn-primary sm:col-span-2" type="submit">Save profile</button>
                    </form>
                </section>
                <div class="space-y-5">
                    <section class="rounded-xl border border-zinc-200 bg-white p-5"><h2 class="text-lg font-semibold text-zinc-950">Test without postage</h2><p class="mt-2 text-sm leading-6 text-zinc-600">Print a setup page and measure the one-inch square. Check all four borders before printing a purchased label.</p><form method="GET" action="{{ route('managed-website.printing.test') }}" target="_blank" class="mt-4 space-y-3"><label class="block text-sm font-medium text-zinc-800">Paper<select name="layout" class="mt-1 block w-full rounded-md border-zinc-300"><option value="thermal_4x6">4 x 6 inch thermal</option><option value="letter">Letter sheet</option><option value="a4">A4 sheet</option></select></label><label class="block text-sm font-medium text-zinc-800">Orientation<select name="orientation" class="mt-1 block w-full rounded-md border-zinc-300"><option value="portrait">Portrait</option><option value="landscape">Landscape</option></select></label><input type="hidden" name="inline" value="1"><button type="submit" class="fb-btn fb-btn-secondary">Open test PDF</button></form></section>
                    <section class="rounded-xl border border-zinc-200 bg-white p-5"><h2 class="text-lg font-semibold text-zinc-950">Actual size on Mac and Windows</h2><ol class="mt-3 list-decimal space-y-2 pl-5 text-sm leading-6 text-zinc-700"><li>Install the printer manufacturer's driver and select the matching paper.</li><li>Choose Actual Size or 100%. Turn off Fit, Shrink, and browser headers/footers.</li><li>Use portrait or landscape to match your saved paper orientation.</li><li>Confirm the full address, barcode, and label size after it reaches paper.</li></ol><p class="mt-3 text-xs leading-5 text-zinc-500">DYMO LabelWriter 4XL over USB is the first planned hardware check. Rollo, Zebra, Brother, and other models can use this PDF path when their driver supports 4 x 6 stock. Narrow printers need a compatible device. Exact model/driver combinations require a physical test.</p></section>
                </div>
            </div>
            <section class="rounded-xl border border-zinc-200 bg-white p-5"><h2 class="text-lg font-semibold text-zinc-950">Your saved profiles</h2><div class="mt-4 grid gap-3 sm:grid-cols-2">@forelse($profiles as $profile)<article class="rounded-lg border border-zinc-200 p-4"><p class="font-medium text-zinc-950">{{ $profile['name'] }}</p><p class="mt-1 text-sm text-zinc-600">{{ $profile['printer_name'] }} · {{ $profile['os'] === 'mac' ? 'Mac' : 'Windows' }}</p><p class="mt-1 text-xs text-zinc-500">{{ $profile['layout'] === 'thermal_4x6' ? '4 x 6 thermal' : str($profile['layout'])->upper() }} · {{ ucfirst($profile['orientation']) }} · {{ strtoupper($profile['connection']) }}</p><button type="button" data-edit-profile="{{ $profile['id'] }}" class="mt-3 text-sm font-medium text-emerald-800 hover:underline">Edit profile</button></article>@empty<p class="text-sm text-zinc-500">Save a profile for this shipping computer to get started.</p>@endforelse</div></section>
        </div>
        <script>
            (() => {
                const form = document.getElementById('print-profile');
                const profiles = @js($profiles);
                const previous = @js(session()->getOldInput());
                Object.entries(previous).forEach(([key, value]) => { if (form.elements[key] && typeof value === 'string' && key !== '_token') form.elements[key].value = value; });
                let workstation;
                try {
                    workstation = localStorage.getItem('everbranch-print-workstation-v1');
                    if (!/^[a-f0-9-]{36}$/i.test(workstation || '')) { workstation = crypto.randomUUID(); localStorage.setItem('everbranch-print-workstation-v1', workstation); }
                } catch { workstation = crypto.randomUUID(); }
                if (!form.elements.workstation_id.value) form.elements.workstation_id.value = workstation;
                document.querySelectorAll('[data-edit-profile]').forEach(button => button.addEventListener('click', () => {
                    const profile = profiles.find(item => item.id === button.dataset.editProfile);
                    if (!profile) return;
                    Object.entries(profile).forEach(([key, value]) => { if (form.elements[key]) form.elements[key].value = value; });
                    form.elements.media_confirmed.checked = false;
                    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }));
            })();
        </script>
    </flux:main>
</x-layouts::app.sidebar>

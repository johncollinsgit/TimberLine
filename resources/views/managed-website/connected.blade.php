<x-layouts::app.sidebar title="Website">
    <flux:main>
        <div class="mx-auto max-w-7xl space-y-6 pb-12">
            <header class="flex flex-wrap items-center justify-between gap-4">
                <div><h1 class="text-3xl font-bold">{{ $tenant->name }} website</h1><p class="mt-2 text-sm text-zinc-600">Edit your live design. Save changes as a draft, then preview and publish.</p></div>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ $publicUrl }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border px-4 py-2 text-sm font-semibold">View live site ↗</a>
                    <a href="{{ $previewUrl }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border px-4 py-2 text-sm font-semibold">Preview saved draft ↗</a>
                    <button form="connected-draft" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white">Save draft</button>
                    @if($canPublish)
                    <form action="{{ route('managed-website.connected.publish') }}" method="POST">@csrf<input type="hidden" name="version" value="{{ $site->draft_site_version_id }}"><button class="rounded-lg bg-emerald-800 px-4 py-2 text-sm font-semibold text-white">Publish saved draft</button></form>
                    @endif
                </div>
            </header>
            @if(session('status'))<p role="status" class="rounded-lg bg-emerald-50 p-4 text-emerald-900">{{ session('status') }}</p>@endif
            @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-4 text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
            <section class="overflow-hidden rounded-xl border bg-white">
                <div class="flex items-center justify-between border-b px-5 py-3"><p class="text-sm font-semibold">Live website</p><span class="text-xs text-emerald-800">Published {{ $site->published_at?->format('M j, g:i A') }}</span></div>
                <iframe src="{{ $publicUrl }}" title="Carolina Barrel live website" style="width:100%;height:620px;border:0" loading="lazy" referrerpolicy="no-referrer"></iframe>
            </section>
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_280px]">
                <form id="connected-draft" action="{{ route('managed-website.connected.save') }}" method="POST" class="space-y-4">
                    @csrf<input type="hidden" name="version" value="{{ $site->draft_site_version_id }}">
                    <h2 class="text-xl font-bold">Website content</h2>
                    <p class="text-sm text-zinc-600">Changes below stay private until you save and publish. Image fields accept an image URL or an existing /images/ path. Product amounts are retail quote starting prices.</p>
                    @foreach($manifest['groups'] as $group => $label)
                    <details class="rounded-xl border bg-white p-5" @if($group === (request()->routeIs('managed-website.products.index', 'managed-website.services.index') ? 'products' : 'home')) open @endif>
                        <summary class="cursor-pointer text-lg font-bold">{{ $label }}</summary>
                        <div class="mt-5 grid gap-5 sm:grid-cols-2">
                            @foreach($manifest['fields'] as $key => $field)
                            @continue($field['group'] !== $group)
                            <label class="block text-sm {{ strlen($content[$key]) > 120 || $field['type'] === 'lines' ? 'sm:col-span-2' : '' }}">
                                <span class="mb-2 block font-semibold">{{ $field['label'] }}</span>
                                @if($field['type'] === 'money')
                                <input class="w-full rounded-lg border-zinc-300" type="number" min="0" max="9999999.99" step="0.01" name="content[{{ $key }}]" value="{{ old('content.'.$key, $content[$key]) }}" required>
                                @else
                                <textarea class="w-full rounded-lg border-zinc-300 text-sm" name="content[{{ $key }}]" rows="{{ $field['type'] === 'lines' ? 5 : (strlen($content[$key]) > 120 ? 3 : 2) }}" maxlength="8000">{{ old('content.'.$key, $content[$key]) }}</textarea>
                                @endif
                                @if($field['type'] === 'image')<span class="mt-1 block text-xs text-zinc-500">Image URL</span>@elseif($field['type'] === 'link')<span class="mt-1 block text-xs text-zinc-500">Link destination</span>@elseif($field['type'] === 'lines')<span class="mt-1 block text-xs text-zinc-500">One detail per line</span>@endif
                            </label>
                            @endforeach
                        </div>
                    </details>
                    @endforeach
                    <button class="rounded-lg bg-zinc-900 px-5 py-3 font-semibold text-white">Save draft</button>
                </form>
                <aside class="space-y-5">
                    <section class="rounded-xl border bg-white p-5"><h2 class="font-bold">Your workspace</h2><a class="mt-3 block text-sm text-emerald-800 underline" href="{{ route('managed-website.leads.index') }}">View inquiries</a><p class="mt-3 text-sm text-zinc-600">Product quotes, wholesale requests, and partner applications appear here. Requests do not take payment or send automatic emails.</p></section>
                    <section class="rounded-xl border bg-white p-5"><h2 class="font-bold">Published versions</h2><p class="mt-2 text-sm text-zinc-600">Restore a version as a private draft, then review it before publishing.</p>
                    @foreach($history as $version)
                    <form action="{{ route('managed-website.connected.restore') }}" method="POST" class="mt-4 border-t pt-4">@csrf<input type="hidden" name="version" value="{{ $site->draft_site_version_id }}"><input type="hidden" name="restore_version" value="{{ $version->id }}"><p class="text-sm">Version {{ $version->version_number }} · {{ $version->published_at?->format('M j, g:i A') }}</p><button class="mt-2 text-sm font-semibold text-emerald-800 underline">Restore as draft</button></form>
                    @endforeach
                    </section>
                </aside>
            </div>
        </div>
    </flux:main>
</x-layouts::app.sidebar>

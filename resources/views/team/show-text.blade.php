<x-layouts::app :title="'Text customers · '.$event->title">
    @vite('resources/js/team/show-text.js')
    @php($eventRoute = ['id' => $event->id, 'tenant' => $tenant->slug])
    <main class="mx-auto max-w-4xl space-y-6 px-4 py-6 sm:px-6">
        <header class="space-y-2">
            <a class="text-sm underline" href="{{ route('team.app', ['tenant' => $tenant->slug]) }}">Teams → Events</a>
            <h1 class="text-3xl font-semibold">Text customers</h1>
            <p>{{ $event->title }}</p>
        </header>

        @if($errors->any())
            <div role="alert" class="rounded-xl border border-red-400 p-4">
                @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </div>
        @endif

        @if($review)
            @php($rows = $review['selection']['rows'])
            <div class="grid items-start gap-4 md:grid-cols-2">
                <section class="space-y-3 rounded-2xl border border-zinc-300 p-4">
                    <h2 class="font-semibold">Message preview</h2>
                    @if($draft['photo'])
                        <img class="max-h-72 max-w-full rounded-xl object-contain" src="{{ $draft['photo']['url'] }}" alt="Attached show photo">
                    @endif
                    <p class="whitespace-pre-wrap break-words">{{ $draft['body'] }}</p>
                    <a class="text-sm underline" href="{{ route('team.events.show-text', $eventRoute + ['edit' => 1]) }}">Edit message and audience</a>
                    @if($preview)
                        <form method="POST" action="{{ route('team.events.show-text.preview', $eventRoute) }}">
                            @csrf
                            <input type="hidden" name="review" value="{{ $review['id'] }}">
                            <button class="eb-secondary-button">Send me a preview</button>
                            <span class="text-sm">•••• {{ substr($preview['phone'], -4) }}</span>
                        </form>
                    @endif
                </section>

                <form class="space-y-3 rounded-2xl border border-zinc-300 p-4" method="POST" data-show-audience action="{{ route('team.events.show-text.send', $eventRoute) }}">
                    @csrf
                    <input type="hidden" name="review" value="{{ $review['id'] }}">
                    <input type="hidden" name="excluded" value="[]">
                    <h2 class="font-semibold">Audience</h2>
                    <p><strong data-count>{{ count($rows) }}</strong> opted-in customers selected</p>
                    @if($review['selection']['omitted'])
                        <p class="text-sm">{{ $review['selection']['omitted'] }} matches left out because of texting eligibility, duplicates, or unresolved customer records.</p>
                    @endif
                    @foreach($review['selection']['issues'] as $issue)
                        <p class="text-sm">{{ $issue }}</p>
                    @endforeach
                    @if($rows)
                        <details>
                            <summary class="cursor-pointer underline">See who’s included</summary>
                            <ul class="mt-3 max-h-80 divide-y divide-zinc-200 overflow-auto">
                                @foreach($rows as $row)
                                    <li class="py-2">
                                        <label class="flex items-start gap-2">
                                            <input class="mt-1" data-recipient value="{{ $row['id'] }}" type="checkbox" checked>
                                            <span><strong>{{ $row['name'] }}</strong> <span class="text-sm">•••• {{ substr($row['phone'], -4) }}</span>
                                                <small class="block">{{ implode(' · ', $row['reasons']) }}</small></span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                        <button class="eb-primary-button" data-send>Send to {{ count($rows) }} {{ count($rows) === 1 ? 'customer' : 'customers' }}</button>
                    @else
                        <p>No customers match this audience yet.</p>
                    @endif
                </form>
            </div>
        @else
            <form method="POST" class="space-y-5 rounded-2xl border border-zinc-300 p-4 sm:p-6"
                  action="{{ route('team.events.show-text.review', $eventRoute) }}"
                  data-show-composer data-upload="{{ route('team.events.show-text.photo', $eventRoute) }}">
                @csrf
                @if(count($stores) > 1)
                    <label class="block">Sending store
                        <select name="store" class="block rounded-lg border border-zinc-300 p-2">
                            @foreach($stores as $store)
                                <option @selected(old('store', $draft['store']) === $store)>{{ $store }}</option>
                            @endforeach
                        </select>
                    </label>
                @else
                    <input type="hidden" name="store" value="{{ $stores[0] }}">
                @endif
                <label class="block font-semibold" for="show-text-body">Message</label>
                <textarea id="show-text-body" name="body" required rows="5" maxlength="1600" class="block w-full rounded-xl border border-zinc-300 px-3 py-2">{{ old('body', $draft['body']) }}</textarea>
                <input type="hidden" name="photo_id" value="{{ old('photo_id', $draft['photo']['asset_id'] ?? '') }}">
                <div data-photo-drop class="space-y-2 rounded-xl border border-dashed border-zinc-400 p-4">
                    <button type="button" class="eb-secondary-button" data-photo-choose>{{ $draft['photo'] ? 'Replace photo' : 'Add photo' }}</button>
                    <input hidden type="file" accept="image/*" data-photo-input>
                    <button type="button" class="text-sm underline" data-photo-remove @if(! $draft['photo']) hidden @endif>Remove photo</button>
                    <img data-photo-preview class="max-h-48 max-w-full rounded-lg object-contain" alt="Attached show photo" @if($draft['photo']) src="{{ $draft['photo']['url'] }}" @else hidden @endif>
                    <p class="text-sm" data-photo-hint>Choose a photo or drop it here.</p>
                    <p data-photo-status role="status" class="text-sm"></p>
                </div>
                <fieldset class="space-y-2">
                    <legend class="font-semibold">Who should get it?</legend>
                    @foreach(['show' => 'Past show buyers', 'nearby' => 'Nearby show buyers', 'online' => 'Local online buyers'] as $key => $label)
                        <label class="mr-4 inline-flex items-center gap-2">
                            <input type="checkbox" name="groups[]" value="{{ $key }}" @checked(in_array($key, old('groups', $draft['groups']), true))>
                            {{ $label }}
                        </label>
                    @endforeach
                </fieldset>
                <details>
                    <summary class="cursor-pointer underline">Edit nearby area</summary>
                    <div class="mt-3 space-y-3">
                        @if($nearby->isNotEmpty())
                            <fieldset class="space-y-1">
                                <legend class="font-semibold">Nearby shows</legend>
                                @foreach($nearby as $series => $title)
                                    <label class="block">
                                        <input type="checkbox" name="nearby_series[]" value="{{ $series }}" @checked(in_array((string) $series, old('nearby_series', $draft['settings']['nearby_series']), true))>
                                        {{ $title }}
                                    </label>
                                @endforeach
                            </fieldset>
                        @endif
                        <label class="block">Cities, separated by commas
                            <input class="block w-full rounded-lg border border-zinc-300 p-2" name="cities" maxlength="500" value="{{ old('cities', implode(', ', $draft['settings']['cities'])) }}">
                        </label>
                        <label class="block">State
                            <input class="block rounded-lg border border-zinc-300 p-2" name="state" maxlength="60" placeholder="SC" value="{{ old('state', $draft['settings']['state']) }}">
                        </label>
                        <label class="block">ZIP codes, separated by commas
                            <input class="block w-full rounded-lg border border-zinc-300 p-2" name="zips" maxlength="250" value="{{ old('zips', implode(', ', $draft['settings']['zips'])) }}">
                        </label>
                        <label class="block"><input type="checkbox" name="save_area" value="1" @checked(old('save_area'))> Save this area for the next edition</label>
                    </div>
                </details>
                <button class="eb-primary-button" data-review>Review audience</button>
            </form>
        @endif

        @if($runs->isNotEmpty())
            <section class="space-y-2">
                <h2 class="font-semibold">Previous texts</h2>
                @foreach($runs as $run)
                    <p><a class="underline" href="{{ route('team.events.show-text.results', $eventRoute + ['campaign' => $run->id]) }}">
                        {{ data_get($run->target_snapshot, 'team_show_text.preview') ? 'Preview' : 'View results' }} · {{ $run->created_at->format('M j, Y g:i A') }}
                    </a></p>
                @endforeach
            </section>
        @endif
    </main>
</x-layouts::app>

<x-layouts::app :title="'Development Notes'">
    <div class="mx-auto w-full max-w-[1400px] px-4 py-6 md:px-6 space-y-6">
        <section class="rounded-3xl border border-zinc-200 bg-white p-6">
            <div class="text-[11px] uppercase tracking-[0.32em] text-zinc-500">Internal Admin</div>
            <h1 class="mt-2 text-2xl font-semibold text-zinc-950 md:text-3xl">Development Notes</h1>
            <p class="mt-2 max-w-3xl text-sm text-zinc-600">
                Internal README + implementation history for this app. This page is for admin use only.
            </p>
        </section>

        @if(session('status'))
            <section class="rounded-2xl border border-emerald-300/35 bg-emerald-100 px-4 py-3 text-sm text-emerald-900">
                {{ session('status') }}
            </section>
        @endif

        @if($errors->any())
            <section class="rounded-2xl border border-red-300/35 bg-red-100 px-4 py-3 text-sm text-red-900">
                <ul class="space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="rounded-3xl border border-zinc-200 bg-white p-6">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-zinc-950">Project Notes</h2>
                    <p class="mt-1 text-sm text-zinc-600">Editable internal notes for architecture, decisions, and development guidance.</p>
                </div>
            </div>

            <form method="POST" action="{{ route('admin.development-notes.notes.store') }}" class="mt-5 space-y-3 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                @csrf
                <label class="block text-sm font-medium text-zinc-800">
                    Title
                    <input
                        type="text"
                        name="title"
                        value="{{ old('title') }}"
                        placeholder="Example: Tableview migration scope snapshot"
                        class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                    />
                </label>
                <label class="block text-sm font-medium text-zinc-800">
                    Note
                    <textarea
                        name="body"
                        rows="6"
                        placeholder="Write internal development notes..."
                        class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                    >{{ old('body') }}</textarea>
                </label>
                <div>
                    <button type="submit" class="rounded-xl border border-zinc-300 bg-white px-4 py-2 text-sm font-semibold text-zinc-900 hover:bg-zinc-100">
                        Add Project Note
                    </button>
                </div>
            </form>

            @if($projectNotes->isEmpty())
                <div class="mt-5 rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 px-4 py-5 text-sm text-zinc-600">
                    No project notes yet. Add the first internal note above.
                </div>
            @else
                <div class="mt-5 space-y-4">
                    @foreach($projectNotes as $note)
                        <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                            <form method="POST" action="{{ route('admin.development-notes.notes.update', $note) }}" class="space-y-3">
                                @csrf
                                @method('PUT')
                                <label class="block text-sm font-medium text-zinc-800">
                                    Title
                                    <input
                                        type="text"
                                        name="title"
                                        value="{{ $note->title }}"
                                        class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                                    />
                                </label>
                                <label class="block text-sm font-medium text-zinc-800">
                                    Note
                                    <textarea
                                        name="body"
                                        rows="6"
                                        class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                                    >{{ $note->body }}</textarea>
                                </label>

                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs text-zinc-500">
                                        Updated {{ optional($note->updated_at)->format('M j, Y g:i A') ?: 'n/a' }}
                                        @php
                                            $updaterLabel = $note->updater?->name ?: ($note->updater?->email ?: null);
                                        @endphp
                                        @if($updaterLabel)
                                            by {{ $updaterLabel }}
                                        @endif
                                    </p>
                                    <div class="flex items-center gap-2">
                                        <button type="submit" class="rounded-xl border border-zinc-300 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-900 hover:bg-zinc-100">
                                            Save
                                        </button>
                                    </div>
                                </div>
                            </form>

                            <form method="POST" action="{{ route('admin.development-notes.notes.destroy', $note) }}" class="mt-2">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded-xl border border-red-300 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-800 hover:bg-red-100">
                                    Delete Note
                                </button>
                            </form>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-white p-6">
            <h2 class="text-xl font-semibold text-zinc-950">Change Log</h2>
            <p class="mt-1 text-sm text-zinc-600">Structured history of app changes, newest entries first.</p>

            <form method="POST" action="{{ route('admin.development-notes.change-logs.store') }}" class="mt-5 space-y-3 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                @csrf
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="block text-sm font-medium text-zinc-800">
                        Title
                        <input
                            type="text"
                            name="title"
                            value="{{ old('title') }}"
                            placeholder="Example: Added admin-only Development Notes page"
                            class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                        />
                    </label>
                    <label class="block text-sm font-medium text-zinc-800">
                        Area / Component (optional)
                        <input
                            type="text"
                            name="area"
                            value="{{ old('area') }}"
                            placeholder="Example: Admin / Navigation / Docs"
                            class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                        />
                    </label>
                </div>
                <label class="block text-sm font-medium text-zinc-800">
                    Summary
                    <textarea
                        name="summary"
                        rows="4"
                        placeholder="What changed and why?"
                        class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                    >{{ old('summary') }}</textarea>
                </label>
                <div>
                    <button type="submit" class="rounded-xl border border-zinc-300 bg-white px-4 py-2 text-sm font-semibold text-zinc-900 hover:bg-zinc-100">
                        Add Change Log Entry
                    </button>
                </div>
            </form>

            @if($changeLogs->isEmpty())
                <div class="mt-5 rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 px-4 py-5 text-sm text-zinc-600">
                    No change log entries yet. Add the first entry above.
                </div>
            @else
                <div class="mt-5 space-y-3">
                    @foreach($changeLogs as $entry)
                        <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <h3 class="text-sm font-semibold text-zinc-950">{{ $entry->title }}</h3>
                                @if($entry->area)
                                    <span class="rounded-full border border-zinc-300 bg-white px-2 py-1 text-[11px] font-medium text-zinc-700">{{ $entry->area }}</span>
                                @endif
                            </div>
                            <p class="mt-2 text-sm text-zinc-700 whitespace-pre-line">{{ $entry->summary }}</p>
                            @php
                                $creatorLabel = $entry->creator?->name ?: ($entry->creator?->email ?: 'System');
                            @endphp
                            <p class="mt-3 text-xs text-zinc-500">
                                {{ optional($entry->created_at)->format('M j, Y g:i A') ?: 'n/a' }} by {{ $creatorLabel }}
                            </p>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-layouts::app>

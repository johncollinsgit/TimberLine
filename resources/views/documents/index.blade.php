<x-layouts::app.sidebar :title="$tenant->name.' documents'">
    <div class="mx-auto w-full max-w-[1400px] space-y-6 px-4 py-6 sm:px-6">
        <header class="border-b border-zinc-200 pb-5">
            <div class="text-xs font-semibold uppercase text-emerald-800">Documents Branch</div>
            <h1 class="mt-2 text-3xl font-semibold">Files that stay with the work</h1>
            <p class="mt-2 text-sm text-zinc-600">Upload private copies, find them by name or job, and link one asset to several jobs.</p>
        </header>
        @if(session('status'))<div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">{{ session('status') }}</div>@endif

        <section class="grid gap-5 lg:grid-cols-[0.8fr_1.2fr]">
            <form method="POST" action="{{ route('documents.store', $tenant) }}" enctype="multipart/form-data" class="rounded-lg border border-zinc-200 bg-white p-5">
                @csrf
                <h2 class="font-semibold">Add documents or photos</h2>
                <label class="mt-4 block text-sm font-semibold">Files<input type="file" name="files[]" multiple required class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm"></label>
                <label class="mt-4 block text-sm font-semibold">Caption<input name="caption" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2"></label>
                <label class="mt-4 block text-sm font-semibold">Tags<input name="tags" placeholder="panel, permit, before photo" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2"></label>
                <label class="mt-4 block text-sm font-semibold">Jobs<select name="job_ids[]" multiple size="7" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">@foreach($jobs as $job)<option value="{{ $job->id }}">{{ $job->title }}</option>@endforeach</select></label>
                @if($canViewOwner)
                    <label class="mt-4 block text-sm font-semibold">Visibility<select name="visibility" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2"><option value="team">Team</option><option value="owner">Owner only</option></select></label>
                @endif
                <button class="mt-5 rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white">Upload</button>
                <p class="mt-3 text-xs leading-5 text-zinc-500">On iPhone, choose images from the system picker. iCloud originals remain untouched; Everbranch stores a private working copy.</p>
            </form>

            <div>
                <form method="GET" class="flex gap-2"><input name="q" value="{{ $query }}" placeholder="Search files, captions, or jobs" class="min-w-0 flex-1 rounded-lg border border-zinc-300 px-3 py-2"><button class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold">Search</button></form>
                <div class="mt-4 divide-y divide-zinc-100 rounded-lg border border-zinc-200 bg-white">
                    @forelse($assets as $asset)
                        <article class="p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0"><a class="font-semibold text-emerald-900 underline" href="{{ route('documents.download', [$tenant, $asset]) }}">{{ $asset->file_name }}</a><div class="mt-1 text-xs text-zinc-500">{{ number_format(($asset->file_size ?? 0) / 1024, 1) }} KB · {{ ucfirst($asset->visibility) }} · {{ ucfirst($asset->source) }}</div><p class="mt-1 text-sm text-zinc-600">{{ $asset->caption }}</p></div>
                                <form method="POST" action="{{ route('documents.destroy', [$tenant, $asset]) }}">@csrf @method('DELETE')<button class="text-xs font-semibold text-red-700">Delete</button></form>
                            </div>
                            <form method="POST" action="{{ route('documents.links', [$tenant, $asset]) }}" class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">@csrf @method('PUT')<label class="min-w-0 flex-1 text-xs font-semibold">Linked jobs<select name="job_ids[]" multiple size="3" class="mt-1 w-full rounded-lg border border-zinc-300 px-2 py-1 text-xs">@foreach($jobs as $job)<option value="{{ $job->id }}" @selected($asset->jobs->contains('id', $job->id))>{{ $job->title }}</option>@endforeach</select></label><button class="rounded-lg border border-zinc-300 px-3 py-2 text-xs font-semibold">Update links</button></form>
                        </article>
                    @empty
                        <div class="p-8 text-center text-sm text-zinc-500">No documents match this workspace search.</div>
                    @endforelse
                </div>
                <div class="mt-4">{{ $assets->links() }}</div>
            </div>
        </section>
    </div>
</x-layouts::app.sidebar>

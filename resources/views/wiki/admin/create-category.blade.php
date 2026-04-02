<x-layouts::app :title="'New Wiki Category'">
    <div class="mx-auto w-full max-w-[860px] px-4 py-6 md:px-6 space-y-6">
        <section class="rounded-3xl border border-zinc-200 bg-white p-6">
            <nav class="text-xs text-zinc-500">
                <a href="{{ route('wiki.categories') }}" class="hover:text-zinc-600">Wiki Categories</a>
                <span class="mx-1">/</span>
                <span>New Category</span>
            </nav>
            <h1 class="mt-2 text-2xl font-semibold text-zinc-950">Create Wiki Category</h1>
        </section>

        @if($errors->any())
            <section class="rounded-2xl border border-red-300/35 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                <ul class="space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <form method="POST" action="{{ route('wiki.admin.category.store') }}" class="space-y-4 rounded-3xl border border-zinc-200 bg-white p-6">
            @csrf

            <label class="text-sm text-zinc-700 block">Slug
                <input name="slug" value="{{ old('slug') }}" placeholder="example: quality-control" class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900" />
            </label>

            <label class="text-sm text-zinc-700 block">Title
                <input name="title" value="{{ old('title') }}" class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900" />
            </label>

            <label class="text-sm text-zinc-700 block">Description
                <textarea name="description" rows="3" class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900">{{ old('description') }}</textarea>
            </label>

            <label class="text-sm text-zinc-700 block">Subcategory slugs (comma-separated)
                <input name="subcategories_csv" value="{{ old('subcategories_csv') }}" class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900" />
            </label>

            <div class="flex items-center gap-2">
                <button type="submit" class="rounded-xl border border-sky-300/40 bg-sky-100 px-4 py-2 text-sm font-medium text-sky-900 hover:bg-sky-100">Create category</button>
                <a href="{{ route('wiki.categories') }}" class="rounded-xl border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:border-zinc-400">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts::app>

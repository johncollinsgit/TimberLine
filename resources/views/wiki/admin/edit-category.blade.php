<x-layouts::app :title="'Edit Wiki Category'">
    <div class="mx-auto w-full max-w-[860px] px-4 py-6 md:px-6 space-y-6">
        <section class="rounded-3xl border border-white/10 bg-zinc-950/40 p-6">
            <nav class="text-xs text-zinc-500">
                <a href="{{ route('wiki.categories') }}" class="hover:text-zinc-300">Wiki Categories</a>
                <span class="mx-1">/</span>
                <span>Edit</span>
            </nav>
            <h1 class="mt-2 text-2xl font-semibold text-white">Edit Category: {{ $category['title'] }}</h1>
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

        <form method="POST" action="{{ route('wiki.admin.category.update', ['slug' => $category['slug']]) }}" class="space-y-4 rounded-3xl border border-white/10 bg-zinc-950/40 p-6">
            @csrf
            @method('PUT')

            <label class="text-sm text-zinc-200 block">Title
                <input name="title" value="{{ old('title', $category['title']) }}" class="mt-1 w-full rounded-xl border border-white/15 bg-zinc-900 px-3 py-2 text-sm text-white" />
            </label>

            <label class="text-sm text-zinc-200 block">Description
                <textarea name="description" rows="3" class="mt-1 w-full rounded-xl border border-white/15 bg-zinc-900 px-3 py-2 text-sm text-white">{{ old('description', $category['description']) }}</textarea>
            </label>

            <label class="text-sm text-zinc-200 block">Subcategory slugs (comma-separated)
                <input name="subcategories_csv" value="{{ old('subcategories_csv', $subcategoriesCsv) }}" class="mt-1 w-full rounded-xl border border-white/15 bg-zinc-900 px-3 py-2 text-sm text-white" />
            </label>

            <div class="flex items-center gap-2">
                <button type="submit" class="rounded-xl border border-sky-300/40 bg-sky-500/20 px-4 py-2 text-sm font-medium text-sky-100 hover:bg-sky-500/30">Save category</button>
                <a href="{{ route('wiki.category', ['slug' => $category['slug']]) }}" class="rounded-xl border border-white/15 px-4 py-2 text-sm text-zinc-200 hover:border-white/30">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts::app>

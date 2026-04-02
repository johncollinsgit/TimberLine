<nav class="rounded-2xl border border-zinc-200 bg-white p-4 text-sm">
    <div class="text-[11px] uppercase tracking-[0.24em] text-zinc-400">Wiki</div>
    <ul class="mt-3 space-y-1">
        <li>
            <a href="{{ route('wiki.index') }}" class="block rounded-lg px-3 py-2 {{ request()->routeIs('wiki.index') ? 'bg-zinc-100 text-zinc-900' : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900' }}">Home</a>
        </li>
        <li>
            <a href="{{ route('wiki.categories') }}" class="block rounded-lg px-3 py-2 {{ request()->routeIs('wiki.categories') || request()->routeIs('wiki.category') ? 'bg-zinc-100 text-zinc-900' : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900' }}">Categories</a>
        </li>
        <li>
            <a href="{{ route('wiki.wholesale-processes') }}" class="block rounded-lg px-3 py-2 {{ request()->routeIs('wiki.wholesale-processes') ? 'bg-zinc-100 text-zinc-900' : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900' }}">Wholesale Processes</a>
        </li>
        <li>
            <a href="{{ route('wiki.article', ['slug' => 'market-room']) }}" class="block rounded-lg px-3 py-2 {{ request()->routeIs('wiki.article') && request()->route('slug') === 'market-room' ? 'bg-zinc-100 text-zinc-900' : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900' }}">Market Room Process</a>
        </li>
        <li>
            <a href="{{ route('wiki.random') }}" class="block rounded-lg px-3 py-2 text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900">Random Article</a>
        </li>
    </ul>
    @if(auth()->user()?->isAdmin())
        <div class="mt-4 border-t border-zinc-200 pt-3">
            <div class="text-[11px] uppercase tracking-[0.2em] text-zinc-500">Admin</div>
            <div class="mt-2 space-y-2">
                <a href="{{ route('wiki.admin.article.create') }}" class="block rounded-lg border border-sky-300/30 bg-sky-100 px-3 py-2 text-xs text-sky-900 hover:bg-sky-100">New Article</a>
                <a href="{{ route('wiki.admin.category.create') }}" class="block rounded-lg border border-sky-300/30 bg-sky-100 px-3 py-2 text-xs text-sky-900 hover:bg-sky-100">New Category</a>
            </div>
        </div>
    @endif
</nav>

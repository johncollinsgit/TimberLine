<nav class="rounded-2xl border border-white/10 bg-zinc-950/40 p-4 text-sm">
    <div class="text-[11px] uppercase tracking-[0.24em] text-zinc-400">Wiki</div>
    <ul class="mt-3 space-y-1">
        <li>
            <a href="{{ route('wiki.index') }}" class="block rounded-lg px-3 py-2 {{ request()->routeIs('wiki.index') ? 'bg-zinc-800 text-white' : 'text-zinc-300 hover:bg-zinc-900 hover:text-white' }}">Home</a>
        </li>
        <li>
            <a href="{{ route('wiki.categories') }}" class="block rounded-lg px-3 py-2 {{ request()->routeIs('wiki.categories') || request()->routeIs('wiki.category') ? 'bg-zinc-800 text-white' : 'text-zinc-300 hover:bg-zinc-900 hover:text-white' }}">Categories</a>
        </li>
        <li>
            <a href="{{ route('wiki.wholesale-processes') }}" class="block rounded-lg px-3 py-2 {{ request()->routeIs('wiki.wholesale-processes') ? 'bg-zinc-800 text-white' : 'text-zinc-300 hover:bg-zinc-900 hover:text-white' }}">Wholesale Processes</a>
        </li>
        <li>
            <a href="{{ route('wiki.article', ['slug' => 'market-room']) }}" class="block rounded-lg px-3 py-2 {{ request()->routeIs('wiki.article') && request()->route('slug') === 'market-room' ? 'bg-zinc-800 text-white' : 'text-zinc-300 hover:bg-zinc-900 hover:text-white' }}">Market Room Process</a>
        </li>
        <li>
            <a href="{{ route('wiki.random') }}" class="block rounded-lg px-3 py-2 text-zinc-300 hover:bg-zinc-900 hover:text-white">Random Article</a>
        </li>
    </ul>
    @if(auth()->user()?->isAdmin())
        <div class="mt-4 border-t border-white/10 pt-3">
            <div class="text-[11px] uppercase tracking-[0.2em] text-zinc-500">Admin</div>
            <div class="mt-2 space-y-2">
                <a href="{{ route('wiki.admin.article.create') }}" class="block rounded-lg border border-sky-300/30 bg-sky-500/10 px-3 py-2 text-xs text-sky-100 hover:bg-sky-500/20">New Article</a>
                <a href="{{ route('wiki.admin.category.create') }}" class="block rounded-lg border border-sky-300/30 bg-sky-500/10 px-3 py-2 text-xs text-sky-100 hover:bg-sky-500/20">New Category</a>
            </div>
        </div>
    @endif
</nav>

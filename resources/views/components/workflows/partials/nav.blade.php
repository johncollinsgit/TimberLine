<nav aria-label="Automation workspace" class="flex flex-wrap items-center gap-1 rounded-2xl border border-zinc-200 bg-white/80 p-1.5 shadow-sm backdrop-blur">
    @foreach([
        ['route' => 'workflows.index', 'label' => 'Workflows', 'match' => 'workflows.index'],
        ['route' => 'workflows.history', 'label' => 'Run history', 'match' => 'workflows.history'],
        ['route' => 'workflows.connections', 'label' => 'Connections', 'match' => 'workflows.connections'],
    ] as $item)
        <a href="{{ route($item['route']) }}" wire:navigate class="rounded-xl px-3.5 py-2 text-sm font-semibold transition {{ request()->routeIs($item['match']) ? 'bg-zinc-950 text-white shadow-sm' : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-950' }}">{{ $item['label'] }}</a>
    @endforeach
</nav>

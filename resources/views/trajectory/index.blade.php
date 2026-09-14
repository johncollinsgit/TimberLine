<x-layouts::app.sidebar :title="'Trajectory'">
    @vite('resources/js/trajectory/app.js')
    <main id="trajectory" class="tr-app" data-spaces="{{ json_encode($spaces->map->only(['id','name','kind'])->values()) }}" data-invite="{{ request('invite','') }}">
        <header class="tr-header">
            <div><div class="tr-brandline"><span class="tr-mark" aria-hidden="true">↗</span><h1>Trajectory</h1><span class="tr-pill">Private pilot</span></div><p>See what’s ahead. Change where you’re going.</p></div>
            <div class="tr-actions"><label class="sr-only" for="tr-space">Finance space</label><select id="tr-space"></select><button id="tr-add" class="tr-primary">＋ Add to your plan</button></div>
        </header>
        <div id="tr-feedback" role="status" aria-live="polite" hidden></div>
        <div id="tr-invite" hidden class="tr-notice">You have a household invitation. <button id="tr-accept">Accept invitation</button></div>
        <nav class="tr-tabs" aria-label="Trajectory sections">
            @foreach(['overview'=>'Overview','transactions'=>'Transactions','bills'=>'Bills & goals','medical'=>'Medical sharing','assets'=>'Wealth & debt','business'=>'Business','connections'=>'Connections'] as $key=>$label)
            <button data-tab="{{ $key }}" @if($key==='overview') aria-current="page" @endif>{{ $label }}</button>
            @endforeach
        </nav>
        <div class="tr-toolbar"><div id="tr-context">Loading your financial picture…</div><div class="tr-actions"><label for="tr-range">Period</label><select id="tr-range"><option value="day">Today</option><option value="week">This week</option><option value="month" selected>This month</option><option value="year">This year</option></select><button id="tr-refresh">↻ Refresh</button></div></div>
        <div id="tr-content" aria-busy="true"><div class="tr-empty">Loading your finance spaces…</div></div>
        <dialog id="tr-dialog" class="tr-dialog"><form id="tr-form"><header><h2 id="tr-dialog-title">Add to your plan</h2><button type="button" id="tr-close" aria-label="Close dialog">×</button></header><div id="tr-fields"></div><div id="tr-form-error" class="tr-error" role="alert" hidden></div><footer><button type="button" id="tr-cancel">Cancel</button><button type="submit" class="tr-primary" id="tr-save">Save</button></footer></form></dialog>
    </main>
</x-layouts::app.sidebar>

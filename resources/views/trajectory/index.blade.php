<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="robots" content="noindex,nofollow"><title>Trajectory · Your financial future</title></head><body class="trajectory-site">
    @vite('resources/js/trajectory/app.js')
    <main id="trajectory" class="tr-app" data-spaces="{{ json_encode($spaces->map->only(['id','name','kind'])->values()) }}" data-invite="{{ request('invite','') }}">
        <aside class="tr-sidebar">
            <a class="tr-wordmark" href="{{ route('trajectory.index') }}"><span class="tr-logo" aria-hidden="true">↗</span>trajectory<span class="tr-brand-dot">.</span></a>
            <p class="tr-nav-label">YOUR FINANCIAL FUTURE</p>
            <nav class="tr-tabs" aria-label="Trajectory sections">
                @foreach(['overview'=>['↗','Trajectory'],'accounts'=>['▤','Accounts'],'transactions'=>['⇄','Review transactions'],'history'=>['◷','Cash flow & history'],'budget'=>['▦','Budget'],'bills'=>['▣','Bills & goals'],'medical'=>['♡','Medical sharing'],'assets'=>['◈','Wealth & debt'],'business'=>['▥','Business'],'connections'=>['⚙','Connections']] as $key=>$item)
                <button data-tab="{{ $key }}" @if($key==='overview') aria-current="page" @endif><span aria-hidden="true">{{ $item[0] }}</span>{{ $item[1] }}</button>
                @endforeach
            </nav>
            <div class="tr-sidebar-bottom"><a href="{{ route('trajectory.welcome') }}">About Trajectory ↗</a><a href="/dashboard">← Back to Everbranch</a><div class="tr-user"><span class="tr-avatar">{{ mb_substr(auth()->user()->name,0,1) }}</span><div>{{ auth()->user()->name }}<small>Private financial workspace</small></div></div></div>
        </aside>
        <div class="tr-workspace">
        <header class="tr-header"><div><p class="tr-eyebrow">MAKE ROOM FOR WHAT MATTERS</p><h1 id="tr-page-title">Your trajectory</h1><p id="tr-page-subtitle">A clearer picture of today. A better plan for tomorrow.</p></div><div class="tr-actions"><div id="tr-space-tabs" class="tr-space-tabs" role="tablist" aria-label="Finance view"></div><button id="tr-add" class="tr-primary">＋ Add to your plan</button></div></header>
        <div id="tr-feedback" role="status" aria-live="polite" hidden></div>
        <div id="tr-invite" hidden class="tr-notice">You have a household invitation. <button id="tr-accept">Accept invitation</button></div>
        <div class="tr-toolbar"><div id="tr-context">Loading your financial picture…</div><div class="tr-actions"><label for="tr-range">Period</label><select id="tr-range"><option value="day">Today</option><option value="week">This week</option><option value="month" selected>This month</option><option value="year">This year</option><option value="all">All history</option><option value="custom">Choose dates</option></select><span id="tr-date-fields" hidden><label class="sr-only" for="tr-start">Start date</label><input id="tr-start" type="date"><label class="sr-only" for="tr-end">End date</label><input id="tr-end" type="date"></span><button id="tr-refresh">↻ Refresh</button></div></div>
        <div id="tr-content" aria-busy="true"><div class="tr-empty">Loading your finance spaces…</div></div>
        <dialog id="tr-dialog" class="tr-dialog"><form id="tr-form"><header><h2 id="tr-dialog-title">Add to your plan</h2><button type="button" id="tr-close" aria-label="Close dialog">×</button></header><div id="tr-fields"></div><div id="tr-form-error" class="tr-error" role="alert" hidden></div><footer><button type="button" id="tr-cancel">Cancel</button><button type="submit" class="tr-primary" id="tr-save">Save</button></footer></form></dialog>
        </div>
    </main>
</body></html>

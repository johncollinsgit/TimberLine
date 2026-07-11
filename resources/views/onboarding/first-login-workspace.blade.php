@php
    $workspaceName = $workspaceName ?? '';
    $businessTypes = is_array($businessTypes ?? null) ? $businessTypes : [];
    $teamSizes = is_array($teamSizes ?? null) ? $teamSizes : [];
    $hardestParts = is_array($hardestParts ?? null) ? $hardestParts : [];
    $toolOptions = is_array($toolOptions ?? null) ? $toolOptions : [];
    $recommendedTools = is_array($recommendedTools ?? null) ? $recommendedTools : [];
    $steps = ['Name', 'Business', 'Team', 'Focus', 'Tools', 'Help', 'Ready'];
@endphp

<x-layouts::auth.simple :title="__('Create your workspace')">
    <style>
        [data-flw-shell] {
            background:
                linear-gradient(135deg, rgba(255, 255, 255, 0.92), rgba(244, 247, 246, 0.74)),
                radial-gradient(120% 120% at 50% -8%, rgba(224, 241, 236, 0.9) 0%, rgba(248, 250, 250, 0.72) 48%, rgba(255, 255, 255, 1) 100%);
        }

        [data-flw] {
            animation: flw-shell-in 520ms cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        [data-step]:not([hidden]) {
            animation: flw-step-in 420ms cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .flw-card,
        .flw-team,
        .flw-focus,
        .flw-tool,
        .flw-help {
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75);
        }

        .flw-card:hover,
        .flw-team:hover,
        .flw-focus:hover,
        .flw-tool:hover,
        .flw-help:hover {
            transform: translateY(-1px);
        }

        @keyframes flw-shell-in {
            from {
                opacity: 0;
                transform: translateY(10px) scale(0.985);
                filter: blur(14px);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
                filter: blur(0);
            }
        }

        @keyframes flw-step-in {
            from {
                opacity: 0;
                transform: translateY(8px);
                filter: blur(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
                filter: blur(0);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            [data-flw],
            [data-step]:not([hidden]) {
                animation: none;
            }

            .flw-card:hover,
            .flw-team:hover,
            .flw-focus:hover,
            .flw-tool:hover,
            .flw-help:hover {
                transform: none;
            }
        }
    </style>

    <div data-flw-shell class="min-h-[80vh] w-full px-3 py-8 sm:py-10">
        <div
            data-flw
            data-workspace-name="{{ $workspaceName }}"
            data-recommended='@json($recommendedTools)'
            class="mx-auto w-full max-w-2xl overflow-hidden rounded-[2rem] border border-white/80 bg-white/80 p-6 shadow-[0_24px_80px_rgba(15,28,31,0.16)] backdrop-blur-2xl sm:p-8"
        >
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">Everbranch setup</p>
                    <h1 class="mt-1 text-xl font-semibold text-zinc-950 sm:text-2xl">Set up your workspace</h1>
                    <p class="mt-1 max-w-md text-sm text-zinc-500">Answer a few things and we will shape the first version around the work your team actually does.</p>
                </div>
                <span class="shrink-0 rounded-full border border-white/80 bg-white/70 px-3 py-1 text-xs font-medium text-zinc-500 shadow-sm">
                    Step <span data-step-num>1</span> of {{ count($steps) }}
                </span>
            </div>

            <div class="mt-5 grid gap-2 rounded-2xl border border-zinc-200/80 bg-zinc-50/80 p-2 text-xs text-zinc-600 sm:grid-cols-3">
                <div class="rounded-xl bg-white/80 px-3 py-2 shadow-sm">
                    <span class="block font-semibold text-zinc-950">Guided launch</span>
                    <span>We review before real use.</span>
                </div>
                <div class="rounded-xl bg-white/80 px-3 py-2 shadow-sm">
                    <span class="block font-semibold text-zinc-950">No surprise billing</span>
                    <span>Tools are recorded first.</span>
                </div>
                <div class="rounded-xl bg-white/80 px-3 py-2 shadow-sm">
                    <span class="block font-semibold text-zinc-950">Field-ready base</span>
                    <span>Jobs, customers, photos.</span>
                </div>
            </div>

            {{-- Progress dots --}}
            <div class="mt-5 flex items-center gap-1.5" aria-hidden="true">
                @foreach ($steps as $i => $label)
                    <span data-dot="{{ $i + 1 }}" class="h-1.5 flex-1 rounded-full bg-zinc-200 transition-colors"></span>
                @endforeach
            </div>

            @if ($errors->any())
                <div class="mt-5 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('workspace.first-login.store') }}" class="mt-6">
                @csrf
                <input type="hidden" name="workspace_name" value="{{ $workspaceName }}">
                <input type="hidden" name="template_key" value="">
                <input type="hidden" name="team_size" value="">
                <input type="hidden" name="hardest_part" value="">
                <input type="hidden" name="start_path" value="guided">
                <input type="hidden" name="appointment_name" value="">
                <input type="hidden" name="appointment_email" value="">
                <input type="hidden" name="appointment_phone" value="">
                <span data-modules-holder></span>

                {{-- Step 1: name --}}
                <section data-step="1" class="space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900">What should we call it?</h2>
                        <p class="mt-1 text-sm text-zinc-500">This becomes the name your team sees in the app. You can change it later.</p>
                    </div>
                    <input
                        data-name-input type="text" value="{{ $workspaceName }}" maxlength="120"
                        placeholder="e.g. Collins Electric"
                        class="w-full rounded-xl border border-zinc-300 px-4 py-3 text-base text-zinc-900 shadow-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200"
                    >
                </section>

                {{-- Step 2: business type --}}
                <section data-step="2" class="space-y-4" hidden>
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900">What kind of work do you do?</h2>
                        <p class="mt-1 text-sm text-zinc-500">We will tune the labels, starter tools, and first dashboard around this.</p>
                    </div>
                    <div class="grid gap-2.5 sm:grid-cols-2">
                        @foreach ($businessTypes as $type)
                            <button
                                type="button" data-pick-type="{{ $type['key'] }}"
                                class="flw-card rounded-xl border border-zinc-200 bg-white p-4 text-left transition hover:border-emerald-300 hover:shadow-sm"
                            >
                                <span class="block text-sm font-semibold text-zinc-900">{{ $type['label'] }}</span>
                                <span class="mt-1 block text-xs text-zinc-500">{{ $type['blurb'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </section>

                {{-- Step 3: team size --}}
                <section data-step="3" class="space-y-4" hidden>
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900">How big is your team right now?</h2>
                        <p class="mt-1 text-sm text-zinc-500">This helps us keep the first setup practical instead of overbuilt.</p>
                    </div>
                    <div class="grid gap-2.5 sm:grid-cols-2">
                        @foreach ($teamSizes as $key => $label)
                            <button
                                type="button" data-pick-team="{{ $key }}"
                                class="flw-team rounded-xl border border-zinc-200 bg-white px-4 py-3 text-left text-sm font-medium text-zinc-800 transition hover:border-emerald-300 hover:shadow-sm"
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                </section>

                {{-- Step 4: hardest part --}}
                <section data-step="4" class="space-y-4" hidden>
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900">What's the biggest headache right now?</h2>
                        <p class="mt-1 text-sm text-zinc-500">We'll put the fix for this front and center.</p>
                    </div>
                    <div class="space-y-2">
                        @foreach ($hardestParts as $key => $opt)
                            <button
                                type="button" data-pick-focus="{{ $key }}"
                                class="flw-focus block w-full rounded-xl border border-zinc-200 bg-white p-3.5 text-left transition hover:border-emerald-300 hover:shadow-sm"
                            >
                                <span class="block text-sm font-semibold text-zinc-900">{{ $opt['label'] }}</span>
                                <span class="mt-0.5 block text-xs text-zinc-500">{{ $opt['description'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </section>

                {{-- Step 5: tools --}}
                <section data-step="5" class="space-y-4" hidden>
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900">Pick the tools that sound useful</h2>
                        <p class="mt-1 text-sm text-zinc-500">Recommended tools are a launch plan, not a checkout screen. We will activate what is ready.</p>
                    </div>
                    <div class="grid max-h-[42vh] gap-2.5 overflow-y-auto pr-1 sm:grid-cols-2">
                        @foreach ($toolOptions as $key => $tool)
                            <button
                                type="button" data-tool="{{ $key }}"
                                class="flw-tool flex items-start gap-3 rounded-xl border border-zinc-200 bg-white p-3 text-left transition hover:border-emerald-300"
                            >
                                <span class="text-lg leading-none">{{ $tool['icon'] ?? '•' }}</span>
                                <span class="min-w-0">
                                    <span class="flex items-center gap-1.5">
                                        <span class="text-sm font-semibold text-zinc-900">{{ $tool['label'] }}</span>
                                        <span data-rec-badge class="hidden rounded-full bg-emerald-100 px-1.5 py-0.5 text-[0.6rem] font-semibold text-emerald-700">Recommended</span>
                                    </span>
                                    <span class="mt-0.5 block text-xs text-zinc-500">{{ $tool['description'] }}</span>
                                </span>
                                <span data-tool-check class="ml-auto hidden text-emerald-600">✓</span>
                            </button>
                        @endforeach
                    </div>
                </section>

                {{-- Step 6: concierge --}}
                <section data-step="6" class="space-y-4" hidden>
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900">Want a hand setting it up?</h2>
                        <p class="mt-1 text-sm text-zinc-500">For early launch partners, guided setup is the default. Self-serve can come after the workflow is proven.</p>
                    </div>
                    <div class="grid gap-2.5 sm:grid-cols-2">
                        <button type="button" data-pick-help="guided" class="flw-help rounded-xl border-2 border-emerald-500 bg-emerald-50 p-4 text-left transition">
                            <span class="block text-sm font-semibold text-zinc-900">Set this up with me</span>
                            <span class="mt-1 block text-xs text-zinc-500">We will help import data, enable messaging, and shape the first dashboard.</span>
                        </button>
                        <button type="button" data-pick-help="self" class="flw-help rounded-xl border-2 border-zinc-200 bg-white p-4 text-left transition">
                            <span class="block text-sm font-semibold text-zinc-900">Let me explore first</span>
                            <span class="mt-1 block text-xs text-zinc-500">Open the workspace now and finish setup later.</span>
                        </button>
                    </div>
                    <div data-help-contact class="space-y-2.5 rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                        <p class="text-xs font-medium text-zinc-600">Where can we reach you? (optional)</p>
                        <input data-help-name type="text" placeholder="Your name" maxlength="120" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                        <input data-help-email type="email" placeholder="Email" maxlength="255" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                        <input data-help-phone type="text" placeholder="Phone" maxlength="40" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                    </div>
                </section>

                {{-- Step 7: review --}}
                <section data-step="7" class="space-y-4" hidden>
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900">Ready to create the workspace</h2>
                        <p class="mt-1 text-sm text-zinc-500">We will open the first version now and keep the launch items queued for review.</p>
                    </div>
                    <dl class="space-y-2 rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-sm">
                        <div class="flex justify-between gap-4"><dt class="text-zinc-500">Workspace</dt><dd data-review-name class="font-medium text-zinc-900"></dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-zinc-500">Business</dt><dd data-review-type class="font-medium text-zinc-900"></dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-zinc-500">Team</dt><dd data-review-team class="font-medium text-zinc-900"></dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-zinc-500">Tools</dt><dd data-review-tools class="text-right font-medium text-zinc-900"></dd></div>
                    </dl>
                    <p class="text-xs text-zinc-400">Your tool picks are saved as recommendations. This does not start billing, send messages, or invite anyone.</p>
                </section>

                {{-- Nav --}}
                <div class="mt-7 flex items-center justify-between gap-3">
                    <button type="button" data-back class="rounded-full px-4 py-2 text-sm font-medium text-zinc-500 hover:text-zinc-800" hidden>Back</button>
                    <span class="flex-1"></span>
                    <button type="button" data-next class="rounded-full bg-zinc-950 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-40">Continue</button>
                    <button type="submit" data-submit class="rounded-full bg-zinc-950 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800" hidden>Create workspace</button>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
    @endpush

    <script>
        (function () {
            var root = document.querySelector('[data-flw]');
            if (!root) return;
            var recommended = {};
            try { recommended = JSON.parse(root.getAttribute('data-recommended') || '{}'); } catch (e) {}
            var TOTAL = 7;
            var step = 1;
            var state = {
                workspace_name: root.getAttribute('data-workspace-name') || '',
                template_key: '', template_label: '',
                team_size: '', team_label: '',
                hardest_part: '',
                module_choices: [],
                start_path: 'guided',
            };

            var $ = function (sel) { return root.querySelector(sel); };
            var $$ = function (sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); };
            var hidden = {
                workspace_name: $('input[name="workspace_name"]'),
                template_key: $('input[name="template_key"]'),
                team_size: $('input[name="team_size"]'),
                hardest_part: $('input[name="hardest_part"]'),
                start_path: $('input[name="start_path"]'),
                appointment_name: $('input[name="appointment_name"]'),
                appointment_email: $('input[name="appointment_email"]'),
                appointment_phone: $('input[name="appointment_phone"]'),
            };
            var modulesHolder = $('[data-modules-holder]');

            function syncHidden() {
                hidden.workspace_name.value = state.workspace_name;
                hidden.template_key.value = state.template_key;
                hidden.team_size.value = state.team_size;
                hidden.hardest_part.value = state.hardest_part;
                hidden.start_path.value = state.start_path;
                modulesHolder.innerHTML = '';
                state.module_choices.forEach(function (k) {
                    var i = document.createElement('input');
                    i.type = 'hidden'; i.name = 'module_choices[]'; i.value = k;
                    modulesHolder.appendChild(i);
                });
            }

            function canNext() {
                if (step === 1) return state.workspace_name.trim().length > 0;
                if (step === 2) return state.template_key !== '';
                if (step === 3) return state.team_size !== '';
                if (step === 4) return state.hardest_part !== '';
                return true;
            }

            function selectOne(nodes, node, activeClasses) {
                nodes.forEach(function (n) {
                    n.classList.remove('border-emerald-500', 'border-2', 'bg-emerald-50');
                    n.classList.add('border-zinc-200');
                });
                node.classList.remove('border-zinc-200');
                node.classList.add('border-emerald-500', 'bg-emerald-50');
            }

            function refreshTools() {
                var rec = recommended[state.template_key] || [];
                $$('[data-tool]').forEach(function (el) {
                    var k = el.getAttribute('data-tool');
                    var picked = state.module_choices.indexOf(k) !== -1;
                    el.classList.toggle('border-emerald-500', picked);
                    el.classList.toggle('bg-emerald-50', picked);
                    el.classList.toggle('border-zinc-200', !picked);
                    var check = el.querySelector('[data-tool-check]');
                    if (check) check.classList.toggle('hidden', !picked);
                    var badge = el.querySelector('[data-rec-badge]');
                    if (badge) badge.classList.toggle('hidden', rec.indexOf(k) === -1);
                });
            }

            function updateReview() {
                $('[data-review-name]').textContent = state.workspace_name || '—';
                $('[data-review-type]').textContent = state.template_label || '—';
                $('[data-review-team]').textContent = state.team_label || '—';
                $('[data-review-tools]').textContent = state.module_choices.length ? (state.module_choices.length + ' selected') : 'None yet';
            }

            function render() {
                $$('[data-step]').forEach(function (p) { p.hidden = parseInt(p.getAttribute('data-step'), 10) !== step; });
                $$('[data-dot]').forEach(function (d) {
                    var on = parseInt(d.getAttribute('data-dot'), 10) <= step;
                    d.classList.toggle('bg-emerald-500', on);
                    d.classList.toggle('bg-zinc-200', !on);
                });
                $('[data-step-num]').textContent = step;
                $('[data-back]').hidden = step === 1;
                $('[data-next]').hidden = step === TOTAL;
                $('[data-submit]').hidden = step !== TOTAL;
                $('[data-next]').disabled = !canNext();
                if (step === 7) updateReview();
            }

            // Name
            var nameInput = $('[data-name-input]');
            nameInput.addEventListener('input', function () {
                state.workspace_name = nameInput.value;
                syncHidden();
                $('[data-next]').disabled = !canNext();
            });

            // Business type
            $$('[data-pick-type]').forEach(function (el) {
                el.addEventListener('click', function () {
                    state.template_key = el.getAttribute('data-pick-type');
                    state.template_label = el.querySelector('.text-sm') ? el.querySelector('.text-sm').textContent : state.template_key;
                    state.module_choices = (recommended[state.template_key] || []).slice();
                    selectOne($$('[data-pick-type]'), el);
                    refreshTools();
                    syncHidden();
                    $('[data-next]').disabled = !canNext();
                });
            });

            // Team size
            $$('[data-pick-team]').forEach(function (el) {
                el.addEventListener('click', function () {
                    state.team_size = el.getAttribute('data-pick-team');
                    state.team_label = el.textContent.trim();
                    selectOne($$('[data-pick-team]'), el);
                    syncHidden();
                    $('[data-next]').disabled = !canNext();
                });
            });

            // Focus
            $$('[data-pick-focus]').forEach(function (el) {
                el.addEventListener('click', function () {
                    state.hardest_part = el.getAttribute('data-pick-focus');
                    selectOne($$('[data-pick-focus]'), el);
                    syncHidden();
                    $('[data-next]').disabled = !canNext();
                });
            });

            // Tools
            $$('[data-tool]').forEach(function (el) {
                el.addEventListener('click', function () {
                    var k = el.getAttribute('data-tool');
                    var idx = state.module_choices.indexOf(k);
                    if (idx === -1) state.module_choices.push(k); else state.module_choices.splice(idx, 1);
                    refreshTools();
                    syncHidden();
                });
            });

            // Concierge
            $$('[data-pick-help]').forEach(function (el) {
                el.addEventListener('click', function () {
                    state.start_path = el.getAttribute('data-pick-help');
                    $$('[data-pick-help]').forEach(function (n) {
                        n.classList.remove('border-emerald-500', 'bg-emerald-50');
                        n.classList.add('border-zinc-200');
                    });
                    el.classList.remove('border-zinc-200');
                    el.classList.add('border-emerald-500', 'bg-emerald-50');
                    $('[data-help-contact]').hidden = state.start_path !== 'guided';
                    syncHidden();
                });
            });
            var hn = $('[data-help-name]'), he = $('[data-help-email]'), hp = $('[data-help-phone]');
            if (hn) hn.addEventListener('input', function () { hidden.appointment_name.value = hn.value; });
            if (he) he.addEventListener('input', function () { hidden.appointment_email.value = he.value; });
            if (hp) hp.addEventListener('input', function () { hidden.appointment_phone.value = hp.value; });

            $('[data-next]').addEventListener('click', function () { if (canNext() && step < TOTAL) { step++; render(); } });
            $('[data-back]').addEventListener('click', function () { if (step > 1) { step--; render(); } });

            syncHidden();
            render();
        })();
    </script>
</x-layouts::auth.simple>

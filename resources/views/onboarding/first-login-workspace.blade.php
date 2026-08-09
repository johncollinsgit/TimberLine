@php
    $workspaceName = $workspaceName ?? '';
    $businessTypes = is_array($businessTypes ?? null) ? $businessTypes : [];
    $teamSizes = is_array($teamSizes ?? null) ? $teamSizes : [];
    $hardestParts = is_array($hardestParts ?? null) ? $hardestParts : [];
    $toolOptions = is_array($toolOptions ?? null) ? $toolOptions : [];
    $recommendedTools = is_array($recommendedTools ?? null) ? $recommendedTools : [];
    $visibleTools = is_array($visibleTools ?? null) ? $visibleTools : [];
    $steps = ['Name', 'Business', 'Priorities', 'Tools', 'Ready'];
@endphp

<x-layouts::auth.simple :title="__('Create your workspace')" :auth-tenant-presentation="$authTenantPresentation ?? []">
    <style>
        body:has([data-flw-shell]) {
            background: #eff8f5;
        }

        body:has([data-flw-shell]) .fb-auth-shell {
            display: block;
            isolation: isolate;
            max-width: none;
            min-height: 100vh;
            overflow: hidden;
            padding: 0;
            position: relative;
        }

        body:has([data-flw-shell]) .fb-auth-brand-panel {
            display: none;
        }

        body:has([data-flw-shell]) .fb-auth-card-wrap {
            align-items: stretch;
            display: flex;
            justify-content: stretch;
            min-height: 100vh;
            position: relative;
        }

        body:has([data-flw-shell]) .fb-auth-card {
            background: transparent;
            border: 0;
            box-shadow: none;
            max-width: none;
            padding: 0;
            width: 100%;
        }

        body:has([data-flw-shell]) .fb-auth-shell::before,
        body:has([data-flw-shell]) .fb-auth-shell::after,
        body:has([data-flw-shell]) .fb-auth-card-wrap::before {
            border-radius: 999px;
            content: '';
            filter: blur(58px);
            inset: auto;
            opacity: 0.58;
            pointer-events: none;
            position: absolute;
            z-index: -1;
        }

        body:has([data-flw-shell]) .fb-auth-shell::before {
            background: rgba(50, 194, 142, 0.7);
            height: min(55vw, 48rem);
            left: -17rem;
            top: -18rem;
            width: min(55vw, 48rem);
        }

        body:has([data-flw-shell]) .fb-auth-shell::after {
            background: rgba(39, 153, 168, 0.58);
            bottom: -19rem;
            height: min(58vw, 52rem);
            right: -18rem;
            width: min(58vw, 52rem);
        }

        body:has([data-flw-shell]) .fb-auth-card-wrap::before {
            background: rgba(109, 225, 180, 0.54);
            height: min(40vw, 36rem);
            right: 13%;
            top: -17rem;
            width: min(40vw, 36rem);
        }

        [data-flw-shell] {
            align-items: center;
            background:
                radial-gradient(54rem 42rem at 108% -12%, rgba(47, 190, 143, 0.46), transparent 67%),
                radial-gradient(48rem 40rem at -8% 112%, rgba(50, 170, 187, 0.42), transparent 68%),
                radial-gradient(36rem 32rem at 50% 112%, rgba(155, 237, 185, 0.34), transparent 70%),
                linear-gradient(135deg, #f7fffc 0%, #effaf6 48%, #eefaf9 100%);
            background-size: 125% 125%;
            display: flex;
            min-height: 100vh;
            overflow: hidden;
            padding: 2rem;
            position: relative;
        }

        [data-flw-shell]::before,
        [data-flw-shell]::after {
            content: '';
            inset: -18%;
            pointer-events: none;
            position: absolute;
        }

        [data-flw-shell]::before {
            background:
                conic-gradient(from 168deg at 50% 50%, transparent 0deg, rgba(20, 150, 108, 0.48) 54deg, transparent 115deg, rgba(24, 125, 143, 0.44) 182deg, transparent 245deg, rgba(115, 219, 167, 0.5) 307deg, transparent 360deg);
            filter: blur(70px);
            opacity: 0.92;
            z-index: 0;
        }

        [data-flw-shell]::after {
            background-image:
                radial-gradient(circle, rgba(15, 111, 90, 0.38) 0 1px, transparent 1.75px),
                radial-gradient(circle, rgba(24, 126, 151, 0.32) 0 1px, transparent 1.5px);
            background-position: 0 0, 42px 72px;
            background-size: 142px 142px, 191px 191px;
            mask-image: radial-gradient(ellipse 94% 80% at 50% 50%, transparent 36%, #000 100%);
            opacity: 0.68;
            z-index: 0;
        }

        .flw-stage {
            align-items: center;
            display: flex;
            flex-direction: column;
            gap: clamp(1.4rem, 3.5vh, 2.75rem);
            position: relative;
            width: 100%;
            z-index: 1;
        }

        .flw-stage__brand {
            filter: drop-shadow(0 12px 28px rgba(9, 67, 62, 0.16));
            height: auto;
            width: min(30rem, 82vw);
        }

        [data-flw] {
            animation: flw-shell-in 520ms cubic-bezier(0.22, 1, 0.36, 1) both;
            backdrop-filter: blur(16px);
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.96);
            border-radius: 2.25rem;
            box-shadow: 0 34px 110px rgba(10, 67, 62, 0.18), 0 4px 14px rgba(21, 55, 48, 0.06), inset 0 1px 0 rgba(255, 255, 255, 0.9);
            position: relative;
            z-index: 1;
        }

        [data-step]:not([hidden]) {
            animation: flw-step-in 420ms cubic-bezier(0.22, 1, 0.36, 1) both;
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

        @keyframes flw-edge-green {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50% { transform: translate3d(9vw, 7vh, 0) scale(1.12); }
        }

        @keyframes flw-edge-blue {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50% { transform: translate3d(-8vw, -6vh, 0) scale(1.1); }
        }

        @keyframes flw-edge-lilac {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50% { transform: translate3d(4vw, 10vh, 0) scale(1.08); }
        }

        @keyframes flw-aurora {
            0%, 100% { transform: rotate(0deg) scale(1); }
            50% { transform: rotate(24deg) scale(1.12); }
        }

        @keyframes flw-canvas-shift {
            0%, 100% { background-position: 0% 0%; }
            50% { background-position: 100% 100%; }
        }

        @keyframes flw-glints {
            0%, 100% { background-position: 0 0, 42px 72px; opacity: 0.27; }
            50% { background-position: 32px -26px, 10px 98px; opacity: 0.48; }
        }

        @media (prefers-reduced-motion: no-preference) {
            body:has([data-flw-shell]) .fb-auth-shell::before {
                animation: flw-edge-green 22s ease-in-out infinite;
            }

            body:has([data-flw-shell]) .fb-auth-shell::after {
                animation: flw-edge-blue 26s ease-in-out infinite;
            }

            body:has([data-flw-shell]) .fb-auth-card-wrap::before {
                animation: flw-edge-lilac 30s ease-in-out infinite;
            }

            [data-flw-shell]::before {
                animation: flw-aurora 34s ease-in-out infinite;
            }

            [data-flw-shell] {
                animation: flw-canvas-shift 24s ease-in-out infinite;
            }

            [data-flw-shell]::after {
                animation: flw-glints 18s ease-in-out infinite;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            [data-flw],
            [data-step]:not([hidden]),
            [data-flw-shell],
            [data-flw-shell]::before,
            [data-flw-shell]::after {
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

        @media (max-width: 640px) {
            [data-flw-shell] {
                align-items: flex-start;
                padding: 1rem;
            }

            [data-flw] {
                border-radius: 1.5rem;
            }
        }
    </style>

    <div data-flw-shell class="w-full">
        <div class="flw-stage">
            <img src="{{ asset('brand/everbranch-lockup.svg') }}" alt="Everbranch" class="flw-stage__brand" />

            <div
                data-flw
                data-workspace-name="{{ $workspaceName }}"
                data-recommended='@json($recommendedTools)'
                data-visible-tools='@json($visibleTools)'
                class="mx-auto w-full max-w-5xl p-8 sm:p-14 lg:p-16"
            >
            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('workspace.first-login.store') }}">
                @csrf
                <input type="hidden" name="workspace_name" value="{{ $workspaceName }}">
                <input type="hidden" name="template_key" value="">
                <input type="hidden" name="team_size" value="">
                <input type="hidden" name="hardest_part" value="">
                <input type="hidden" name="start_path" value="self">
                <input type="hidden" name="appointment_name" value="">
                <input type="hidden" name="appointment_email" value="">
                <input type="hidden" name="appointment_phone" value="">
                <input type="hidden" name="custom_business_type" value="">
                <input type="hidden" name="business_description" value="">
                <input type="hidden" name="customer_label" value="">
                <input type="hidden" name="work_label" value="">
                <span data-modules-holder></span>

                {{-- Step 1: name --}}
                <section data-step="1" class="space-y-4">
                    <div>
                        <h1 class="text-3xl font-semibold tracking-tight text-zinc-950">Name your workspace</h1>
                        <p class="mt-2 text-sm leading-6 text-zinc-600">This is the name your team will see. You can change it later.</p>
                    </div>
                    <input
                        data-name-input type="text" value="{{ $workspaceName }}" maxlength="120"
                                placeholder="Your business name"
                        class="w-full rounded-xl border border-zinc-300 px-4 py-3 text-base text-zinc-900 shadow-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200"
                    >
                </section>

                {{-- Step 2: business type --}}
                <section data-step="2" class="space-y-4" hidden>
                    <div class="max-w-xl">
                        <h1 class="text-3xl font-semibold tracking-tight text-zinc-950">What kind of work do you do?</h1>
                        <p class="mt-2 text-sm leading-6 text-zinc-600">Choose the closest match. It only changes the tools we suggest.</p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($businessTypes as $type)
                            <button
                                type="button" data-pick-type="{{ $type['key'] }}"
                                class="flw-card {{ ($type['key'] ?? '') === 'custom' ? 'sm:col-span-2' : '' }} rounded-2xl border border-zinc-200 bg-white px-4 py-4 text-left transition hover:border-zinc-400 hover:bg-zinc-50"
                            >
                                <span class="text-sm font-semibold text-zinc-900">{{ $type['label'] }}</span>
                                <span class="mt-1 block text-sm leading-5 text-zinc-500">{{ $type['blurb'] }}</span>
                            </button>
                        @endforeach
                    </div>
                    <div data-custom-context hidden class="border-l-2 border-emerald-500 pl-5">
                        <p class="text-base font-semibold text-zinc-900">Tell us about your business.</p>
                        <p class="mt-1 text-sm leading-6 text-zinc-600">We will start with a simple workspace for contacts and updates. The right tools get added after we have confirmed the fit together.</p>
                        <div class="mt-5 grid gap-4 sm:grid-cols-2">
                            <label class="block text-sm font-medium text-zinc-800">What type of business is it? <span class="text-rose-600">*</span>
                                <input data-custom-business-type type="text" maxlength="120" placeholder="Nonprofit, studio, property manager…" class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-3 text-sm text-zinc-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200">
                            </label>
                            <label class="block text-sm font-medium text-zinc-800">Anything we should know? <span class="font-normal text-zinc-400">Optional</span>
                                <textarea data-business-description rows="2" maxlength="500" placeholder="For example: memberships, inspections, client projects, or classes." class="mt-2 w-full resize-none rounded-xl border border-zinc-300 bg-white px-3.5 py-3 text-sm text-zinc-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200"></textarea>
                            </label>
                        </div>
                    </div>
                </section>

                {{-- Step 3: team size --}}
                <section data-step="3" class="space-y-5" hidden>
                    <div>
                        <h1 class="text-3xl font-semibold tracking-tight text-zinc-950">Who will use this workspace?</h1>
                        <p class="mt-2 text-sm leading-6 text-zinc-600">Choose what fits today. You can invite more people later.</p>
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

                {{-- Step 4: first priority --}}
                <section data-step="4" class="space-y-4" hidden>
                    <div>
                        <h1 class="text-3xl font-semibold tracking-tight text-zinc-950">What would make your day easier?</h1>
                        <p class="mt-2 text-sm leading-6 text-zinc-600">We will put this front and center when you arrive.</p>
                    </div>
                    <div class="grid gap-2.5 sm:grid-cols-2">
                        @foreach ($hardestParts as $key => $opt)
                            <button
                                type="button" data-pick-focus="{{ $key }}"
                                class="flw-focus rounded-xl border border-zinc-200 bg-white p-4 text-left transition hover:border-emerald-300 hover:shadow-sm"
                            >
                                <span class="block text-sm font-semibold text-zinc-900">{{ $opt['label'] }}</span>
                                <span class="mt-1 block text-xs leading-5 text-zinc-500">{{ $opt['description'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </section>

                {{-- Step 5: starting tools --}}
                <section data-step="5" class="space-y-4" hidden>
                    <div>
                        <h1 class="text-3xl font-semibold tracking-tight text-zinc-950">What would you like to manage first?</h1>
                        <p class="mt-2 text-sm leading-6 text-zinc-600">Pick anything useful. You can add more later.</p>
                        <p data-neutral-tools-note class="mt-2 hidden text-sm leading-6 text-emerald-800">Your workspace will start with the essentials. When you need more, you can review the tools that fit your work.</p>
                    </div>
                    <div class="grid max-h-[42vh] gap-2.5 overflow-y-auto pr-1 sm:grid-cols-3">
                        @foreach ($toolOptions as $key => $tool)
                            <button
                                type="button" data-tool="{{ $key }}"
                                class="flw-tool flex items-start gap-3 rounded-xl border border-zinc-200 bg-white p-3.5 text-left transition hover:border-emerald-300 hover:shadow-sm"
                            >
                                <span class="text-lg leading-none">{{ $tool['icon'] ?? '•' }}</span>
                                <span class="min-w-0">
                                    <span class="flex items-center gap-1.5">
                                        <span class="text-sm font-semibold text-zinc-900">{{ $tool['label'] }}</span>
                                        <span data-rec-badge class="hidden rounded-full bg-emerald-100 px-1.5 py-0.5 text-[0.6rem] font-semibold text-emerald-700">Suggested</span>
                                    </span>
                                    <span class="mt-0.5 block text-xs leading-5 text-zinc-500">{{ $tool['description'] }}</span>
                                </span>
                                <span data-tool-check class="ml-auto hidden text-emerald-600">✓</span>
                            </button>
                        @endforeach
                    </div>
                </section>

                {{-- Nav --}}
                <div class="mt-7 flex items-center justify-between gap-3">
                    <button type="button" data-back class="rounded-full px-4 py-2 text-sm font-medium text-zinc-500 hover:text-zinc-800" hidden>Back</button>
                    <span class="flex-1"></span>
                    <button type="button" data-next class="rounded-full bg-zinc-950 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-40">Continue</button>
                    <button type="submit" data-submit class="rounded-full bg-zinc-950 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800" hidden>Open workspace</button>
                </div>
            </form>
            </div>
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
            var visibleTools = {};
            try { visibleTools = JSON.parse(root.getAttribute('data-visible-tools') || '{}'); } catch (e) {}
            var TOTAL = 5;
            var step = 1;
            var state = {
                workspace_name: root.getAttribute('data-workspace-name') || '',
                template_key: '', template_label: '',
                custom_business_type: '', business_description: '', customer_label: '', work_label: '',
                team_size: '', team_label: '',
                hardest_part: '',
                module_choices: [],
                start_path: 'self',
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
                custom_business_type: $('input[name="custom_business_type"]'),
                business_description: $('input[name="business_description"]'),
                customer_label: $('input[name="customer_label"]'),
                work_label: $('input[name="work_label"]'),
            };
            var modulesHolder = $('[data-modules-holder]');

            function syncHidden() {
                hidden.workspace_name.value = state.workspace_name;
                hidden.template_key.value = state.template_key;
                hidden.team_size.value = state.team_size;
                hidden.hardest_part.value = state.hardest_part;
                hidden.start_path.value = state.start_path;
                hidden.custom_business_type.value = state.custom_business_type;
                hidden.business_description.value = state.business_description;
                hidden.customer_label.value = state.customer_label;
                hidden.work_label.value = state.work_label;
                modulesHolder.innerHTML = '';
                state.module_choices.forEach(function (k) {
                    var i = document.createElement('input');
                    i.type = 'hidden'; i.name = 'module_choices[]'; i.value = k;
                    modulesHolder.appendChild(i);
                });
            }

            function canNext() {
                if (step === 1) return state.workspace_name.trim().length > 0;
                if (step === 2) return state.template_key !== '' && (state.template_key !== 'custom' || state.custom_business_type.trim().length > 0);
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
                var allowed = visibleTools[state.template_key] || ['customers', 'messaging', 'reporting'];
                var isNeutralWorkspace = state.template_key === 'generic' || state.template_key === 'custom';
                $$('[data-tool]').forEach(function (el) {
                    var k = el.getAttribute('data-tool');
                    el.hidden = allowed.indexOf(k) === -1;
                    var picked = state.module_choices.indexOf(k) !== -1;
                    el.classList.toggle('border-emerald-500', picked);
                    el.classList.toggle('bg-emerald-50', picked);
                    el.classList.toggle('border-zinc-200', !picked);
                    var check = el.querySelector('[data-tool-check]');
                    if (check) check.classList.toggle('hidden', !picked);
                    var badge = el.querySelector('[data-rec-badge]');
                    if (badge) badge.classList.toggle('hidden', rec.indexOf(k) === -1);
                });
                var neutralNote = $('[data-neutral-tools-note]');
                if (neutralNote) neutralNote.classList.toggle('hidden', !isNeutralWorkspace);
            }

            function render() {
                $$('[data-step]').forEach(function (p) { p.hidden = parseInt(p.getAttribute('data-step'), 10) !== step; });
                $$('[data-dot]').forEach(function (d) {
                    var on = parseInt(d.getAttribute('data-dot'), 10) <= step;
                    d.classList.toggle('bg-emerald-500', on);
                    d.classList.toggle('bg-zinc-200', !on);
                });
                $('[data-back]').hidden = step === 1;
                $('[data-next]').hidden = step === TOTAL;
                $('[data-submit]').hidden = step !== TOTAL;
                $('[data-next]').disabled = !canNext();
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
                    $('[data-custom-context]').hidden = state.template_key !== 'custom';
                    selectOne($$('[data-pick-type]'), el);
                    refreshTools();
                    syncHidden();
                    $('[data-next]').disabled = !canNext();
                });
            });

            [['[data-custom-business-type]', 'custom_business_type'], ['[data-business-description]', 'business_description'], ['[data-customer-label]', 'customer_label'], ['[data-work-label]', 'work_label']].forEach(function (pair) {
                var input = $(pair[0]);
                if (!input) return;
                input.addEventListener('input', function () {
                    state[pair[1]] = input.value;
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

            $('[data-next]').addEventListener('click', function () { if (canNext() && step < TOTAL) { step++; render(); } });
            $('[data-back]').addEventListener('click', function () { if (step > 1) { step--; render(); } });

            syncHidden();
            render();
        })();
    </script>
</x-layouts::auth.simple>

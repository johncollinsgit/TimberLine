<x-layouts::app.sidebar title="Request Custom Module">
    <flux:main>
        <div class="fb-workflow-shell">
            <header class="fb-workflow-header">
                <div class="fb-eyebrow">Custom Modules</div>
                <h1 class="fb-title-xl">Request something custom</h1>
                <p class="fb-subtitle">Tell Everbranch what workflow you need. This is an intake request only; it does not install modules, activate billing, generate quotes, or guarantee a build.</p>
            </header>

            @if ($errors->any())
                <section class="fb-state text-sm">
                    <div class="font-semibold text-zinc-950">Please fix the highlighted fields.</div>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-zinc-600">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section class="fb-panel">
                <div class="fb-panel-head">
                    <div>
                        <div class="fb-panel-title">Request details</div>
                        <div class="fb-panel-copy">Everbranch may use repeatable ideas to inform future reusable modules, but conversion remains manual and separate.</div>
                    </div>
                </div>
                <div class="fb-panel-body">
                    <form method="POST" action="{{ route('custom-module-requests.store', ['tenant' => (string) ($tenant->slug ?? '')]) }}" class="space-y-5">
                        @csrf
                        @if($relatedModuleKey !== null)
                            <input type="hidden" name="related_module_key" value="{{ $relatedModuleKey }}">
                            <div class="fb-state text-sm">Related module: {{ $relatedModuleLabel ?? $relatedModuleKey }}</div>
                        @endif

                        <label class="block text-sm text-zinc-700">
                            Request title
                            <input name="title" value="{{ old('title') }}" required class="fb-input mt-2" placeholder="Example: Field photo checklist for installations">
                        </label>

                        <label class="block text-sm text-zinc-700">
                            What problem are you solving?
                            <textarea name="problem_summary" required rows="4" class="fb-input mt-2" placeholder="Describe the workflow, bottleneck, or customer experience problem.">{{ old('problem_summary') }}</textarea>
                        </label>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <label class="block text-sm text-zinc-700">
                                How do you do it today?
                                <textarea name="current_workaround" rows="3" class="fb-input mt-2">{{ old('current_workaround') }}</textarea>
                            </label>
                            <label class="block text-sm text-zinc-700">
                                Desired outcome
                                <textarea name="desired_outcome" rows="3" class="fb-input mt-2">{{ old('desired_outcome') }}</textarea>
                            </label>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <label class="block text-sm text-zinc-700">
                                Tools or data involved
                                <input name="tools_involved" value="{{ old('tools_involved') }}" class="fb-input mt-2" placeholder="Shopify, Square, spreadsheets, field photos, email, etc.">
                            </label>
                            <label class="block text-sm text-zinc-700">
                                Who uses it?
                                <input name="users_impacted" value="{{ old('users_impacted') }}" class="fb-input mt-2" placeholder="Owner, managers, field team, customers">
                            </label>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-3">
                            <label class="block text-sm text-zinc-700">
                                Frequency
                                <select name="frequency" class="fb-input mt-2">
                                    @foreach($frequencyOptions as $key => $label)
                                        <option value="{{ $key }}" @selected(old('frequency', 'undecided') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-sm text-zinc-700">
                                Urgency
                                <select name="urgency" class="fb-input mt-2">
                                    @foreach($urgencyOptions as $key => $label)
                                        <option value="{{ $key }}" @selected(old('urgency', 'undecided') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-sm text-zinc-700">
                                Budget range
                                <select name="budget_range" class="fb-input mt-2">
                                    @foreach($budgetRangeOptions as $key => $label)
                                        <option value="{{ $key }}" @selected(old('budget_range', 'not_sure') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <label class="block text-sm text-zinc-700">
                                Mobile relevance
                                <select name="mobile_relevance" class="fb-input mt-2">
                                    @foreach($mobileRelevanceOptions as $key => $label)
                                        <option value="{{ $key }}" @selected(old('mobile_relevance', 'undecided') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <span class="mt-1 block text-xs text-zinc-500">Mobile relevance is planning context only. It does not create a mobile module or generic mobile API.</span>
                            </label>
                            <label class="flex items-start gap-3 rounded-xl border border-zinc-200 bg-white p-4 text-sm text-zinc-700">
                                <input type="checkbox" name="reusable_module_interest" value="1" @checked(old('reusable_module_interest'))>
                                <span>
                                    <span class="block font-semibold text-zinc-950">This could become reusable</span>
                                    <span class="mt-1 block text-xs text-zinc-500">This only tells Everbranch the idea may fit other businesses later.</span>
                                </span>
                            </label>
                        </div>

                        <div class="fb-state text-sm">
                            Submitting does not install a module, change feature access, activate billing, generate quotes or invoices, or guarantee approval. Everbranch will review and follow up if discovery is needed.
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="fb-btn fb-btn-primary">Submit request</button>
                            <a href="{{ route('custom-module-requests.index', ['tenant' => (string) ($tenant->slug ?? '')]) }}" class="fb-btn fb-btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </flux:main>
</x-layouts::app.sidebar>

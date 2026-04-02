<x-layouts::app :title="'Add Customer'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Add Customer"
            description="Create or link a canonical customer profile through a guided, duplicate-aware flow."
            hint-title="Wizard behavior"
            hint-text="This creates/updates canonical marketing profiles first. External provider fields are treated as enrichment, not source-of-truth."
        />

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <div class="grid gap-2 sm:grid-cols-5">
                @foreach([
                    1 => 'Identity',
                    2 => 'Context',
                    3 => 'Duplicate Check',
                    4 => 'Details',
                    5 => 'Review',
                ] as $stepNumber => $label)
                    <div class="rounded-xl border px-3 py-2 text-xs font-semibold {{ $step >= $stepNumber ? 'border-zinc-300 bg-emerald-100 text-emerald-900' : 'border-zinc-200 bg-zinc-50 text-zinc-600' }}">
                        Step {{ $stepNumber }} · {{ $label }}
                    </div>
                @endforeach
            </div>

            <form method="POST" action="{{ route('marketing.customers.store-create') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="step" value="{{ $step }}" />

                @if($step === 1)
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">First Name</label>
                            <input type="text" name="first_name" value="{{ old('first_name', $wizardState['identity']['first_name'] ?? '') }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                        </div>
                        <div>
                            <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Last Name</label>
                            <input type="text" name="last_name" value="{{ old('last_name', $wizardState['identity']['last_name'] ?? '') }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                        </div>
                        <div>
                            <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Email</label>
                            <input type="email" name="email" value="{{ old('email', $wizardState['identity']['email'] ?? '') }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                        </div>
                        <div>
                            <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Phone</label>
                            <input type="text" name="phone" value="{{ old('phone', $wizardState['identity']['phone'] ?? '') }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                        </div>
                    </div>
                    <div class="text-xs text-zinc-500">At least one identifier (email or phone) is required for duplicate detection.</div>
                @endif

                @if($step === 2)
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Customer Context</label>
                            <select name="customer_context" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                                <option value="retail" @selected(($wizardState['context']['customer_context'] ?? 'general') === 'retail')>Retail</option>
                                <option value="wholesale" @selected(($wizardState['context']['customer_context'] ?? 'general') === 'wholesale')>Wholesale</option>
                                <option value="event_manual" @selected(($wizardState['context']['customer_context'] ?? 'general') === 'event_manual')>Event / Manual</option>
                                <option value="general" @selected(($wizardState['context']['customer_context'] ?? 'general') === 'general')>Unknown / General</option>
                            </select>
                        </div>
                    </div>
                @endif

                @if($step === 3)
                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                        <div class="text-sm font-semibold text-zinc-950">Potential Duplicate Matches</div>
                        @if($duplicateCandidates->isEmpty())
                            <div class="mt-2 text-sm text-zinc-600">No likely matches found using exact email/phone and name similarity checks.</div>
                        @else
                            <div class="mt-3 overflow-x-auto rounded-xl border border-zinc-200">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-zinc-50 text-zinc-600">
                                        <tr>
                                            <th class="px-3 py-2 text-left">Use</th>
                                            <th class="px-3 py-2 text-left">Customer</th>
                                            <th class="px-3 py-2 text-left">Email</th>
                                            <th class="px-3 py-2 text-left">Phone</th>
                                            <th class="px-3 py-2 text-left">Match Reasons</th>
                                            <th class="px-3 py-2 text-left">Linked Sources</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-200">
                                        @foreach($duplicateCandidates as $candidate)
                                            @php($candidateProfile = $candidate['profile'])
                                            <tr>
                                                <td class="px-3 py-2">
                                                    <input
                                                        type="radio"
                                                        name="selected_profile_id"
                                                        value="{{ $candidateProfile->id }}"
                                                        @checked((int) old('selected_profile_id', $wizardState['duplicate']['selected_profile_id'] ?? 0) === (int) $candidateProfile->id)
                                                    />
                                                </td>
                                                <td class="px-3 py-2 text-zinc-700">
                                                    {{ trim(($candidateProfile->first_name ?? '') . ' ' . ($candidateProfile->last_name ?? '')) ?: ('Profile #' . $candidateProfile->id) }}
                                                </td>
                                                <td class="px-3 py-2 text-zinc-700">{{ $candidateProfile->email ?: '—' }}</td>
                                                <td class="px-3 py-2 text-zinc-700">{{ $candidateProfile->phone ?: '—' }}</td>
                                                <td class="px-3 py-2 text-zinc-600">{{ implode(', ', $candidate['reasons']) }}</td>
                                                <td class="px-3 py-2 text-zinc-600">{{ (int) $candidateProfile->links_count }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <label class="inline-flex items-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-700">
                            <input type="radio" name="decision" value="use_existing" @checked(old('decision', $wizardState['duplicate']['decision'] ?? 'continue') === 'use_existing') />
                            Use selected existing customer
                        </label>
                        <label class="inline-flex items-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-700">
                            <input type="radio" name="decision" value="continue" @checked(old('decision', $wizardState['duplicate']['decision'] ?? 'continue') === 'continue') />
                            Continue and create new profile
                        </label>
                    </div>
                @endif

                @if($step === 4)
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        <div class="xl:col-span-2">
                            <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Internal Notes</label>
                            <textarea name="notes" rows="3" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">{{ old('notes', $wizardState['additional']['notes'] ?? '') }}</textarea>
                        </div>
                        <div>
                            <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Company / Store Name</label>
                            <input type="text" name="company_store_name" value="{{ old('company_store_name', $wizardState['additional']['company_store_name'] ?? '') }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                        </div>
                        <div class="xl:col-span-2">
                            <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Tags (comma-separated)</label>
                            <input type="text" name="tags" value="{{ old('tags', $wizardState['additional']['tags'] ?? '') }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                        </div>
                        <div class="xl:col-span-3 flex flex-wrap gap-3 text-sm text-zinc-700">
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="accepts_email_marketing" value="1" @checked((bool) old('accepts_email_marketing', $wizardState['additional']['accepts_email_marketing'] ?? false)) />
                                Email eligible
                            </label>
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="accepts_sms_marketing" value="1" @checked((bool) old('accepts_sms_marketing', $wizardState['additional']['accepts_sms_marketing'] ?? false)) />
                                SMS eligible
                            </label>
                        </div>
                    </div>
                @endif

                @if($step === 5)
                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 space-y-3 text-sm text-zinc-700">
                        <div class="text-sm font-semibold text-zinc-950">Review</div>
                        <div>
                            <span class="text-zinc-500">Identity:</span>
                            {{ trim((string) (($wizardState['identity']['first_name'] ?? '') . ' ' . ($wizardState['identity']['last_name'] ?? ''))) ?: '—' }}
                            · {{ $wizardState['identity']['email'] ?? 'no-email' }}
                            · {{ $wizardState['identity']['phone'] ?? 'no-phone' }}
                        </div>
                        <div>
                            <span class="text-zinc-500">Context:</span>
                            {{ $wizardState['context']['customer_context'] ?? 'general' }}
                        </div>
                        <div>
                            <span class="text-zinc-500">Duplicate decision:</span>
                            @if(($wizardState['duplicate']['decision'] ?? 'continue') === 'use_existing')
                                Use existing profile #{{ (int) ($wizardState['duplicate']['selected_profile_id'] ?? 0) }}
                            @else
                                Create new profile
                            @endif
                        </div>
                        <div>
                            <span class="text-zinc-500">Notes/Tags:</span>
                            {{ $wizardState['additional']['notes'] ?? '—' }}
                            @if(!empty($wizardState['additional']['tags']))
                                · tags: {{ $wizardState['additional']['tags'] }}
                            @endif
                        </div>
                        <label class="inline-flex items-center gap-2 text-sm text-zinc-800">
                            <input type="checkbox" name="confirm_create" value="1" required />
                            Confirm and save to canonical customer profile layer
                        </label>
                    </div>
                @endif

                @if($errors->any())
                    <div class="rounded-2xl border border-rose-300/35 bg-rose-100 p-3 text-sm text-rose-900">
                        {{ $errors->first() }}
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-2 pt-2">
                    @if($step > 1)
                        <button type="submit" name="direction" value="back" class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-100">
                            Back
                        </button>
                    @endif

                    <button type="submit" name="direction" value="next" class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-4 py-2 text-sm font-semibold text-zinc-950">
                        {{ $step === 5 ? 'Create Customer' : 'Continue' }}
                    </button>

                    <a href="{{ route('marketing.customers.create', ['step' => 1, 'reset' => 1]) }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100">
                        Reset
                    </a>
                    <a href="{{ route('marketing.customers') }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100">
                        Cancel
                    </a>
                </div>
            </form>
        </section>
    </div>
</x-layouts::app>

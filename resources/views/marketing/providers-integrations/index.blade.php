@php
    $tenantId = request()?->attributes->get('current_tenant_id');
    $resolvedTenantId = is_numeric($tenantId) ? (int) $tenantId : null;
    $resolvedLabels = app(\App\Services\Tenancy\TenantDisplayLabelResolver::class)->resolve($resolvedTenantId);
    $displayLabels = is_array($resolvedLabels['labels'] ?? null) ? (array) $resolvedLabels['labels'] : [];
    $rewardsLabel = trim((string) ($displayLabels['rewards_label'] ?? $displayLabels['rewards'] ?? 'Rewards'));
    if ($rewardsLabel === '') {
        $rewardsLabel = 'Rewards';
    }
@endphp

<x-layouts::app :title="'Connections'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Connections"
            description="Native workflow automations, Square sync, legacy importer workflows, and event source mapping administration for marketing attribution."
            hint-title="How this page works"
            hint-text="All imports and syncs are additive. Identity merges still follow exact email/phone rules, and ambiguous matches are routed to Identity Review."
        />

        <div class="flex justify-end">
            <a href="{{ route('marketing.providers-integrations.shopify-customer-sync-health') }}" wire:navigate class="inline-flex rounded-full border border-sky-300/35 bg-sky-100 px-4 py-2 text-sm font-semibold text-sky-900">
                Shopify Customer Sync Health
            </a>
        </div>

        @if(is_array($workflowAutomationSetup ?? null))
            @php
                $workflowSetup = $workflowAutomationSetup;
                $workflowModule = is_array($workflowAutomationModule ?? null) ? $workflowAutomationModule : [];
                $workflowRunResult = is_array($workflowAutomationRunResult ?? null) ? $workflowAutomationRunResult : [];
                $workflowState = is_array($workflowSetup['state'] ?? null) ? $workflowSetup['state'] : [];
                $workflowCounts = is_array($workflowState['counts'] ?? null) ? $workflowState['counts'] : [];
                $workflowDryRunCounts = is_array($workflowState['dry_run_counts'] ?? null) ? $workflowState['dry_run_counts'] : [];
                $asanaCredential = is_array(data_get($workflowSetup, 'credentials.asana_personal_access_token')) ? data_get($workflowSetup, 'credentials.asana_personal_access_token') : [];
                $asanaOauthClientIdCredential = is_array(data_get($workflowSetup, 'credentials.asana_oauth_client_id')) ? data_get($workflowSetup, 'credentials.asana_oauth_client_id') : [];
                $asanaOauthClientSecretCredential = is_array(data_get($workflowSetup, 'credentials.asana_oauth_client_secret')) ? data_get($workflowSetup, 'credentials.asana_oauth_client_secret') : [];
                $asanaOauthRefreshTokenCredential = is_array(data_get($workflowSetup, 'credentials.asana_oauth_refresh_token')) ? data_get($workflowSetup, 'credentials.asana_oauth_refresh_token') : [];
                $googleClientIdCredential = is_array(data_get($workflowSetup, 'credentials.google_calendar_client_id')) ? data_get($workflowSetup, 'credentials.google_calendar_client_id') : [];
                $googleClientSecretCredential = is_array(data_get($workflowSetup, 'credentials.google_calendar_client_secret')) ? data_get($workflowSetup, 'credentials.google_calendar_client_secret') : [];
                $googleRefreshTokenCredential = is_array(data_get($workflowSetup, 'credentials.google_calendar_refresh_token')) ? data_get($workflowSetup, 'credentials.google_calendar_refresh_token') : [];
                $asanaConnection = is_array($asanaWorkflowConnection ?? null) ? $asanaWorkflowConnection : [];
                $asanaProjectOptions = is_array($asanaConnection['projects'] ?? null) ? $asanaConnection['projects'] : [];
                $asanaOauthConnected = (bool) ($asanaConnection['oauth_connected'] ?? false);
                $asanaOauthReady = (bool) ($asanaConnection['oauth_ready'] ?? false);
                $asanaTokenReady = (bool) ($asanaConnection['token_ready'] ?? false);
                $googleCalendarConnection = is_array($googleCalendarWorkflowConnection ?? null) ? $googleCalendarWorkflowConnection : [];
                $googleCalendarOptions = is_array($googleCalendarConnection['calendars'] ?? null) ? $googleCalendarConnection['calendars'] : [];
                $googleCalendarConnected = (bool) ($googleCalendarConnection['connected'] ?? false);
                $googleCalendarOauthReady = (bool) ($googleCalendarConnection['oauth_ready'] ?? false);
                $moduleEnabled = (bool) ($workflowModule['enabled'] ?? false);
                $workflowEnabled = (bool) ($workflowSetup['enabled'] ?? false);
                $lastFinishedAt = filled($workflowState['last_finished_at'] ?? null)
                    ? \Illuminate\Support\Carbon::parse((string) $workflowState['last_finished_at'])->format('M j, Y g:i A')
                    : 'Never';
                $lastStartedAt = filled($workflowState['last_started_at'] ?? null)
                    ? \Illuminate\Support\Carbon::parse((string) $workflowState['last_started_at'])->format('M j, Y g:i A')
                    : 'Not running';
                $workflowLastStatus = trim((string) ($workflowState['last_status'] ?? ''));
                if ($workflowLastStatus === '') {
                    $workflowLastStatus = trim((string) ($workflowState['status'] ?? 'idle'));
                }
                $selectedProjectGid = (string) old('project_gid', (string) data_get($workflowSetup, 'trigger.project_gid', ''));
                $selectedCalendarId = (string) old('calendar_id', (string) data_get($workflowSetup, 'action.calendar_id', ''));
            @endphp

            <section class="rounded-[2rem] border border-emerald-300/40 bg-[linear-gradient(135deg,rgba(236,253,245,0.95),rgba(240,249,255,0.96))] p-5 sm:p-6 space-y-5 shadow-[0_20px_60px_-45px_rgba(15,118,110,0.55)]">
                <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                    <div class="max-w-3xl space-y-3">
                        <div class="inline-flex w-fit rounded-full border border-emerald-300/40 bg-white/80 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.24em] text-emerald-900">
                            Native Zap Replacement
                        </div>
                        <div>
                            <h2 class="text-xl font-semibold text-zinc-950">{{ $workflowSetup['title'] ?? 'Workflow Automations' }}</h2>
                            <p class="mt-2 text-sm text-zinc-700">{{ $workflowSetup['description'] ?? 'Configure tenant-native workflow automations.' }}</p>
                        </div>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-1">
                        <div class="rounded-2xl border px-4 py-3 text-sm {{ $workflowEnabled ? 'border-emerald-300/40 bg-emerald-100 text-emerald-950' : 'border-amber-300/40 bg-amber-100 text-amber-950' }}">
                            <div class="text-[11px] uppercase tracking-[0.22em] opacity-75">Scheduler</div>
                            <div class="mt-1 font-semibold">{{ $workflowEnabled ? 'Enabled for scheduled runs' : 'Saved but disabled' }}</div>
                        </div>
                        <div class="rounded-2xl border px-4 py-3 text-sm {{ $moduleEnabled ? 'border-sky-300/40 bg-sky-100 text-sky-950' : 'border-rose-300/40 bg-rose-100 text-rose-950' }}">
                            <div class="text-[11px] uppercase tracking-[0.22em] opacity-75">Module Access</div>
                            <div class="mt-1 font-semibold">{{ $moduleEnabled ? 'Workflow module active' : 'Workflow module not enabled' }}</div>
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-2xl border border-white/70 bg-white/80 p-4">
                        <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Last Run Status</div>
                        <div class="mt-2 text-lg font-semibold text-zinc-950">{{ \Illuminate\Support\Str::of($workflowLastStatus)->replace('_', ' ')->headline() }}</div>
                        <div class="mt-1 text-xs text-zinc-500">Finished {{ $lastFinishedAt }}</div>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white/80 p-4">
                        <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Tracked Links</div>
                        <div class="mt-2 text-lg font-semibold text-zinc-950">{{ number_format((int) ($workflowSetup['link_count'] ?? 0)) }}</div>
                        <div class="mt-1 text-xs text-zinc-500">Stored Asana task to calendar event relationships.</div>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white/80 p-4">
                        <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Last Started</div>
                        <div class="mt-2 text-lg font-semibold text-zinc-950">{{ $lastStartedAt }}</div>
                        <div class="mt-1 text-xs text-zinc-500">Workflow instance: {{ $workflowSetup['instance_key'] ?? 'n/a' }}</div>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white/80 p-4">
                        <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Recent Counts</div>
                        <div class="mt-2 text-lg font-semibold text-zinc-950">
                            {{ number_format((int) ($workflowCounts['created'] ?? 0)) }} created /
                            {{ number_format((int) ($workflowCounts['updated'] ?? 0)) }} updated
                        </div>
                        <div class="mt-1 text-xs text-zinc-500">
                            Dry run forecast: {{ number_format((int) ($workflowDryRunCounts['would_create'] ?? 0)) }} create, {{ number_format((int) ($workflowDryRunCounts['would_update'] ?? 0)) }} update.
                        </div>
                    </div>
                </div>

                @if($workflowRunResult !== [])
                    @php
                        $runCounts = is_array($workflowRunResult['counts'] ?? null) ? $workflowRunResult['counts'] : [];
                        $runDryRunCounts = is_array($workflowRunResult['dry_run_counts'] ?? null) ? $workflowRunResult['dry_run_counts'] : [];
                    @endphp
                    <div class="rounded-2xl border border-zinc-200/70 bg-white/80 p-4">
                        <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-zinc-950">Most Recent Button Run</h3>
                                <p class="mt-1 text-sm text-zinc-600">Status: {{ \Illuminate\Support\Str::of((string) ($workflowRunResult['status'] ?? 'unknown'))->replace('_', ' ')->headline() }}</p>
                            </div>
                            <div class="text-xs text-zinc-500">
                                fetched {{ (int) ($runCounts['fetched'] ?? 0) }},
                                processed {{ (int) ($runCounts['processed'] ?? 0) }},
                                created {{ (int) ($runCounts['created'] ?? 0) }},
                                updated {{ (int) ($runCounts['updated'] ?? 0) }},
                                would create {{ (int) ($runDryRunCounts['would_create'] ?? 0) }},
                                would update {{ (int) ($runDryRunCounts['would_update'] ?? 0) }}
                            </div>
                        </div>
                        @if(filled($workflowRunResult['message'] ?? null))
                            <div class="mt-3 text-sm text-zinc-600">{{ $workflowRunResult['message'] }}</div>
                        @endif
                    </div>
                @endif

                <x-admin.help-hint title="How this setup works">
                    Save Setup stores tenant-specific workflow settings. Connect Asana and Google to turn raw IDs into pickers. Dry Run fetches matching Asana tasks without writing Google events. Run Live writes the events immediately. Leaving credential fields blank keeps the current saved value.
                </x-admin.help-hint>

                @if(! $moduleEnabled)
                    <div class="rounded-2xl border border-rose-300/35 bg-rose-100 px-4 py-3 text-sm text-rose-900">
                        This tenant does not currently have the `workflow_automations` module enabled, so scheduled or manual runs will fail until module access is active.
                    </div>
                @endif

                @if(filled($workflowState['last_error'] ?? null))
                    <div class="rounded-2xl border border-amber-300/35 bg-amber-100 px-4 py-3 text-sm text-amber-900">
                        Last workflow error: {{ $workflowState['last_error'] }}
                    </div>
                @endif

                <form method="POST" action="{{ route('marketing.providers-integrations.workflow-automations.save') }}" class="space-y-6">
                    @csrf
                    <input type="hidden" name="workflow_key" value="{{ $workflowSetup['workflow_key'] ?? 'asana_to_google_calendar' }}" />

                    <div class="grid gap-4 xl:grid-cols-[minmax(0,1.45fr),minmax(0,1fr)]">
                        <div class="space-y-4">
                            <div class="rounded-2xl border border-zinc-200/70 bg-white/80 p-4 space-y-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-base font-semibold text-zinc-950">Workflow Settings</h3>
                                        <p class="mt-1 text-sm text-zinc-600">Set the Asana source, Google Calendar target, and default event timing for this tenant.</p>
                                    </div>
                                    <label class="inline-flex items-center gap-2 rounded-full border border-emerald-300/40 bg-emerald-100 px-3 py-2 text-sm font-semibold text-emerald-950">
                                        <input type="checkbox" name="enabled" value="1" class="rounded border-emerald-400" @checked((bool) old('enabled', $workflowEnabled)) />
                                        Enable automation
                                    </label>
                                </div>

                                <div class="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Asana Project GID</label>
                                        @if($asanaProjectOptions !== [])
                                            <select name="project_gid" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950">
                                                <option value="">Choose an Asana project</option>
                                                @foreach($asanaProjectOptions as $project)
                                                    @php
                                                        $projectGid = (string) ($project['gid'] ?? '');
                                                        $projectName = (string) ($project['name'] ?? $projectGid);
                                                        $workspaceName = trim((string) ($project['workspace_name'] ?? ''));
                                                        $teamName = trim((string) ($project['team_name'] ?? ''));
                                                    @endphp
                                                    <option value="{{ $projectGid }}" @selected($selectedProjectGid === $projectGid)>
                                                        {{ $projectName }}{{ $workspaceName !== '' ? ' - ' . $workspaceName : '' }}{{ $teamName !== '' ? ' - ' . $teamName : '' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <div class="mt-1 text-[11px] text-zinc-500">Pick from projects visible to the connected Asana account.</div>
                                        @else
                                            <input type="text" name="project_gid" value="{{ $selectedProjectGid }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950" placeholder="1201541082238924" />
                                            <div class="mt-1 text-[11px] text-zinc-500">The Asana project this workflow watches for updated tasks. Connect Asana below to turn this into a picker.</div>
                                        @endif
                                    </div>
                                    <div>
                                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Google Calendar ID</label>
                                        @if($googleCalendarOptions !== [])
                                            <select name="calendar_id" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950">
                                                <option value="">Choose a writable Google calendar</option>
                                                @foreach($googleCalendarOptions as $calendar)
                                                    @php
                                                        $calendarId = (string) ($calendar['id'] ?? '');
                                                        $calendarSummary = (string) ($calendar['summary'] ?? $calendarId);
                                                        $calendarAccessRole = (string) ($calendar['access_role'] ?? 'writer');
                                                        $calendarTimeZone = trim((string) ($calendar['time_zone'] ?? ''));
                                                    @endphp
                                                    <option value="{{ $calendarId }}" @selected($selectedCalendarId === $calendarId)>
                                                        {{ $calendarSummary }}{{ !empty($calendar['primary']) ? ' (Primary)' : '' }}{{ $calendarTimeZone !== '' ? ' - ' . $calendarTimeZone : '' }}{{ $calendarAccessRole !== '' ? ' - ' . \Illuminate\Support\Str::of($calendarAccessRole)->replace('_', ' ')->headline() : '' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <div class="mt-1 text-[11px] text-zinc-500">Pick from writable calendars discovered through Google OAuth.</div>
                                        @else
                                            <input type="text" name="calendar_id" value="{{ $selectedCalendarId }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950" placeholder="calendar@group.calendar.google.com" />
                                            <div class="mt-1 text-[11px] text-zinc-500">The destination calendar that receives created or updated events. Connect Google below to turn this into a picker.</div>
                                        @endif
                                    </div>
                                    <div>
                                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Timezone</label>
                                        <input type="text" name="timezone" value="{{ old('timezone', (string) data_get($workflowSetup, 'action.timezone', 'America/New_York')) }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950" placeholder="America/New_York" />
                                    </div>
                                    <div>
                                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Default Start Time</label>
                                        <input type="time" name="default_start_time" value="{{ old('default_start_time', (string) data_get($workflowSetup, 'action.default_start_time', '12:00')) }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950" />
                                    </div>
                                    <div>
                                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Default Duration (Minutes)</label>
                                        <input type="number" name="default_duration_minutes" min="1" max="1440" value="{{ old('default_duration_minutes', (int) data_get($workflowSetup, 'action.default_duration_minutes', 60)) }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950" />
                                    </div>
                                    <label class="flex items-center gap-2 rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-700">
                                        <input type="checkbox" name="skip_completed_tasks" value="1" class="rounded border-zinc-300 bg-white" @checked((bool) old('skip_completed_tasks', (bool) data_get($workflowSetup, 'action.skip_completed_tasks', true))) />
                                        Skip completed Asana tasks
                                    </label>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-zinc-200/70 bg-white/80 p-4 space-y-4">
                                <div>
                                    <h3 class="text-base font-semibold text-zinc-950">Polling Defaults</h3>
                                    <p class="mt-1 text-sm text-zinc-600">These control how aggressively Everbranch scans Asana for changed tasks.</p>
                                </div>
                                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                    <div>
                                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Modified Overlap</label>
                                        <input type="number" name="modified_overlap_minutes" min="0" max="1440" value="{{ old('modified_overlap_minutes', (int) data_get($workflowSetup, 'trigger.modified_overlap_minutes', 5)) }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950" />
                                        <div class="mt-1 text-[11px] text-zinc-500">Minutes of overlap between polls to avoid missed edits.</div>
                                    </div>
                                    <div>
                                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Bootstrap Lookback</label>
                                        <input type="number" name="bootstrap_lookback_days" min="1" max="365" value="{{ old('bootstrap_lookback_days', (int) data_get($workflowSetup, 'trigger.bootstrap_lookback_days', 14)) }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950" />
                                        <div class="mt-1 text-[11px] text-zinc-500">Days to scan on the first run before a cursor exists.</div>
                                    </div>
                                    <div>
                                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Poll Limit</label>
                                        <input type="number" name="poll_limit" min="1" max="100" value="{{ old('poll_limit', (int) data_get($workflowSetup, 'trigger.poll_limit', 100)) }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950" />
                                        <div class="mt-1 text-[11px] text-zinc-500">Tasks requested per Asana page.</div>
                                    </div>
                                    <div>
                                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Max Tasks Per Run</label>
                                        <input type="number" name="max_tasks_per_run" min="1" max="5000" value="{{ old('max_tasks_per_run', (int) data_get($workflowSetup, 'trigger.max_tasks_per_run', 500)) }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950" />
                                        <div class="mt-1 text-[11px] text-zinc-500">Safety cap for a single execution.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div class="rounded-2xl border border-zinc-200/70 bg-white/80 p-4 space-y-4">
                                <div>
                                    <h3 class="text-base font-semibold text-zinc-950">Credentials</h3>
                                    <p class="mt-1 text-sm text-zinc-600">Credentials are encrypted at rest. Leave fields blank to keep the current saved value. Asana and Google can both be connected with OAuth once client credentials are available.</p>
                                </div>

                                <div>
                                    <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Asana Personal Access Token</label>
                                    <input type="password" name="asana_personal_access_token" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950" autocomplete="new-password" />
                                    <div class="mt-1 text-[11px] text-zinc-500">
                                        {{ $asanaCredential['source_label'] ?? 'Not configured yet' }}
                                        @if(filled($asanaCredential['masked_value'] ?? null))
                                            - {{ $asanaCredential['masked_value'] }}
                                        @endif
                                    </div>
                                    <label class="mt-2 flex items-center gap-2 text-xs text-zinc-600">
                                        <input type="checkbox" name="clear_asana_personal_access_token" value="1" class="rounded border-zinc-300 bg-white" />
                                        Clear saved token
                                    </label>
                                    <div class="mt-2 text-[11px] text-zinc-500">PAT remains a fallback, but OAuth is the smoother option because it can load projects directly into a picker.</div>
                                </div>

                                <div>
                                    <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Asana OAuth Client ID</label>
                                    <input type="password" name="asana_oauth_client_id" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950" autocomplete="new-password" />
                                    <div class="mt-1 text-[11px] text-zinc-500">
                                        {{ $asanaOauthClientIdCredential['source_label'] ?? 'Not configured yet' }}
                                        @if(filled($asanaOauthClientIdCredential['masked_value'] ?? null))
                                            - {{ $asanaOauthClientIdCredential['masked_value'] }}
                                        @endif
                                    </div>
                                    <label class="mt-2 flex items-center gap-2 text-xs text-zinc-600">
                                        <input type="checkbox" name="clear_asana_oauth_client_id" value="1" class="rounded border-zinc-300 bg-white" />
                                        Clear saved client ID
                                    </label>
                                </div>

                                <div>
                                    <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Asana OAuth Client Secret</label>
                                    <input type="password" name="asana_oauth_client_secret" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950" autocomplete="new-password" />
                                    <div class="mt-1 text-[11px] text-zinc-500">
                                        {{ $asanaOauthClientSecretCredential['source_label'] ?? 'Not configured yet' }}
                                        @if(filled($asanaOauthClientSecretCredential['masked_value'] ?? null))
                                            - {{ $asanaOauthClientSecretCredential['masked_value'] }}
                                        @endif
                                    </div>
                                    <label class="mt-2 flex items-center gap-2 text-xs text-zinc-600">
                                        <input type="checkbox" name="clear_asana_oauth_client_secret" value="1" class="rounded border-zinc-300 bg-white" />
                                        Clear saved client secret
                                    </label>
                                </div>

                                <div>
                                    <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Asana OAuth Refresh Token</label>
                                    <input type="password" name="asana_oauth_refresh_token" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950" autocomplete="new-password" />
                                    <div class="mt-1 text-[11px] text-zinc-500">
                                        {{ $asanaOauthRefreshTokenCredential['source_label'] ?? 'Not configured yet' }}
                                        @if(filled($asanaOauthRefreshTokenCredential['masked_value'] ?? null))
                                            - {{ $asanaOauthRefreshTokenCredential['masked_value'] }}
                                        @endif
                                    </div>
                                    <label class="mt-2 flex items-center gap-2 text-xs text-zinc-600">
                                        <input type="checkbox" name="clear_asana_oauth_refresh_token" value="1" class="rounded border-zinc-300 bg-white" />
                                        Clear saved refresh token
                                    </label>
                                </div>

                                <div class="rounded-2xl border {{ $asanaOauthConnected ? 'border-emerald-300/35 bg-emerald-100/60' : ($asanaOauthReady ? 'border-sky-300/35 bg-sky-100/60' : ($asanaTokenReady ? 'border-zinc-300/60 bg-zinc-100/80' : 'border-amber-300/35 bg-amber-100/60')) }} px-4 py-3 text-sm">
                                    <div class="font-semibold text-zinc-950">
                                        @if($asanaOauthConnected)
                                            Connected via Asana OAuth
                                        @elseif($asanaOauthReady)
                                            Ready to connect Asana
                                        @elseif($asanaTokenReady)
                                            Using Asana personal access token
                                        @else
                                            Asana needs OAuth credentials or a PAT
                                        @endif
                                    </div>
                                    <div class="mt-1 text-zinc-700">
                                        @if($asanaProjectOptions !== [])
                                            {{ count($asanaProjectOptions) }} project(s) loaded.
                                            @if(filled($asanaConnection['selected_project_name'] ?? null))
                                                Current selection: {{ $asanaConnection['selected_project_name'] }}{{ filled($asanaConnection['selected_workspace_name'] ?? null) ? ' in ' . $asanaConnection['selected_workspace_name'] : '' }}.
                                            @endif
                                        @elseif($asanaOauthReady)
                                            Save your client settings, then connect Asana to pull in project choices automatically.
                                        @elseif($asanaTokenReady)
                                            A token is available, but no project list is cached yet. Refresh projects to load them.
                                        @else
                                            Add an Asana OAuth client ID and secret first, or rely on a personal access token fallback.
                                        @endif
                                    </div>
                                    @if(filled($asanaConnection['error'] ?? null))
                                        <div class="mt-2 text-xs text-amber-900">Project load issue: {{ $asanaConnection['error'] }}</div>
                                    @endif
                                </div>

                                <div>
                                    <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Google Calendar Client ID</label>
                                    <input type="password" name="google_calendar_client_id" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950" autocomplete="new-password" />
                                    <div class="mt-1 text-[11px] text-zinc-500">
                                        {{ $googleClientIdCredential['source_label'] ?? 'Not configured yet' }}
                                        @if(filled($googleClientIdCredential['masked_value'] ?? null))
                                            - {{ $googleClientIdCredential['masked_value'] }}
                                        @endif
                                    </div>
                                    <label class="mt-2 flex items-center gap-2 text-xs text-zinc-600">
                                        <input type="checkbox" name="clear_google_calendar_client_id" value="1" class="rounded border-zinc-300 bg-white" />
                                        Clear saved client ID
                                    </label>
                                </div>

                                <div>
                                    <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Google Calendar Client Secret</label>
                                    <input type="password" name="google_calendar_client_secret" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950" autocomplete="new-password" />
                                    <div class="mt-1 text-[11px] text-zinc-500">
                                        {{ $googleClientSecretCredential['source_label'] ?? 'Not configured yet' }}
                                        @if(filled($googleClientSecretCredential['masked_value'] ?? null))
                                            - {{ $googleClientSecretCredential['masked_value'] }}
                                        @endif
                                    </div>
                                    <label class="mt-2 flex items-center gap-2 text-xs text-zinc-600">
                                        <input type="checkbox" name="clear_google_calendar_client_secret" value="1" class="rounded border-zinc-300 bg-white" />
                                        Clear saved client secret
                                    </label>
                                </div>

                                <div>
                                    <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Google Calendar Refresh Token</label>
                                    <input type="password" name="google_calendar_refresh_token" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-950" autocomplete="new-password" />
                                    <div class="mt-1 text-[11px] text-zinc-500">
                                        {{ $googleRefreshTokenCredential['source_label'] ?? 'Not configured yet' }}
                                        @if(filled($googleRefreshTokenCredential['masked_value'] ?? null))
                                            - {{ $googleRefreshTokenCredential['masked_value'] }}
                                        @endif
                                    </div>
                                    <label class="mt-2 flex items-center gap-2 text-xs text-zinc-600">
                                        <input type="checkbox" name="clear_google_calendar_refresh_token" value="1" class="rounded border-zinc-300 bg-white" />
                                        Clear saved refresh token
                                    </label>
                                </div>

                                <div class="rounded-2xl border {{ $googleCalendarConnected ? 'border-emerald-300/35 bg-emerald-100/60' : ($googleCalendarOauthReady ? 'border-sky-300/35 bg-sky-100/60' : 'border-amber-300/35 bg-amber-100/60') }} px-4 py-3 text-sm">
                                    <div class="font-semibold text-zinc-950">
                                        @if($googleCalendarConnected)
                                            Connected via Google OAuth
                                        @elseif($googleCalendarOauthReady)
                                            Ready to connect Google Calendar
                                        @else
                                            Google OAuth needs client credentials
                                        @endif
                                    </div>
                                    <div class="mt-1 text-zinc-700">
                                        @if($googleCalendarConnected)
                                            {{ count($googleCalendarOptions) }} writable calendar(s) loaded.
                                            @if(filled($googleCalendarConnection['selected_calendar_summary'] ?? null))
                                                Current selection: {{ $googleCalendarConnection['selected_calendar_summary'] }}.
                                            @endif
                                        @elseif($googleCalendarOauthReady)
                                            Save your client settings, then connect Google to pull in writable calendars automatically.
                                        @else
                                            Add a Google client ID and client secret first, or rely on the server fallback if one is configured.
                                        @endif
                                    </div>
                                    @if(filled($googleCalendarConnection['error'] ?? null))
                                        <div class="mt-2 text-xs text-amber-900">Calendar load issue: {{ $googleCalendarConnection['error'] }}</div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                        <button type="submit" name="submit_action" value="connect_asana" class="inline-flex justify-center rounded-full border border-sky-300/40 bg-sky-100 px-5 py-2.5 text-sm font-semibold text-sky-950">
                            Save + Connect Asana
                        </button>
                        <button type="submit" name="submit_action" value="refresh_asana_projects" class="inline-flex justify-center rounded-full border border-zinc-300 bg-zinc-50 px-5 py-2.5 text-sm font-semibold text-zinc-950">
                            Save + Refresh Projects
                        </button>
                        <button type="submit" name="submit_action" value="disconnect_asana" class="inline-flex justify-center rounded-full border border-rose-300/40 bg-rose-100 px-5 py-2.5 text-sm font-semibold text-rose-950">
                            Save + Disconnect Asana
                        </button>
                        <button type="submit" name="submit_action" value="connect_google" class="inline-flex justify-center rounded-full border border-sky-300/40 bg-sky-100 px-5 py-2.5 text-sm font-semibold text-sky-950">
                            Save + Connect Google
                        </button>
                        <button type="submit" name="submit_action" value="refresh_google_calendars" class="inline-flex justify-center rounded-full border border-zinc-300 bg-zinc-50 px-5 py-2.5 text-sm font-semibold text-zinc-950">
                            Save + Refresh Calendars
                        </button>
                        <button type="submit" name="submit_action" value="disconnect_google" class="inline-flex justify-center rounded-full border border-rose-300/40 bg-rose-100 px-5 py-2.5 text-sm font-semibold text-rose-950">
                            Save + Disconnect Google
                        </button>
                        <button type="submit" name="submit_action" value="save" class="inline-flex justify-center rounded-full border border-zinc-300 bg-white px-5 py-2.5 text-sm font-semibold text-zinc-950">
                            Save Setup
                        </button>
                        <button type="submit" name="submit_action" value="dry_run" class="inline-flex justify-center rounded-full border border-sky-300/40 bg-sky-100 px-5 py-2.5 text-sm font-semibold text-sky-950">
                            Save + Dry Run
                        </button>
                        <button type="submit" name="submit_action" value="run_now" class="inline-flex justify-center rounded-full border border-emerald-300/40 bg-emerald-100 px-5 py-2.5 text-sm font-semibold text-emerald-950">
                            Save + Run Live
                        </button>
                    </div>
                </form>
            </section>
        @endif

        <section class="grid gap-4 lg:grid-cols-2">
            <article class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-950">Square Integration Sync</h2>
                    <p class="mt-1 text-sm text-zinc-600">Pull Square customers, orders, and payments into source tables and sync identities.</p>
                </div>

                <x-admin.help-hint title="Square sync behavior">
                    Syncs are idempotent and safe to rerun. Event attribution is derived from mapped tax/source values after order sync.
                </x-admin.help-hint>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3">
                        <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Square Customers</div>
                        <div class="mt-1 text-xl font-semibold text-zinc-950">{{ number_format($squareCounts['customers']) }}</div>
                    </div>
                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3">
                        <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Square Orders</div>
                        <div class="mt-1 text-xl font-semibold text-zinc-950">{{ number_format($squareCounts['orders']) }}</div>
                    </div>
                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3">
                        <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Square Payments</div>
                        <div class="mt-1 text-xl font-semibold text-zinc-950">{{ number_format($squareCounts['payments']) }}</div>
                    </div>
                </div>

                <form method="POST" action="{{ route('marketing.providers-integrations.sync-square') }}" class="grid gap-3 sm:grid-cols-2">
                    @csrf
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Sync Type</label>
                        <select name="sync_type" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                            <option value="customers">Customers</option>
                            <option value="orders">Orders</option>
                            <option value="payments">Payments</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Limit</label>
                        <input type="number" name="limit" value="" min="1" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" placeholder="Leave blank for full sync" />
                        <div class="mt-1 text-[11px] text-zinc-500">Blank now means exhaustion mode with checkpoint-safe runs.</div>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Since (optional)</label>
                        <input type="datetime-local" name="since" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                    </div>
                    <label class="flex items-center gap-2 pt-6 text-sm text-zinc-700">
                        <input type="checkbox" name="dry_run" value="1" class="rounded border-zinc-300 bg-zinc-50" />
                        Dry run
                    </label>
                    <div class="sm:col-span-2">
                        <button type="submit" class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-4 py-2 text-sm font-semibold text-zinc-950">
                            Run Square Sync
                        </button>
                    </div>
                </form>
            </article>

            <article class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-950">Legacy CSV Importers</h2>
                    <p class="mt-1 text-sm text-zinc-600">Import Yotpo and Square Marketing contacts/consent/activity summaries into the marketing layer.</p>
                </div>

                <x-admin.help-hint title="Consent precedence">
                    @foreach($consentRules as $rule)
                        <div>• {{ $rule }}</div>
                    @endforeach
                </x-admin.help-hint>

                <x-admin.help-hint tone="neutral" title="Expected CSV columns">
                    Include identity fields (`email`, `phone`, `first_name`, `last_name`), consent columns (`email_subscribed`, `sms_subscribed`, `unsubscribed_at`), and optional activity summary (`sends_count`, `opens_count`, `clicks_count`, `last_engaged_at`).
                </x-admin.help-hint>

                <form method="POST" action="{{ route('marketing.providers-integrations.import-legacy') }}" enctype="multipart/form-data" class="grid gap-3">
                    @csrf
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Import Type</label>
                        <select name="import_type" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                            <option value="yotpo_contacts_import">Yotpo Contacts Export</option>
                            <option value="square_marketing_import">Square Marketing Export</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">CSV File</label>
                        <input type="file" name="file" accept=".csv,.txt" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-zinc-700">
                        <input type="checkbox" name="dry_run" value="1" class="rounded border-zinc-300 bg-zinc-50" />
                        Dry run
                    </label>
                    <div>
                        <button type="submit" class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-4 py-2 text-sm font-semibold text-zinc-950">
                            Import CSV
                        </button>
                    </div>
                </form>
            </article>
        </section>

        @php
            $squareSummary = $squareAudit['summary'];
            $squareProfiles = $squareAudit['profiles'];
            $squareFilters = $squareAudit['filters'];
            $squarePayload = $squareAudit['payload_diagnostics'];
            $manualFollowUpOrders = $squareAudit['manual_follow_up_orders'];
            $overlapSummary = $sourceOverlap['summary'];
            $overlapProfiles = $sourceOverlap['profiles'];
            $overlapFilters = $sourceOverlap['filters'];
            $overlapFilter = $sourceOverlap['active_filter'];
            $overlapSearch = $sourceOverlap['search'];
            $overlapTotalProfiles = $sourceOverlap['total_profiles'];
        @endphp

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-5">
            <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-950">Square Contact Quality</h2>
                    <p class="mt-1 text-sm text-zinc-600">Audit Square-only profiles, contact gaps, and raw POS buyers that still need manual capture.</p>
                </div>
                <div class="rounded-2xl border border-amber-300/20 bg-amber-100 px-4 py-3 text-xs text-amber-800">
                    Current bottleneck is contact quality, not sync reliability. Square customer directory is locally resident; orders/payments still often lack `square_customer_id`.
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Profiles with Square Link</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($squareSummary['profiles_with_square_link'] ?? 0)) }}</div>
                    <div class="mt-1 text-xs text-zinc-500">Canonical profiles linked to Square customers, orders, or payments.</div>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Square-only Profiles</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($squareSummary['square_only_profiles'] ?? 0)) }}</div>
                    <div class="mt-1 text-xs text-zinc-500">Source channels indicate Square only, with no Shopify/Growave enrichment.</div>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Square-only Missing Contact</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($squareSummary['square_only_missing_contact'] ?? 0)) }}</div>
                    <div class="mt-1 text-xs text-zinc-500">No email and no phone on the canonical profile.</div>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">No Shopify / Growave</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($squareSummary['no_shopify_or_growave'] ?? 0)) }}</div>
                    <div class="mt-1 text-xs text-zinc-500">Square-linked profiles with no Shopify order/customer or Growave customer link.</div>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Identity Reviews</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($squareSummary['square_identity_reviews'] ?? 0)) }}</div>
                    <div class="mt-1 text-xs text-zinc-500">Conflicts held for manual review instead of blind merge.</div>
                </div>
            </div>

            <div class="grid gap-4 xl:grid-cols-[minmax(0,2fr),minmax(320px,1fr)]">
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 space-y-4">
                    <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-zinc-950">Square-linked Profile Audit</h3>
                            <p class="mt-1 text-sm text-zinc-500">Filter canonical profiles to find the biggest contact capture gaps before doing more automation work.</p>
                        </div>
                        <div class="text-xs text-zinc-500">High-value threshold: ${{ number_format((float) $squareMinSpendDollars, 2) }}</div>
                    </div>

                    <form method="GET" action="{{ route('marketing.providers-integrations') }}" class="grid gap-3 lg:grid-cols-12">
                        <input type="hidden" name="search" value="{{ $search }}" />
                        <input type="hidden" name="source_system" value="{{ $sourceSystem }}" />
                        <input type="hidden" name="mapped" value="{{ $mapped }}" />
                        <div class="lg:col-span-4">
                            <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Audit Filter</label>
                            <select name="square_filter" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                                @foreach($squareFilters as $filterOption)
                                    <option value="{{ $filterOption['value'] }}" @selected($squareProfileFilter === $filterOption['value'])>{{ $filterOption['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="lg:col-span-4">
                            <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Search</label>
                            <input type="text" name="square_search" value="{{ $squareProfileSearch }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" placeholder="Name, email, phone, Square customer id" />
                        </div>
                        <div class="lg:col-span-2">
                            <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Min Spend</label>
                            <input type="number" step="0.01" min="0" name="square_min_spend" value="{{ $squareMinSpendDollars }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" />
                        </div>
                        <div class="lg:col-span-2 flex items-end">
                            <button type="submit" class="w-full rounded-xl border border-zinc-300 bg-emerald-100 px-3 py-2 text-sm font-semibold text-zinc-950">Apply</button>
                        </div>
                    </form>

                    <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                        <table class="min-w-full text-sm">
                            <thead class="bg-zinc-50 text-zinc-600">
                                <tr>
                                    <th class="px-4 py-3 text-left">Profile</th>
                                    <th class="px-4 py-3 text-left">Square Customer</th>
                                    <th class="px-4 py-3 text-left">Contact</th>
                                    <th class="px-4 py-3 text-left">Square Value</th>
                                    <th class="px-4 py-3 text-left">Linked Sources</th>
                                    <th class="px-4 py-3 text-left">Last Square Activity</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200">
                                @forelse($squareProfiles as $profile)
                                    @php
                                        $displayName = trim((string) (($profile->first_name ?? '') . ' ' . ($profile->last_name ?? '')));
                                        $squareSpend = ((int) ($profile->square_total_spend_cents ?? 0)) / 100;
                                        $hasContact = filled($profile->email ?? null) || filled($profile->phone ?? null);
                                        $lastSquareActivity = collect([$profile->last_square_order_at ?? null, $profile->last_square_payment_at ?? null])->filter()->max();
                                    @endphp
                                    <tr>
                                        <td class="px-4 py-3 align-top">
                                            <div class="font-semibold text-zinc-950">{{ $displayName !== '' ? $displayName : 'Unnamed profile' }}</div>
                                            <div class="mt-1 text-xs text-zinc-500">Profile #{{ $profile->id }}</div>
                                        </td>
                                        <td class="px-4 py-3 align-top text-zinc-700">
                                            <div>{{ $profile->sample_square_customer_id ?: '—' }}</div>
                                            <div class="mt-1 text-xs text-zinc-500">{{ number_format((int) ($profile->square_customer_link_count ?? 0)) }} customer link(s)</div>
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <div class="text-zinc-700">{{ $profile->email ?: 'No email' }}</div>
                                            <div class="mt-1 text-zinc-600">{{ $profile->phone ?: 'No phone' }}</div>
                                            <div class="mt-2 inline-flex rounded-full border px-2 py-1 text-[11px] font-semibold {{ $hasContact ? 'border-emerald-300/30 bg-emerald-100 text-emerald-900' : 'border-amber-300/30 bg-amber-100 text-amber-900' }}">
                                                {{ $hasContact ? 'Contact captured' : 'Needs manual capture' }}
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 align-top text-zinc-700">
                                            <div>${{ number_format($squareSpend, 2) }}</div>
                                            <div class="mt-1 text-xs text-zinc-500">{{ number_format((int) ($profile->square_order_count ?? 0)) }} orders / {{ number_format((int) ($profile->square_payment_count ?? 0)) }} payments</div>
                                        </td>
                                        <td class="px-4 py-3 align-top text-zinc-700">
                                            <div class="flex flex-wrap gap-2">
                                                <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-1 text-[11px] {{ ((int) ($profile->has_shopify_link ?? 0)) === 1 ? 'text-emerald-900' : 'text-zinc-500' }}">Shopify</span>
                                                <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-1 text-[11px] {{ ((int) ($profile->has_growave_link ?? 0)) === 1 ? 'text-emerald-900' : 'text-zinc-500' }}">Growave</span>
                                                <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-1 text-[11px] {{ ((int) ($profile->has_square_order_link ?? 0)) === 1 ? 'text-emerald-900' : 'text-zinc-500' }}">Square Order Link</span>
                                                <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-1 text-[11px] {{ ((int) ($profile->has_square_payment_link ?? 0)) === 1 ? 'text-emerald-900' : 'text-zinc-500' }}">Square Payment Link</span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 align-top text-zinc-600">
                                            {{ $lastSquareActivity ? \Illuminate\Support\Carbon::parse($lastSquareActivity)->format('Y-m-d H:i') : 'No linked order/payment activity' }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-4 py-6 text-center text-zinc-500">No Square-linked profiles matched this audit filter.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div>{{ $squareProfiles->links() }}</div>
                </article>

                <div class="space-y-4">
                    <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 space-y-3">
                        <div>
                            <h3 class="text-base font-semibold text-zinc-950">Manual Follow-up Queue</h3>
                            <p class="mt-1 text-sm text-zinc-500">Raw Square orders that still have no customer id and no canonical order link. These are the event/POS buyers most likely to need staff follow-up.</p>
                        </div>
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3">
                            <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">High-value unlinked orders</div>
                            <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($squareAudit['manual_follow_up_order_count'] ?? 0)) }}</div>
                            <div class="mt-1 text-xs text-zinc-500">Orders without `square_customer_id` or canonical order link, at or above ${{ number_format((float) $squareMinSpendDollars, 2) }}.</div>
                        </div>
                        <div class="space-y-3">
                            @forelse($manualFollowUpOrders as $row)
                                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="font-semibold text-zinc-950">{{ $row['square_order_id'] }}</div>
                                            <div class="mt-1 text-xs text-zinc-500">{{ $row['source_name'] ?: 'Unknown source' }} · {{ $row['location_id'] ?: 'No location' }}</div>
                                        </div>
                                        <div class="text-right">
                                            <div class="font-semibold text-zinc-950">${{ number_format(((int) ($row['total_money_amount'] ?? 0)) / 100, 2) }}</div>
                                            <div class="mt-1 text-xs {{ ($row['is_high_value'] ?? false) ? 'text-amber-900' : 'text-zinc-500' }}">
                                                {{ ($row['is_high_value'] ?? false) ? 'High-value' : 'Below threshold' }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-3 grid gap-2 text-xs text-zinc-600">
                                        <div>Closed: {{ $row['closed_at'] ?: '—' }}</div>
                                        <div>Cardholder hint: {{ $row['cardholder_name'] ?: 'No cardholder name in payment payload' }}</div>
                                        <div>Event attribution rows: {{ number_format((int) ($row['attribution_count'] ?? 0)) }}</div>
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-500">
                                    No manual Square follow-up candidates matched the current threshold.
                                </div>
                            @endforelse
                        </div>
                    </article>

                    <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 space-y-4">
                        <div>
                            <h3 class="text-base font-semibold text-zinc-950">Raw Payload Diagnostics</h3>
                            <p class="mt-1 text-sm text-zinc-500">This shows whether Square is actually giving us alternate contact fields beyond `square_customer_id` in the stored raw payloads.</p>
                        </div>
                        <div class="grid gap-3">
                            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3">
                                <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Orders</div>
                                <div class="mt-2 space-y-1 text-sm text-zinc-700">
                                    <div>Total: {{ number_format((int) data_get($squarePayload, 'orders.total', 0)) }}</div>
                                    <div>No customer id: {{ number_format((int) data_get($squarePayload, 'orders.no_customer_id', 0)) }}</div>
                                    <div>`customer_details.email_address`: {{ number_format((int) data_get($squarePayload, 'orders.customer_details_email', 0)) }}</div>
                                    <div>`customer_details.phone_number`: {{ number_format((int) data_get($squarePayload, 'orders.customer_details_phone', 0)) }}</div>
                                    <div>Pickup recipient name: {{ number_format((int) data_get($squarePayload, 'orders.pickup_recipient_name', 0)) }}</div>
                                    <div>Shipment recipient name: {{ number_format((int) data_get($squarePayload, 'orders.shipment_recipient_name', 0)) }}</div>
                                    <div>Tender customer id: {{ number_format((int) data_get($squarePayload, 'orders.tender_customer_id', 0)) }}</div>
                                </div>
                            </div>
                            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3">
                                <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Payments</div>
                                <div class="mt-2 space-y-1 text-sm text-zinc-700">
                                    <div>Total: {{ number_format((int) data_get($squarePayload, 'payments.total', 0)) }}</div>
                                    <div>No customer id: {{ number_format((int) data_get($squarePayload, 'payments.no_customer_id', 0)) }}</div>
                                    <div>`buyer_email_address`: {{ number_format((int) data_get($squarePayload, 'payments.buyer_email', 0)) }}</div>
                                    <div>`billing_address.address_line_1`: {{ number_format((int) data_get($squarePayload, 'payments.billing_address_line_1', 0)) }}</div>
                                    <div>Recoverable payment cardholder: {{ number_format((int) data_get($squarePayload, 'payments.cardholder_name', 0)) }}</div>
                                </div>
                            </div>
                        </div>
                        <x-admin.help-hint tone="neutral" title="Operational implication">
                            Current production payloads do not expose order-level email/phone fields in a way that supports safe auto-linking. Cardholder name is present on some payments, but that is not strong enough to auto-merge identities without a captured email or phone.
                        </x-admin.help-hint>
                    </article>
                </div>
            </div>

            <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                <h3 class="text-base font-semibold text-zinc-950">Recommended Operational Capture Path</h3>
                <div class="mt-3 grid gap-3 lg:grid-cols-3">
                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3">
                        <div class="text-sm font-semibold text-zinc-950">At Booth / POS</div>
                        <div class="mt-2 text-sm text-zinc-600">Ask for phone or email at purchase with a clear loyalty claim reason: “Enter phone or email to track rewards.”</div>
                    </div>
                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3">
                        <div class="text-sm font-semibold text-zinc-950">After Purchase</div>
                        <div class="mt-2 text-sm text-zinc-600">Use QR and receipt signage to route buyers into a fast claim flow for rewards instead of relying on Square to supply contact data later.</div>
                    </div>
                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3">
                        <div class="text-sm font-semibold text-zinc-950">Manual Follow-up</div>
                        <div class="mt-2 text-sm text-zinc-600">Prioritize high-value unlinked orders first, then staff-review cardholder hints and mapped event sources where they exist.</div>
                    </div>
                </div>
            </article>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-5">
            <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-950">Customer Source Overlap</h2>
                    <p class="mt-1 text-sm text-zinc-600">Break the canonical customer universe into Shopify, Square, and Growave overlap buckets so channel coverage and contact quality are obvious.</p>
                </div>
                <div class="rounded-2xl border border-sky-300/20 bg-sky-100 px-4 py-3 text-xs text-sky-800">
                    Uses canonical <code>marketing_profiles</code> plus existing provider links. This is reporting only; no new identity model or sync path is introduced.
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Canonical Profiles</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) $overlapTotalProfiles) }}</div>
                    <div class="mt-1 text-xs text-zinc-500">Total customer universe used as the overlap base.</div>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Square-only</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) data_get($overlapSummary, 'square_only.profile_count', 0)) }}</div>
                    <div class="mt-1 text-xs text-zinc-500">POS/event-heavy customers not yet linked to Shopify or Growave.</div>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">All 3 Sources</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) data_get($overlapSummary, 'shopify_square_growave.profile_count', 0)) }}</div>
                    <div class="mt-1 text-xs text-zinc-500">True multi-channel customers touching Shopify, Square, and Growave.</div>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Square-only Missing Contact</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) data_get($overlapSummary, 'square_only.missing_both_count', 0)) }}</div>
                    <div class="mt-1 text-xs text-zinc-500">Square-only profiles with no email and no phone.</div>
                </div>
            </div>

            <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 space-y-4">
                <div>
                    <h3 class="text-base font-semibold text-zinc-950">Overlap Buckets</h3>
                    <p class="mt-1 text-sm text-zinc-500">Each row is derived from existing source links on canonical profiles. Tracked spend reflects currently stored Shopify order totals and Square customer-linked spend where available.</p>
                </div>

                <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-zinc-50 text-zinc-600">
                            <tr>
                                <th class="px-4 py-3 text-left">Bucket</th>
                                <th class="px-4 py-3 text-left">Profiles</th>
                                <th class="px-4 py-3 text-left">Missing Email</th>
                                <th class="px-4 py-3 text-left">Missing Phone</th>
                                <th class="px-4 py-3 text-left">Missing Both</th>
                                <th class="px-4 py-3 text-left">Tracked Spend</th>
                                <th class="px-4 py-3 text-left">{{ $rewardsLabel }}</th>
                                <th class="px-4 py-3 text-left">Review Coverage</th>
                                <th class="px-4 py-3 text-left"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200">
                            @foreach($overlapSummary as $bucket)
                                <tr>
                                    <td class="px-4 py-3 align-top">
                                        <div class="font-semibold text-zinc-950">{{ $bucket['label'] }}</div>
                                        <div class="mt-1 text-xs text-zinc-500">{{ $bucket['description'] }}</div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-zinc-700">
                                        <div>{{ number_format((int) $bucket['profile_count']) }}</div>
                                        <div class="mt-1 text-xs text-zinc-500">{{ number_format((float) $bucket['percent_of_total'], 1) }}% of total</div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-zinc-700">{{ number_format((int) $bucket['missing_email_count']) }}</td>
                                    <td class="px-4 py-3 align-top text-zinc-700">{{ number_format((int) $bucket['missing_phone_count']) }}</td>
                                    <td class="px-4 py-3 align-top text-zinc-700">{{ number_format((int) $bucket['missing_both_count']) }}</td>
                                    <td class="px-4 py-3 align-top text-zinc-700">${{ number_format(((int) $bucket['total_tracked_spend_cents']) / 100, 2) }}</td>
                                    <td class="px-4 py-3 align-top text-zinc-700">{{ number_format((int) $bucket['total_candle_cash_balance']) }}</td>
                                    <td class="px-4 py-3 align-top text-zinc-700">
                                        <div>{{ number_format((int) $bucket['review_summary_profile_count']) }} profiles</div>
                                        <div class="mt-1 text-xs text-zinc-500">{{ number_format((int) $bucket['total_review_count']) }} total reviews</div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-right">
                                        <a
                                            href="{{ route('marketing.providers-integrations', array_merge(request()->query(), ['overlap_filter' => 'bucket:' . $bucket['key'], 'overlap_page' => null])) }}"
                                            class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1 text-xs font-semibold text-zinc-700"
                                        >
                                            View Profiles
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 space-y-4">
                <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-zinc-950">Profile Drilldown</h3>
                        <p class="mt-1 text-sm text-zinc-500">Use this to isolate the operational buckets that matter: Square-only missing contact, Shopify without Growave, Growave without Square, or full cross-channel customers.</p>
                    </div>
                    <div class="text-xs text-zinc-500">Filter is applied against canonical source-link presence, not raw source channel text.</div>
                </div>

                <form method="GET" action="{{ route('marketing.providers-integrations') }}" class="grid gap-3 lg:grid-cols-12">
                    <input type="hidden" name="search" value="{{ $search }}" />
                    <input type="hidden" name="source_system" value="{{ $sourceSystem }}" />
                    <input type="hidden" name="mapped" value="{{ $mapped }}" />
                    <input type="hidden" name="square_filter" value="{{ $squareProfileFilter }}" />
                    <input type="hidden" name="square_search" value="{{ $squareProfileSearch }}" />
                    <input type="hidden" name="square_min_spend" value="{{ $squareMinSpendDollars }}" />
                    <div class="lg:col-span-4">
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Overlap Filter</label>
                        <select name="overlap_filter" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                            @foreach($overlapFilters as $filterOption)
                                <option value="{{ $filterOption['value'] }}" @selected($overlapFilter === $filterOption['value'])>{{ $filterOption['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lg:col-span-6">
                        <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Search</label>
                        <input type="text" name="overlap_search" value="{{ $overlapSearch }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" placeholder="Name, email, phone" />
                    </div>
                    <div class="lg:col-span-2 flex items-end">
                        <button type="submit" class="w-full rounded-xl border border-zinc-300 bg-emerald-100 px-3 py-2 text-sm font-semibold text-zinc-950">Apply</button>
                    </div>
                </form>

                <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-zinc-50 text-zinc-600">
                            <tr>
                                <th class="px-4 py-3 text-left">Profile</th>
                                <th class="px-4 py-3 text-left">Overlap Bucket</th>
                                <th class="px-4 py-3 text-left">Sources</th>
                                <th class="px-4 py-3 text-left">Contact</th>
                                <th class="px-4 py-3 text-left">Tracked Spend</th>
                                <th class="px-4 py-3 text-left">{{ $rewardsLabel }}</th>
                                <th class="px-4 py-3 text-left">Reviews</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200">
                            @forelse($overlapProfiles as $profile)
                                @php
                                    $displayName = trim((string) (($profile->first_name ?? '') . ' ' . ($profile->last_name ?? '')));
                                    $hasEmail = filled($profile->email ?? null);
                                    $hasPhone = filled($profile->phone ?? null);
                                    $bucket = $overlapSummary[(string) ($profile->overlap_bucket ?? 'unlinked_or_other')] ?? null;
                                @endphp
                                <tr>
                                    <td class="px-4 py-3 align-top">
                                        <div class="font-semibold text-zinc-950">{{ $displayName !== '' ? $displayName : 'Unnamed profile' }}</div>
                                        <div class="mt-1 text-xs text-zinc-500">Profile #{{ $profile->id }}</div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-zinc-700">
                                        <div>{{ $bucket['label'] ?? 'Unlinked / Other' }}</div>
                                        <div class="mt-1 text-xs text-zinc-500">{{ $bucket['description'] ?? 'No Shopify, Square, or Growave link present.' }}</div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-zinc-700">
                                        <div class="flex flex-wrap gap-2">
                                            <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-1 text-[11px] {{ ((int) ($profile->has_shopify_link ?? 0)) === 1 ? 'text-emerald-900' : 'text-zinc-500' }}">Shopify</span>
                                            <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-1 text-[11px] {{ ((int) ($profile->has_square_link ?? 0)) === 1 ? 'text-emerald-900' : 'text-zinc-500' }}">Square</span>
                                            <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-1 text-[11px] {{ ((int) ($profile->has_growave_link ?? 0)) === 1 ? 'text-emerald-900' : 'text-zinc-500' }}">Growave</span>
                                        </div>
                                        <div class="mt-2 text-xs text-zinc-500">
                                            {{ number_format((int) ($profile->square_customer_link_count ?? 0)) }} Square customers ·
                                            {{ number_format((int) ($profile->shopify_order_link_count ?? 0)) }} Shopify orders
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <div class="text-zinc-700">{{ $profile->email ?: 'No email' }}</div>
                                        <div class="mt-1 text-zinc-600">{{ $profile->phone ?: 'No phone' }}</div>
                                        <div class="mt-2 inline-flex rounded-full border px-2 py-1 text-[11px] font-semibold {{ $hasEmail || $hasPhone ? 'border-emerald-300/30 bg-emerald-100 text-emerald-900' : 'border-amber-300/30 bg-amber-100 text-amber-900' }}">
                                            {{ $hasEmail || $hasPhone ? 'Reachable' : 'Missing both' }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-zinc-700">${{ number_format(((int) ($profile->tracked_spend_cents ?? 0)) / 100, 2) }}</td>
                                    <td class="px-4 py-3 align-top text-zinc-700">{{ number_format((int) ($profile->candle_cash_balance ?? 0)) }}</td>
                                    <td class="px-4 py-3 align-top text-zinc-700">
                                        <div>{{ number_format((int) ($profile->review_count ?? 0)) }} reviews</div>
                                        <div class="mt-1 text-xs text-zinc-500">{{ ((int) ($profile->has_review_summary ?? 0)) === 1 ? 'Review summary present' : 'No review summary' }}</div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-6 text-center text-zinc-500">No canonical profiles matched this overlap filter.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div>{{ $overlapProfiles->links() }}</div>
            </article>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <h2 class="text-lg font-semibold text-zinc-950">Public Event Utilities + Shopify Endpoints</h2>
            <x-admin.help-hint title="Architecture boundary">
                Laravel public pages here are minimal event/QR utilities only. Online storefront UI remains in Shopify theme widgets, which should call the JSON endpoints below.
            </x-admin.help-hint>
            <div class="grid gap-4 lg:grid-cols-2">
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-sm font-semibold text-zinc-950">Public event routes</div>
                    <div class="mt-2 space-y-1 text-xs text-zinc-600">
                        <div><code>/events/{event-slug}/optin</code> - event QR consent/profile capture</div>
                        <div><code>/events/{event-slug}/rewards</code> - event reward balance + redemption lookup</div>
                        <div><code>/rewards/lookup</code> - generic public reward lookup utility</div>
                        <div><code>/marketing/consent/confirm</code> - confirmation page for capture flows</div>
                    </div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-sm font-semibold text-zinc-950">Shopify widget endpoints</div>
                    <div class="mt-2 space-y-1 text-xs text-zinc-600">
                        <div><code>GET /shopify/marketing/v1/rewards/balance</code></div>
                        <div><code>GET /shopify/marketing/v1/rewards/available</code></div>
                        <div><code>GET /shopify/marketing/v1/rewards/history</code></div>
                        <div><code>POST /shopify/marketing/v1/rewards/redeem</code></div>
                        <div><code>POST /shopify/marketing/v1/consent/optin</code></div>
                        <div><code>POST /shopify/marketing/v1/consent/confirm</code></div>
                        <div><code>GET /shopify/marketing/v1/birthday/status</code></div>
                        <div><code>POST /shopify/marketing/v1/birthday/capture</code></div>
                        <div><code>POST /shopify/marketing/v1/birthday/claim</code></div>
                        <div><code>GET /shopify/marketing/v1/customer/status</code></div>
                    </div>
                    <x-admin.help-hint tone="neutral" title="Storefront request verification">
                        Shopify-facing endpoints now require signed requests:
                        <div class="mt-1"><code>X-Marketing-Timestamp</code> + <code>X-Marketing-Signature</code> (HMAC SHA-256 over method/path/query/body).</div>
                        <div class="mt-1">App-proxy mode can also be enabled via <code>signature</code> query verification.</div>
                        <div class="mt-1">Legacy <code>X-Marketing-Token</code> storefront auth has been retired.</div>
                    </x-admin.help-hint>
                    <div class="mt-2 text-[11px] text-zinc-500">
                        Contract shape: <code>{`"ok":true,"version":"v1","data":{...},"meta":{"states":[...]}`}</code> and errors as <code>{`"ok":false,"version":"v1","error":{"code":"...","states":[...],"recovery_states":[...]}`}</code>.
                    </div>
                    <div class="mt-2 rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-[11px] text-zinc-600">
                        Widget state examples:
                        <div class="mt-1"><code>known_customer_has_balance</code>, <code>reward_available</code>, <code>already_has_active_code</code>, <code>sms_requested</code>, <code>linked_customer</code>, <code>needs_verification</code>.</div>
                        <div class="mt-1">Recovery states include <code>verification_required</code>, <code>try_again_later</code>, <code>already_redeemed</code>, and <code>contact_support</code>.</div>
                    </div>
                </article>
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-lg font-semibold text-zinc-950">Recent Import/Sync Runs</h2>
            </div>
            <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-zinc-600">
                        <tr>
                            <th class="px-4 py-3 text-left">Type</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Source</th>
                            <th class="px-4 py-3 text-left">File</th>
                            <th class="px-4 py-3 text-left">Started</th>
                            <th class="px-4 py-3 text-left">Finished</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse($recentRuns as $run)
                            <tr>
                                <td class="px-4 py-3 text-zinc-700">{{ $run->type }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ $run->status }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ $run->source_label ?: '—' }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ $run->file_name ?: '—' }}</td>
                                <td class="px-4 py-3 text-zinc-500">{{ optional($run->started_at)->format('Y-m-d H:i') ?: '—' }}</td>
                                <td class="px-4 py-3 text-zinc-500">{{ optional($run->finished_at)->format('Y-m-d H:i') ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-zinc-500">No import runs logged yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-lg font-semibold text-zinc-950">Event Source Mapping</h2>
                <a href="{{ route('marketing.providers-integrations.mappings.create') }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-4 py-2 text-sm font-semibold text-zinc-950">Create Mapping</a>
            </div>

            <x-admin.help-hint title="Mapping workflow">
                Map noisy Square tax/source values to event instances. Unmapped values remain visible so attribution can be cleaned safely instead of guessed.
            </x-admin.help-hint>

            <form method="GET" action="{{ route('marketing.providers-integrations') }}" class="grid gap-3 md:grid-cols-12">
                <div class="md:col-span-5">
                    <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Search</label>
                    <input type="text" name="search" value="{{ $search }}" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950" placeholder="Raw value, normalized value, notes" />
                </div>
                <div class="md:col-span-3">
                    <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Source System</label>
                    <select name="source_system" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                        <option value="all">All</option>
                        @foreach($sourceSystems as $system)
                            <option value="{{ $system }}" @selected($sourceSystem === $system)>{{ $system }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Mapping Status</label>
                    <select name="mapped" class="mt-1 w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-950">
                        <option value="all" @selected($mapped === 'all')>All</option>
                        <option value="mapped" @selected($mapped === 'mapped')>Mapped</option>
                        <option value="unmapped" @selected($mapped === 'unmapped')>Unmapped</option>
                    </select>
                </div>
                <div class="md:col-span-2 flex items-end">
                    <button type="submit" class="w-full rounded-xl border border-zinc-300 bg-emerald-100 px-3 py-2 text-sm font-semibold text-zinc-950">Apply</button>
                </div>
            </form>

            <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-zinc-600">
                        <tr>
                            <th class="px-4 py-3 text-left">Source System</th>
                            <th class="px-4 py-3 text-left">Raw Value</th>
                            <th class="px-4 py-3 text-left">Normalized</th>
                            <th class="px-4 py-3 text-left">Event Instance</th>
                            <th class="px-4 py-3 text-left">Confidence</th>
                            <th class="px-4 py-3 text-left">Active</th>
                            <th class="px-4 py-3 text-left">Updated</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse($mappings as $mapping)
                            <tr class="cursor-pointer hover:bg-zinc-50" onclick="window.location='{{ route('marketing.providers-integrations.mappings.edit', $mapping) }}'">
                                <td class="px-4 py-3 text-zinc-700">{{ $mapping->source_system }}</td>
                                <td class="px-4 py-3 text-zinc-700">{{ $mapping->raw_value }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ $mapping->normalized_value ?: '—' }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ $mapping->eventInstance?->title ?: 'Unmapped' }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ $mapping->confidence !== null ? number_format((float) $mapping->confidence, 2) : '—' }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ $mapping->is_active ? 'Yes' : 'No' }}</td>
                                <td class="px-4 py-3 text-zinc-500">{{ optional($mapping->updated_at)->format('Y-m-d') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('marketing.providers-integrations.mappings.edit', $mapping) }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1 text-xs font-semibold text-zinc-700">Edit</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-6 text-center text-zinc-500">No mappings found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="pt-2">{{ $mappings->links() }}</div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <h2 class="text-lg font-semibold text-zinc-950">Unmapped Source Values</h2>
            <p class="text-sm text-zinc-600">Distinct Square values seen in source data that do not yet have an event mapping.</p>
            <div class="overflow-x-auto rounded-2xl border border-zinc-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-zinc-600">
                        <tr>
                            <th class="px-4 py-3 text-left">Source System</th>
                            <th class="px-4 py-3 text-left">Raw Value</th>
                            <th class="px-4 py-3 text-left">Normalized</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse($unmappedValues as $value)
                            <tr>
                                <td class="px-4 py-3 text-zinc-700">{{ $value['source_system'] }}</td>
                                <td class="px-4 py-3 text-zinc-700">{{ $value['raw_value'] }}</td>
                                <td class="px-4 py-3 text-zinc-600">{{ $value['normalized_value'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('marketing.providers-integrations.mappings.create', ['source_system' => $value['source_system'], 'raw_value' => $value['raw_value'], 'normalized_value' => $value['normalized_value']]) }}" wire:navigate class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-3 py-1 text-xs font-semibold text-zinc-950">
                                        Map value
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-zinc-500">No unmapped values detected from recent Square order records.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts::app>

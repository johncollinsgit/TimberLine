<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\EventInstance;
use App\Models\MarketingEventSourceMapping;
use App\Models\MarketingIdentityReview;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\Tenant;
use App\Services\Automation\AsanaWorkflowConnectionService;
use App\Services\Automation\AutomationWorkflowEngine;
use App\Services\Automation\GoogleCalendarWorkflowConnectionService;
use App\Services\Automation\TenantWorkflowAutomationSettingsService;
use App\Services\Marketing\MarketingEventAttributionService;
use App\Services\Marketing\MarketingLegacyImportService;
use App\Services\Marketing\MarketingSourceOverlapReportService;
use App\Services\Marketing\ShopifyCustomerSyncHealthService;
use App\Services\Marketing\SquareMarketingSyncService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketingProvidersIntegrationsController extends Controller
{
    public function index(
        Request $request,
        MarketingEventAttributionService $attributionService,
        MarketingSourceOverlapReportService $sourceOverlapReportService,
        TenantWorkflowAutomationSettingsService $workflowAutomationSettingsService,
        TenantModuleAccessResolver $moduleAccessResolver,
        AsanaWorkflowConnectionService $asanaConnectionService,
        GoogleCalendarWorkflowConnectionService $googleCalendarConnectionService
    ): View {
        $tenantId = $this->currentTenantId($request);
        if ($tenantId === null && Tenant::query()->exists()) {
            abort(403, 'Tenant context is required to view integrations.');
        }
        $search = trim((string) $request->query('search', ''));
        $sourceSystem = trim((string) $request->query('source_system', 'all'));
        $mapped = trim((string) $request->query('mapped', 'all'));
        $squareProfileFilter = trim((string) $request->query('square_filter', 'square_only_missing_contact'));
        $squareProfileSearch = trim((string) $request->query('square_search', ''));
        $squareMinSpendDollars = (float) $request->query('square_min_spend', '100');
        $squareMinSpendCents = max(0, (int) round($squareMinSpendDollars * 100));

        if (! in_array($squareProfileFilter, [
            'square_only_missing_contact',
            'square_only_profiles',
            'missing_contact',
            'no_shopify_or_growave',
            'high_value_missing_contact',
            'all',
        ], true)) {
            $squareProfileFilter = 'square_only_missing_contact';
        }

        $overlapFilter = trim((string) $request->query('overlap_filter', 'all'));
        $overlapSearch = trim((string) $request->query('overlap_search', ''));

        $mappings = MarketingEventSourceMapping::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('raw_value', 'like', '%'.$search.'%')
                        ->orWhere('normalized_value', 'like', '%'.$search.'%')
                        ->orWhere('notes', 'like', '%'.$search.'%');
                });
            })
            ->when($sourceSystem !== 'all' && $sourceSystem !== '', fn ($query) => $query->where('source_system', $sourceSystem))
            ->when($mapped === 'mapped', fn ($query) => $query->whereNotNull('event_instance_id'))
            ->when($mapped === 'unmapped', fn ($query) => $query->whereNull('event_instance_id'))
            ->with('eventInstance:id,title,starts_at')
            ->orderByDesc('updated_at')
            ->paginate(25, ['*'], 'mappings_page')
            ->withQueryString();

        $unmappedValues = $attributionService->unmappedValuesFromOrders(null, $tenantId);

        $sourceSystems = MarketingEventSourceMapping::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->distinct()
            ->pluck('source_system')
            ->merge(
                $unmappedValues
                    ->pluck('source_system')
                    ->map(fn ($value): string => trim((string) $value))
                    ->filter()
            )
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $recentRuns = MarketingImportRun::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        $squareAudit = $this->squareContactAudit(
            filter: $squareProfileFilter,
            search: $squareProfileSearch,
            minSpendCents: $squareMinSpendCents,
            tenantId: $tenantId
        );

        $normalizedOverlapFilter = $sourceOverlapReportService->normalizeFilter($overlapFilter);
        $sourceOverlap = $tenantId === null
            ? [
                'summary' => [],
                'profiles' => new LengthAwarePaginator([], 0, 25, null, [
                    'path' => request()->url(),
                    'pageName' => 'overlap_page',
                ]),
                'filters' => $sourceOverlapReportService->filterOptions(),
                'active_filter' => $normalizedOverlapFilter,
                'search' => $overlapSearch,
                'total_profiles' => MarketingProfile::query()->count(),
            ]
            : [
                'summary' => $sourceOverlapReportService->summary($tenantId),
                'profiles' => $sourceOverlapReportService->profilesQuery($tenantId, $normalizedOverlapFilter, $overlapSearch)
                    ->paginate(25, ['*'], 'overlap_page')
                    ->withQueryString(),
                'filters' => $sourceOverlapReportService->filterOptions(),
                'active_filter' => $normalizedOverlapFilter,
                'search' => $overlapSearch,
                'total_profiles' => MarketingProfile::query()->forTenantId($tenantId)->count(),
            ];

        return view('marketing/providers-integrations/index', [
            'section' => MarketingSectionRegistry::section('providers-integrations'),
            'sections' => $this->navigationItems(),
            'mappings' => $mappings,
            'search' => $search,
            'sourceSystem' => $sourceSystem,
            'mapped' => $mapped,
            'sourceSystems' => $sourceSystems,
            'unmappedValues' => $unmappedValues,
            'recentRuns' => $recentRuns,
            'squareCounts' => [
                'customers' => SquareCustomer::query()
                    ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                    ->count(),
                'orders' => SquareOrder::query()
                    ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                    ->count(),
                'payments' => SquarePayment::query()
                    ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                    ->count(),
            ],
            'squareAudit' => $squareAudit,
            'squareProfileFilter' => $squareProfileFilter,
            'squareProfileSearch' => $squareProfileSearch,
            'squareMinSpendDollars' => number_format($squareMinSpendCents / 100, 2, '.', ''),
            'sourceOverlap' => $sourceOverlap,
            'consentRules' => $this->consentRules(),
            'workflowAutomationSetup' => $tenantId !== null
                ? $workflowAutomationSettingsService->forTenant($tenantId)
                : null,
            'workflowAutomationModule' => $tenantId !== null
                ? $moduleAccessResolver->module($tenantId, 'workflow_automations')
                : null,
            'workflowAutomationRunResult' => session('workflowAutomationRunResult'),
            'asanaWorkflowConnection' => $tenantId !== null
                ? $asanaConnectionService->status($tenantId)
                : null,
            'googleCalendarWorkflowConnection' => $tenantId !== null
                ? $googleCalendarConnectionService->status($tenantId)
                : null,
        ]);
    }

    public function shopifyCustomerSyncHealth(
        Request $request,
        ShopifyCustomerSyncHealthService $healthService
    ): View {
        $windowHours = max(1, min(24 * 30, (int) $request->query('window_hours', 72)));
        $refresh = $request->boolean('refresh');
        $report = $healthService->report($refresh, $windowHours, $this->currentTenantId($request));

        return view('marketing/providers-integrations/shopify-customer-sync-health', [
            'section' => MarketingSectionRegistry::section('providers-integrations'),
            'sections' => $this->navigationItems(),
            'report' => $report,
        ]);
    }

    public function createMapping(Request $request): View
    {
        $tenantId = $this->currentTenantId($request);
        if ($tenantId === null) {
            abort(403, 'Tenant context is required to manage event source mappings.');
        }

        $mapping = new MarketingEventSourceMapping([
            'tenant_id' => $tenantId,
            'source_system' => (string) $request->query('source_system', 'square_tax_name'),
            'raw_value' => (string) $request->query('raw_value', ''),
            'normalized_value' => (string) $request->query('normalized_value', ''),
            'is_active' => true,
        ]);

        return view('marketing/providers-integrations/mapping-form', [
            'section' => MarketingSectionRegistry::section('providers-integrations'),
            'sections' => $this->navigationItems(),
            'mapping' => $mapping,
            'eventInstances' => $this->eventInstanceOptions(),
            'formMode' => 'create',
        ]);
    }

    public function storeMapping(Request $request, MarketingEventAttributionService $attributionService): RedirectResponse
    {
        $tenantId = $this->currentTenantId($request);
        if ($tenantId === null) {
            abort(403, 'Tenant context is required to manage event source mappings.');
        }

        $data = $request->validate([
            'source_system' => ['required', 'string', 'max:100'],
            'raw_value' => ['required', 'string', 'max:255'],
            'normalized_value' => ['nullable', 'string', 'max:255'],
            'event_instance_id' => ['nullable', 'integer', 'exists:event_instances,id'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        MarketingEventSourceMapping::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'source_system' => trim((string) $data['source_system']),
                'raw_value' => trim((string) $data['raw_value']),
            ],
            [
                'tenant_id' => $tenantId,
                'normalized_value' => trim((string) ($data['normalized_value'] ?? '')) ?: null,
                'event_instance_id' => $data['event_instance_id'] ?? null,
                'confidence' => $data['confidence'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? false),
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ]
        );

        $attributionService->refreshSquareOrderAttributions(500, $tenantId);

        return redirect()
            ->route('marketing.providers-integrations')
            ->with('toast', ['style' => 'success', 'message' => 'Event source mapping created.']);
    }

    public function editMapping(Request $request, MarketingEventSourceMapping $mapping): View
    {
        $tenantId = $this->currentTenantId($request);
        if ($tenantId === null) {
            abort(403, 'Tenant context is required to manage event source mappings.');
        }
        $this->assertMappingAccess($mapping, $tenantId);

        return view('marketing/providers-integrations/mapping-form', [
            'section' => MarketingSectionRegistry::section('providers-integrations'),
            'sections' => $this->navigationItems(),
            'mapping' => $mapping,
            'eventInstances' => $this->eventInstanceOptions(),
            'formMode' => 'edit',
        ]);
    }

    public function updateMapping(
        Request $request,
        MarketingEventSourceMapping $mapping,
        MarketingEventAttributionService $attributionService
    ): RedirectResponse {
        $tenantId = $this->currentTenantId($request);
        if ($tenantId === null) {
            abort(403, 'Tenant context is required to manage event source mappings.');
        }
        $this->assertMappingAccess($mapping, $tenantId);

        $data = $request->validate([
            'source_system' => ['required', 'string', 'max:100'],
            'raw_value' => ['required', 'string', 'max:255'],
            'normalized_value' => ['nullable', 'string', 'max:255'],
            'event_instance_id' => ['nullable', 'integer', 'exists:event_instances,id'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $mapping->fill([
            'tenant_id' => $tenantId,
            'source_system' => trim((string) $data['source_system']),
            'raw_value' => trim((string) $data['raw_value']),
            'normalized_value' => trim((string) ($data['normalized_value'] ?? '')) ?: null,
            'event_instance_id' => $data['event_instance_id'] ?? null,
            'confidence' => $data['confidence'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ])->save();

        $attributionService->refreshSquareOrderAttributions(500, $tenantId);

        return redirect()
            ->route('marketing.providers-integrations')
            ->with('toast', ['style' => 'success', 'message' => 'Event source mapping updated.']);
    }

    public function runSquareSync(Request $request, SquareMarketingSyncService $syncService): RedirectResponse
    {
        $tenantId = $this->currentTenantId($request);
        if ($tenantId === null) {
            return redirect()
                ->route('marketing.providers-integrations')
                ->with('toast', [
                    'style' => 'error',
                    'message' => 'Square sync requires an explicit tenant context before running.',
                ]);
        }

        $data = $request->validate([
            'sync_type' => ['required', 'in:customers,orders,payments'],
            'limit' => ['nullable', 'integer', 'min:1'],
            'since' => ['nullable', 'date'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $options = [
            'limit' => isset($data['limit']) ? (int) $data['limit'] : null,
            'since' => $data['since'] ?? null,
            'dry_run' => (bool) ($data['dry_run'] ?? false),
            'created_by' => auth()->id(),
            'tenant_id' => $tenantId,
        ];

        $result = match ($data['sync_type']) {
            'customers' => $syncService->syncCustomers($options),
            'orders' => $syncService->syncOrders($options),
            'payments' => $syncService->syncPayments($options),
        };

        $toastStyle = $result['status'] === 'blocked' ? 'error' : 'success';
        $toastMessage = $result['status'] === 'blocked'
            ? 'Square sync blocked: '.($result['reason'] ?? 'missing tenant context or configuration.')
            : 'Square sync started and logged.';

        return redirect()
            ->route('marketing.providers-integrations')
            ->with('toast', ['style' => $toastStyle, 'message' => $toastMessage]);
    }

    public function importLegacy(Request $request, MarketingLegacyImportService $importService): RedirectResponse
    {
        $tenantId = $this->currentTenantId($request);
        if ($tenantId === null) {
            return redirect()
                ->route('marketing.providers-integrations')
                ->with('toast', [
                    'style' => 'error',
                    'message' => 'Legacy imports require an explicit tenant context before running.',
                ]);
        }

        $data = $request->validate([
            'import_type' => ['required', 'in:yotpo_contacts_import,square_marketing_import'],
            'file' => ['required', 'file', 'mimes:csv,txt'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $importService->importFile(
            file: $data['file'],
            type: (string) $data['import_type'],
            createdBy: auth()->id(),
            tenantId: $tenantId,
            dryRun: (bool) ($data['dry_run'] ?? false)
        );

        return redirect()
            ->route('marketing.providers-integrations')
            ->with('toast', ['style' => 'success', 'message' => 'Legacy import completed and logged.']);
    }

    public function saveWorkflowAutomation(
        Request $request,
        TenantWorkflowAutomationSettingsService $workflowAutomationSettingsService,
        AutomationWorkflowEngine $workflowEngine,
        AsanaWorkflowConnectionService $asanaConnectionService,
        GoogleCalendarWorkflowConnectionService $googleCalendarConnectionService
    ): RedirectResponse {
        $tenantId = $this->currentTenantId($request);
        if ($tenantId === null) {
            return redirect()
                ->route('marketing.providers-integrations')
                ->with('toast', [
                    'style' => 'error',
                    'message' => 'Workflow automation setup requires an explicit tenant context before saving.',
                ]);
        }

        $submitAction = trim((string) $request->input('submit_action', 'save'));
        $data = $request->validate([
            'workflow_key' => ['required', 'in:asana_to_google_calendar'],
            'submit_action' => ['required', 'in:save,dry_run,run_now,connect_asana,disconnect_asana,refresh_asana_projects,connect_google,disconnect_google,refresh_google_calendars'],
            'enabled' => ['nullable', 'boolean'],
            'project_gid' => [in_array($submitAction, ['save', 'dry_run', 'run_now'], true) ? 'required' : 'nullable', 'string', 'max:120'],
            'calendar_id' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'max:100'],
            'default_start_time' => ['required', 'date_format:H:i'],
            'default_duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'skip_completed_tasks' => ['nullable', 'boolean'],
            'modified_overlap_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'bootstrap_lookback_days' => ['required', 'integer', 'min:1', 'max:365'],
            'poll_limit' => ['required', 'integer', 'min:1', 'max:100'],
            'max_tasks_per_run' => ['required', 'integer', 'min:1', 'max:5000'],
            'asana_personal_access_token' => ['nullable', 'string', 'max:5000'],
            'clear_asana_personal_access_token' => ['nullable', 'boolean'],
            'asana_oauth_client_id' => ['nullable', 'string', 'max:5000'],
            'clear_asana_oauth_client_id' => ['nullable', 'boolean'],
            'asana_oauth_client_secret' => ['nullable', 'string', 'max:5000'],
            'clear_asana_oauth_client_secret' => ['nullable', 'boolean'],
            'asana_oauth_refresh_token' => ['nullable', 'string', 'max:5000'],
            'clear_asana_oauth_refresh_token' => ['nullable', 'boolean'],
            'google_calendar_client_id' => ['nullable', 'string', 'max:5000'],
            'clear_google_calendar_client_id' => ['nullable', 'boolean'],
            'google_calendar_client_secret' => ['nullable', 'string', 'max:5000'],
            'clear_google_calendar_client_secret' => ['nullable', 'boolean'],
            'google_calendar_refresh_token' => ['nullable', 'string', 'max:5000'],
            'clear_google_calendar_refresh_token' => ['nullable', 'boolean'],
        ]);

        $workflowKey = (string) $data['workflow_key'];

        $workflowAutomationSettingsService->saveForTenant($tenantId, $workflowKey, [
            'enabled' => array_key_exists('enabled', $data) ? (bool) $data['enabled'] : false,
            'trigger' => [
                'project_gid' => trim((string) ($data['project_gid'] ?? '')),
                'modified_overlap_minutes' => (int) $data['modified_overlap_minutes'],
                'bootstrap_lookback_days' => (int) $data['bootstrap_lookback_days'],
                'poll_limit' => (int) $data['poll_limit'],
                'max_tasks_per_run' => (int) $data['max_tasks_per_run'],
            ],
            'action' => [
                'calendar_id' => trim((string) ($data['calendar_id'] ?? '')),
                'timezone' => trim((string) $data['timezone']),
                'default_start_time' => trim((string) $data['default_start_time']),
                'default_duration_minutes' => (int) $data['default_duration_minutes'],
                'skip_completed_tasks' => array_key_exists('skip_completed_tasks', $data) ? (bool) $data['skip_completed_tasks'] : false,
            ],
            'credentials' => [
                'asana_personal_access_token' => $data['asana_personal_access_token'] ?? null,
                'clear_asana_personal_access_token' => array_key_exists('clear_asana_personal_access_token', $data) ? (bool) $data['clear_asana_personal_access_token'] : false,
                'asana_oauth_client_id' => $data['asana_oauth_client_id'] ?? null,
                'clear_asana_oauth_client_id' => array_key_exists('clear_asana_oauth_client_id', $data) ? (bool) $data['clear_asana_oauth_client_id'] : false,
                'asana_oauth_client_secret' => $data['asana_oauth_client_secret'] ?? null,
                'clear_asana_oauth_client_secret' => array_key_exists('clear_asana_oauth_client_secret', $data) ? (bool) $data['clear_asana_oauth_client_secret'] : false,
                'asana_oauth_refresh_token' => $data['asana_oauth_refresh_token'] ?? null,
                'clear_asana_oauth_refresh_token' => array_key_exists('clear_asana_oauth_refresh_token', $data) ? (bool) $data['clear_asana_oauth_refresh_token'] : false,
                'google_calendar_client_id' => $data['google_calendar_client_id'] ?? null,
                'clear_google_calendar_client_id' => array_key_exists('clear_google_calendar_client_id', $data) ? (bool) $data['clear_google_calendar_client_id'] : false,
                'google_calendar_client_secret' => $data['google_calendar_client_secret'] ?? null,
                'clear_google_calendar_client_secret' => array_key_exists('clear_google_calendar_client_secret', $data) ? (bool) $data['clear_google_calendar_client_secret'] : false,
                'google_calendar_refresh_token' => $data['google_calendar_refresh_token'] ?? null,
                'clear_google_calendar_refresh_token' => array_key_exists('clear_google_calendar_refresh_token', $data) ? (bool) $data['clear_google_calendar_refresh_token'] : false,
            ],
        ]);

        if ($submitAction === 'save') {
            return redirect()
                ->route('marketing.providers-integrations')
                ->with('toast', [
                    'style' => 'success',
                    'message' => 'Workflow automation setup saved.',
                ]);
        }

        if ($submitAction === 'connect_asana') {
            try {
                $connectUrl = $asanaConnectionService->buildConnectUrl($tenantId, $request->user(), $workflowKey);
            } catch (\Throwable $exception) {
                return redirect()
                    ->route('marketing.providers-integrations')
                    ->with('toast', [
                        'style' => 'warning',
                        'message' => $exception->getMessage(),
                    ]);
            }

            return redirect()->away($connectUrl);
        }

        if ($submitAction === 'disconnect_asana') {
            $asanaConnectionService->disconnect($tenantId, $workflowKey);

            return redirect()
                ->route('marketing.providers-integrations')
                ->with('toast', [
                    'style' => 'success',
                    'message' => 'Asana OAuth was disconnected for this workflow.',
                ]);
        }

        if ($submitAction === 'refresh_asana_projects') {
            try {
                $projects = $asanaConnectionService->projectOptions($tenantId, $workflowKey, true);
            } catch (\Throwable $exception) {
                return redirect()
                    ->route('marketing.providers-integrations')
                    ->with('toast', [
                        'style' => 'warning',
                        'message' => $exception->getMessage(),
                    ]);
            }

            return redirect()
                ->route('marketing.providers-integrations')
                ->with('toast', [
                    'style' => 'success',
                    'message' => sprintf('Asana project list refreshed. %d project(s) loaded.', count($projects)),
                ]);
        }

        if ($submitAction === 'connect_google') {
            try {
                $connectUrl = $googleCalendarConnectionService->buildConnectUrl($tenantId, $request->user(), $workflowKey);
            } catch (\Throwable $exception) {
                return redirect()
                    ->route('marketing.providers-integrations')
                    ->with('toast', [
                        'style' => 'warning',
                        'message' => $exception->getMessage(),
                    ]);
            }

            return redirect()->away($connectUrl);
        }

        if ($submitAction === 'disconnect_google') {
            $googleCalendarConnectionService->disconnect($tenantId, $workflowKey);

            return redirect()
                ->route('marketing.providers-integrations')
                ->with('toast', [
                    'style' => 'success',
                    'message' => 'Google Calendar OAuth was disconnected for this workflow.',
                ]);
        }

        if ($submitAction === 'refresh_google_calendars') {
            try {
                $calendars = $googleCalendarConnectionService->calendarOptions($tenantId, $workflowKey, true);
            } catch (\Throwable $exception) {
                return redirect()
                    ->route('marketing.providers-integrations')
                    ->with('toast', [
                        'style' => 'warning',
                        'message' => $exception->getMessage(),
                    ]);
            }

            return redirect()
                ->route('marketing.providers-integrations')
                ->with('toast', [
                    'style' => 'success',
                    'message' => sprintf('Google Calendar list refreshed. %d writable calendar(s) loaded.', count($calendars)),
                ]);
        }

        $dryRun = $submitAction === 'dry_run';
        $result = $workflowEngine->runTenantWorkflow(
            workflowKey: $workflowKey,
            tenantId: $tenantId,
            dryRun: $dryRun,
            forceEnabled: true
        );

        return redirect()
            ->route('marketing.providers-integrations')
            ->with('workflowAutomationRunResult', $result)
            ->with('toast', [
                'style' => $this->workflowAutomationToastStyle($result),
                'message' => $this->workflowAutomationToastMessage($submitAction, $result),
            ]);
    }

    public function workflowGoogleCalendarCallback(
        Request $request,
        GoogleCalendarWorkflowConnectionService $googleCalendarConnectionService
    ): RedirectResponse {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        try {
            $result = $googleCalendarConnectionService->connectFromCallback(
                code: $data['code'],
                state: $data['state'],
                actor: $request->user()
            );
        } catch (\Throwable $exception) {
            return redirect()
                ->route('marketing.providers-integrations')
                ->with('toast', [
                    'style' => 'warning',
                    'message' => $exception->getMessage(),
                ]);
        }

        $tenantId = (int) ($result['tenant_id'] ?? 0);
        if ($tenantId > 0) {
            $request->session()->put('tenant_id', $tenantId);
            $request->attributes->set('current_tenant_id', $tenantId);
        }

        $calendars = is_array($result['calendars'] ?? null) ? $result['calendars'] : [];
        $message = (bool) ($result['auto_selected'] ?? false)
            ? 'Google Calendar connected and the only writable calendar was selected automatically.'
            : sprintf('Google Calendar connected. %d writable calendar(s) are ready to choose from.', count($calendars));

        return redirect()
            ->to((string) ($result['return_path'] ?? route('workflows.connections', absolute: false)))
            ->with('toast', [
                'style' => 'success',
                'message' => $message,
            ]);
    }

    public function workflowAsanaCallback(
        Request $request,
        AsanaWorkflowConnectionService $asanaConnectionService
    ): RedirectResponse {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        try {
            $result = $asanaConnectionService->connectFromCallback(
                code: $data['code'],
                state: $data['state'],
                actor: $request->user()
            );
        } catch (\Throwable $exception) {
            return redirect()
                ->route('marketing.providers-integrations')
                ->with('toast', [
                    'style' => 'warning',
                    'message' => $exception->getMessage(),
                ]);
        }

        $tenantId = (int) ($result['tenant_id'] ?? 0);
        if ($tenantId > 0) {
            $request->session()->put('tenant_id', $tenantId);
            $request->attributes->set('current_tenant_id', $tenantId);
        }

        $projects = is_array($result['projects'] ?? null) ? $result['projects'] : [];
        $message = (bool) ($result['auto_selected'] ?? false)
            ? 'Asana connected and the only available project was selected automatically.'
            : sprintf('Asana connected. %d project(s) are ready to choose from.', count($projects));

        return redirect()
            ->to((string) ($result['return_path'] ?? route('workflows.connections', absolute: false)))
            ->with('toast', [
                'style' => 'success',
                'message' => $message,
            ]);
    }

    /**
     * @return array<int,array{id:int,label:string}>
     */
    protected function eventInstanceOptions(): array
    {
        return EventInstance::query()
            ->orderByDesc('starts_at')
            ->orderBy('title')
            ->limit(300)
            ->get(['id', 'title', 'starts_at'])
            ->map(fn (EventInstance $row): array => [
                'id' => (int) $row->id,
                'label' => (string) $row->title.' ('.(optional($row->starts_at)->toDateString() ?: 'no-date').')',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    protected function consentRules(): array
    {
        return [
            'Explicit opt-out always overrides opt-in.',
            'Email and SMS consent are handled independently.',
            'Imported consent only upgrades to opt-in when there is no stronger local opt-out signal.',
            'Ambiguous or missing consent is never auto-upgraded to true.',
        ];
    }

    /**
     * @return array{
     *   summary:array<string,int>,
     *   profiles:LengthAwarePaginator,
     *   filters:array<int,array{value:string,label:string}>,
     *   payload_diagnostics:array<string,mixed>,
     *   manual_follow_up_orders:Collection<int,array<string,mixed>>,
     *   manual_follow_up_order_count:int
     * }
     */
    protected function squareContactAudit(string $filter, string $search, int $minSpendCents, ?int $tenantId): array
    {
        $profiles = $this->squareContactProfilesQuery($filter, $search, $minSpendCents, $tenantId)
            ->paginate(25, ['*'], 'square_page')
            ->withQueryString();

        return [
            'summary' => $this->squareContactAuditSummary($minSpendCents, $tenantId),
            'profiles' => $profiles,
            'filters' => [
                ['value' => 'square_only_missing_contact', 'label' => 'Square-only + Missing Contact'],
                ['value' => 'square_only_profiles', 'label' => 'Square-only Profiles'],
                ['value' => 'missing_contact', 'label' => 'Missing Email/Phone'],
                ['value' => 'no_shopify_or_growave', 'label' => 'No Shopify/Growave'],
                ['value' => 'high_value_missing_contact', 'label' => 'High-value Missing Contact'],
                ['value' => 'all', 'label' => 'All Square-linked Profiles'],
            ],
            'payload_diagnostics' => $this->squarePayloadDiagnostics($tenantId),
            'manual_follow_up_orders' => $this->manualFollowUpOrders($minSpendCents, $tenantId),
            'manual_follow_up_order_count' => $this->manualFollowUpOrdersCount($minSpendCents, $tenantId),
        ];
    }

    protected function squareContactProfilesQuery(string $filter, string $search, int $minSpendCents, ?int $tenantId): QueryBuilder
    {
        $squareLinkFlags = $this->sourceLinkFlagsSubquery($tenantId);
        $squareCustomerMetrics = $this->squareCustomerMetricsSubquery($tenantId);

        $query = MarketingProfile::query()
            ->toBase()
            ->leftJoinSub($squareLinkFlags, 'square_link_flags', function ($join): void {
                $join->on('square_link_flags.marketing_profile_id', '=', 'marketing_profiles.id');
            })
            ->leftJoinSub($squareCustomerMetrics, 'square_customer_metrics', function ($join): void {
                $join->on('square_customer_metrics.marketing_profile_id', '=', 'marketing_profiles.id');
            })
            ->whereRaw('coalesce(square_link_flags.has_square_link, 0) = 1')
            ->when($tenantId !== null, fn ($query) => $query->where('marketing_profiles.tenant_id', $tenantId))
            ->select([
                'marketing_profiles.id',
                'marketing_profiles.first_name',
                'marketing_profiles.last_name',
                'marketing_profiles.email',
                'marketing_profiles.phone',
                'marketing_profiles.source_channels',
                'marketing_profiles.updated_at',
                DB::raw('coalesce(square_link_flags.has_shopify_link, 0) as has_shopify_link'),
                DB::raw('coalesce(square_link_flags.has_growave_link, 0) as has_growave_link'),
                DB::raw('coalesce(square_link_flags.has_square_customer_link, 0) as has_square_customer_link'),
                DB::raw('coalesce(square_link_flags.has_square_order_link, 0) as has_square_order_link'),
                DB::raw('coalesce(square_link_flags.has_square_payment_link, 0) as has_square_payment_link'),
                DB::raw('coalesce(square_customer_metrics.square_customer_link_count, 0) as square_customer_link_count'),
                DB::raw('square_customer_metrics.sample_square_customer_id as sample_square_customer_id'),
                DB::raw('coalesce(square_customer_metrics.square_order_count, 0) as square_order_count'),
                DB::raw('coalesce(square_customer_metrics.square_payment_count, 0) as square_payment_count'),
                DB::raw('coalesce(square_customer_metrics.square_order_spend_cents, 0) as square_order_spend_cents'),
                DB::raw('coalesce(square_customer_metrics.square_payment_spend_cents, 0) as square_payment_spend_cents'),
                DB::raw('(coalesce(square_customer_metrics.square_order_spend_cents, 0) + coalesce(square_customer_metrics.square_payment_spend_cents, 0)) as square_total_spend_cents'),
                DB::raw('square_customer_metrics.last_square_order_at as last_square_order_at'),
                DB::raw('square_customer_metrics.last_square_payment_at as last_square_payment_at'),
            ]);

        $this->applySquareProfileFilter($query, $filter, $minSpendCents);

        if ($search !== '') {
            $query->where(function ($nested) use ($search): void {
                $nested->where('marketing_profiles.first_name', 'like', '%'.$search.'%')
                    ->orWhere('marketing_profiles.last_name', 'like', '%'.$search.'%')
                    ->orWhere('marketing_profiles.email', 'like', '%'.$search.'%')
                    ->orWhere('marketing_profiles.phone', 'like', '%'.$search.'%')
                    ->orWhereRaw('coalesce(square_customer_metrics.sample_square_customer_id, "") like ?', ['%'.$search.'%']);
            });
        }

        return $query
            ->orderByRaw('(coalesce(square_customer_metrics.square_order_spend_cents, 0) + coalesce(square_customer_metrics.square_payment_spend_cents, 0)) desc')
            ->orderByDesc('marketing_profiles.updated_at');
    }

    protected function applySquareProfileFilter(QueryBuilder $query, string $filter, int $minSpendCents): void
    {
        if ($filter === 'square_only_profiles' || $filter === 'square_only_missing_contact') {
            $query->whereJsonLength('marketing_profiles.source_channels', 1)
                ->whereJsonContains('marketing_profiles.source_channels', 'square');
        }

        if ($filter === 'missing_contact' || $filter === 'square_only_missing_contact' || $filter === 'high_value_missing_contact') {
            $this->applyMissingContactFilter($query);
        }

        if ($filter === 'no_shopify_or_growave') {
            $query->whereRaw('coalesce(square_link_flags.has_shopify_link, 0) = 0')
                ->whereRaw('coalesce(square_link_flags.has_growave_link, 0) = 0');
        }

        if ($filter === 'high_value_missing_contact') {
            $query->whereRaw('(coalesce(square_customer_metrics.square_order_spend_cents, 0) + coalesce(square_customer_metrics.square_payment_spend_cents, 0)) >= ?', [$minSpendCents]);
        }
    }

    protected function applyMissingContactFilter(QueryBuilder $query): void
    {
        $query->where(function ($email): void {
            $email->whereNull('marketing_profiles.email')
                ->orWhere('marketing_profiles.email', '');
        })->where(function ($phone): void {
            $phone->whereNull('marketing_profiles.phone')
                ->orWhere('marketing_profiles.phone', '');
        });
    }

    protected function sourceLinkFlagsSubquery(?int $tenantId): QueryBuilder
    {
        return MarketingProfileLink::query()
            ->toBase()
            ->select('marketing_profile_id')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereIn('source_type', [
                'square_customer',
                'square_order',
                'square_payment',
                'shopify_customer',
                'shopify_order',
                'growave_customer',
            ])
            ->groupBy('marketing_profile_id')
            ->selectRaw("max(case when source_type = 'square_customer' then 1 else 0 end) as has_square_customer_link")
            ->selectRaw("max(case when source_type = 'square_order' then 1 else 0 end) as has_square_order_link")
            ->selectRaw("max(case when source_type = 'square_payment' then 1 else 0 end) as has_square_payment_link")
            ->selectRaw("max(case when source_type in ('square_customer', 'square_order', 'square_payment') then 1 else 0 end) as has_square_link")
            ->selectRaw("max(case when source_type in ('shopify_customer', 'shopify_order') then 1 else 0 end) as has_shopify_link")
            ->selectRaw("max(case when source_type = 'growave_customer' then 1 else 0 end) as has_growave_link");
    }

    protected function profileReviewMetricsSubquery(): ?QueryBuilder
    {
        if (! Schema::hasTable('marketing_review_summaries')) {
            return null;
        }

        return DB::table('marketing_review_summaries')
            ->select('marketing_profile_id')
            ->whereNotNull('marketing_profile_id')
            ->groupBy('marketing_profile_id')
            ->selectRaw('max(1) as has_review_summary')
            ->selectRaw('max(coalesce(review_count, 0)) as review_count');
    }

    protected function profileCandleCashBalanceSubquery(): ?QueryBuilder
    {
        if (! Schema::hasTable('candle_cash_balances')) {
            return null;
        }

        return DB::table('candle_cash_balances')
            ->select('marketing_profile_id', 'balance');
    }

    protected function shopifyOrderMetricsSubquery(): ?QueryBuilder
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'shopify_order_id')) {
            return null;
        }

        $amountColumn = $this->detectShopifyOrderAmountColumn();

        if ($amountColumn === null) {
            return null;
        }

        $orderRows = DB::table('orders')
            ->selectRaw($this->shopifyOrderSourceIdExpression().' as shopify_source_id')
            ->whereNotNull('shopify_order_id')
            ->selectRaw('round(coalesce('.$amountColumn.', 0) * 100, 0) as spend_cents');

        return DB::table('marketing_profile_links as shopify_links')
            ->leftJoinSub($orderRows, 'shopify_order_rows', function ($join): void {
                $join->on('shopify_order_rows.shopify_source_id', '=', 'shopify_links.source_id');
            })
            ->where('shopify_links.source_type', 'shopify_order')
            ->groupBy('shopify_links.marketing_profile_id')
            ->selectRaw('shopify_links.marketing_profile_id')
            ->selectRaw('count(distinct shopify_links.source_id) as shopify_order_link_count')
            ->selectRaw('coalesce(sum(coalesce(shopify_order_rows.spend_cents, 0)), 0) as shopify_order_spend_cents');
    }

    protected function shopifyOrderSourceIdExpression(): string
    {
        $driver = DB::connection()->getDriverName();
        $storeExpression = match (true) {
            Schema::hasColumn('orders', 'shopify_store_key') && Schema::hasColumn('orders', 'shopify_store') => "coalesce(shopify_store_key, shopify_store, 'unknown')",
            Schema::hasColumn('orders', 'shopify_store_key') => "coalesce(shopify_store_key, 'unknown')",
            Schema::hasColumn('orders', 'shopify_store') => "coalesce(shopify_store, 'unknown')",
            default => "'unknown'",
        };

        if ($driver === 'sqlite') {
            return $storeExpression." || ':' || cast(shopify_order_id as text)";
        }

        return 'concat('.$storeExpression.", ':', cast(shopify_order_id as char))";
    }

    protected function detectShopifyOrderAmountColumn(): ?string
    {
        foreach (['total_price', 'total', 'grand_total', 'order_total', 'subtotal_price'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                return $column;
            }
        }

        return null;
    }

    protected function squareCustomerMetricsSubquery(?int $tenantId): QueryBuilder
    {
        $orderMetrics = SquareOrder::query()
            ->toBase()
            ->select('square_customer_id')
            ->whereNotNull('square_customer_id')
            ->where('square_customer_id', '<>', '')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->groupBy('square_customer_id')
            ->selectRaw('count(*) as order_count')
            ->selectRaw('coalesce(sum(total_money_amount), 0) as order_spend_cents')
            ->selectRaw('max(closed_at) as last_square_order_at');

        $paymentMetrics = SquarePayment::query()
            ->toBase()
            ->select('square_customer_id')
            ->whereNotNull('square_customer_id')
            ->where('square_customer_id', '<>', '')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->groupBy('square_customer_id')
            ->selectRaw('count(*) as payment_count')
            ->selectRaw('coalesce(sum(amount_money), 0) as payment_spend_cents')
            ->selectRaw('max(created_at_source) as last_square_payment_at');

        return MarketingProfileLink::query()
            ->toBase()
            ->from('marketing_profile_links as square_links')
            ->leftJoinSub($orderMetrics, 'square_order_metrics', function ($join): void {
                $join->on('square_order_metrics.square_customer_id', '=', 'square_links.source_id');
            })
            ->leftJoinSub($paymentMetrics, 'square_payment_metrics', function ($join): void {
                $join->on('square_payment_metrics.square_customer_id', '=', 'square_links.source_id');
            })
            ->where('square_links.source_type', 'square_customer')
            ->when($tenantId !== null, fn ($query) => $query->where('square_links.tenant_id', $tenantId))
            ->groupBy('square_links.marketing_profile_id')
            ->selectRaw('square_links.marketing_profile_id')
            ->selectRaw('count(distinct square_links.source_id) as square_customer_link_count')
            ->selectRaw('min(square_links.source_id) as sample_square_customer_id')
            ->selectRaw('coalesce(sum(coalesce(square_order_metrics.order_count, 0)), 0) as square_order_count')
            ->selectRaw('coalesce(sum(coalesce(square_order_metrics.order_spend_cents, 0)), 0) as square_order_spend_cents')
            ->selectRaw('max(square_order_metrics.last_square_order_at) as last_square_order_at')
            ->selectRaw('coalesce(sum(coalesce(square_payment_metrics.payment_count, 0)), 0) as square_payment_count')
            ->selectRaw('coalesce(sum(coalesce(square_payment_metrics.payment_spend_cents, 0)), 0) as square_payment_spend_cents')
            ->selectRaw('max(square_payment_metrics.last_square_payment_at) as last_square_payment_at');
    }

    /**
     * @return array<string,int>
     */
    protected function squareContactAuditSummary(int $minSpendCents, ?int $tenantId): array
    {
        $profilesWithSquareLink = MarketingProfile::query()
            ->when($tenantId !== null, fn ($query) => $query->forTenantId($tenantId))
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('marketing_profile_links as square_profile_links')
                    ->whereColumn('square_profile_links.marketing_profile_id', 'marketing_profiles.id')
                    ->whereIn('square_profile_links.source_type', ['square_customer', 'square_order', 'square_payment']);
            })
            ->count();

        $squareOnlyProfiles = MarketingProfile::query()
            ->when($tenantId !== null, fn ($query) => $query->forTenantId($tenantId))
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('marketing_profile_links as square_profile_links')
                    ->whereColumn('square_profile_links.marketing_profile_id', 'marketing_profiles.id')
                    ->whereIn('square_profile_links.source_type', ['square_customer', 'square_order', 'square_payment']);
            })
            ->whereJsonLength('source_channels', 1)
            ->whereJsonContains('source_channels', 'square')
            ->count();

        $squareOnlyMissingContact = MarketingProfile::query()
            ->when($tenantId !== null, fn ($query) => $query->forTenantId($tenantId))
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('marketing_profile_links as square_profile_links')
                    ->whereColumn('square_profile_links.marketing_profile_id', 'marketing_profiles.id')
                    ->whereIn('square_profile_links.source_type', ['square_customer', 'square_order', 'square_payment']);
            })
            ->whereJsonLength('source_channels', 1)
            ->whereJsonContains('source_channels', 'square')
            ->where(function ($email): void {
                $email->whereNull('email')->orWhere('email', '');
            })
            ->where(function ($phone): void {
                $phone->whereNull('phone')->orWhere('phone', '');
            })
            ->count();

        $noShopifyOrGrowave = MarketingProfile::query()
            ->when($tenantId !== null, fn ($query) => $query->forTenantId($tenantId))
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('marketing_profile_links as square_profile_links')
                    ->whereColumn('square_profile_links.marketing_profile_id', 'marketing_profiles.id')
                    ->whereIn('square_profile_links.source_type', ['square_customer', 'square_order', 'square_payment']);
            })
            ->whereDoesntHave('links', function ($query): void {
                $query->whereIn('source_type', ['shopify_customer', 'shopify_order', 'growave_customer']);
            })
            ->count();

        $highValueMissingContact = $this->squareContactProfilesQuery('high_value_missing_contact', '', $minSpendCents, $tenantId)->count();

        return [
            'profiles_with_square_link' => $profilesWithSquareLink,
            'square_customer_links' => MarketingProfileLink::query()->when($tenantId !== null, fn ($query) => $query->forTenantId($tenantId))->where('source_type', 'square_customer')->count(),
            'square_order_links' => MarketingProfileLink::query()->when($tenantId !== null, fn ($query) => $query->forTenantId($tenantId))->where('source_type', 'square_order')->count(),
            'square_payment_links' => MarketingProfileLink::query()->when($tenantId !== null, fn ($query) => $query->forTenantId($tenantId))->where('source_type', 'square_payment')->count(),
            'square_identity_reviews' => MarketingIdentityReview::query()
                ->when($tenantId !== null && Schema::hasColumn('marketing_identity_reviews', 'tenant_id'), fn ($query) => $query->where('tenant_id', $tenantId))
                ->whereIn('source_type', ['square_customer', 'square_order', 'square_payment'])
                ->count(),
            'square_only_profiles' => $squareOnlyProfiles,
            'square_only_missing_contact' => $squareOnlyMissingContact,
            'no_shopify_or_growave' => $noShopifyOrGrowave,
            'high_value_missing_contact' => $highValueMissingContact,
            'square_orders_without_customer_id' => SquareOrder::query()
                ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                ->where(function ($query): void {
                    $query->whereNull('square_customer_id')->orWhere('square_customer_id', '');
                })->count(),
            'square_payments_without_customer_id' => SquarePayment::query()
                ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                ->where(function ($query): void {
                    $query->whereNull('square_customer_id')->orWhere('square_customer_id', '');
                })->count(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function squarePayloadDiagnostics(?int $tenantId): array
    {
        $cacheKey = 'marketing:square-contact-quality:payload-diagnostics:'.($tenantId !== null ? 'tenant:'.$tenantId : 'global');

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($tenantId): array {
            $orders = [
                'total' => 0,
                'no_customer_id' => 0,
                'customer_details_email' => 0,
                'customer_details_phone' => 0,
                'pickup_recipient_name' => 0,
                'shipment_recipient_name' => 0,
                'tender_customer_id' => 0,
            ];

            foreach (SquareOrder::query()
                ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                ->select(['id', 'square_customer_id', 'raw_payload'])
                ->cursor() as $row) {
                $orders['total']++;
                $payload = is_array($row->raw_payload) ? $row->raw_payload : [];

                if (! filled($row->square_customer_id)) {
                    $orders['no_customer_id']++;
                }

                if (filled(data_get($payload, 'customer_details.email_address'))) {
                    $orders['customer_details_email']++;
                }

                if (filled(data_get($payload, 'customer_details.phone_number'))) {
                    $orders['customer_details_phone']++;
                }

                if (filled(data_get($payload, 'fulfillments.0.pickup_details.recipient.display_name'))) {
                    $orders['pickup_recipient_name']++;
                }

                if (filled(data_get($payload, 'fulfillments.0.shipment_details.recipient.display_name'))) {
                    $orders['shipment_recipient_name']++;
                }

                if (filled(data_get($payload, 'tenders.0.customer_id'))) {
                    $orders['tender_customer_id']++;
                }
            }

            $payments = [
                'total' => 0,
                'no_customer_id' => 0,
                'buyer_email' => 0,
                'cardholder_name' => 0,
                'billing_address_line_1' => 0,
            ];

            foreach (SquarePayment::query()
                ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                ->select(['id', 'square_customer_id', 'raw_payload'])
                ->cursor() as $row) {
                $payments['total']++;
                $payload = is_array($row->raw_payload) ? $row->raw_payload : [];

                if (! filled($row->square_customer_id)) {
                    $payments['no_customer_id']++;
                }

                if (filled(data_get($payload, 'buyer_email_address'))) {
                    $payments['buyer_email']++;
                }

                if (filled(data_get($payload, 'card_details.card.cardholder_name'))) {
                    $payments['cardholder_name']++;
                }

                if (filled(data_get($payload, 'billing_address.address_line_1'))) {
                    $payments['billing_address_line_1']++;
                }
            }

            return [
                'orders' => $orders,
                'payments' => $payments,
            ];
        });
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    protected function manualFollowUpOrders(int $minSpendCents, ?int $tenantId): Collection
    {
        return SquareOrder::query()
            ->with(['payments' => fn ($query) => $query->orderByDesc('created_at_source')])
            ->withCount('attributions')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->where(function ($query): void {
                $query->whereNull('square_customer_id')->orWhere('square_customer_id', '');
            })
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('marketing_profile_links as mpl')
                    ->whereColumn('mpl.source_id', 'square_orders.square_order_id')
                    ->where('mpl.source_type', 'square_order');
            })
            ->orderByDesc('total_money_amount')
            ->orderByDesc('closed_at')
            ->limit(15)
            ->get()
            ->map(function (SquareOrder $order) use ($minSpendCents): array {
                $cardholderName = $order->payments
                    ->map(fn (SquarePayment $payment): ?string => $this->nullableString(data_get($payment->raw_payload, 'card_details.card.cardholder_name')))
                    ->filter()
                    ->first();

                return [
                    'square_order_id' => (string) $order->square_order_id,
                    'source_name' => $order->source_name,
                    'location_id' => $order->location_id,
                    'closed_at' => optional($order->closed_at)?->toDateTimeString(),
                    'total_money_amount' => (int) ($order->total_money_amount ?? 0),
                    'is_high_value' => (int) ($order->total_money_amount ?? 0) >= $minSpendCents,
                    'attribution_count' => (int) ($order->attributions_count ?? 0),
                    'cardholder_name' => $cardholderName,
                    'square_customer_id' => $order->square_customer_id,
                ];
            });
    }

    protected function manualFollowUpOrdersCount(int $minSpendCents, ?int $tenantId): int
    {
        return SquareOrder::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->where(function ($query): void {
                $query->whereNull('square_customer_id')->orWhere('square_customer_id', '');
            })
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('marketing_profile_links as mpl')
                    ->whereColumn('mpl.source_id', 'square_orders.square_order_id')
                    ->where('mpl.source_type', 'square_order');
            })
            ->where('total_money_amount', '>=', $minSpendCents)
            ->count();
    }

    /**
     * @param  array<string,mixed>  $result
     */
    protected function workflowAutomationToastStyle(array $result): string
    {
        return (bool) ($result['ok'] ?? false) ? 'success' : 'warning';
    }

    /**
     * @param  array<string,mixed>  $result
     */
    protected function workflowAutomationToastMessage(string $submitAction, array $result): string
    {
        $label = $submitAction === 'run_now' ? 'Live run' : 'Dry run';
        $message = trim((string) ($result['message'] ?? ''));
        $counts = is_array($result['counts'] ?? null) ? (array) $result['counts'] : [];
        $dryRunCounts = is_array($result['dry_run_counts'] ?? null) ? (array) $result['dry_run_counts'] : [];

        $summaryParts = [];

        if ($counts !== []) {
            $summaryParts[] = sprintf(
                'fetched %d, processed %d, created %d, updated %d, unchanged %d, skipped %d, failed %d',
                (int) ($counts['fetched'] ?? 0),
                (int) ($counts['processed'] ?? 0),
                (int) ($counts['created'] ?? 0),
                (int) ($counts['updated'] ?? 0),
                (int) ($counts['unchanged'] ?? 0),
                (int) ($counts['skipped'] ?? 0),
                (int) ($counts['failed'] ?? 0)
            );
        }

        if ($dryRunCounts !== []) {
            $summaryParts[] = sprintf(
                'would create %d and update %d',
                (int) ($dryRunCounts['would_create'] ?? 0),
                (int) ($dryRunCounts['would_update'] ?? 0)
            );
        }

        if ($message !== '') {
            $summaryParts[] = $message;
        }

        $summary = $summaryParts !== []
            ? implode('. ', $summaryParts).'.'
            : 'No workflow diagnostics were returned.';

        return 'Workflow automation setup saved. '.$label.' '.((bool) ($result['ok'] ?? false) ? 'completed' : 'finished with issues').': '.$summary;
    }

    protected function currentTenantId(Request $request): ?int
    {
        foreach (['current_tenant_id', 'host_tenant_id'] as $attribute) {
            $tenantId = $request->attributes->get($attribute);
            if (is_numeric($tenantId) && (int) $tenantId > 0) {
                $resolved = (int) $tenantId;
                $request->attributes->set('current_tenant_id', $resolved);

                return $resolved;
            }
        }

        $sessionTenantId = $request->session()->get('tenant_id');
        if (is_numeric($sessionTenantId) && (int) $sessionTenantId > 0) {
            $resolved = (int) $sessionTenantId;
            $request->attributes->set('current_tenant_id', $resolved);

            return $resolved;
        }

        $user = $request->user();
        if ($user) {
            $tenantIds = $user->tenants()
                ->pluck('tenants.id')
                ->map(fn ($value): int => (int) $value)
                ->filter(fn (int $value): bool => $value > 0)
                ->values();

            if ($tenantIds->count() === 1) {
                $resolved = (int) $tenantIds->first();
                $request->attributes->set('current_tenant_id', $resolved);

                return $resolved;
            }
        }

        return null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    protected function assertMappingAccess(MarketingEventSourceMapping $mapping, int $tenantId): void
    {
        if ((int) ($mapping->tenant_id ?? 0) === $tenantId) {
            return;
        }

        abort(404);
    }

    /**
     * @return array<int,array{key:string,label:string,href:string,current:bool}>
     */
    protected function navigationItems(): array
    {
        $items = [];
        foreach (MarketingSectionRegistry::sections() as $key => $section) {
            $items[] = [
                'key' => $key,
                'label' => $section['label'],
                'href' => route($section['route']),
                'current' => request()->routeIs($section['route']) || request()->routeIs($section['route'].'.*'),
            ];
        }

        return $items;
    }
}

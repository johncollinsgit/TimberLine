<?php

namespace App\Http\Controllers;

use App\Jobs\RunAutomationWorkflowJob;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowRun;
use App\Models\IntegrationConnection;
use App\Services\Automation\AsanaWorkflowConnectionService;
use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\CalendarEventPresentationService;
use App\Services\Automation\CommerceWorkflowConnectionService;
use App\Services\Automation\GoogleCalendarWorkflowConnectionService;
use App\Services\Automation\V2\WorkflowComponentCatalog as WorkflowStudioComponentCatalog;
use App\Services\Automation\V2\WorkflowDraftService;
use App\Services\Automation\V2\WorkflowStudioBootstrapService;
use App\Services\Automation\V2\WorkflowStudioFeatureGate;
use App\Services\Automation\V2\WorkflowStudioProductService;
use App\Services\Automation\WorkflowProductService;
use App\Services\Automation\WorkflowTemplateCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WorkflowAutomationController extends Controller
{
    public function index(
        Request $request,
        WorkflowTemplateCatalog $catalog,
        WorkflowStudioComponentCatalog $studioCatalog,
        WorkflowStudioFeatureGate $featureGate,
    ): View {
        $tenantId = $this->tenantId($request);
        $studioEnabled = $featureGate->enabledForTenant($tenantId);
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', 'all'));
        $workflows = AutomationWorkflow::query()
            ->forTenantId($tenantId)
            ->with(['publishedVersion', 'runs' => fn ($query) => $query->latest()->limit(1)])
            ->withCount(['runs', 'runs as successful_runs_count' => fn ($query) => $query->where('status', 'success')])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->when(in_array($status, ['draft', 'active', 'paused'], true), fn ($query) => $query->where('status', $status))
            ->orderByRaw("case status when 'active' then 0 when 'draft' then 1 else 2 end")
            ->orderByDesc('updated_at')
            ->get();

        return view('workflows.index', compact('workflows', 'search', 'status') + [
            'templates' => $catalog->templates(),
            'providers' => $catalog->providers(),
            'studioComponents' => $studioCatalog->components(),
            'studioEnabled' => $studioEnabled,
        ]);
    }

    public function create(
        Request $request,
        WorkflowStudioBootstrapService $bootstrap,
        WorkflowStudioFeatureGate $featureGate,
        WorkflowTemplateCatalog $catalog,
    ): View {
        $tenantId = $this->tenantId($request);
        if (! $featureGate->enabledForTenant($tenantId)) {
            return view('workflows.create-legacy', [
                'templates' => $catalog->templates(),
                'providers' => $catalog->providers(),
            ]);
        }

        return view('workflows.create', [
            'workflowStudioBootstrap' => $bootstrap->forNew(
                $tenantId,
                (string) $request->query('picker', 'home'),
            ),
        ]);
    }

    public function store(Request $request, WorkflowProductService $service): RedirectResponse
    {
        $data = $request->validate(['template_key' => ['required', 'string', 'max:100']]);
        try {
            $workflow = $service->create($this->tenantId($request), (string) $data['template_key'], $request->user());
        } catch (AutomationWorkflowException $exception) {
            return back()->withInput()->with('toast', ['style' => 'warning', 'message' => $exception->getMessage()]);
        }

        return redirect()->route('workflows.show', $workflow)->with('toast', ['style' => 'success', 'message' => 'Workflow draft created. Connect each step, test it, and publish when ready.']);
    }

    public function show(
        Request $request,
        AutomationWorkflow $workflow,
        WorkflowDraftService $drafts,
        WorkflowStudioBootstrapService $bootstrap,
        WorkflowStudioFeatureGate $featureGate,
        WorkflowTemplateCatalog $catalog,
        AsanaWorkflowConnectionService $asana,
        GoogleCalendarWorkflowConnectionService $google,
        CalendarEventPresentationService $calendarPresentation,
        CommerceWorkflowConnectionService $commerceConnections,
    ): View {
        $this->assertOwned($request, $workflow);
        $tenantId = $this->tenantId($request);
        if (! $featureGate->enabledForTenant($tenantId)) {
            $definition = (array) $workflow->draft_definition;
            $isLegacy = (int) $workflow->definition_schema_version !== 2
                && (int) ($definition['schema_version'] ?? 1) !== 2;
            if (! $isLegacy) {
                return view('workflows.studio-disabled', [
                    'workflow' => $workflow->load([
                        'publishedVersion',
                        'runs' => fn ($query) => $query->latest()->limit(10),
                    ]),
                ]);
            }

            $workflow->load([
                'publishedVersion',
                'versions' => fn ($query) => $query->latest('version')->limit(8),
                'runs' => fn ($query) => $query->latest()->limit(10),
            ]);
            $sourceProvider = (string) data_get($definition, 'trigger.provider', 'asana');
            $calendarAppearance = $calendarPresentation->fromPayload(
                [],
                $sourceProvider,
                (array) data_get($definition, 'action.presentation', [])
            );

            return view('workflows.show-legacy', [
                'workflow' => $workflow,
                'template' => $catalog->template($workflow->template_key),
                'providers' => $catalog->providers(),
                'asanaConnection' => $asana->status($tenantId),
                'googleConnection' => $google->status($tenantId),
                'calendarAppearance' => $calendarAppearance,
                'commerceConnections' => IntegrationConnection::query()
                    ->forTenantId($tenantId)
                    ->where('provider', $sourceProvider)
                    ->where('status', IntegrationConnection::STATUS_CONNECTED)
                    ->orderBy('external_account_label')
                    ->get(),
                'commerceConnectionStatus' => in_array(
                    $sourceProvider,
                    CommerceWorkflowConnectionService::PROVIDERS,
                    true
                ) ? $commerceConnections->status($tenantId, $sourceProvider) : [],
            ]);
        }

        $loaded = $drafts->load($this->tenantId($request), (int) $workflow->getKey());
        $workflow = $loaded['workflow']->load([
            'publishedVersion',
            'versions' => fn ($query) => $query->latest('version')->limit(8),
            'runs' => fn ($query) => $query->latest()->limit(10),
        ]);

        return view('workflows.show', [
            'workflow' => $workflow,
            'workflowStudioBootstrap' => $bootstrap->forWorkflow($workflow, (array) $loaded['definition']),
            'convertedFromLegacy' => (bool) $loaded['converted_from_legacy'],
        ]);
    }

    public function update(Request $request, AutomationWorkflow $workflow, WorkflowProductService $service): RedirectResponse
    {
        $this->assertOwned($request, $workflow);
        $sourceProvider = (string) data_get($workflow->draft_definition, 'trigger.provider', 'asana');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'project_gid' => [$sourceProvider === 'asana' ? 'required' : 'nullable', 'string', 'max:120'],
            'trigger_connection_id' => [$sourceProvider === 'asana' ? 'nullable' : 'required', 'integer'],
            'schedule_source' => ['nullable', 'string', 'in:source_date,order_created,fulfillment,delivery,pickup'],
            'calendar_id' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'timezone'],
            'default_duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'skip_completed_tasks' => ['nullable', 'boolean'],
            'event_title_template' => ['required', 'string', 'max:160'],
            'event_description_fields' => ['nullable', 'array'],
            'event_description_fields.*' => ['string', 'in:notes,items,total,status,customer_contact,source_link'],
            'event_location_source' => ['required', 'string', 'in:none,shipping_address,billing_address,pickup_location'],
            'event_color_id' => ['nullable', 'string', 'in:1,2,3,4,5,6,7,8,9,10,11'],
            'event_availability' => ['required', 'string', 'in:busy,free'],
            'event_visibility' => ['required', 'string', 'in:default,private'],
            'event_reminders' => ['required', 'string', 'in:default,none'],
            'cancelled_order_behavior' => ['required', 'string', 'in:mark_cancelled,leave_unchanged'],
        ]);
        $service->updateDraft($workflow, $data + [
            'skip_completed_tasks' => $request->boolean('skip_completed_tasks'),
            'event_description_fields' => $request->input('event_description_fields', []),
        ], $request->user());

        return back()->with('toast', ['style' => 'success', 'message' => 'Draft saved. Retest both steps before publishing.']);
    }

    public function testTrigger(Request $request, AutomationWorkflow $workflow, WorkflowProductService $service): RedirectResponse
    {
        return $this->workflowAction($request, $workflow, fn () => $service->testTrigger($workflow, $request->user()), 'Asana trigger test passed.');
    }

    public function testAction(Request $request, AutomationWorkflow $workflow, WorkflowProductService $service): RedirectResponse
    {
        return $this->workflowAction($request, $workflow, fn () => $service->testAction($workflow, $request->user()), 'Google Calendar write-and-cleanup test passed.');
    }

    public function publish(Request $request, AutomationWorkflow $workflow, WorkflowProductService $service): RedirectResponse
    {
        return $this->workflowAction($request, $workflow, fn () => $service->publish($workflow, $request->user()), 'Workflow published and turned on.');
    }

    public function pause(Request $request, AutomationWorkflow $workflow, WorkflowProductService $service): RedirectResponse
    {
        return $this->workflowAction($request, $workflow, fn () => $service->pause($workflow, $request->user()), 'Workflow paused.');
    }

    public function resume(Request $request, AutomationWorkflow $workflow, WorkflowProductService $service): RedirectResponse
    {
        return $this->workflowAction($request, $workflow, fn () => $service->resume($workflow, $request->user()), 'Workflow turned on.');
    }

    public function runNow(Request $request, AutomationWorkflow $workflow): RedirectResponse
    {
        return $this->queueWorkflowRun($request, $workflow, 'manual', 'Workflow run queued. It will appear in history when the worker starts it.');
    }

    public function history(Request $request): View
    {
        $tenantId = $this->tenantId($request);
        $status = trim((string) $request->query('status', 'all'));
        $workflowId = (int) $request->query('workflow', 0);
        $runs = AutomationWorkflowRun::query()->forTenantId($tenantId)
            ->with('workflow:id,tenant_id,name,template_key')
            ->when($workflowId > 0, fn ($query) => $query->where('automation_workflow_id', $workflowId))
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest()->paginate(30)->withQueryString();

        return view('workflows.history', [
            'runs' => $runs,
            'status' => $status,
            'workflowId' => $workflowId,
            'workflows' => AutomationWorkflow::query()->forTenantId($tenantId)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function run(Request $request, AutomationWorkflowRun $run): View
    {
        if ((int) $run->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }

        return view('workflows.run', [
            'run' => $run->load(['workflow', 'version', 'steps', 'items.steps']),
        ]);
    }

    public function retry(
        Request $request,
        AutomationWorkflowRun $run,
        WorkflowStudioProductService $studio,
    ): RedirectResponse {
        if ((int) $run->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }

        $version = $run->version()->first();
        if ($version && (int) data_get($version->definition, 'schema_version', 1) === 2) {
            try {
                $count = $studio->retryRun($run, $request->user());
            } catch (AutomationWorkflowException $exception) {
                return back()->with('toast', [
                    'style' => 'warning',
                    'message' => $exception->getMessage(),
                ]);
            }

            return back()->with('toast', [
                'style' => 'success',
                'message' => "{$count} failed workflow item(s) queued from their last durable checkpoint.",
            ]);
        }

        return $this->queueWorkflowRun($request, $run->workflow, 'retry', 'Retry queued against the current published workflow.');
    }

    public function connections(
        Request $request,
        WorkflowTemplateCatalog $catalog,
        AsanaWorkflowConnectionService $asana,
        GoogleCalendarWorkflowConnectionService $google,
        CommerceWorkflowConnectionService $commerce,
        WorkflowStudioComponentCatalog $studioCatalog,
    ): View {
        $tenantId = $this->tenantId($request);
        $usage = [];
        AutomationWorkflow::query()->forTenantId($tenantId)->get(['id', 'name', 'draft_definition'])->each(
            function (AutomationWorkflow $workflow) use (&$usage, $studioCatalog): void {
                foreach ($this->definitionProviders((array) $workflow->draft_definition, $studioCatalog) as $provider) {
                    $usage[$provider][(int) $workflow->id] = [
                        'id' => $workflow->id,
                        'name' => $workflow->name,
                    ];
                }
            }
        );
        $usage = collect($usage)
            ->map(fn (array $workflows): array => array_values($workflows))
            ->all();

        $studioPublicCatalog = $studioCatalog->publicCatalog();
        $liveProviderKeys = collect((array) ($studioPublicCatalog['components'] ?? []))
            ->filter(fn (array $component): bool => (bool) ($component['connection_required'] ?? false))
            ->pluck('provider')
            ->unique()
            ->values();
        $roadmapProviders = collect((array) ($studioPublicCatalog['roadmap'] ?? []))
            ->filter(fn (array $component): bool => (bool) ($component['connection_required'] ?? false))
            ->groupBy('provider')
            ->map(fn ($components, string $provider): array => [
                'provider' => $provider,
                'label' => (string) data_get($components, '0.provider_label', str($provider)->headline()),
                'description' => (string) data_get($components, '0.description', 'Provider execution is not available yet.'),
            ])
            ->values()
            ->all();

        return view('workflows.connections', [
            'providers' => collect($catalog->providers())->only($liveProviderKeys->all())->all(),
            'roadmapProviders' => $roadmapProviders,
            'asanaConnection' => $asana->status($tenantId),
            'googleConnection' => $google->status($tenantId),
            'connections' => IntegrationConnection::query()->forTenantId($tenantId)->orderBy('provider')->get(),
            'usage' => $usage,
            'commerceStatuses' => $commerce->statuses($tenantId),
            'returnPath' => $this->connectionReturnPath($request),
        ]);
    }

    public function connectCommerce(Request $request, string $provider, CommerceWorkflowConnectionService $service): RedirectResponse
    {
        try {
            $url = $service->buildConnectUrl($this->tenantId($request), $request->user(), $provider, [
                'shop_domain' => $request->input('shop_domain'),
                'return_path' => $this->connectionReturnPath($request),
            ]);

            return redirect()->away($url);
        } catch (AutomationWorkflowException $exception) {
            return back()->withInput()->with('toast', ['style' => 'warning', 'message' => $exception->getMessage()]);
        }
    }

    public function commerceCallback(Request $request, string $provider, CommerceWorkflowConnectionService $service): RedirectResponse
    {
        try {
            $result = $service->handleCallback($provider, $request);

            return redirect((string) $result['return_path'])->with('toast', ['style' => 'success', 'message' => str($provider)->headline().' connected and ready to test.']);
        } catch (AutomationWorkflowException $exception) {
            return redirect()->route('workflows.connections')->with('toast', ['style' => 'warning', 'message' => $exception->getMessage()]);
        }
    }

    public function testCommerce(Request $request, string $provider, IntegrationConnection $connection, CommerceWorkflowConnectionService $service): RedirectResponse
    {
        $this->assertConnectionOwned($request, $connection, $provider);
        try {
            $service->test($connection);
        } catch (AutomationWorkflowException $exception) {
            return back()->with('toast', ['style' => 'warning', 'message' => $exception->getMessage()]);
        }

        return back()->with('toast', ['style' => 'success', 'message' => str($provider)->headline().' connection checked.']);
    }

    public function disconnectCommerce(Request $request, string $provider, IntegrationConnection $connection, CommerceWorkflowConnectionService $service, WorkflowProductService $workflows): RedirectResponse
    {
        $this->assertConnectionOwned($request, $connection, $provider);
        $service->disconnect($connection);
        $workflows->pauseForProvider($this->tenantId($request), $provider, $request->user());

        return back()->with('toast', ['style' => 'success', 'message' => str($provider)->headline().' disconnected. Workflows using this account were paused.']);
    }

    public function connectAsana(Request $request, AsanaWorkflowConnectionService $service): RedirectResponse
    {
        try {
            return redirect()->away($service->buildConnectUrl(
                $this->tenantId($request),
                $request->user(),
                returnPath: $this->connectionReturnPath($request),
            ));
        } catch (AutomationWorkflowException $exception) {
            return back()->with('toast', ['style' => 'warning', 'message' => $exception->getMessage()]);
        }
    }

    public function connectGoogle(Request $request, GoogleCalendarWorkflowConnectionService $service): RedirectResponse
    {
        try {
            return redirect()->away($service->buildConnectUrl(
                $this->tenantId($request),
                $request->user(),
                returnPath: $this->connectionReturnPath($request),
            ));
        } catch (AutomationWorkflowException $exception) {
            return back()->with('toast', ['style' => 'warning', 'message' => $exception->getMessage()]);
        }
    }

    public function testAsanaConnection(Request $request, AsanaWorkflowConnectionService $service): RedirectResponse
    {
        return $this->testConnection($request, 'asana', fn (int $tenantId): array => $service->projectOptions($tenantId, forceRefresh: true), 'Asana connection checked.');
    }

    public function testGoogleConnection(Request $request, GoogleCalendarWorkflowConnectionService $service): RedirectResponse
    {
        return $this->testConnection($request, 'google_calendar', fn (int $tenantId): array => $service->calendarOptions($tenantId, forceRefresh: true), 'Google Calendar connection checked.');
    }

    public function disconnectAsana(Request $request, AsanaWorkflowConnectionService $service, WorkflowProductService $workflows): RedirectResponse
    {
        $tenantId = $this->tenantId($request);
        $service->disconnect($tenantId);
        $workflows->pauseForProvider($tenantId, 'asana', $request->user());

        return back()->with('toast', ['style' => 'success', 'message' => 'Asana disconnected. Published workflows using it should remain paused until reconnected.']);
    }

    public function disconnectGoogle(Request $request, GoogleCalendarWorkflowConnectionService $service, WorkflowProductService $workflows): RedirectResponse
    {
        $tenantId = $this->tenantId($request);
        $service->disconnect($tenantId);
        $workflows->pauseForProvider($tenantId, 'google_calendar', $request->user());

        return back()->with('toast', ['style' => 'success', 'message' => 'Google Calendar disconnected.']);
    }

    protected function workflowAction(Request $request, AutomationWorkflow $workflow, callable $action, string $message): RedirectResponse
    {
        $this->assertOwned($request, $workflow);
        try {
            $action();
        } catch (AutomationWorkflowException $exception) {
            return back()->with('toast', ['style' => 'warning', 'message' => $exception->getMessage()]);
        }

        return back()->with('toast', ['style' => 'success', 'message' => $message]);
    }

    protected function queueWorkflowRun(Request $request, AutomationWorkflow $workflow, string $mode, string $message): RedirectResponse
    {
        $this->assertOwned($request, $workflow);
        if ($workflow->published_version_id === null) {
            return back()->with('toast', ['style' => 'warning', 'message' => 'Publish this workflow before running it.']);
        }

        dispatch(new RunAutomationWorkflowJob((int) $workflow->id, $mode, (int) $request->user()->id));

        return back()->with('toast', ['style' => 'success', 'message' => $message]);
    }

    protected function assertConnectionOwned(Request $request, IntegrationConnection $connection, string $provider): void
    {
        if ((int) $connection->tenant_id !== $this->tenantId($request) || (string) $connection->provider !== $provider) {
            abort(404);
        }
    }

    protected function testConnection(Request $request, string $provider, callable $test, string $message): RedirectResponse
    {
        $tenantId = $this->tenantId($request);
        $connection = IntegrationConnection::query()->forTenantId($tenantId)
            ->where('provider', $provider)
            ->where('status', IntegrationConnection::STATUS_CONNECTED)
            ->where(function ($query): void {
                $query->whereNotNull('access_token')
                    ->orWhereNotNull('refresh_token')
                    ->orWhereNotNull('external_account_secret');
            })
            ->latest('connected_at')
            ->latest('id')
            ->first();
        try {
            $options = $test($tenantId);
            $connection?->forceFill([
                'status' => IntegrationConnection::STATUS_CONNECTED,
                'last_synced_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
                'last_error_at' => null,
            ])->save();
        } catch (\Throwable $exception) {
            $connection?->forceFill([
                'last_error_code' => 'connection_test_failed',
                'last_error_message' => 'Connection test failed. Reconnect the account and try again.',
                'last_error_at' => now(),
            ])->save();

            return back()->with('toast', ['style' => 'warning', 'message' => 'Connection test failed. Reconnect the account and try again.']);
        }

        return back()->with('toast', ['style' => 'success', 'message' => $message.' '.count($options).' option(s) discovered.']);
    }

    protected function tenantId(Request $request): int
    {
        $tenantId = $request->attributes->get('current_tenant_id');
        if (! is_numeric($tenantId) || (int) $tenantId <= 0) {
            abort(403, 'A workspace is required to manage automations.');
        }

        return (int) $tenantId;
    }

    protected function assertOwned(Request $request, AutomationWorkflow $workflow): void
    {
        if ((int) $workflow->tenant_id !== $this->tenantId($request)) {
            abort(404);
        }
    }

    protected function connectionReturnPath(Request $request): string
    {
        $fallback = route('workflows.connections', absolute: false);
        $candidate = trim((string) $request->input('return_path', ''));
        if ($candidate === '') {
            return $fallback;
        }

        $parts = parse_url($candidate);
        if (
            $parts === false
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['fragment'])
        ) {
            return $fallback;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === route('workflows.create', absolute: false) && blank($parts['query'] ?? null)) {
            return $path;
        }
        if (preg_match('#^/workflows/([1-9][0-9]*)$#', $path, $matches) !== 1) {
            return $fallback;
        }

        $workflow = AutomationWorkflow::query()
            ->forAllTenants()
            ->where('tenant_id', $this->tenantId($request))
            ->find((int) $matches[1]);
        if (! $workflow) {
            return $fallback;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (array_diff(array_keys($query), ['step']) !== []) {
            return $fallback;
        }

        $stepId = trim((string) ($query['step'] ?? ''));
        if ($stepId !== '') {
            if (! Str::isUlid($stepId) || ! $this->definitionHasStep((array) $workflow->draft_definition, $stepId)) {
                return $fallback;
            }

            return route('workflows.show', $workflow, absolute: false).'?'.http_build_query([
                'step' => $stepId,
            ], '', '&', PHP_QUERY_RFC3986);
        }

        return route('workflows.show', $workflow, absolute: false);
    }

    /** @param array<string,mixed> $definition */
    protected function definitionHasStep(array $definition, string $stepId): bool
    {
        if ((string) data_get($definition, 'trigger.id') === $stepId) {
            return true;
        }

        $find = function (array $steps) use (&$find, $stepId): bool {
            foreach ($steps as $step) {
                if (! is_array($step)) {
                    continue;
                }
                if ((string) ($step['id'] ?? '') === $stepId) {
                    return true;
                }
                foreach ((array) data_get($step, 'config.branches', []) as $branch) {
                    if (is_array($branch) && $find((array) ($branch['steps'] ?? []))) {
                        return true;
                    }
                }
            }

            return false;
        };

        return $find((array) ($definition['steps'] ?? []));
    }

    /**
     * Resolve every connection-backed provider referenced by a workflow,
     * including actions nested inside v2 Paths branches.
     *
     * @param  array<string,mixed>  $definition
     * @return list<string>
     */
    protected function definitionProviders(
        array $definition,
        WorkflowStudioComponentCatalog $catalog,
    ): array {
        if ((int) ($definition['schema_version'] ?? 1) !== 2) {
            return collect(['trigger.provider', 'action.provider'])
                ->map(fn (string $path): string => trim((string) data_get($definition, $path)))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        $providers = [];
        $visitStep = function (array $step) use (&$visitStep, &$providers, $catalog): void {
            $componentKey = trim((string) ($step['component_key'] ?? ''));
            $component = $componentKey !== '' ? $catalog->component($componentKey) : null;
            if (is_array($component) && (bool) ($component['connection_required'] ?? false)) {
                $provider = trim((string) ($component['connection_provider'] ?? $component['provider'] ?? ''));
                if ($provider !== '') {
                    $providers[$provider] = true;
                }
            }

            $branches = data_get($step, 'config.branches');
            if (! is_array($branches)) {
                $branches = $step['branches'] ?? [];
            }
            foreach ((array) $branches as $branch) {
                if (! is_array($branch)) {
                    continue;
                }
                foreach ((array) ($branch['steps'] ?? []) as $nestedStep) {
                    if (is_array($nestedStep)) {
                        $visitStep($nestedStep);
                    }
                }
            }
        };

        if (is_array($definition['trigger'] ?? null)) {
            $visitStep($definition['trigger']);
        }
        foreach ((array) ($definition['steps'] ?? []) as $step) {
            if (is_array($step)) {
                $visitStep($step);
            }
        }

        return array_keys($providers);
    }
}

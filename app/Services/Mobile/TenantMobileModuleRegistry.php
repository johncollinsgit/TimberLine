<?php

namespace App\Services\Mobile;

use App\Models\FieldServiceJob;
use App\Models\MarketingProfile;
use App\Models\MessagingConversation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceAsset;
use App\Services\FieldService\QuickBooksOwnerReportingService;
use App\Services\Tenancy\TenantFinancialAccess;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TenantMobileModuleRegistry
{
    public const CONTRACT_VERSION = 2;

    public function __construct(
        protected TenantModuleAccessResolver $accessResolver,
        protected TenantFinancialAccess $financialAccess,
        protected QuickBooksOwnerReportingService $ownerReports,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function manifest(int $tenantId): array
    {
        $definitions = (array) config('module_catalog.modules', []);
        $mobileDefinitions = collect($definitions)
            ->filter(fn (mixed $definition): bool => is_array($definition)
                && in_array(strtolower((string) data_get($definition, 'mobile.status', 'hidden')), ['ready', 'beta'], true))
            ->all();
        $states = (array) ($this->accessResolver->resolveForTenant($tenantId, array_keys($mobileDefinitions))['modules'] ?? []);

        return collect($mobileDefinitions)
            ->map(function (array $definition, string $moduleKey) use ($states): ?array {
                $state = is_array($states[$moduleKey] ?? null) ? (array) $states[$moduleKey] : [];
                if (! ($state['enabled'] ?? false)) {
                    return null;
                }

                return [
                    'module_key' => $moduleKey,
                    'display_name' => (string) data_get($definition, 'mobile.display_name', ($state['label'] ?? $definition['display_name'] ?? Str::headline($moduleKey)).' Branch'),
                    'description' => (string) ($definition['description'] ?? ''),
                    'status' => (string) data_get($definition, 'mobile.status', 'hidden'),
                    'renderer' => (string) data_get($definition, 'mobile.renderer', 'list'),
                    'entry_screen' => (string) data_get($definition, 'mobile.entry_screen', 'index'),
                    'contract_version' => (int) data_get($definition, 'mobile.contract_version', self::CONTRACT_VERSION),
                    'min_app_version' => (string) data_get($definition, 'mobile.min_app_version', '1.0.0'),
                    'navigation' => [
                        'group' => (string) data_get($definition, 'mobile.navigation.group', 'work'),
                        'icon' => (string) data_get($definition, 'mobile.navigation.icon', 'grid-2x2'),
                        'position' => (int) data_get($definition, 'mobile.navigation.position', 100),
                    ],
                    'actions' => array_values(array_map('strval', (array) data_get($definition, 'mobile.actions', []))),
                ];
            })
            ->filter()
            ->sortBy(fn (array $module): int => (int) data_get($module, 'navigation.position', 100))
            ->values()
            ->all();
    }

    /** @return array<string,mixed> */
    public function screen(int $tenantId, string $moduleKey, ?User $user = null, ?string $rangeKey = null): array
    {
        $moduleKey = strtolower(trim($moduleKey));
        $module = collect($this->manifest($tenantId))->firstWhere('module_key', $moduleKey);
        abort_unless(is_array($module), 404);

        $screen = match ($moduleKey) {
            'customers' => $this->customersScreen($tenantId),
            'field_service' => $this->fieldServiceScreen($tenantId),
            'work_core' => $this->summaryScreen($module),
            'messaging' => $this->messagingScreen($tenantId),
            'reporting' => $this->reportingScreen($tenantId, $user, $rangeKey),
            'documents' => $this->documentsScreen($tenantId, $user),
            default => $this->summaryScreen($module),
        };

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'module' => $module,
            'screen' => $screen,
        ];
    }

    /** @return array<string,mixed> */
    protected function customersScreen(int $tenantId): array
    {
        $profiles = Schema::hasTable('marketing_profiles')
            ? MarketingProfile::query()->forTenantId($tenantId)
                ->select(['id', 'first_name', 'last_name', 'email', 'phone', 'updated_at'])
                ->latest('updated_at')->limit(30)->get()
            : collect();

        return [
            'id' => 'customers.index',
            'kind' => 'list',
            'title' => 'Customers',
            'refreshable' => true,
            'sections' => [[
                'type' => 'list',
                'items' => $profiles->map(fn (MarketingProfile $profile): array => [
                    'id' => (string) $profile->id,
                    'title' => trim($profile->first_name.' '.$profile->last_name) ?: ($profile->email ?: 'Customer'),
                    'subtitle' => trim(implode(' | ', array_filter([$profile->email, $profile->phone]))),
                    'icon' => 'user-round',
                ])->values(),
                'empty' => ['title' => 'No customers yet', 'message' => 'Customer records will appear here once they are added or imported.'],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    protected function fieldServiceScreen(int $tenantId): array
    {
        $jobs = Schema::hasTable('field_service_jobs')
            ? FieldServiceJob::query()->forTenantId($tenantId)
                ->withCount(['tasks', 'materials', 'photos'])
                ->latest('updated_at')->limit(30)->get()
            : collect();

        return [
            'id' => 'field-service.index',
            'kind' => 'list',
            'title' => 'Work',
            'refreshable' => true,
            'primary_action' => ['id' => 'create_job', 'label' => 'New job', 'icon' => 'plus'],
            'sections' => [[
                'type' => 'list',
                'items' => $jobs->map(fn (FieldServiceJob $job): array => [
                    'id' => (string) $job->id,
                    'title' => (string) $job->title,
                    'subtitle' => trim(implode(' | ', array_filter([$job->customer_name, Str::headline((string) $job->status)]))),
                    'badge' => Str::headline((string) $job->status),
                    'meta' => ['tasks' => $job->tasks_count, 'materials' => $job->materials_count, 'photos' => $job->photos_count],
                    'icon' => 'briefcase-business',
                ])->values(),
                'empty' => ['title' => 'No active jobs', 'message' => 'Create the first job when work is ready to schedule.'],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    protected function messagingScreen(int $tenantId): array
    {
        $conversations = Schema::hasTable('messaging_conversations')
            ? MessagingConversation::query()->forTenantId($tenantId)
                ->with('profile:id,first_name,last_name,email,phone')
                ->latest('last_message_at')->limit(30)->get()
            : collect();

        return [
            'id' => 'messaging.inbox',
            'kind' => 'list',
            'title' => 'Messages',
            'refreshable' => true,
            'sections' => [[
                'type' => 'list',
                'items' => $conversations->map(fn (MessagingConversation $conversation): array => [
                    'id' => (string) $conversation->id,
                    'title' => trim(($conversation->profile?->first_name ?? '').' '.($conversation->profile?->last_name ?? '')) ?: ($conversation->email ?: $conversation->phone ?: 'Conversation'),
                    'subtitle' => (string) ($conversation->last_message_preview ?: $conversation->subject ?: 'No preview available'),
                    'badge' => $conversation->unread_count > 0 ? $conversation->unread_count.' unread' : Str::headline((string) $conversation->channel),
                    'icon' => 'messages-square',
                ])->values(),
                'empty' => ['title' => 'Inbox is clear', 'message' => 'Customer conversations will appear here.'],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    protected function reportingScreen(int $tenantId, ?User $user, ?string $rangeKey): array
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $range = app(\App\Services\Dashboard\DashboardDateRange::class)->resolve($rangeKey);
        $completed = Schema::hasTable('field_service_jobs')
            ? FieldServiceJob::query()->forTenantId($tenantId)->whereNotNull('completed_at')->whereBetween('completed_at', [$range['starts_at'], $range['ends_at']])->count()
            : 0;
        $upcoming = Schema::hasTable('field_service_jobs')
            ? FieldServiceJob::query()->forTenantId($tenantId)->whereNotNull('scheduled_for')->where('scheduled_for', '>=', now())
                ->where('status', '!=', 'done')->with('assignedUser:id,name')->orderBy('scheduled_for')->limit(5)->get()
            : collect();
        $owner = $user instanceof User && $this->financialAccess->allows($user, $tenant);
        $report = $owner ? $this->ownerReports->report($tenant, $range['key'], false) : null;
        $metrics = [
            ['label' => 'Jobs completed', 'value' => number_format($completed), 'tone' => 'teal'],
            ['label' => 'Upcoming jobs', 'value' => number_format($upcoming->count()), 'tone' => 'blue'],
        ];
        if (is_array($report)) {
            $metrics[] = ['label' => 'Work billed', 'value' => '$'.number_format((float) data_get($report, 'cards.work_billed.amount', 0), 2), 'tone' => 'green'];
            $metrics[] = ['label' => 'Unpaid invoices', 'value' => '$'.number_format((float) data_get($report, 'cards.unpaid_invoices.amount', 0), 2), 'tone' => 'amber'];
            $metrics[] = ['label' => 'Contract labor', 'value' => data_get($report, 'cards.contract_labor.amount') === null ? 'Mapping needed' : '$'.number_format((float) data_get($report, 'cards.contract_labor.amount'), 2), 'tone' => 'neutral'];
        }

        return [
            'id' => 'reporting.overview',
            'kind' => 'dashboard',
            'title' => 'Reporting · '.$range['short_label'],
            'refreshable' => true,
            'range' => ['key' => $range['key'], 'options' => $range['options']],
            'sections' => [
                ['type' => 'metrics', 'items' => $metrics],
                ['type' => 'list', 'items' => $upcoming->map(fn (FieldServiceJob $job): array => [
                    'id' => (string) $job->id,
                    'title' => (string) $job->title,
                    'subtitle' => trim(implode(' | ', array_filter([
                        $job->scheduled_for?->format('M j, g:i A'),
                        trim(implode(', ', array_filter([$job->service_address_line_1, $job->service_city]))),
                        $job->assignedUser?->name,
                    ]))),
                    'badge' => 'Upcoming',
                    'icon' => 'calendar-days',
                ])->values(), 'empty' => ['title' => 'No upcoming jobs', 'message' => 'Scheduled jobs will appear here.']],
            ],
        ];
    }

    /** @return array<string,mixed> */
    protected function documentsScreen(int $tenantId, ?User $user): array
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $owner = $user instanceof User && $this->financialAccess->allows($user, $tenant);
        $assets = Schema::hasTable('workspace_assets')
            ? WorkspaceAsset::query()->forTenantId($tenantId)->with('jobs:id,title')
                ->when(! $owner, fn ($query) => $query->where('visibility', 'team'))
                ->latest()->limit(30)->get()
            : collect();

        return [
            'id' => 'documents.overview',
            'kind' => 'list',
            'title' => 'Documents',
            'refreshable' => true,
            'primary_action' => ['id' => 'upload_assets', 'label' => 'Add photos or files', 'icon' => 'upload'],
            'job_options' => FieldServiceJob::query()->forTenantId($tenantId)->latest('updated_at')->limit(100)->get(['id', 'title'])
                ->map(fn (FieldServiceJob $job): array => ['id' => (string) $job->id, 'title' => (string) $job->title])->values(),
            'sections' => [[
                'type' => 'list',
                'items' => $assets->map(fn (WorkspaceAsset $asset): array => [
                    'id' => (string) $asset->id,
                    'title' => (string) $asset->file_name,
                    'subtitle' => trim(implode(' | ', array_filter([
                        $asset->caption,
                        $asset->jobs->pluck('title')->take(2)->implode(', '),
                    ]))),
                    'badge' => $asset->visibility === 'owner' ? 'Owner only' : 'Team',
                    'icon' => str_starts_with((string) $asset->mime_type, 'image/') ? 'image' : 'file-text',
                ])->values(),
                'empty' => ['title' => 'No documents yet', 'message' => 'Choose photos from iCloud or upload files and link them to jobs.'],
            ]],
        ];
    }

    /** @param array<string,mixed> $module */
    protected function summaryScreen(array $module): array
    {
        return [
            'id' => ($module['module_key'] ?? 'module').'.index',
            'kind' => 'summary',
            'title' => (string) ($module['display_name'] ?? 'Branch'),
            'refreshable' => true,
            'sections' => [[
                'type' => 'notice',
                'tone' => 'neutral',
                'title' => (string) ($module['display_name'] ?? 'Branch'),
                'message' => (string) ($module['description'] ?? ''),
            ]],
        ];
    }
}

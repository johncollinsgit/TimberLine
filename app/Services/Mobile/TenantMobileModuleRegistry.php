<?php

namespace App\Services\Mobile;

use App\Models\FieldServiceJob;
use App\Models\MarketingProfile;
use App\Models\MessagingConversation;
use App\Models\Order;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TenantMobileModuleRegistry
{
    public const CONTRACT_VERSION = 2;

    public function __construct(
        protected TenantModuleAccessResolver $accessResolver
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
    public function screen(int $tenantId, string $moduleKey): array
    {
        $moduleKey = strtolower(trim($moduleKey));
        $module = collect($this->manifest($tenantId))->firstWhere('module_key', $moduleKey);
        abort_unless(is_array($module), 404);

        $screen = match ($moduleKey) {
            'customers' => $this->customersScreen($tenantId),
            'field_service' => $this->fieldServiceScreen($tenantId),
            'work_core' => $this->summaryScreen($module),
            'messaging' => $this->messagingScreen($tenantId),
            'reporting' => $this->reportingScreen($tenantId),
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
    protected function reportingScreen(int $tenantId): array
    {
        $customerCount = Schema::hasTable('marketing_profiles') ? MarketingProfile::query()->forTenantId($tenantId)->count() : 0;
        $orderCount = Schema::hasTable('orders') ? Order::query()->forTenantId($tenantId)->count() : 0;
        $revenue = Schema::hasTable('orders') ? (float) Order::query()->forTenantId($tenantId)->where('ordered_at', '>=', now()->subDays(30))->sum('total_price') : 0.0;

        return [
            'id' => 'reporting.overview',
            'kind' => 'dashboard',
            'title' => 'Reporting',
            'refreshable' => true,
            'sections' => [[
                'type' => 'metrics',
                'items' => [
                    ['label' => 'Customers', 'value' => number_format($customerCount), 'tone' => 'teal'],
                    ['label' => 'Orders', 'value' => number_format($orderCount), 'tone' => 'blue'],
                    ['label' => 'Revenue (30D)', 'value' => '$'.number_format($revenue, 2), 'tone' => 'green'],
                ],
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

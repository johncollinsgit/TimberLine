<?php

namespace App\Services\Search\Providers;

use App\Models\IntegrationHealthEvent;
use App\Models\MarketingStorefrontEvent;
use App\Services\Search\Concerns\BuildsSearchResults;
use App\Services\Search\GlobalSearchProvider;
use Illuminate\Support\Facades\Schema;

class EventsSearchProvider implements GlobalSearchProvider
{
    use BuildsSearchResults;

    public function search(string $query, array $context = []): array
    {
        $user = $context['user'] ?? null;
        if (! $user || ! method_exists($user, 'canAccessMarketing') || ! $user->canAccessMarketing()) {
            return [];
        }

        $tenantId = is_numeric($context['tenant_id'] ?? null) ? (int) $context['tenant_id'] : null;
        if ($tenantId === null) {
            return [];
        }

        $normalized = trim($query);
        $results = [];

        if (Schema::hasTable('marketing_storefront_events')) {
            $rows = MarketingStorefrontEvent::query()
                ->forTenantId($tenantId)
                ->select(['id', 'event_type', 'status', 'issue_type', 'source_surface'])
                ->when($normalized !== '', function ($builder) use ($normalized): void {
                    $builder->where(function ($query) use ($normalized): void {
                        $like = '%'.$normalized.'%';
                        $query->where('event_type', 'like', $like)
                            ->orWhere('status', 'like', $like)
                            ->orWhere('issue_type', 'like', $like)
                            ->orWhere('source_surface', 'like', $like);
                    });
                })
                ->limit(4)
                ->get();

            foreach ($rows as $row) {
                $title = trim((string) ($row->event_type ?: 'Storefront event'));
                $results[] = $this->result([
                    'type' => 'event',
                    'subtype' => 'storefront',
                    'title' => $title,
                    'subtitle' => trim(implode(' • ', array_filter([(string) $row->status, (string) $row->issue_type, (string) $row->source_surface]))),
                    'url' => route('marketing.operations.reconciliation'),
                    'badge' => 'Event',
                    'score' => $this->matchScore($normalized, [$title, $row->issue_type, $row->source_surface], 170),
                    'icon' => 'bolt',
                    'meta' => [
                        'event_id' => (int) $row->id,
                    ],
                ]);
            }
        }

        if (Schema::hasTable('integration_health_events')) {
            $rows = IntegrationHealthEvent::query()
                ->forTenantId($tenantId)
                ->select(['id', 'provider', 'event_type', 'severity', 'status'])
                ->when($normalized !== '', function ($builder) use ($normalized): void {
                    $builder->where(function ($query) use ($normalized): void {
                        $like = '%'.$normalized.'%';
                        $query->where('provider', 'like', $like)
                            ->orWhere('event_type', 'like', $like)
                            ->orWhere('severity', 'like', $like)
                            ->orWhere('status', 'like', $like);
                    });
                })
                ->limit(3)
                ->get();

            foreach ($rows as $row) {
                $title = trim((string) ($row->provider ?: 'Integration health'));
                $results[] = $this->result([
                    'type' => 'event',
                    'subtype' => 'integration_health',
                    'title' => $title,
                    'subtitle' => trim(implode(' • ', array_filter([(string) $row->event_type, (string) $row->severity, (string) $row->status]))),
                    'url' => route('marketing.providers-integrations'),
                    'badge' => 'Health',
                    'score' => $this->matchScore($normalized, [$title, $row->event_type, $row->status], 160),
                    'icon' => 'shield-exclamation',
                    'meta' => [
                        'event_id' => (int) $row->id,
                    ],
                ]);
            }
        }

        return $results;
    }
}

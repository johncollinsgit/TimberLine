<?php

namespace App\Services\Search\Providers;

use App\Models\MarketingImportRun;
use App\Models\ShopifyImportRun;
use App\Models\User;
use App\Services\Search\Concerns\BuildsSearchResults;
use App\Services\Search\GlobalSearchProvider;
use Illuminate\Support\Facades\Schema;

class ImportsSearchProvider implements GlobalSearchProvider
{
    use BuildsSearchResults;

    public function search(string $query, array $context = []): array
    {
        $tenantId = is_numeric($context['tenant_id'] ?? null) ? (int) $context['tenant_id'] : null;
        $user = $context['user'] ?? null;
        $normalized = trim($query);
        $results = [];

        if ($tenantId !== null && $user && method_exists($user, 'canAccessMarketing') && $user->canAccessMarketing() && Schema::hasTable('marketing_import_runs')) {
            $rows = MarketingImportRun::query()
                ->forTenantId($tenantId)
                ->select(['id', 'type', 'status', 'source_label', 'file_name'])
                ->when($normalized !== '', function ($builder) use ($normalized): void {
                    $builder->where(function ($query) use ($normalized): void {
                        $like = '%'.$normalized.'%';
                        $query->where('type', 'like', $like)
                            ->orWhere('status', 'like', $like)
                            ->orWhere('source_label', 'like', $like)
                            ->orWhere('file_name', 'like', $like);
                    });
                })
                ->limit(4)
                ->get();

            foreach ($rows as $row) {
                $title = trim((string) ($row->source_label ?: $row->type ?: 'Import run'));
                $results[] = $this->result([
                    'type' => 'import',
                    'subtype' => 'marketing_run',
                    'title' => $title,
                    'subtitle' => trim(implode(' • ', array_filter([(string) $row->status, (string) $row->file_name]))),
                    'url' => route('marketing.providers-integrations'),
                    'badge' => 'Import',
                    'score' => $this->matchScore($normalized, [$title, $row->file_name, $row->status], 210),
                    'icon' => 'arrow-down-tray',
                    'meta' => [
                        'run_id' => (int) $row->id,
                    ],
                ]);
            }
        }

        if ($user instanceof User && ($user->isAdmin() || $user->isManager()) && Schema::hasTable('shopify_import_runs')) {
            $rows = ShopifyImportRun::query()
                ->select(['id', 'store_key', 'source', 'mapping_exceptions_count'])
                ->when($normalized !== '', function ($builder) use ($normalized): void {
                    $builder->where(function ($query) use ($normalized): void {
                        $like = '%'.$normalized.'%';
                        $query->where('store_key', 'like', $like)
                            ->orWhere('source', 'like', $like);
                    });
                })
                ->limit(3)
                ->get();

            foreach ($rows as $row) {
                $title = trim((string) ($row->store_key ?: 'Shopify import'));
                $results[] = $this->result([
                    'type' => 'import',
                    'subtype' => 'shopify_run',
                    'title' => $title,
                    'subtitle' => trim(implode(' • ', array_filter([(string) $row->source, (int) $row->mapping_exceptions_count > 0 ? $row->mapping_exceptions_count.' exceptions' : 'clean run']))),
                    'url' => route('admin.import-runs'),
                    'badge' => 'Shopify import',
                    'score' => $this->matchScore($normalized, [$title, $row->source], 180),
                    'icon' => 'arrow-path',
                    'meta' => [
                        'run_id' => (int) $row->id,
                    ],
                ]);
            }
        }

        return $results;
    }
}

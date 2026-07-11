<?php

namespace App\Services\Search\Providers;

use App\Models\FieldServiceJob;
use App\Services\Search\Concerns\BuildsSearchResults;
use App\Services\Search\GlobalSearchProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class FieldServiceSearchProvider implements GlobalSearchProvider
{
    use BuildsSearchResults;

    public function search(string $query, array $context = []): array
    {
        $user = $context['user'] ?? null;
        if (! $user || ! method_exists($user, 'canAccessMarketing') || ! $user->canAccessMarketing()) {
            return [];
        }

        $tenantId = is_numeric($context['tenant_id'] ?? null) ? (int) $context['tenant_id'] : null;
        if ($tenantId === null || ! Schema::hasTable('field_service_jobs')) {
            return [];
        }

        $normalized = trim($query);
        $rows = FieldServiceJob::query()
            ->forTenantId($tenantId)
            ->with(['notes'])
            ->when($normalized !== '', function (Builder $builder) use ($normalized): void {
                $like = '%'.$normalized.'%';
                $builder->where(function (Builder $query) use ($like): void {
                    $query->where('title', 'like', $like)
                        ->orWhere('customer_name', 'like', $like)
                        ->orWhere('customer_email', 'like', $like)
                        ->orWhere('customer_phone', 'like', $like)
                        ->orWhere('lock_box_code', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('service_address_line_1', 'like', $like)
                        ->orWhere('service_city', 'like', $like)
                        ->orWhereHas('notes', fn (Builder $notes) => $notes->where('body', 'like', $like));
                });
            })
            ->latest('updated_at')
            ->limit(6)
            ->get();

        return $rows->map(function (FieldServiceJob $job) use ($normalized): array {
            $address = trim(implode(', ', array_filter([
                $job->service_address_line_1,
                $job->service_city,
                $job->service_state,
            ])));
            $latestNote = optional($job->notes->sortByDesc('noted_at')->first())->body;

            return $this->result([
                'type' => 'work',
                'subtype' => 'field_service_job',
                'title' => (string) $job->title,
                'subtitle' => trim(implode(' • ', array_filter([
                    (string) ($job->customer_name ?: 'Service job'),
                    $job->customer_phone,
                    $address,
                    $latestNote ? str($latestNote)->limit(80)->toString() : null,
                ]))),
                'url' => route('field-service.jobs.show', ['job' => $job->id]),
                'badge' => 'Job',
                'score' => $this->matchScore($normalized, [
                    $job->title,
                    $job->customer_name,
                    $job->customer_phone,
                    $job->service_address_line_1,
                    $job->lock_box_code,
                    $latestNote,
                ], 300),
                'icon' => 'briefcase-business',
                'meta' => [
                    'job_id' => (int) $job->id,
                    'destination' => 'work',
                    'kind' => 'jobs',
                ],
            ]);
        })->all();
    }
}

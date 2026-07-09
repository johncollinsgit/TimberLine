<?php

namespace Database\Seeders;

use App\Models\ClientProject;
use App\Models\ClientProjectTicket;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ModernForestryAppFeedbackSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/modern_forestry_app_feedback_seed.json');
        if (! File::exists($path)) {
            return;
        }

        $seed = json_decode(File::get($path), true);
        if (! is_array($seed)) {
            return;
        }

        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'modern-forestry'],
            ['name' => 'Modern Forestry']
        );

        $project = ClientProject::query()->firstOrNew([
            'tenant_id' => (int) $tenant->id,
            'title' => 'Modern Forestry App Request Board',
        ]);

        $project->fill([
            'summary' => 'Localized request-management board for Modern Forestry iOS app beta feedback, login hardening follow-ups, social sign-in QA, and app roadmap requests.',
            'status' => 'in_progress',
            'health' => 'watching',
            'sort_order' => 0,
            'metadata' => [
                'source' => 'modern_forestry_app_feedback_seed',
                'board' => (string) ($seed['board'] ?? 'Modern Forestry App Feedback & Bug Tracker'),
                'generated' => (string) ($seed['generated'] ?? ''),
            ],
        ])->save();

        $source = (string) ($seed['source'] ?? 'Modern Forestry app feedback seed');
        $statusLegend = is_array($seed['statusLegend'] ?? null) ? $seed['statusLegend'] : [];
        $backendState = is_array($seed['backendState'] ?? null) ? $seed['backendState'] : [];

        foreach (($seed['tickets'] ?? []) as $ticket) {
            if (! is_array($ticket)) {
                continue;
            }

            $sourceTicketId = is_numeric($ticket['id'] ?? null) ? (int) $ticket['id'] : null;
            $title = $this->text($ticket['title'] ?? 'Untitled Modern Forestry app request', 190);
            $status = $this->statusFor($ticket['status'] ?? null);
            $type = $this->typeFor($ticket['type'] ?? null);

            $query = ClientProjectTicket::query()
                ->where('tenant_id', (int) $tenant->id)
                ->where('client_project_id', (int) $project->id);

            if ($sourceTicketId !== null) {
                $query->where(function ($query) use ($sourceTicketId, $title): void {
                    $query
                        ->where('metadata->modern_forestry_feedback_source_ticket_id', $sourceTicketId)
                        ->orWhere('title', $title);
                });
            } else {
                $query->where('title', $title);
            }

            $projectTicket = $query->first() ?? new ClientProjectTicket([
                'tenant_id' => (int) $tenant->id,
                'client_project_id' => (int) $project->id,
            ]);

            $projectTicket->fill([
                'type' => $type,
                'title' => $title,
                'problem_summary' => $this->problemSummary($ticket, $source),
                'desired_outcome' => $this->nullableText($ticket['resolution'] ?? null, 5000),
                'scope_notes' => $this->nullableText($ticket['resolution'] ?? null, 5000),
                'urgency' => $status === 'in_progress' ? 'high' : 'normal',
                'priority' => in_array($status, ['done', 'in_review'], true) ? 'high' : 'normal',
                'status' => $status,
                'customer_visible' => true,
                'landlord_notes' => null,
                'metadata' => [
                    'source' => 'modern_forestry_app_feedback_seed',
                    'board' => (string) ($seed['board'] ?? ''),
                    'source_ticket_id' => $sourceTicketId,
                    'modern_forestry_feedback_source_ticket_id' => $sourceTicketId,
                    'source_type' => (string) ($ticket['type'] ?? ''),
                    'source_status' => (string) ($ticket['status'] ?? ''),
                    'reporters' => array_values(array_filter((array) ($ticket['reporters'] ?? []))),
                    'status_legend' => $statusLegend,
                    'backend_state' => $backendState,
                ],
            ])->save();
        }
    }

    protected function typeFor(mixed $type): string
    {
        return match ((string) $type) {
            'feature-request' => 'feature',
            'bug', 'task' => 'app_request',
            'improvement' => 'change_request',
            'risk' => 'question',
            default => 'app_request',
        };
    }

    protected function statusFor(mixed $status): string
    {
        return match ((string) $status) {
            'fixed' => 'done',
            'in-progress' => 'in_progress',
            'needs-qa' => 'in_review',
            'planned' => 'scoped',
            'under-consideration' => 'needs_discovery',
            default => 'needs_discovery',
        };
    }

    /**
     * @param  array<string,mixed>  $ticket
     */
    protected function problemSummary(array $ticket, string $source): string
    {
        $detail = $this->text($ticket['detail'] ?? '', 4200);
        $reporters = array_values(array_filter((array) ($ticket['reporters'] ?? [])));
        $reporterLine = $reporters === []
            ? null
            : 'Reporters: '.implode(', ', array_map(static fn (mixed $reporter): string => (string) $reporter, $reporters));

        return collect([$detail, $reporterLine, 'Source: '.$source])
            ->filter()
            ->implode("\n\n");
    }

    protected function text(mixed $value, int $limit): string
    {
        return Str::limit(trim((string) $value), $limit, '');
    }

    protected function nullableText(mixed $value, int $limit): ?string
    {
        $text = $this->text($value, $limit);

        return $text !== '' ? $text : null;
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\ClientProject;
use App\Models\ClientProjectMilestone;
use App\Models\ClientProjectPhase;
use App\Models\ClientProjectTicket;
use App\Models\ClientProjectTicketReference;
use App\Models\ClientProjectTicketTask;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientProjectTicketController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $this->tenant($request);

        $tickets = ClientProjectTicket::query()
            ->forTenantId((int) $tenant->id)
            ->where('customer_visible', true)
            ->with(['project', 'phase', 'milestone', 'tasks', 'references'])
            ->latest('id')
            ->get();

        return view('client.projects.requests.index', [
            'tenant' => $tenant,
            'tickets' => $tickets,
            'statusLabels' => $this->statusLabels(),
            'typeLabels' => $this->typeLabels(),
        ]);
    }

    public function create(Request $request, ClientProject $project): View
    {
        $tenant = $this->tenant($request);
        $this->assertProject($tenant, $project);

        $project->load(['phases', 'milestones']);

        return view('client.projects.requests.create', [
            'tenant' => $tenant,
            'project' => $project,
            'typeLabels' => $this->typeLabels(),
            'urgencyLabels' => $this->urgencyLabels(),
            'priorityLabels' => $this->priorityLabels(),
        ]);
    }

    public function store(Request $request, ClientProject $project): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->assertProject($tenant, $project);

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(ClientProjectTicket::TYPES)],
            'title' => ['required', 'string', 'max:190'],
            'problem_summary' => ['required', 'string', 'max:5000'],
            'desired_outcome' => ['nullable', 'string', 'max:5000'],
            'scope_notes' => ['nullable', 'string', 'max:5000'],
            'urgency' => ['required', 'string', Rule::in(ClientProjectTicket::URGENCIES)],
            'priority' => ['required', 'string', Rule::in(array_keys($this->priorityLabels()))],
            'client_project_phase_id' => ['nullable', 'integer'],
            'client_project_milestone_id' => ['nullable', 'integer'],
            'task_titles' => ['nullable', 'string', 'max:3000'],
            'reference_label' => ['nullable', 'string', 'max:190'],
            'reference_url' => ['nullable', 'url', 'max:500'],
            'reference_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $phase = $this->phaseForProject($tenant, $project, $validated['client_project_phase_id'] ?? null);
        $milestone = $this->milestoneForProject($tenant, $project, $validated['client_project_milestone_id'] ?? null);

        $ticket = ClientProjectTicket::query()->create([
            'tenant_id' => (int) $tenant->id,
            'client_project_id' => (int) $project->id,
            'client_project_phase_id' => $phase?->id,
            'client_project_milestone_id' => $milestone?->id,
            'requested_by_user_id' => $request->user()?->id,
            'type' => (string) $validated['type'],
            'title' => $this->text($validated['title'] ?? '', 190),
            'problem_summary' => $this->text($validated['problem_summary'] ?? '', 5000),
            'desired_outcome' => $this->nullableText($validated['desired_outcome'] ?? null, 5000),
            'scope_notes' => $this->nullableText($validated['scope_notes'] ?? null, 5000),
            'urgency' => (string) $validated['urgency'],
            'priority' => (string) $validated['priority'],
            'status' => 'new',
            'customer_visible' => true,
        ]);

        $this->storeTaskLines($ticket, $phase, (string) ($validated['task_titles'] ?? ''));
        $this->storeReference($ticket, $validated);

        return redirect()
            ->route('client.projects.requests.show', ['ticket' => $ticket])
            ->with('status', 'Project request submitted.');
    }

    public function show(Request $request, ClientProjectTicket $ticket): View
    {
        $tenant = $this->tenant($request);
        abort_unless((int) $ticket->tenant_id === (int) $tenant->id && $ticket->customer_visible, 404);

        $ticket->load(['project', 'phase', 'milestone', 'tasks', 'references']);

        return view('client.projects.requests.show', [
            'tenant' => $tenant,
            'ticket' => $ticket,
            'statusLabels' => $this->statusLabels(),
            'typeLabels' => $this->typeLabels(),
            'urgencyLabels' => $this->urgencyLabels(),
            'priorityLabels' => $this->priorityLabels(),
        ]);
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    protected function assertProject(Tenant $tenant, ClientProject $project): void
    {
        abort_unless((int) $project->tenant_id === (int) $tenant->id, 404);
    }

    protected function phaseForProject(Tenant $tenant, ClientProject $project, mixed $phaseId): ?ClientProjectPhase
    {
        if (! is_numeric($phaseId)) {
            return null;
        }

        $phase = ClientProjectPhase::query()
            ->where('tenant_id', (int) $tenant->id)
            ->where('client_project_id', (int) $project->id)
            ->find((int) $phaseId);

        if (! $phase) {
            throw ValidationException::withMessages([
                'client_project_phase_id' => 'Selected phase is outside this project.',
            ]);
        }

        return $phase;
    }

    protected function milestoneForProject(Tenant $tenant, ClientProject $project, mixed $milestoneId): ?ClientProjectMilestone
    {
        if (! is_numeric($milestoneId)) {
            return null;
        }

        $milestone = ClientProjectMilestone::query()
            ->where('tenant_id', (int) $tenant->id)
            ->where('client_project_id', (int) $project->id)
            ->find((int) $milestoneId);

        if (! $milestone) {
            throw ValidationException::withMessages([
                'client_project_milestone_id' => 'Selected milestone is outside this project.',
            ]);
        }

        return $milestone;
    }

    protected function storeTaskLines(ClientProjectTicket $ticket, ?ClientProjectPhase $phase, string $taskLines): void
    {
        collect(preg_split('/\r\n|\r|\n/', $taskLines) ?: [])
            ->map(fn (string $line): string => $this->text($line, 190))
            ->filter()
            ->take(12)
            ->values()
            ->each(function (string $title, int $index) use ($ticket, $phase): void {
                ClientProjectTicketTask::query()->create([
                    'tenant_id' => (int) $ticket->tenant_id,
                    'client_project_ticket_id' => (int) $ticket->id,
                    'client_project_phase_id' => $phase?->id,
                    'title' => $title,
                    'owner_type' => 'evergrove',
                    'status' => 'open',
                    'sort_order' => $index,
                ]);
            });
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    protected function storeReference(ClientProjectTicket $ticket, array $validated): void
    {
        $label = $this->nullableText($validated['reference_label'] ?? null, 190);
        $url = $this->nullableText($validated['reference_url'] ?? null, 500);
        $notes = $this->nullableText($validated['reference_notes'] ?? null, 2000);

        if ($label === null && $url === null && $notes === null) {
            return;
        }

        ClientProjectTicketReference::query()->create([
            'tenant_id' => (int) $ticket->tenant_id,
            'client_project_ticket_id' => (int) $ticket->id,
            'label' => $label ?: 'Reference',
            'url' => $url,
            'reference_type' => $url !== null ? 'link' : 'note',
            'notes' => $notes,
        ]);
    }

    /**
     * @return array<string,string>
     */
    protected function statusLabels(): array
    {
        return collect(ClientProjectTicket::STATUSES)
            ->mapWithKeys(static fn (string $status): array => [$status => Str::headline(str_replace('_', ' ', $status))])
            ->all();
    }

    /**
     * @return array<string,string>
     */
    protected function typeLabels(): array
    {
        return [
            'feature' => 'Feature request',
            'app_request' => 'App request',
            'change_request' => 'Change request',
            'question' => 'Question',
        ];
    }

    /**
     * @return array<string,string>
     */
    protected function urgencyLabels(): array
    {
        return [
            'low' => 'Low',
            'normal' => 'Normal',
            'high' => 'High',
            'urgent' => 'Urgent',
        ];
    }

    /**
     * @return array<string,string>
     */
    protected function priorityLabels(): array
    {
        return [
            'low' => 'Low',
            'normal' => 'Normal',
            'high' => 'High',
        ];
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

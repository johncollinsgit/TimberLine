<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\ClientProject;
use App\Models\ClientProjectTicket;
use App\Models\Tenant;
use Database\Seeders\ModernForestryAppFeedbackSeeder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ModernForestryAppFeedbackController extends Controller
{
    public function index(Request $request): View
    {
        [$tenant, $project] = $this->board();

        $tickets = ClientProjectTicket::query()
            ->where('tenant_id', (int) $tenant->id)
            ->where('client_project_id', (int) $project->id)
            ->where('customer_visible', true)
            ->latest('id')
            ->get();

        return view('marketing.public.modern-forestry-app-feedback', [
            'project' => $project,
            'tickets' => $tickets,
            'columns' => $this->columns($tickets),
            'formAction' => '',
            'storefrontUrl' => 'https://theforestrystudio.com',
        ]);
    }

    public function store(Request $request): View
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'detail' => ['required', 'string', 'max:2500'],
            'request_type' => ['required', 'string', Rule::in(['feature', 'bug', 'improvement', 'question'])],
            'name' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:190'],
            'website' => ['nullable', 'max:0'],
        ]);

        [$tenant, $project] = $this->board();

        $type = match ((string) $validated['request_type']) {
            'feature' => 'feature',
            'bug' => 'app_request',
            'improvement' => 'change_request',
            'question' => 'question',
            default => 'app_request',
        };

        $name = $this->nullableText($validated['name'] ?? null, 80);
        $email = $this->nullableText($validated['email'] ?? null, 190);

        ClientProjectTicket::query()->create([
            'tenant_id' => (int) $tenant->id,
            'client_project_id' => (int) $project->id,
            'type' => $type,
            'title' => $this->text($validated['title'] ?? '', 190),
            'problem_summary' => $this->problemSummary($validated, $name, $email),
            'desired_outcome' => $this->nullableText($validated['detail'] ?? null, 2500),
            'scope_notes' => null,
            'urgency' => 'normal',
            'priority' => 'normal',
            'status' => 'new',
            'customer_visible' => true,
            'metadata' => [
                'source' => 'modern_forestry_public_feedback_board',
                'submitter_name' => $name,
                'submitter_email' => $email,
                'request_type' => (string) $validated['request_type'],
                'submitted_via' => 'shopify_app_proxy',
                'shop' => $this->nullableText($request->query('shop'), 190),
            ],
        ]);

        $tickets = ClientProjectTicket::query()
            ->where('tenant_id', (int) $tenant->id)
            ->where('client_project_id', (int) $project->id)
            ->where('customer_visible', true)
            ->latest('id')
            ->get();

        return view('marketing.public.modern-forestry-app-feedback', [
            'project' => $project,
            'tickets' => $tickets,
            'columns' => $this->columns($tickets),
            'formAction' => '',
            'storefrontUrl' => 'https://theforestrystudio.com',
            'status' => 'Thanks. Your request was added to the board.',
        ]);
    }

    /**
     * @return array{0:Tenant,1:ClientProject}
     */
    protected function board(): array
    {
        app(ModernForestryAppFeedbackSeeder::class)->run();

        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'modern-forestry'],
            ['name' => 'Modern Forestry']
        );

        $project = ClientProject::query()->firstOrCreate(
            [
                'tenant_id' => (int) $tenant->id,
                'title' => 'Modern Forestry App Request Board',
            ],
            [
                'summary' => 'Customer-visible Modern Forestry app feature request and changelog board.',
                'status' => 'in_progress',
                'health' => 'watching',
                'metadata' => ['source' => 'modern_forestry_public_feedback_board'],
            ]
        );

        return [$tenant, $project];
    }

    /**
     * @param  iterable<ClientProjectTicket>  $tickets
     * @return array<string,array{label:string,description:string,tickets:\Illuminate\Support\Collection<int,ClientProjectTicket>}>
     */
    protected function columns(iterable $tickets): array
    {
        $collection = collect($tickets);

        return [
            'working' => [
                'label' => 'Being worked on',
                'description' => 'Active fixes, QA, and scoped changes.',
                'tickets' => $collection->filter(fn (ClientProjectTicket $ticket): bool => in_array((string) $ticket->status, ['in_progress', 'in_review', 'scoped'], true))->values(),
            ],
            'considering' => [
                'label' => 'Under consideration',
                'description' => 'Requests we are reviewing or newly received ideas.',
                'tickets' => $collection->filter(fn (ClientProjectTicket $ticket): bool => in_array((string) $ticket->status, ['new', 'needs_discovery'], true))->values(),
            ],
            'done' => [
                'label' => 'Done',
                'description' => 'Completed fixes and shipped app improvements.',
                'tickets' => $collection->filter(fn (ClientProjectTicket $ticket): bool => (string) $ticket->status === 'done')->values(),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    protected function problemSummary(array $validated, ?string $name, ?string $email): string
    {
        $lines = [
            $this->text($validated['detail'] ?? '', 2500),
            $name !== null ? 'Submitted by: '.$name : null,
            $email !== null ? 'Contact: '.$email : null,
            'Source: Modern Forestry public app feedback board',
        ];

        return collect($lines)->filter()->implode("\n\n");
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

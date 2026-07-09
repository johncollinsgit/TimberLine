<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\ClientProject;
use App\Models\ClientProjectTicketComment;
use App\Models\ClientProjectTicket;
use App\Models\ClientProjectTicketVote;
use App\Models\Tenant;
use Database\Seeders\ModernForestryAppFeedbackSeeder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ModernForestryAppFeedbackController extends Controller
{
    public function index(Request $request): View
    {
        [$tenant, $project] = $this->board();
        $tickets = $this->tickets($tenant, $project);
        $publicTickets = $this->publicTickets($tickets);

        return view('marketing.public.modern-forestry-app-feedback', [
            'project' => $project,
            'tickets' => $publicTickets,
            'columns' => $this->columns($publicTickets),
            'formAction' => '',
            'storefrontUrl' => 'https://theforestrystudio.com',
            'appScreenshotUrl' => asset('brand/modern-forestry-app-home.png'),
            'rankedRequests' => collect($publicTickets)
                ->sortByDesc(fn (array $ticket): int => (int) $ticket['votes'])
                ->take(5)
                ->values(),
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

        $ticket = ClientProjectTicket::query()->create([
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

        return $this->showBoard($tenant, $project, $ticket, 'Thanks. Your request was added to the board.');
    }

    public function show(Request $request, ClientProjectTicket $ticket): View
    {
        [$tenant, $project] = $this->board();
        $ticket = $this->visibleTicket($tenant, $project, $ticket);

        return $this->showBoard($tenant, $project, $ticket);
    }

    public function comment(Request $request, ClientProjectTicket $ticket): View
    {
        $validated = $request->validate([
            'author_name' => ['nullable', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:1600'],
            'website' => ['nullable', 'max:0'],
        ]);

        [$tenant, $project] = $this->board();
        $ticket = $this->visibleTicket($tenant, $project, $ticket);

        ClientProjectTicketComment::query()->create([
            'tenant_id' => (int) $tenant->id,
            'client_project_ticket_id' => (int) $ticket->id,
            'author_name' => $this->nullableText($validated['author_name'] ?? null, 80),
            'body' => $this->text($validated['body'] ?? '', 1600),
            'public_visible' => true,
            'metadata' => [
                'source' => 'modern_forestry_public_feedback_board',
                'shop' => $this->nullableText($request->query('shop'), 190),
            ],
        ]);

        return $this->showBoard($tenant, $project, $ticket, 'Comment added. Thanks for helping shape the app.');
    }

    public function vote(Request $request, ClientProjectTicket $ticket): View
    {
        [$tenant, $project] = $this->board();
        $ticket = $this->visibleTicket($tenant, $project, $ticket);

        ClientProjectTicketVote::query()->firstOrCreate([
            'client_project_ticket_id' => (int) $ticket->id,
            'voter_hash' => $this->voterHash($request, $ticket),
        ], [
            'tenant_id' => (int) $tenant->id,
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
        ]);

        return $this->showBoard($tenant, $project, $ticket, 'Vote counted. The most requested ideas rise to the top.');
    }

    protected function showBoard(Tenant $tenant, ClientProject $project, ClientProjectTicket $activeTicket, string $status = ''): View
    {
        $tickets = $this->tickets($tenant, $project);
        $publicTickets = $this->publicTickets($tickets);
        $active = $this->publicTicket($activeTicket->loadMissing(['publicComments'])->loadCount('feedbackVotes'));

        return view('marketing.public.modern-forestry-app-feedback', [
            'project' => $project,
            'tickets' => $publicTickets,
            'columns' => $this->columns($publicTickets),
            'formAction' => '',
            'storefrontUrl' => 'https://theforestrystudio.com',
            'appScreenshotUrl' => asset('brand/modern-forestry-app-home.png'),
            'rankedRequests' => collect($publicTickets)
                ->sortByDesc(fn (array $ticket): int => (int) $ticket['votes'])
                ->take(5)
                ->values(),
            'activeTicket' => $active,
            'status' => $status,
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
     * @return EloquentCollection<int,ClientProjectTicket>
     */
    protected function tickets(Tenant $tenant, ClientProject $project): EloquentCollection
    {
        return ClientProjectTicket::query()
            ->where('tenant_id', (int) $tenant->id)
            ->where('client_project_id', (int) $project->id)
            ->where('customer_visible', true)
            ->withCount(['feedbackVotes', 'publicComments'])
            ->with(['publicComments'])
            ->latest('id')
            ->get();
    }

    protected function visibleTicket(Tenant $tenant, ClientProject $project, ClientProjectTicket $ticket): ClientProjectTicket
    {
        abort_unless(
            (int) $ticket->tenant_id === (int) $tenant->id
            && (int) $ticket->client_project_id === (int) $project->id
            && (bool) $ticket->customer_visible,
            404
        );

        return $ticket;
    }

    /**
     * @param  iterable<ClientProjectTicket>  $tickets
     * @return array<int,array<string,mixed>>
     */
    protected function publicTickets(iterable $tickets): array
    {
        return collect($tickets)
            ->map(fn (ClientProjectTicket $ticket): array => $this->publicTicket($ticket))
            ->sortByDesc(fn (array $ticket): int => (int) $ticket['votes'])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    protected function publicTicket(ClientProjectTicket $ticket): array
    {
        $copy = $this->customerCopy($ticket);

        return [
            'id' => (int) $ticket->id,
            'url' => '/apps/forestry/feedback/'.(int) $ticket->id,
            'title' => $copy['title'] ?? (string) $ticket->title,
            'summary' => $copy['summary'],
            'update' => $copy['update'],
            'status' => (string) $ticket->status,
            'status_label' => $this->statusLabel($ticket),
            'type' => (string) $ticket->type,
            'type_label' => $this->typeLabel($ticket),
            'votes' => (int) ($ticket->feedback_votes_count ?? $ticket->feedbackVotes()->count()),
            'comments_count' => (int) ($ticket->public_comments_count ?? $ticket->publicComments()->count()),
            'vote_action' => '/apps/forestry/feedback/'.(int) $ticket->id.'/vote',
            'comment_action' => '/apps/forestry/feedback/'.(int) $ticket->id.'/comments',
            'comments' => $ticket->relationLoaded('publicComments')
                ? $ticket->publicComments->map(fn (ClientProjectTicketComment $comment): array => [
                    'author_name' => $comment->author_name ?: 'A Modern Forestry customer',
                    'body' => (string) $comment->body,
                    'created_at' => $comment->created_at?->format('M j, Y'),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $tickets
     * @return array<string,array{label:string,description:string,tickets:\Illuminate\Support\Collection<int,array<string,mixed>>}>
     */
    protected function columns(iterable $tickets): array
    {
        $collection = collect($tickets);

        return [
            'working' => [
                'label' => 'Coming next',
                'description' => 'Improvements we are actively building, testing, or have already planned.',
                'tickets' => $collection->filter(fn (array $ticket): bool => in_array((string) $ticket['status'], ['in_progress', 'in_review', 'scoped'], true))->values(),
            ],
            'considering' => [
                'label' => 'Vote on ideas',
                'description' => 'Requests customers have shared. Vote for what would help you most.',
                'tickets' => $collection->filter(fn (array $ticket): bool => in_array((string) $ticket['status'], ['new', 'needs_discovery'], true))->values(),
            ],
            'done' => [
                'label' => 'Shipped',
                'description' => 'Fixes and features already added to make the app smoother.',
                'tickets' => $collection->filter(fn (array $ticket): bool => (string) $ticket['status'] === 'done')->values(),
            ],
        ];
    }

    /**
     * @return array{title?:string,summary:string,update:string}
     */
    protected function customerCopy(ClientProjectTicket $ticket): array
    {
        $sourceId = (int) data_get($ticket->metadata, 'modern_forestry_feedback_source_ticket_id', data_get($ticket->metadata, 'source_ticket_id', 0));

        $copy = [
            1 => [
                'title' => 'Sign-in has more time to finish',
                'summary' => 'Some customers were getting knocked out of sign-in before they had time to finish.',
                'update' => 'We extended the sign-in flow and fixed the handoff so customers have time to complete login normally.',
            ],
            2 => [
                'title' => 'Sign-in starts with the right email',
                'summary' => 'The sign-in page could show an old saved email instead of letting customers pick the right one.',
                'update' => 'Sign-in now starts by asking which email to use, so customers can choose the right account every time.',
            ],
            3 => [
                'title' => 'Rewards stays connected',
                'summary' => 'Rewards could ask for sign-in again even when the rest of the account still looked connected.',
                'update' => 'The app now refreshes account access quietly in the background so Rewards stays connected.',
            ],
            4 => [
                'title' => 'Wishlist saves show the right message',
                'summary' => 'Wishlist saves were working, but the app sometimes showed a confusing unavailable message.',
                'update' => 'Successful saves now show as successful, so the message matches what actually happened.',
            ],
            5 => [
                'title' => 'Quiz candle saves are clearer',
                'summary' => 'Saving candle picks from the scent quiz could show an error even when the item saved.',
                'update' => 'Quiz recommendations now use the improved wishlist save path.',
            ],
            6 => [
                'title' => 'Try the scent quiz before signing in',
                'summary' => 'Customers want to explore the scent quiz before being asked to sign in.',
                'update' => 'Login is working now; anonymous quiz play is on the roadmap so saving can happen later.',
            ],
            7 => [
                'title' => 'More sign-in choices',
                'summary' => 'Customers asked for familiar sign-in choices instead of only email-code login.',
                'update' => 'Google and Facebook sign-in options have been enabled on the hosted sign-in screen.',
            ],
            8 => [
                'title' => 'Clearer reviewer access wording',
                'summary' => 'Reviewer Access could look like something regular customers were supposed to use.',
                'update' => 'We are clarifying the wording so normal customers know where to go.',
            ],
            9 => [
                'title' => 'Android app interest',
                'summary' => 'Customers have asked whether Modern Forestry will be available on Android.',
                'update' => 'The app is iPhone-first today. Android interest is being tracked for future planning.',
            ],
            10 => [
                'title' => 'Sale section in the app',
                'summary' => 'Customers wanted a faster way to browse sale and clearance candles.',
                'update' => 'A dedicated Sale shelf has been added to the Shop experience.',
            ],
            11 => [
                'title' => 'More Modern Forestry branding during sign-in',
                'summary' => 'The sign-in step should feel like Modern Forestry, not a random third-party page.',
                'update' => 'The account domain and sign-in branding have been moved toward Modern Forestry styling wherever the platform allows.',
            ],
            12 => [
                'title' => 'Google and Facebook sign-in check',
                'summary' => 'We needed to confirm Google sign-in appeared alongside Facebook.',
                'update' => 'Google and Facebook now both appear on the live sign-in screen.',
            ],
            13 => [
                'title' => 'Safer sign-in account choice',
                'summary' => 'Customers want the app to avoid using an old saved email by mistake.',
                'update' => 'We are keeping the safer sign-in setup that asks customers to choose their account, even if it means a little less one-tap convenience.',
            ],
            14 => [
                'title' => 'Privacy-friendly sign-in choices',
                'summary' => 'Adding Google and Facebook sign-in means we still need a simple private option for customers who do not use those accounts.',
                'update' => 'The email-code sign-in path remains available as the privacy-friendly option.',
            ],
            15 => [
                'title' => 'Keep rewards tied to the right email',
                'summary' => 'Using a different Google or Facebook email can create a separate customer account.',
                'update' => 'We are tracking clearer guidance and account-merge handling so rewards stay easy to understand.',
            ],
        ];

        if (isset($copy[$sourceId])) {
            return $copy[$sourceId];
        }

        return [
            'summary' => $this->cleanCustomerText((string) $ticket->problem_summary),
            'update' => $this->cleanCustomerText((string) ($ticket->scope_notes ?: $ticket->desired_outcome ?: 'We are reviewing this request.')),
        ];
    }

    protected function statusLabel(ClientProjectTicket $ticket): string
    {
        return match ((string) $ticket->status) {
            'done' => 'Shipped',
            'in_progress' => 'In progress',
            'in_review' => 'Testing',
            'scoped' => 'Planned',
            'new' => 'New',
            'needs_discovery' => 'Considering',
            default => $ticket->statusLabel(),
        };
    }

    protected function typeLabel(ClientProjectTicket $ticket): string
    {
        return match ((string) $ticket->type) {
            'feature' => 'Feature',
            'app_request' => 'App update',
            'change_request' => 'Improvement',
            'question' => 'Question',
            default => $ticket->typeLabel(),
        };
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

    protected function cleanCustomerText(string $value): string
    {
        $lines = collect(preg_split('/\r\n|\r|\n/', $value) ?: [])
            ->reject(fn (string $line): bool => str_starts_with(trim($line), 'Source:') || str_starts_with(trim($line), 'Reporters:'))
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values();

        return Str::limit($lines->implode(' '), 260, '');
    }

    protected function nullableText(mixed $value, int $limit): ?string
    {
        $text = $this->text($value, $limit);

        return $text !== '' ? $text : null;
    }

    protected function voterHash(Request $request, ClientProjectTicket $ticket): string
    {
        return hash('sha256', implode('|', [
            'modern-forestry-feedback-vote',
            (int) $ticket->id,
            (string) $request->ip(),
            substr((string) $request->userAgent(), 0, 300),
        ]));
    }
}

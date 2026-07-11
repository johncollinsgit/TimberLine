<?php

namespace App\Services\Mobile;

use App\Models\ClientProject;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\MarketingProfile;
use App\Models\MessagingConversation;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Marketing\MessagingContactChannelStateService;
use App\Services\Tenancy\TenantBlueprintProfileService;
use App\Services\Tenancy\TenantExperienceProfileService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TenantMobileResourceService
{
    public function __construct(
        protected MessagingContactChannelStateService $channelState,
        protected TenantBlueprintProfileService $blueprints,
        protected TenantExperienceProfileService $experienceProfiles,
    ) {}

    /** @return array<string,mixed> */
    public function customers(int $tenantId, string $search = '', ?string $cursor = null, int $limit = 25): array
    {
        $search = trim($search);
        $paginator = MarketingProfile::query()->forTenantId($tenantId)
            ->select(['id', 'first_name', 'last_name', 'email', 'phone', 'city', 'state', 'updated_at'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function (Builder $builder) use ($like): void {
                    $builder->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like);
                });
            })
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->cursorPaginate(max(10, min(50, $limit)), ['*'], 'cursor', $cursor);

        return [
            'customers' => collect($paginator->items())->map(fn (MarketingProfile $profile): array => $this->customerSummary($profile))->values(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ];
    }

    /** @return array<string,mixed> */
    public function customer(int $tenantId, int $customerId): array
    {
        $profile = MarketingProfile::query()->forTenantId($tenantId)->findOrFail($customerId);
        $jobs = Schema::hasTable('field_service_jobs')
            ? FieldServiceJob::query()->forTenantId($tenantId)->where('marketing_profile_id', $profile->id)
                ->latest('updated_at')->limit(8)->get()
            : collect();
        $identities = array_filter([$profile->email, $profile->normalized_email, $profile->phone, $profile->normalized_phone]);
        $orders = Schema::hasTable('orders') && $identities !== []
            ? Order::query()->forTenantId($tenantId)
                ->where(function (Builder $query) use ($profile): void {
                    foreach (array_filter([$profile->email, $profile->normalized_email]) as $email) {
                        $query->orWhere('email', $email)->orWhere('customer_email', $email)->orWhere('shipping_email', $email)->orWhere('billing_email', $email);
                    }
                    foreach (array_filter([$profile->phone, $profile->normalized_phone]) as $phone) {
                        $query->orWhere('phone', $phone)->orWhere('customer_phone', $phone)->orWhere('shipping_phone', $phone)->orWhere('billing_phone', $phone);
                    }
                })->latest('ordered_at')->limit(8)->get()
            : collect();
        $conversations = Schema::hasTable('messaging_conversations')
            ? MessagingConversation::query()->forTenantId($tenantId)->where('marketing_profile_id', $profile->id)
                ->latest('last_message_at')->limit(8)->get()
            : collect();

        return [
            'customer' => [
                ...$this->customerSummary($profile),
                'email' => $profile->email,
                'phone' => $profile->phone,
                'address' => array_filter([
                    'line_1' => $profile->address_line_1,
                    'line_2' => $profile->address_line_2,
                    'city' => $profile->city,
                    'state' => $profile->state,
                    'postal_code' => $profile->postal_code,
                    'country' => $profile->country,
                ]),
                'notes' => $profile->notes,
                'channels' => [
                    'text' => $profile->phone ? $this->channelState->resolveSmsStatus($tenantId, $profile, $profile->phone) : 'unavailable',
                    'email' => $profile->email ? $this->channelState->resolveEmailStatus($tenantId, $profile, $profile->email) : 'unavailable',
                ],
            ],
            'recent_orders' => $orders->map(fn (Order $order): array => $this->orderSummary($order))->values(),
            'recent_jobs' => $jobs->map(fn (FieldServiceJob $job): array => $this->jobSummary($job))->values(),
            'conversations' => $conversations->map(fn (MessagingConversation $conversation): array => [
                'id' => (int) $conversation->id,
                'channel' => $conversation->source_type === 'modern_forestry_app' ? 'app' : ($conversation->channel === 'sms' ? 'text' : 'email'),
                'preview' => $conversation->last_message_preview,
                'unread_count' => (int) $conversation->unread_count,
                'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
            ])->values(),
        ];
    }

    /** @return array<string,mixed> */
    public function work(Tenant $tenant, mixed $user, string $search = '', int $limit = 40): array
    {
        $kind = $this->workKind($tenant, $user);
        $search = trim($search);
        $limit = max(10, min(50, $limit));

        $items = match ($kind) {
            'orders' => Order::query()->forTenantId((int) $tenant->id)
                ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $builder) use ($search): void {
                    $like = '%'.$search.'%';
                    $builder->where('order_number', 'like', $like)->orWhere('order_label', 'like', $like)
                        ->orWhere('customer_name', 'like', $like)->orWhere('shopify_name', 'like', $like);
                }))->latest('ordered_at')->limit($limit)->get()->map(fn (Order $order): array => $this->orderSummary($order)),
            'jobs' => FieldServiceJob::query()->forTenantId((int) $tenant->id)
                ->withCount(['tasks', 'materials', 'photos', 'notes'])
                ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $builder) use ($search): void {
                    $like = '%'.$search.'%';
                    $builder->where('title', 'like', $like)->orWhere('customer_name', 'like', $like)
                        ->orWhere('customer_phone', 'like', $like)
                        ->orWhere('lock_box_code', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('service_city', 'like', $like)->orWhere('service_address_line_1', 'like', $like)
                        ->orWhereHas('notes', fn (Builder $notes) => $notes->where('body', 'like', $like));
                }))->latest('updated_at')->limit($limit)->get()->map(fn (FieldServiceJob $job): array => $this->jobSummary($job)),
            default => ClientProject::query()->forTenantId((int) $tenant->id)
                ->withCount(['milestones', 'tickets'])
                ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $builder) use ($search): void {
                    $like = '%'.$search.'%';
                    $builder->where('title', 'like', $like)->orWhere('summary', 'like', $like)->orWhere('status', 'like', $like);
                }))->orderBy('sort_order')->latest('updated_at')->limit($limit)->get()->map(fn (ClientProject $project): array => $this->clientSummary($project)),
        };

        return ['kind' => $kind, 'label' => match ($kind) {
            'orders' => 'Orders', 'jobs' => 'Jobs', default => 'Clients'
        }, 'items' => $items->values()];
    }

    /** @return array<string,mixed> */
    public function workDetail(Tenant $tenant, mixed $user, string $kind, int $id): array
    {
        abort_unless($kind === $this->workKind($tenant, $user), 404);

        return match ($kind) {
            'orders' => $this->orderDetail((int) $tenant->id, $id),
            'jobs' => $this->jobDetail((int) $tenant->id, $id),
            'clients' => $this->clientDetail((int) $tenant->id, $id),
            default => abort(404),
        };
    }

    public function workKind(Tenant $tenant, mixed $user): string
    {
        $blueprint = $this->blueprints->payloadForTenant($tenant->loadMissing('accessProfile'));
        $template = strtolower(trim((string) ($blueprint['business_template'] ?? '')));

        if (in_array($template, ['candle_maker', 'apparel'], true)) {
            return 'orders';
        }
        if (in_array($template, ['electrician', 'landscaping'], true)) {
            return 'jobs';
        }
        if (in_array($template, ['law', 'generic', 'custom'], true) && in_array('clients', (array) ($blueprint['starter_modules'] ?? []), true)) {
            return 'clients';
        }

        $experience = $this->experienceProfiles->forTenant((int) $tenant->id, $user, $tenant);
        if (($experience['channel_type'] ?? null) === 'shopify' || (bool) data_get($experience, 'data_availability.orders', false)) {
            return 'orders';
        }
        if (($experience['use_case_profile'] ?? null) === 'field_service') {
            return 'jobs';
        }

        return 'clients';
    }

    /** @return array<string,mixed> */
    protected function orderDetail(int $tenantId, int $id): array
    {
        $order = Order::query()->forTenantId($tenantId)->with('lines')->findOrFail($id);

        return ['kind' => 'orders', 'item' => [...$this->orderSummary($order), 'ordered_at' => optional($order->ordered_at)->toIso8601String(), 'ship_by_at' => optional($order->ship_by_at)->toIso8601String(), 'shipping_address' => array_filter([$order->shipping_address1, $order->shipping_city, $order->shipping_province, $order->shipping_zip]), 'lines' => $order->lines->map(fn ($line): array => ['id' => (int) $line->id, 'title' => (string) ($line->raw_title ?: $line->scent_name ?: 'Item'), 'quantity' => (int) ($line->quantity ?? 0), 'variant' => $line->raw_variant ?: $line->size_code])]];
    }

    /** @return array<string,mixed> */
    protected function jobDetail(int $tenantId, int $id): array
    {
        $job = FieldServiceJob::query()->forTenantId($tenantId)->with(['tasks.assignedUser:id,name', 'materials', 'photos', 'notes.createdBy:id,name', 'notes.photos', 'assignedUser:id,name'])->findOrFail($id);

        return ['kind' => 'jobs', 'item' => [
            ...$this->jobSummary($job),
            'description' => $job->description,
            'customer_phone' => $job->customer_phone,
            'lock_box_code' => $job->lock_box_code,
            'scheduled_for' => optional($job->scheduled_for)->toIso8601String(),
            'address' => array_filter([$job->service_address_line_1, $job->service_address_line_2, $job->service_city, $job->service_state, $job->service_postal_code]),
            'assigned_to' => $job->assignedUser?->name,
            'tasks' => $job->tasks->map(fn ($task): array => [
                'id' => (int) $task->id,
                'title' => (string) $task->title,
                'status' => (string) $task->status,
                'due_at' => optional($task->due_at)->toIso8601String(),
                'assigned_to' => $task->assignedUser?->name,
            ])->values(),
            'materials' => $job->materials,
            'photos' => $job->photos,
            'notes' => $job->notes->sortByDesc('noted_at')->map(fn (FieldServiceJobNote $note): array => [
                'id' => (int) $note->id,
                'body' => (string) $note->body,
                'status_update' => $note->status_update,
                'noted_at' => optional($note->noted_at)->toIso8601String(),
                'created_by' => $note->createdBy?->name,
                'photos' => $note->photos->map(fn ($photo): array => [
                    'id' => (int) $photo->id,
                    'file_path' => (string) $photo->file_path,
                    'caption' => $photo->caption,
                ])->values(),
            ])->values(),
        ]];
    }

    /** @return array<string,mixed> */
    protected function clientDetail(int $tenantId, int $id): array
    {
        $project = ClientProject::query()->forTenantId($tenantId)->with(['phases', 'milestones', 'updates', 'links', 'tickets'])->findOrFail($id);

        return ['kind' => 'clients', 'item' => [...$this->clientSummary($project), 'summary' => $project->summary, 'starts_on' => optional($project->starts_on)->toDateString(), 'due_on' => optional($project->due_on)->toDateString(), 'phases' => $project->phases, 'milestones' => $project->milestones, 'updates' => $project->updates, 'links' => $project->links, 'tickets' => $project->tickets]];
    }

    /** @return array<string,mixed> */
    protected function customerSummary(MarketingProfile $profile): array
    {
        $name = trim($profile->first_name.' '.$profile->last_name) ?: ($profile->email ?: $profile->phone ?: 'Customer');

        return ['id' => (int) $profile->id, 'name' => $name, 'initials' => Str::of($name)->explode(' ')->filter()->take(2)->map(fn ($part) => Str::upper(Str::substr($part, 0, 1)))->implode(''), 'subtitle' => trim(implode(' | ', array_filter([$profile->email, $profile->phone]))), 'location' => trim(implode(', ', array_filter([$profile->city, $profile->state])))];
    }

    /** @return array<string,mixed> */
    protected function orderSummary(Order $order): array
    {
        return ['id' => (int) $order->id, 'kind' => 'orders', 'title' => (string) ($order->order_label ?: $order->shopify_name ?: $order->order_number ?: 'Order'), 'subtitle' => (string) ($order->customer_name ?: $order->display_name), 'status' => Str::headline((string) $order->status), 'amount' => '$'.number_format((float) $order->total_price, 2)];
    }

    /** @return array<string,mixed> */
    protected function jobSummary(FieldServiceJob $job): array
    {
        return [
            'id' => (int) $job->id,
            'kind' => 'jobs',
            'title' => (string) $job->title,
            'subtitle' => (string) ($job->customer_name ?: $job->service_city ?: 'Service job'),
            'status' => Str::headline((string) $job->status),
            'customer_phone' => $job->customer_phone,
            'scheduled_for' => optional($job->scheduled_for)->toIso8601String(),
            'has_lock_box_code' => filled($job->lock_box_code),
            'meta' => [
                'tasks' => (int) ($job->tasks_count ?? $job->tasks()->count()),
                'materials' => (int) ($job->materials_count ?? $job->materials()->count()),
                'photos' => (int) ($job->photos_count ?? $job->photos()->count()),
                'notes' => (int) ($job->notes_count ?? $job->notes()->count()),
            ],
        ];
    }

    /** @return array<string,mixed> */
    protected function clientSummary(ClientProject $project): array
    {
        return ['id' => (int) $project->id, 'kind' => 'clients', 'title' => (string) $project->title, 'subtitle' => Str::limit((string) $project->summary, 100), 'status' => Str::headline((string) $project->status), 'health' => Str::headline((string) $project->health), 'meta' => ['milestones' => (int) ($project->milestones_count ?? 0), 'tickets' => (int) ($project->tickets_count ?? 0)]];
    }
}

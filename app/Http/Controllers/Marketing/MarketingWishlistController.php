<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingProfileWishlistItem;
use App\Models\MarketingWishlistOutreachQueue;
use App\Services\Marketing\MarketingWishlistOutreachService;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MarketingWishlistController extends Controller
{
    public function index(Request $request): View
    {
        $tenantId = $this->requireTenantId($request);
        $filters = $this->filters($request);
        $selectedItemId = $this->positiveInt($request->query('item'));

        $itemsQuery = MarketingProfileWishlistItem::query()
            ->forTenantId($tenantId)
            ->with([
                'profile:id,tenant_id,first_name,last_name,email,phone,accepts_sms_marketing',
                'wishlistList:id,tenant_id,name,is_default',
            ])
            ->when($filters['search'] !== '', function (Builder $builder) use ($filters): void {
                $search = $filters['search'];
                $builder->where(function (Builder $query) use ($search): void {
                    $query->where('product_title', 'like', '%' . $search . '%')
                        ->orWhere('product_handle', 'like', '%' . $search . '%')
                        ->orWhere('product_id', 'like', '%' . $search . '%')
                        ->orWhereHas('profile', function (Builder $profileQuery) use ($search): void {
                            $profileQuery->where('first_name', 'like', '%' . $search . '%')
                                ->orWhere('last_name', 'like', '%' . $search . '%')
                                ->orWhere('email', 'like', '%' . $search . '%')
                                ->orWhere('phone', 'like', '%' . $search . '%');
                        });
                });
            })
            ->when($filters['status'] !== 'all', fn (Builder $builder) => $builder->where('status', $filters['status']))
            ->when($filters['channel'] === 'sms_ready', fn (Builder $builder) => $builder->whereHas('profile', fn (Builder $profileQuery) => $profileQuery->whereNotNull('phone')->where('phone', '!=', '')))
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByRaw('COALESCE(last_added_at, added_at, removed_at, updated_at, created_at) DESC')
            ->orderByDesc('id');

        $items = $itemsQuery
            ->paginate(20)
            ->withQueryString();

        $selectedItem = $selectedItemId
            ? (clone $itemsQuery)->whereKey($selectedItemId)->first()
            : $items->first();

        $queueQuery = MarketingWishlistOutreachQueue::query()
            ->forTenantId($tenantId)
            ->with([
                'profile:id,tenant_id,first_name,last_name,email,phone',
                'wishlistList:id,tenant_id,name',
                'wishlistItem:id,product_id,product_title,product_url',
            ])
            ->when($filters['search'] !== '', function (Builder $builder) use ($filters): void {
                $search = $filters['search'];
                $builder->where(function (Builder $query) use ($search): void {
                    $query->where('product_title', 'like', '%' . $search . '%')
                        ->orWhere('product_handle', 'like', '%' . $search . '%')
                        ->orWhere('offer_code', 'like', '%' . $search . '%')
                        ->orWhereHas('profile', function (Builder $profileQuery) use ($search): void {
                            $profileQuery->where('first_name', 'like', '%' . $search . '%')
                                ->orWhere('last_name', 'like', '%' . $search . '%')
                                ->orWhere('email', 'like', '%' . $search . '%');
                        });
                });
            })
            ->when($filters['queue_status'] !== 'all', fn (Builder $builder) => $builder->where('queue_status', $filters['queue_status']))
            ->when($filters['channel_filter'] !== 'all', fn (Builder $builder) => $builder->where('channel', $filters['channel_filter']))
            ->latest('id');

        $queueEntries = $queueQuery
            ->paginate(10, ['*'], 'queue_page')
            ->withQueryString();

        $activeItems = MarketingProfileWishlistItem::query()
            ->forTenantId($tenantId)
            ->with(['profile:id,tenant_id,first_name,last_name,email,phone', 'wishlistList:id,tenant_id,name'])
            ->where('status', MarketingProfileWishlistItem::STATUS_ACTIVE)
            ->get();

        return view('marketing.wishlist.index', [
            'section' => MarketingSectionRegistry::section('wishlist'),
            'sections' => $this->navigationItems(),
            'wishlistFilters' => $filters,
            'wishlistItems' => $items,
            'selectedWishlistItem' => $selectedItem,
            'wishlistQueueEntries' => $queueEntries,
            'wishlistSummary' => [
                'active_items' => (int) $activeItems->count(),
                'unique_customers' => (int) $activeItems->pluck('marketing_profile_id')->filter()->unique()->count(),
                'unique_products' => (int) $activeItems->map(fn (MarketingProfileWishlistItem $item): string => strtolower(trim((string) $item->store_key)) . ':' . trim((string) $item->product_id))->unique()->count(),
                'outreach_candidates' => (int) $activeItems->filter(fn (MarketingProfileWishlistItem $item): bool => trim((string) ($item->profile?->phone ?? '')) !== '')->count(),
                'prepared_queue' => (int) MarketingWishlistOutreachQueue::query()->forTenantId($tenantId)->where('queue_status', MarketingWishlistOutreachQueue::STATUS_PREPARED)->count(),
                'sent_queue' => (int) MarketingWishlistOutreachQueue::query()->forTenantId($tenantId)->where('queue_status', MarketingWishlistOutreachQueue::STATUS_SENT)->count(),
            ],
            'customerIntentRollup' => $this->customerIntentRollup($activeItems),
            'productIntentRollup' => $this->productIntentRollup($activeItems),
        ]);
    }

    public function prepareOutreach(
        Request $request,
        MarketingProfileWishlistItem $item,
        MarketingWishlistOutreachService $outreachService
    ): RedirectResponse {
        $this->assertWishlistItemInTenantScope($item, $request);

        $data = $request->validate([
            'channel' => ['required', 'in:sms,email'],
            'offer_type' => ['required', 'in:amount_off,percent_off'],
            'offer_value' => ['required', 'numeric', 'min:0.01', 'max:9999'],
            'message_body' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $queue = $outreachService->prepare($item, $data, auth()->id());
        } catch (\Throwable $exception) {
            return back()->with('toast', [
                'style' => 'danger',
                'message' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('marketing.wishlist', array_filter([
                ...$request->query(),
                'item' => $item->id,
            ]))
            ->with('toast', [
                'style' => 'success',
                'message' => 'Wishlist offer prepared for ' . ($queue->product_title ?: 'saved product') . '.',
            ]);
    }

    public function sendOutreach(
        Request $request,
        MarketingWishlistOutreachQueue $queue,
        MarketingWishlistOutreachService $outreachService
    ): RedirectResponse {
        $this->assertWishlistQueueInTenantScope($queue, $request);

        $data = $request->validate([
            'message_body' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $outreachService->send($queue, $data, auth()->id());

        return back()->with('toast', [
            'style' => $result['ok'] ? 'success' : 'danger',
            'message' => $result['ok']
                ? 'Wishlist offer sent.'
                : ($result['error'] ?: 'Wishlist offer could not be sent.'),
        ]);
    }

    /**
     * @return array{
     *   search:string,
     *   status:string,
     *   queue_status:string,
     *   channel:string,
     *   channel_filter:string
     * }
     */
    protected function filters(Request $request): array
    {
        $status = trim((string) $request->query('status', 'active'));
        if (! in_array($status, ['all', 'active', 'removed'], true)) {
            $status = 'active';
        }

        $queueStatus = trim((string) $request->query('queue_status', 'all'));
        if (! in_array($queueStatus, ['all', 'queued', 'prepared', 'sent', 'failed', 'redeemed'], true)) {
            $queueStatus = 'all';
        }

        $channel = trim((string) $request->query('channel', 'all'));
        if (! in_array($channel, ['all', 'sms_ready'], true)) {
            $channel = 'all';
        }

        $channelFilter = trim((string) $request->query('channel_filter', 'all'));
        if (! in_array($channelFilter, ['all', 'sms', 'email'], true)) {
            $channelFilter = 'all';
        }

        return [
            'search' => trim((string) $request->query('search', '')),
            'status' => $status,
            'queue_status' => $queueStatus,
            'channel' => $channel,
            'channel_filter' => $channelFilter,
        ];
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    protected function customerIntentRollup(Collection $items): Collection
    {
        return $items
            ->filter(fn (MarketingProfileWishlistItem $item): bool => $item->profile !== null)
            ->groupBy('marketing_profile_id')
            ->map(function (Collection $group): array {
                /** @var MarketingProfileWishlistItem|null $first */
                $first = $group->first();
                $profile = $first?->profile;

                return [
                    'profile_id' => $profile?->id,
                    'label' => trim(($profile?->first_name ?? '') . ' ' . ($profile?->last_name ?? '')) ?: ($profile?->email ?: 'Customer'),
                    'email' => $profile?->email,
                    'phone' => $profile?->phone,
                    'active_count' => (int) $group->count(),
                    'products' => $group->pluck('product_title')->filter()->unique()->take(3)->values()->all(),
                ];
            })
            ->sortByDesc('active_count')
            ->take(8)
            ->values();
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    protected function productIntentRollup(Collection $items): Collection
    {
        return $items
            ->groupBy(fn (MarketingProfileWishlistItem $item): string => strtolower(trim((string) $item->store_key)) . ':' . trim((string) $item->product_id))
            ->map(function (Collection $group): array {
                /** @var MarketingProfileWishlistItem|null $first */
                $first = $group->first();

                return [
                    'product_id' => $first?->product_id,
                    'product_title' => $first?->product_title ?: ($first?->product_handle ?: 'Product'),
                    'product_url' => $first?->product_url,
                    'store_key' => $first?->store_key,
                    'customer_count' => (int) $group->pluck('marketing_profile_id')->filter()->unique()->count(),
                    'list_count' => (int) $group->pluck('wishlist_list_id')->filter()->unique()->count(),
                ];
            })
            ->sortByDesc('customer_count')
            ->take(8)
            ->values();
    }

    protected function currentTenantId(Request $request): ?int
    {
        foreach (['current_tenant_id', 'host_tenant_id'] as $attribute) {
            $tenantId = $this->positiveInt($request->attributes->get($attribute));
            if ($tenantId !== null) {
                $request->attributes->set('current_tenant_id', $tenantId);

                return $tenantId;
            }
        }

        $sessionTenantId = $this->positiveInt($request->session()->get('tenant_id'));
        if ($sessionTenantId !== null) {
            $request->attributes->set('current_tenant_id', $sessionTenantId);

            return $sessionTenantId;
        }

        $user = $request->user();
        if ($user) {
            $tenantIds = $user->tenants()
                ->pluck('tenants.id')
                ->map(fn ($value): int => (int) $value)
                ->filter(fn (int $value): bool => $value > 0)
                ->values();

            if ($tenantIds->count() === 1) {
                $tenantId = (int) $tenantIds->first();
                $request->attributes->set('current_tenant_id', $tenantId);

                return $tenantId;
            }
        }

        return null;
    }

    protected function requireTenantId(Request $request): int
    {
        $tenantId = $this->currentTenantId($request);
        if ($tenantId === null) {
            abort(403, 'Tenant context is required for wishlist management.');
        }

        return $tenantId;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    protected function assertWishlistItemInTenantScope(MarketingProfileWishlistItem $item, Request $request): void
    {
        $tenantId = $this->requireTenantId($request);
        if ((int) ($item->tenant_id ?? 0) !== $tenantId) {
            abort(404);
        }
    }

    protected function assertWishlistQueueInTenantScope(MarketingWishlistOutreachQueue $queue, Request $request): void
    {
        $tenantId = $this->requireTenantId($request);
        if ((int) ($queue->tenant_id ?? 0) !== $tenantId) {
            abort(404);
        }
    }

    protected function displayLabel(string $key, string $fallback): string
    {
        /** @var TenantDisplayLabelResolver $resolver */
        $resolver = app(TenantDisplayLabelResolver::class);

        return $resolver->label($this->currentTenantId(request()), $key, $fallback);
    }

    /**
     * @return array<int,array{key:string,label:string,href:string,current:bool}>
     */
    protected function navigationItems(): array
    {
        $items = [];
        foreach (MarketingSectionRegistry::sections() as $key => $section) {
            $items[] = [
                'key' => $key,
                'label' => $section['label'],
                'href' => route($section['route']),
                'current' => request()->routeIs($section['route']) || request()->routeIs($section['route'] . '.*'),
            ];
        }

        return $items;
    }
}

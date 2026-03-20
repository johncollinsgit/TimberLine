<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\CandleCashRedemption;
use App\Models\MarketingProfile;
use App\Models\MarketingStorefrontEvent;
use App\Services\Marketing\CandleCashAccessGate;
use App\Services\Marketing\CandleCashRedemptionReconciliationService;
use App\Services\Marketing\CandleCashService;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class MarketingOperationsController extends Controller
{
    public function reconciliation(Request $request): View
    {
        $status = trim((string) $request->query('status', 'open'));
        $issueType = trim((string) $request->query('issue_type', 'all'));
        $platform = trim((string) $request->query('platform', 'all'));
        $search = trim((string) $request->query('search', ''));

        $events = MarketingStorefrontEvent::query()
            ->with([
                'profile:id,first_name,last_name,email,phone',
                'redemption:id,marketing_profile_id,reward_id,redemption_code,status,platform,external_order_source,external_order_id',
            ])
            ->when($status === 'open', function ($query): void {
                $query->where('resolution_status', 'open')
                    ->whereIn('status', ['error', 'verification_required', 'pending']);
            })
            ->when($status === 'resolved', fn ($query) => $query->where('resolution_status', 'resolved'))
            ->when($status === 'ignored', fn ($query) => $query->where('resolution_status', 'ignored'))
            ->when($issueType !== 'all' && $issueType !== '', fn ($query) => $query->where('issue_type', $issueType))
            ->when($platform !== 'all' && $platform !== '', function ($query) use ($platform): void {
                $query->where(function ($nested) use ($platform): void {
                    $nested->where('meta->platform', $platform)
                        ->orWhereHas('redemption', fn ($r) => $r->where('platform', $platform));
                });
            })
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('event_type', 'like', '%' . $search . '%')
                        ->orWhere('issue_type', 'like', '%' . $search . '%')
                        ->orWhere('endpoint', 'like', '%' . $search . '%')
                        ->orWhere('source_id', 'like', '%' . $search . '%')
                        ->orWhere('request_key', 'like', '%' . $search . '%')
                        ->orWhere('meta', 'like', '%' . $search . '%');
                });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        $issuedRedemptions = CandleCashRedemption::query()
            ->with(['profile:id,first_name,last_name,email,phone', 'reward:id,name'])
            ->where('status', 'issued')
            ->when($platform !== 'all' && $platform !== '', fn ($query) => $query->where('platform', $platform))
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        $issueTypes = MarketingStorefrontEvent::query()
            ->whereNotNull('issue_type')
            ->distinct()
            ->orderBy('issue_type')
            ->pluck('issue_type')
            ->values();

        $openCount = (int) MarketingStorefrontEvent::query()
            ->where('resolution_status', 'open')
            ->whereIn('status', ['error', 'verification_required', 'pending'])
            ->count();

        $reconciledToday = (int) CandleCashRedemption::query()
            ->where('status', 'redeemed')
            ->whereDate('redeemed_at', now()->toDateString())
            ->count();

        return view('marketing/operations/reconciliation', [
            'section' => MarketingSectionRegistry::section('candle-cash'),
            'sections' => $this->navigationItems(),
            'events' => $events,
            'issuedRedemptions' => $issuedRedemptions,
            'status' => $status,
            'issueType' => $issueType,
            'issueTypes' => $issueTypes,
            'platform' => $platform,
            'search' => $search,
            'openIssueCount' => $openCount,
            'issuedCodeCount' => (int) $issuedRedemptions->count(),
            'reconciledToday' => $reconciledToday,
        ]);
    }

    public function resolveIssue(MarketingStorefrontEvent $event, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'resolution_status' => ['required', 'in:resolved,ignored'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $event->forceFill([
            'resolution_status' => (string) $data['resolution_status'],
            'resolved_by' => auth()->id(),
            'resolved_at' => now(),
            'resolution_notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'status' => $data['resolution_status'] === 'resolved' ? 'resolved' : $event->status,
        ])->save();

        return redirect()
            ->back()
            ->with('toast', ['style' => 'success', 'message' => 'Issue updated.']);
    }

    public function retryReconciliation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['nullable', 'in:all,shopify,square'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $source = (string) ($data['source'] ?? 'all');
        $limit = (int) ($data['limit'] ?? 500);
        $dryRun = (bool) ($data['dry_run'] ?? false);

        Artisan::call('marketing:reconcile-redemptions', [
            '--source' => $source,
            '--limit' => $limit,
            '--dry-run' => $dryRun,
        ]);

        $output = trim(Artisan::output());

        return redirect()
            ->back()
            ->with('toast', [
                'style' => 'success',
                'message' => 'Reconciliation command completed' . ($dryRun ? ' (dry-run)' : '') . '.',
                'detail' => $output !== '' ? $output : null,
            ]);
    }

    public function markRedemptionRedeemed(
        CandleCashRedemption $redemption,
        Request $request,
        CandleCashRedemptionReconciliationService $service
    ): RedirectResponse {
        $data = $request->validate([
            'platform' => ['nullable', 'in:shopify,square,manual'],
            'external_order_source' => ['nullable', 'string', 'max:80'],
            'external_order_id' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1200'],
        ]);

        $service->markRedeemedManually($redemption, [
            'platform' => $data['platform'] ?? null,
            'external_order_source' => $data['external_order_source'] ?? null,
            'external_order_id' => $data['external_order_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'redeemed_by' => auth()->id(),
        ]);

        return redirect()
            ->back()
            ->with('toast', ['style' => 'success', 'message' => 'Redemption marked as redeemed.']);
    }

    public function storefrontRedemptionDebug(
        Request $request,
        CandleCashService $candleCashService,
        CandleCashAccessGate $accessGate
    ): JsonResponse {
        $data = $request->validate([
            'email' => ['nullable', 'string', 'max:255'],
            'profile_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $profileId = (int) ($data['profile_id'] ?? 0);
        $email = strtolower(trim((string) ($data['email'] ?? '')));

        $profile = $profileId > 0
            ? MarketingProfile::query()->find($profileId)
            : null;

        if (! $profile && $email !== '') {
            $profile = MarketingProfile::query()
                ->where('normalized_email', $email)
                ->orWhere('email', $email)
                ->first();
        }

        if (! $profile) {
            return response()->json([
                'ok' => false,
                'error' => [
                    'code' => 'profile_not_found',
                    'message' => 'Unable to locate a marketing profile for the requested customer.',
                ],
            ], 404);
        }

        $limit = (int) ($data['limit'] ?? 5);
        $balance = $candleCashService->currentBalance($profile);
        $reward = $candleCashService->storefrontReward();
        $rewardCost = $reward ? $candleCashService->storefrontRewardPointsCost($reward) : null;
        $openIssuedCount = (int) CandleCashRedemption::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('status', 'issued')
            ->count();

        $events = MarketingStorefrontEvent::query()
            ->where('marketing_profile_id', $profile->id)
            ->whereIn('event_type', ['widget_redeem_request', 'public_redeem_request'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $latestRedeem = $events->first();
        $latestIssue = $events->first(fn (MarketingStorefrontEvent $event) => in_array($event->status, ['error', 'verification_required', 'pending'], true));

        $accessPayload = $accessGate->storefrontRedeemAccessPayload($profile);
        $primaryIssue = $this->primaryRedemptionIssue($accessPayload, $openIssuedCount, $rewardCost, $balance, $latestIssue);

        return response()->json([
            'ok' => true,
            'data' => [
                'profile' => [
                    'id' => (int) $profile->id,
                    'email' => $profile->email,
                    'normalized_email' => $profile->normalized_email,
                ],
                'balance' => $balance,
                'reward_cost' => $rewardCost,
                'open_issued_count' => $openIssuedCount,
                'max_open_codes' => (int) config('marketing.candle_cash.max_open_codes', 1),
                'redemption_access' => $accessPayload,
                'primary_issue' => $primaryIssue,
                'latest_redeem' => $this->eventSummary($latestRedeem),
                'latest_issue' => $this->eventSummary($latestIssue),
                'recent_redeem_events' => $events->map(fn (MarketingStorefrontEvent $event) => $this->eventSummary($event))->values(),
            ],
        ]);
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

    /**
     * @return array<string,mixed>|null
     */
    protected function eventSummary(?MarketingStorefrontEvent $event): ?array
    {
        if (! $event) {
            return null;
        }

        return [
            'id' => (int) $event->id,
            'event_type' => $event->event_type,
            'status' => $event->status,
            'issue_type' => $event->issue_type,
            'endpoint' => $event->endpoint,
            'source_surface' => $event->source_surface,
            'resolution_status' => $event->resolution_status,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'meta' => $event->meta,
        ];
    }

    /**
     * @param array{redeem_enabled:bool,cta_label:string,message:string,mode:string} $accessPayload
     */
    protected function primaryRedemptionIssue(
        array $accessPayload,
        int $openIssuedCount,
        ?float $rewardCost,
        float $balance,
        ?MarketingStorefrontEvent $latestIssue
    ): ?string {
        if (! ($accessPayload['redeem_enabled'] ?? false)) {
            return 'coming_soon';
        }

        $maxOpenCodes = (int) config('marketing.candle_cash.max_open_codes', 1);
        if ($maxOpenCodes > 0 && $openIssuedCount >= $maxOpenCodes) {
            return 'open_code_exists';
        }

        if ($rewardCost !== null && $balance < $rewardCost) {
            return 'insufficient_candle_cash';
        }

        if ($latestIssue && $latestIssue->issue_type) {
            return (string) $latestIssue->issue_type;
        }

        if ($latestIssue && $latestIssue->status !== 'ok') {
            return (string) $latestIssue->status;
        }

        return null;
    }
}

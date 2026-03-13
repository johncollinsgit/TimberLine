<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\CandleCashRedemption;
use App\Models\MarketingStorefrontEvent;
use App\Services\Marketing\CandleCashRedemptionReconciliationService;
use App\Support\Marketing\MarketingSectionRegistry;
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


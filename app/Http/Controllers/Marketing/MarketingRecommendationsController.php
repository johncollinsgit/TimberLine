<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingProfile;
use App\Models\MarketingRecommendation;
use App\Models\MarketingRecommendationRun;
use App\Services\Marketing\MarketingApprovalService;
use App\Services\Marketing\MarketingRecommendationEngine;
use App\Services\Marketing\MarketingTenantOwnershipService;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MarketingRecommendationsController extends Controller
{
    public function __construct(
        protected MarketingTenantOwnershipService $ownershipService
    ) {
    }

    public function index(Request $request): View
    {
        $tenantId = $this->resolveTenantId($request);
        $strict = $this->strictTenantMode();
        $status = trim((string) $request->query('status', 'pending'));
        $type = trim((string) $request->query('type', 'all'));

        $recommendationsQuery = MarketingRecommendation::query()
            ->with(['campaign:id,name', 'profile:id,first_name,last_name,email', 'variant:id,name'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($type !== 'all', fn ($query) => $query->where('type', $type))
            ->orderByRaw("case when status='pending' then 0 else 1 end")
            ->orderByDesc('id');

        if ($strict && $tenantId !== null) {
            $recommendationIds = $this->ownershipService->tenantRecommendationIds($tenantId);
            if ($recommendationIds->isEmpty()) {
                $recommendationsQuery->whereRaw('1 = 0');
            } else {
                $recommendationsQuery->whereIn('id', $recommendationIds->all());
            }
        }

        $recommendations = $recommendationsQuery
            ->paginate(30)
            ->withQueryString();

        $approvalRecipientsQuery = MarketingCampaignRecipient::query()
            ->with(['campaign:id,name', 'profile:id,first_name,last_name,email,phone', 'variant:id,name'])
            ->where('status', 'queued_for_approval')
            ->orderByDesc('id')
            ->limit(100);
        if ($strict && $tenantId !== null) {
            $approvalRecipientsQuery->whereHas('profile', fn ($query) => $query->forTenantId($tenantId));
        }
        $approvalRecipients = $approvalRecipientsQuery->get();

        $typeSummaryQuery = MarketingRecommendation::query()
            ->selectRaw('type, count(*) as aggregate')
            ->where('status', 'pending')
            ->groupBy('type');
        if ($strict && $tenantId !== null) {
            $recommendationIds = $this->ownershipService->tenantRecommendationIds($tenantId);
            if ($recommendationIds->isEmpty()) {
                $typeSummaryQuery->whereRaw('1 = 0');
            } else {
                $typeSummaryQuery->whereIn('id', $recommendationIds->all());
            }
        }
        $typeSummary = $typeSummaryQuery->pluck('aggregate', 'type')->all();

        $latestRuns = $strict
            ? collect()
            : MarketingRecommendationRun::query()
                ->orderByDesc('id')
                ->limit(12)
                ->get();

        return view('marketing/recommendations/index', [
            'section' => MarketingSectionRegistry::section('recommendations'),
            'sections' => $this->navigationItems(),
            'recommendations' => $recommendations,
            'approvalRecipients' => $approvalRecipients,
            'status' => $status,
            'type' => $type,
            'typeSummary' => $typeSummary,
            'latestRuns' => $latestRuns,
        ]);
    }

    public function generateGlobal(Request $request, MarketingRecommendationEngine $engine): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $result = $engine->generateGlobal([
            'tenant_id' => $tenantId,
        ]);

        return redirect()
            ->route('marketing.recommendations')
            ->with('toast', [
                'style' => 'success',
                'message' => "Generated {$result['created']} global recommendations (potential {$result['potential']}).",
            ]);
    }

    public function createForProfile(
        MarketingProfile $profile,
        Request $request,
        MarketingRecommendationEngine $engine
    ): RedirectResponse {
        $tenantId = $this->resolveTenantId($request);
        if ($this->strictTenantMode() && $tenantId !== null && (int) ($profile->tenant_id ?? 0) !== $tenantId) {
            abort(404);
        }

        $sendResult = $engine->generateSendSuggestionForProfile($profile, null, [
            'tenant_id' => $tenantId,
        ]);
        $consentResult = $engine->generateConsentCaptureSuggestionForProfile($profile, null, [
            'tenant_id' => $tenantId,
        ]);

        $created = (int) ($sendResult['created'] ?? 0) + (int) ($consentResult['created'] ?? 0);
        $message = $created > 0
            ? "Generated {$created} recommendation(s) for this profile."
            : 'No recommendations were generated for this profile under current rules.';

        $redirectTo = trim((string) $request->input('redirect_to', ''));
        if ($redirectTo === 'profile') {
            return redirect()
                ->route('marketing.customers.show', $profile)
                ->with('toast', ['style' => 'success', 'message' => $message]);
        }

        return redirect()
            ->route('marketing.recommendations')
            ->with('toast', ['style' => 'success', 'message' => $message]);
    }

    public function approve(
        MarketingRecommendation $recommendation,
        Request $request,
        MarketingApprovalService $approvalService
    ): RedirectResponse {
        $tenantId = $this->resolveTenantId($request);
        $this->assertRecommendationAccess($recommendation, $tenantId);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $approvalService->approveRecommendation($recommendation, (int) auth()->id(), $data['notes'] ?? null);

        return redirect()
            ->route('marketing.recommendations')
            ->with('toast', ['style' => 'success', 'message' => 'Recommendation approved.']);
    }

    public function reject(
        MarketingRecommendation $recommendation,
        Request $request,
        MarketingApprovalService $approvalService
    ): RedirectResponse {
        $tenantId = $this->resolveTenantId($request);
        $this->assertRecommendationAccess($recommendation, $tenantId);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $approvalService->rejectRecommendation($recommendation, (int) auth()->id(), $data['notes'] ?? null);

        return redirect()
            ->route('marketing.recommendations')
            ->with('toast', ['style' => 'warning', 'message' => 'Recommendation rejected.']);
    }

    public function dismiss(
        MarketingRecommendation $recommendation,
        Request $request,
        MarketingApprovalService $approvalService
    ): RedirectResponse {
        $tenantId = $this->resolveTenantId($request);
        $this->assertRecommendationAccess($recommendation, $tenantId);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $approvalService->dismissRecommendation($recommendation, (int) auth()->id(), $data['notes'] ?? null);

        return redirect()
            ->route('marketing.recommendations')
            ->with('toast', ['style' => 'warning', 'message' => 'Recommendation dismissed.']);
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

    protected function strictTenantMode(): bool
    {
        return $this->ownershipService->strictModeEnabled();
    }

    protected function resolveTenantId(Request $request): ?int
    {
        return $this->ownershipService->resolveTenantId($request, $this->strictTenantMode());
    }

    protected function assertRecommendationAccess(MarketingRecommendation $recommendation, ?int $tenantId): void
    {
        if (! $this->strictTenantMode() || $tenantId === null) {
            return;
        }

        if (! $this->ownershipService->recommendationOwnedByTenant((int) $recommendation->id, $tenantId)) {
            abort(404);
        }
    }
}

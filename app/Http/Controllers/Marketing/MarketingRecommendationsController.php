<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingProfile;
use App\Models\MarketingRecommendation;
use App\Services\Marketing\MarketingApprovalService;
use App\Services\Marketing\MarketingRecommendationEngine;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MarketingRecommendationsController extends Controller
{
    public function index(Request $request): View
    {
        $status = trim((string) $request->query('status', 'pending'));
        $type = trim((string) $request->query('type', 'all'));

        $recommendations = MarketingRecommendation::query()
            ->with(['campaign:id,name', 'profile:id,first_name,last_name,email', 'variant:id,name'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($type !== 'all', fn ($query) => $query->where('type', $type))
            ->orderByRaw("case when status='pending' then 0 else 1 end")
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $approvalRecipients = MarketingCampaignRecipient::query()
            ->with(['campaign:id,name', 'profile:id,first_name,last_name,email,phone', 'variant:id,name'])
            ->where('status', 'queued_for_approval')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('marketing/recommendations/index', [
            'section' => MarketingSectionRegistry::section('recommendations'),
            'sections' => $this->navigationItems(),
            'recommendations' => $recommendations,
            'approvalRecipients' => $approvalRecipients,
            'status' => $status,
            'type' => $type,
        ]);
    }

    public function generateGlobal(MarketingRecommendationEngine $engine): RedirectResponse
    {
        $result = $engine->generateGlobal();

        return redirect()
            ->route('marketing.recommendations')
            ->with('toast', ['style' => 'success', 'message' => "Generated {$result['created']} global recommendations."]);
    }

    public function createForProfile(
        MarketingProfile $profile,
        Request $request,
        MarketingRecommendationEngine $engine
    ): RedirectResponse {
        $sendResult = $engine->generateSendSuggestionForProfile($profile);
        $consentResult = $engine->generateConsentCaptureSuggestionForProfile($profile);

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
}

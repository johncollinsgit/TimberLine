<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingIdentityReview;
use App\Models\MarketingProfile;
use App\Services\Marketing\MarketingProfileSyncService;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MarketingIdentityReviewController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', 'pending'));
        $perPage = max(10, min(100, (int) $request->query('per_page', 25)));

        if (!in_array($status, ['all', 'pending', 'resolved', 'ignored'], true)) {
            $status = 'pending';
        }

        $reviews = MarketingIdentityReview::query()
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('raw_first_name', 'like', '%' . $search . '%')
                        ->orWhere('raw_last_name', 'like', '%' . $search . '%')
                        ->orWhere('raw_email', 'like', '%' . $search . '%')
                        ->orWhere('raw_phone', 'like', '%' . $search . '%')
                        ->orWhere('source_type', 'like', '%' . $search . '%')
                        ->orWhere('source_id', 'like', '%' . $search . '%');
                });
            })
            ->with(['reviewer:id,name,email', 'proposedMarketingProfile:id,first_name,last_name,email'])
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return view('marketing.identity-reviews.index', [
            'section' => MarketingSectionRegistry::section('identity-review'),
            'sections' => $this->navigationItems(),
            'reviews' => $reviews,
            'search' => $search,
            'status' => $status,
            'perPage' => $perPage,
        ]);
    }

    public function show(MarketingIdentityReview $review, Request $request): View
    {
        $review->load(['reviewer:id,name,email', 'proposedMarketingProfile:id,first_name,last_name,email']);

        $payload = is_array($review->payload) ? $review->payload : [];
        $profileSearch = trim((string) $request->query('profile_search', ''));

        $candidateIds = collect([
            ...((array) ($payload['email_match_profile_ids'] ?? [])),
            ...((array) ($payload['phone_match_profile_ids'] ?? [])),
            ...((array) ($payload['source_link_profile_ids'] ?? [])),
            (int) ($payload['source_link_profile_id'] ?? 0),
            (int) ($payload['matched_profile_id'] ?? 0),
        ])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $candidateProfiles = MarketingProfile::query()
            ->when($candidateIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $candidateIds->all()))
            ->when($profileSearch !== '', function ($query) use ($profileSearch): void {
                $query->where(function ($nested) use ($profileSearch): void {
                    $nested->where('first_name', 'like', '%' . $profileSearch . '%')
                        ->orWhere('last_name', 'like', '%' . $profileSearch . '%')
                        ->orWhere('email', 'like', '%' . $profileSearch . '%')
                        ->orWhere('phone', 'like', '%' . $profileSearch . '%');
                });
            })
            ->orderByDesc('updated_at')
            ->limit(25)
            ->get();

        if ($candidateProfiles->isEmpty() && $profileSearch === '') {
            $candidateProfiles = MarketingProfile::query()
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get();
        }

        return view('marketing.identity-reviews.show', [
            'section' => MarketingSectionRegistry::section('identity-review'),
            'sections' => $this->navigationItems(),
            'review' => $review,
            'payload' => $payload,
            'candidateProfiles' => $candidateProfiles,
            'profileSearch' => $profileSearch,
        ]);
    }

    public function resolveExisting(
        MarketingIdentityReview $review,
        Request $request,
        MarketingProfileSyncService $syncService
    ): RedirectResponse {
        $data = $request->validate([
            'profile_id' => ['required', 'integer', 'exists:marketing_profiles,id'],
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $profile = MarketingProfile::query()->findOrFail((int) $data['profile_id']);
        $syncService->resolveReviewToExistingProfile(
            review: $review,
            profile: $profile,
            reviewedBy: auth()->id(),
            resolutionNotes: (string) ($data['resolution_notes'] ?? '')
        );

        return redirect()
            ->route('marketing.identity-review.show', $review)
            ->with('toast', ['style' => 'success', 'message' => 'Identity review resolved to existing profile.']);
    }

    public function resolveNew(
        MarketingIdentityReview $review,
        Request $request,
        MarketingProfileSyncService $syncService
    ): RedirectResponse {
        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $syncService->resolveReviewToNewProfile(
            review: $review,
            profileData: $data,
            reviewedBy: auth()->id(),
            resolutionNotes: (string) ($data['resolution_notes'] ?? '')
        );

        return redirect()
            ->route('marketing.identity-review.show', $review)
            ->with('toast', ['style' => 'success', 'message' => 'Identity review resolved by creating a new profile.']);
    }

    public function ignore(
        MarketingIdentityReview $review,
        Request $request,
        MarketingProfileSyncService $syncService
    ): RedirectResponse {
        $data = $request->validate([
            'resolution_notes' => ['required', 'string', 'max:2000'],
        ]);

        $syncService->ignoreReview(
            review: $review,
            reviewedBy: auth()->id(),
            resolutionNotes: (string) $data['resolution_notes']
        );

        return redirect()
            ->route('marketing.identity-review.show', $review)
            ->with('toast', ['style' => 'warning', 'message' => 'Identity review dismissed.']);
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

<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\CustomModuleRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LandlordCustomModuleRequestController extends Controller
{
    public function index(Request $request): View
    {
        $filter = strtolower(trim((string) $request->query('filter', 'all')));
        $filterOptions = $this->filterOptions();
        $activeFilter = array_key_exists($filter, $filterOptions) ? $filter : 'all';

        $query = CustomModuleRequest::query()
            ->with(['tenant', 'requester', 'reviewer'])
            ->latest('id');

        if (in_array($activeFilter, CustomModuleRequest::STATUSES, true)) {
            $query->where('status', $activeFilter);
        } elseif ($activeFilter === 'mobile_relevance') {
            $query->whereNotNull('mobile_relevance')
                ->whereNotIn('mobile_relevance', ['none', 'undecided']);
        } elseif ($activeFilter === 'reusable_module_interest') {
            $query->where('reusable_module_interest', true);
        }

        return view('landlord.custom-module-requests.index', [
            'requests' => $query->get(),
            'filterOptions' => $filterOptions,
            'activeFilter' => $activeFilter,
            'statusLabels' => $this->statusLabels(),
        ]);
    }

    public function update(Request $request, CustomModuleRequest $customModuleRequest): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(CustomModuleRequest::STATUSES)],
            'next_action' => ['nullable', 'string', 'max:500'],
            'landlord_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        /** @var User|null $user */
        $user = $request->user();
        $customModuleRequest->fill([
            'status' => (string) $validated['status'],
            'next_action' => $this->nullableText($validated['next_action'] ?? null, 500),
            'landlord_notes' => $this->nullableText($validated['landlord_notes'] ?? null, 5000),
            'reviewed_by_user_id' => $user?->id,
            'reviewed_at' => now(),
        ])->save();

        return redirect()
            ->route('landlord.custom-module-requests.index', ['filter' => (string) $validated['status']])
            ->with('status', 'Custom module request triage updated.');
    }

    /**
     * @return array<string,string>
     */
    protected function filterOptions(): array
    {
        return [
            'all' => 'All',
            'new' => 'New',
            'needs_discovery' => 'Needs discovery',
            'quoted' => 'Quoted',
            'approved' => 'Approved',
            'in_development' => 'In development',
            'in_review' => 'In review',
            'installed' => 'Installed',
            'converted_to_reusable_module' => 'Converted to reusable module',
            'declined' => 'Declined',
            'archived' => 'Archived',
            'mobile_relevance' => 'Mobile relevance',
            'reusable_module_interest' => 'Reusable module interest',
        ];
    }

    /**
     * @return array<string,string>
     */
    protected function statusLabels(): array
    {
        return collect(CustomModuleRequest::STATUSES)
            ->mapWithKeys(static fn (string $status): array => [$status => Str::headline(str_replace('_', ' ', $status))])
            ->all();
    }

    protected function nullableText(mixed $value, int $limit): ?string
    {
        $text = Str::limit(trim((string) $value), $limit, '');

        return $text !== '' ? $text : null;
    }
}

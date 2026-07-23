<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\LandlordProspect;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LandlordProspectOnboardingController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => strtolower(trim((string) $request->query('status', 'all'))),
            'trade' => trim((string) $request->query('trade', 'all')),
            'county' => trim((string) $request->query('county', 'all')),
        ];

        $prospects = $this->filteredQuery($filters)
            ->with([
                'communications' => fn ($query) => $query->orderByDesc('occurred_at')->orderByDesc('id'),
                'convertedTenant:id,name,slug',
            ])
            ->orderByRaw("case status
                when 'replied' then 0
                when 'meeting_scheduled' then 1
                when 'contacted' then 2
                when 'draft_ready' then 3
                else 4 end")
            ->orderBy('business_name')
            ->paginate(50)
            ->withQueryString();

        $statusCounts = LandlordProspect::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count);

        return view('landlord.onboarding.prospects', [
            'prospects' => $prospects,
            'filters' => $filters,
            'statusOptions' => $this->statusOptions(),
            'tradeOptions' => LandlordProspect::query()->whereNotNull('trade')->distinct()->orderBy('trade')->pluck('trade'),
            'countyOptions' => LandlordProspect::query()->whereNotNull('county')->distinct()->orderBy('county')->pluck('county'),
            'metrics' => [
                'total' => LandlordProspect::query()->count(),
                'draft_ready' => (int) ($statusCounts['draft_ready'] ?? 0),
                'replied' => (int) ($statusCounts['replied'] ?? 0),
                'meetings' => (int) ($statusCounts['meeting_scheduled'] ?? 0),
                'converted' => (int) ($statusCounts['converted'] ?? 0),
                'launch_partner_spots_open' => 8,
                'launch_partner_spots_total' => 10,
            ],
        ]);
    }

    public function store(
        Request $request,
        LandlordOperatorActionAuditService $auditService
    ): RedirectResponse {
        $validated = $request->validate($this->prospectRules());
        $validated['created_by_user_id'] = $request->user()?->id;
        $validated['status'] = $validated['status'] ?? 'new';

        $prospect = LandlordProspect::query()->create($this->normalizeProspect($validated));

        $auditService->record(
            tenantId: null,
            actorUserId: $request->user()?->id,
            actionType: 'landlord_prospect_created',
            targetType: 'landlord_prospect',
            targetId: (int) $prospect->id,
            afterState: $prospect->fresh()?->toArray()
        );

        return back()->with('status', 'Prospect added to the onboarding pipeline.');
    }

    public function update(
        Request $request,
        LandlordProspect $prospect,
        LandlordOperatorActionAuditService $auditService
    ): RedirectResponse {
        $validated = $request->validate($this->prospectRules(partial: true));
        $before = $prospect->toArray();

        $prospect->fill($this->normalizeProspect($validated));
        $prospect->save();

        $auditService->record(
            tenantId: $prospect->converted_tenant_id,
            actorUserId: $request->user()?->id,
            actionType: 'landlord_prospect_updated',
            targetType: 'landlord_prospect',
            targetId: (int) $prospect->id,
            beforeState: $before,
            afterState: $prospect->fresh()?->toArray()
        );

        return back()->with('status', 'Prospect details updated.');
    }

    public function storeCommunication(
        Request $request,
        LandlordProspect $prospect,
        LandlordOperatorActionAuditService $auditService
    ): RedirectResponse {
        $validated = $request->validate([
            'direction' => ['required', 'string', 'in:outbound,inbound,note'],
            'channel' => ['required', 'string', 'in:email,phone,meeting,note'],
            'communication_status' => ['required', 'string', 'in:draft,sent,received,replied,scheduled,logged'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'from_address' => ['nullable', 'string', 'max:255'],
            'to_address' => ['nullable', 'string', 'max:255'],
            'external_message_id' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $communication = $prospect->communications()->create([
            'direction' => $validated['direction'],
            'channel' => $validated['channel'],
            'status' => $validated['communication_status'],
            'subject' => $validated['subject'] ?? null,
            'body' => $validated['body'],
            'from_address' => $validated['from_address'] ?? null,
            'to_address' => $validated['to_address'] ?? null,
            'external_message_id' => $validated['external_message_id'] ?? null,
            'occurred_at' => $validated['occurred_at'] ?? now(),
            'created_by_user_id' => $request->user()?->id,
        ]);

        $updates = [];
        if ($validated['direction'] === 'outbound' && $validated['communication_status'] === 'sent') {
            $updates['last_contacted_at'] = $communication->occurred_at;
            if (! in_array($prospect->status, ['replied', 'meeting_scheduled', 'qualified', 'converted'], true)) {
                $updates['status'] = 'contacted';
            }
        }
        if ($validated['direction'] === 'inbound') {
            $updates['responded_at'] = $communication->occurred_at;
            if ($prospect->status !== 'converted') {
                $updates['status'] = 'replied';
            }
        }
        if ($validated['channel'] === 'meeting' && $validated['communication_status'] === 'scheduled' && $prospect->status !== 'converted') {
            $updates['status'] = 'meeting_scheduled';
        }
        if ($updates !== []) {
            $prospect->forceFill($updates)->save();
        }

        $auditService->record(
            tenantId: $prospect->converted_tenant_id,
            actorUserId: $request->user()?->id,
            actionType: 'landlord_prospect_communication_logged',
            targetType: 'landlord_prospect_communication',
            targetId: (int) $communication->id,
            context: [
                'prospect_id' => (int) $prospect->id,
                'direction' => $communication->direction,
                'channel' => $communication->channel,
                'status' => $communication->status,
            ]
        );

        return back()->with('status', 'Communication added to the prospect timeline.');
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => strtolower(trim((string) $request->query('status', 'all'))),
            'trade' => trim((string) $request->query('trade', 'all')),
            'county' => trim((string) $request->query('county', 'all')),
        ];

        $rows = $this->filteredQuery($filters)
            ->withCount('communications')
            ->orderBy('business_name')
            ->get();

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fputcsv($output, [
                'Business',
                'Contact',
                'Trade',
                'County',
                'City',
                'Email',
                'Phone',
                'Website',
                'Status',
                'Last contacted',
                'Response received',
                'Next follow-up',
                'Communications',
                'Notes',
            ]);

            foreach ($rows as $prospect) {
                fputcsv($output, [
                    $prospect->business_name,
                    $prospect->contact_name,
                    $prospect->trade,
                    $prospect->county,
                    $prospect->city,
                    $prospect->email,
                    $prospect->phone,
                    $prospect->website,
                    $prospect->status,
                    optional($prospect->last_contacted_at)?->toDateTimeString(),
                    optional($prospect->responded_at)?->toDateTimeString(),
                    optional($prospect->next_follow_up_at)?->toDateTimeString(),
                    $prospect->communications_count,
                    $prospect->notes,
                ]);
            }

            fclose($output);
        }, 'evergrove-launch-prospects-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string,string>  $filters
     */
    protected function filteredQuery(array $filters): Builder
    {
        return LandlordProspect::query()
            ->when(($filters['q'] ?? '') !== '', function (Builder $query) use ($filters): void {
                $search = (string) $filters['q'];
                $query->where(function (Builder $nested) use ($search): void {
                    $nested
                        ->where('business_name', 'like', '%'.$search.'%')
                        ->orWhere('contact_name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('notes', 'like', '%'.$search.'%');
                });
            })
            ->when(($filters['status'] ?? 'all') !== 'all', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(($filters['trade'] ?? 'all') !== 'all', fn (Builder $query) => $query->where('trade', $filters['trade']))
            ->when(($filters['county'] ?? 'all') !== 'all', fn (Builder $query) => $query->where('county', $filters['county']));
    }

    /**
     * @return array<string,string>
     */
    protected function statusOptions(): array
    {
        return [
            'new' => 'New lead',
            'draft_ready' => 'Draft ready',
            'contacted' => 'Contacted',
            'replied' => 'Replied',
            'meeting_scheduled' => 'Meeting scheduled',
            'qualified' => 'Qualified',
            'converted' => 'Converted',
            'not_fit' => 'Not a fit',
            'unsubscribed' => 'Do not contact',
        ];
    }

    /**
     * @return array<string,array<int,string>>
     */
    protected function prospectRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'business_name' => [$required, 'string', 'max:160'],
            'contact_name' => ['nullable', 'string', 'max:160'],
            'trade' => [$required, 'string', 'max:80'],
            'county' => [$required, 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'url', 'max:500'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys($this->statusOptions()))],
            'source' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'next_follow_up_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    protected function normalizeProspect(array $attributes): array
    {
        foreach (['business_name', 'contact_name', 'trade', 'county', 'city', 'website', 'email', 'phone', 'status', 'source', 'notes'] as $key) {
            if (! array_key_exists($key, $attributes) || $attributes[$key] === null) {
                continue;
            }

            $attributes[$key] = trim((string) $attributes[$key]);
        }

        if (isset($attributes['email'])) {
            $attributes['email'] = Str::lower($attributes['email']);
        }

        return $attributes;
    }
}

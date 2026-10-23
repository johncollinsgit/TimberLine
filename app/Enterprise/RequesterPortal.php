<?php

namespace App\Enterprise;

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/** Existing tenant members can submit to explicitly published intake without joining its department. */
class RequesterPortal
{
    public function __construct(private WorkspaceAccess $access, private RecordStore $store) {}

    private function actor(Tenant $tenant, User $user, bool $write = false): array
    {
        abort_unless(config('enterprise.requester_enabled'), 404);
        $actor = $this->access->actor($tenant, $user, $write);
        abort_unless($user->fresh()?->email_verified_at !== null && $user->fresh()?->is_active, 403);
        abort_if($write && ! in_array($actor['role'], ['owner', 'admin', 'member', 'guest'], true), 403);

        return $actor;
    }

    public function listing(Tenant $tenant, User $user): array
    {
        $this->actor($tenant, $user);
        $areas = TeamArea::query()->where('tenant_id', $tenant->id)->whereIn('profile', WorkspaceAccess::PROFILES)->orderBy('name')->get()
            ->filter(fn ($area) => ($area->settings['enterprise_request_intake'] ?? false) === true)
            ->map(fn ($area) => ['id' => $area->id, 'name' => $area->name])->values()->all();
        // Counts disclose only this requester's records, never hidden department totals.
        $requests = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => 'enterprise_request'])
            ->orderByDesc('created_at')->orderBy('id')->limit(1000)->get()
            ->filter(fn ($row) => ($row->data['created_by'] ?? null) === (string) $user->id)
            ->take(100)->map(fn ($row) => $this->projection($row))->values()->all();

        return ['departments' => $areas, 'requests' => $requests, 'as_of' => now()->toIso8601String(),
            'coverage' => 'Up to 100 of your requests from a bounded scan. Internal notes, staff records and other requesters are excluded.',
            'partial' => true, 'notifications' => 'In-app progress only; no email or SMS has been sent.'];
    }

    private function owned(Tenant $tenant, User $user, string $id): TeamOperationRecord
    {
        $this->actor($tenant, $user);
        $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => 'enterprise_request'])->findOrFail($id);
        abort_unless(($row->data['created_by'] ?? null) === (string) $user->id, 404);

        return $row;
    }

    private function projection(TeamOperationRecord $row): array
    {
        return ['id' => $row->id, 'area_id' => $row->area_id, 'title' => $row->title, 'status' => $row->status,
            'category' => $row->data['requester_category'] ?? 'Projects', 'description' => $row->data['description'] ?? '',
            'created_at' => $row->created_at?->toIso8601String(), 'updated_at' => $row->updated_at?->toIso8601String(), 'version' => $row->version];
    }

    public function show(Tenant $tenant, User $user, string $id): array
    {
        $row = $this->owned($tenant, $user, $id);
        $updates = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $row->area_id, 'kind' => 'ent_request_public'])
            ->orderByDesc('created_at')->orderBy('id')->limit(1000)->get()->filter(fn ($event) => $event->data['request_id'] === $row->id)
            ->take(100)->reverse()->map(fn ($event) => ['id' => $event->id, 'message' => $event->data['message'], 'at' => $event->created_at?->toIso8601String()])->values()->all();

        return $this->projection($row) + ['updates' => $updates, 'partial' => true, 'coverage' => 'Your submitted fields and up to 100 explicitly published updates from a bounded scan only.'];
    }

    public function submit(Tenant $tenant, User $user, array $input): array
    {
        return $this->store->locked($tenant, function () use ($tenant, $user, $input): array {
            $this->actor($tenant, $user, true);
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $receipt = TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($receipt) {
                abort_unless($receipt->kind === 'ent.requester.submit' && hash_equals($receipt->input_hash, $hash), 409);

                return $this->show($tenant, $user, $receipt->result['id']) + ['replayed' => true];
            }
            $area = TeamArea::query()->where('tenant_id', $tenant->id)->whereIn('profile', WorkspaceAccess::PROFILES)->findOrFail($input['area_id']);
            abort_unless(($area->settings['enterprise_request_intake'] ?? false) === true, 404);
            abort_if(TeamOperationRecord::query()->where('id', $input['id'])->exists(), 409);
            $row = TeamOperationRecord::query()->create(['id' => $input['id'], 'tenant_id' => $tenant->id, 'area_id' => $area->id,
                'kind' => 'enterprise_request', 'source_key' => hash('sha256', 'enterprise:'.$input['id']), 'visibility' => 'area',
                'title' => trim($input['title']), 'status' => 'submitted', 'version' => 1,
                'data' => ['created_by' => (string) $user->id, 'description' => trim($input['description']), 'requester_category' => $input['category'],
                    'requester_portal' => true, 'location' => trim($input['location'] ?? ''), 'latitude' => null, 'longitude' => null]]);
            $this->store->audit($row, $user->id, 'requester.submitted');
            TeamOperationAttempt::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
                'record_id' => $row->id, 'user_id' => $user->id, 'kind' => 'ent.requester.submit', 'status' => 'accepted',
                'request_id' => $input['request_id'], 'input_hash' => $hash, 'result' => ['id' => $row->id]]);

            return $this->show($tenant, $user, $row->id) + ['replayed' => false];
        });
    }

    public function publish(Tenant $tenant, User $user, string $id, array $input): array
    {
        return $this->store->locked($tenant, function () use ($tenant, $user, $id, $input): array {
            $actor = $this->actor($tenant, $user, true);
            $request = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => 'enterprise_request'])->findOrFail($id);
            $this->access->area($tenant, $actor, $request->area_id, true);
            app(RequestWorkflow::class)->view($tenant, $actor, $request);
            $source = hash('sha256', 'public-update:'.$user->id.':'.$input['request_id']);
            $hash = hash('sha256', json_encode([$id, $input], JSON_THROW_ON_ERROR));
            $existing = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => 'ent_request_public', 'source_key' => $source])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->data['input_hash'], $hash), 409);

                return ['id' => $existing->id, 'replayed' => true];
            }
            abort_unless($request->version === $input['expected_version'], 409);
            $event = TeamOperationRecord::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $request->area_id,
                'kind' => 'ent_request_public', 'source_key' => $source, 'visibility' => 'area', 'title' => 'Requester-visible update', 'status' => 'published', 'version' => 1,
                'data' => ['request_id' => $id, 'message' => trim($input['message']), 'input_hash' => $hash]]);
            $this->store->audit($event, $user->id, 'requester.update_published', ['request_id' => $id]);

            return ['id' => $event->id, 'replayed' => false];
        });
    }
}

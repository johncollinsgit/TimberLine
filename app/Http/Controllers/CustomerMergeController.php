<?php

namespace App\Http\Controllers;

use App\Models\CustomerMergeOperation;
use App\Models\Tenant;
use App\Services\Marketing\CustomerMergeException;
use App\Services\Marketing\CustomerMergeService;
use App\Services\Marketing\SquareCustomerIdentityService;
use Illuminate\Http\Request;

/** Explicit Core customer consolidation; never requests a provider customer merge. */
class CustomerMergeController extends Controller
{
    public function preview(Request $request, CustomerMergeService $merges)
    {
        $tenant = $this->tenant($request);
        $input = $request->validate(['profile_ids' => 'required|array|min:2|max:10', 'profile_ids.*' => 'required|integer|distinct|min:1',
            'survivor_profile_id' => 'required|integer|min:1', 'request_id' => 'required|uuid', 'field_sources' => 'sometimes|array']);
        $operation = $this->reviewable(fn () => $merges->createOperation($tenant->id, $input['profile_ids'], $input['survivor_profile_id'], 'core', $input['request_id'], $input['field_sources'] ?? [],
            ['source' => 'core_customers', 'initiated_by' => $request->user()->id]));

        return response()->json(['operation_id' => $operation->id, 'version' => $this->version($operation), 'before' => $operation->before_state,
            'rewards' => $operation->reward_resolution, 'survivor_profile_id' => $operation->survivor_profile_id, 'provider_mutations' => false])->header('Cache-Control', 'private, no-store');
    }

    public function apply(Request $request, int $operation, CustomerMergeService $merges)
    {
        $tenant = $this->tenant($request);
        $input = $request->validate(['expected_version' => 'required|string|size:64', 'identity_confirmed' => 'required|accepted']);
        $record = $this->reviewable(fn () => app(\App\Services\Marketing\CustomerAccountFence::class)->forTenant($tenant->id, function () use ($tenant, $request, $operation, $input, $merges) {
            app(SquareCustomerIdentityService::class)->authorize($tenant->id, $request->user());
            $record = CustomerMergeOperation::where('tenant_id', $tenant->id)->where('source', 'core_customers')->lockForUpdate()->findOrFail($operation);
            if ($record->status !== 'completed') {
                abort_unless($record->status === 'preview', 409, 'Review a new customer merge first.');
                abort_unless(hash_equals($this->version($record), $input['expected_version']), 409, 'Review the current customer merge first.');
                $merges->assertCurrentPreview($record);
                $record->update(['approved_by' => $request->user()->id]);
                $record = $merges->apply($record);
            }

            return $record;
        }));

        return response()->json(['status' => $record->status, 'profile_id' => $record->survivor_profile_id,
            'url' => route('customers.profile', ['profile' => $record->survivor_profile_id, 'tenant' => $tenant->slug])])->header('Cache-Control', 'private, no-store');
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        app(SquareCustomerIdentityService::class)->authorize($tenant->id, $request->user());

        return $tenant;
    }

    private function reviewable(callable $work): CustomerMergeOperation
    {
        try {
            return $work();
        } catch (CustomerMergeException $error) {
            abort(409, $error->getMessage());
        }
    }

    public function version(CustomerMergeOperation $operation): string
    {
        return app(CustomerMergeService::class)->previewVersion($operation);
    }
}

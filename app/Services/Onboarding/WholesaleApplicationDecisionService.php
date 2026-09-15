<?php

namespace App\Services\Onboarding;

use App\Models\CustomerAccessRequest;
use App\Models\Tenant;
use App\Services\Shopify\ShopifyWholesaleCustomerApprovalService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

class WholesaleApplicationDecisionService
{
    public function decide(int $id, int $actorId, string $status, ?string $note): CustomerAccessRequest
    {
        return DB::transaction(function () use ($id, $actorId, $status, $note) {
            $request = CustomerAccessRequest::wholesaleApplications()->lockForUpdate()->findOrFail($id);
            $tenant = Tenant::where('slug', config('product_surfaces.access_request.wholesale_storefront_tenant_slug'))->firstOrFail();
            if ((int) $request->tenant_id !== (int) $tenant->id) {
                throw new DomainException('This application does not belong to the wholesale store.');
            }
            if ($request->status === $status) {
                return $request;
            }
            if ($request->status !== 'pending') {
                throw new DomainException('This application has already been decided.');
            }
            $before = ['status' => $request->status];
            if ($status === 'approved') {
                // Shopify tagging is idempotent. A failure must never appear approved.
                try {
                    $sync = app(ShopifyWholesaleCustomerApprovalService::class)->syncByEmail($request->email, ['name' => $request->name, 'company' => $request->company]);
                } catch (\Throwable $e) {
                    report($e);
                    throw new DomainException('Shopify could not grant access. The application is still pending; please try Approve again.');
                }
                $metadata = $request->metadata ?? [];
                $metadata['shopify_customer_gid'] = $sync['customer_gid'];
                $metadata['shopify_access'] = 'ready';
                $request->metadata = $metadata;
                $request->approved_by = $actorId;
                $request->approved_at = now();
                $request->decision_note = $note;
            } else {
                $request->rejected_by = $actorId;
                $request->rejected_at = now();
                $request->rejection_note = $note;
            }
            $request->status = $status;
            $request->save();
            app(WholesaleApplicationDeliveryService::class)->enqueue($request, 'decision');
            app(LandlordOperatorActionAuditService::class)->record(
                tenantId: $tenant->id, actorUserId: $actorId,
                actionType: 'customer_access_request.'.($status === 'approved' ? 'approve' : 'reject'),
                status: 'success', targetType: 'customer_access_request', targetId: $id,
                beforeState: $before, afterState: ['status' => $status],
            );

            return $request->fresh();
        });
    }

    public function resend(int $id, int $actorId): CustomerAccessRequest
    {
        return DB::transaction(function () use ($id, $actorId) {
            $request = CustomerAccessRequest::wholesaleApplications()->lockForUpdate()->findOrFail($id);
            if ($request->status !== 'approved') {
                throw new DomainException('Only approved applications can receive a welcome email.');
            }
            $tenant = Tenant::where('slug', config('product_surfaces.access_request.wholesale_storefront_tenant_slug'))->firstOrFail();
            if ((int) $request->tenant_id !== (int) $tenant->id) {
                throw new DomainException('This application does not belong to the wholesale store.');
            }
            if (in_array(data_get($request->metadata, 'delivery.decision.status'), ['pending', 'failed'], true)) {
                return $request;
            }
            if ($request->activation_email_last_sent_at?->greaterThan(now()->subMinute())) {
                return $request;
            }
            app(WholesaleApplicationDeliveryService::class)->enqueue($request, 'decision', true);
            app(LandlordOperatorActionAuditService::class)->record(
                tenantId: $tenant->id, actorUserId: $actorId,
                actionType: 'customer_access_request.resend_welcome',
                status: 'success', targetType: 'customer_access_request', targetId: $id,
                beforeState: ['delivery' => 'sent'], afterState: ['delivery' => 'pending'],
            );

            return $request->fresh();
        });
    }
}

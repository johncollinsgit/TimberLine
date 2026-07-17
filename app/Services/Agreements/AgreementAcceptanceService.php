<?php

namespace App\Services\Agreements;

use App\Models\Agreement;
use App\Models\AgreementAcceptance;
use App\Models\SubscriptionAuthorization;
use App\Services\Billing\AgreementBillingOrderService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AgreementAcceptanceService
{
    public function __construct(
        protected AgreementEventRecorder $events,
        protected LandlordOperatorActionAuditService $audit,
        protected AgreementBillingOrderService $billingOrders,
    ) {}

    /** @param array<string,mixed> $input */
    public function accept(Agreement $agreement, array $input, Request $request): AgreementAcceptance
    {
        $snapshotPath = null;

        try {
            return DB::transaction(function () use ($agreement, $input, $request, &$snapshotPath): AgreementAcceptance {
                $locked = Agreement::query()->with('currentVersion')->lockForUpdate()->findOrFail($agreement->id);
                if (! in_array($locked->status, ['sent', 'viewed'], true) || ! $locked->currentVersion) {
                    throw new RuntimeException('This proposal is no longer available for acceptance.');
                }
                if ($locked->acceptances()->exists()) {
                    throw new RuntimeException('This agreement has already been accepted.');
                }

                foreach (['authorized_to_bind', 'accepted_scope', 'accepted_pricing', 'accepted_subscription', 'accepted_hourly_rate', 'accepted_termination', 'electronic_consent'] as $confirmation) {
                    if (($input[$confirmation] ?? false) !== true && ($input[$confirmation] ?? null) !== '1' && ($input[$confirmation] ?? null) !== 1) {
                        throw new RuntimeException('Every required agreement confirmation must be accepted.');
                    }
                }

                $acceptedAt = CarbonImmutable::now();
                $evidence = [
                    'agreement_id' => (int) $locked->id,
                    'agreement_version_id' => (int) $locked->currentVersion->id,
                    'content_hash' => (string) $locked->currentVersion->content_hash,
                    'signer_legal_name' => trim((string) $input['signer_legal_name']),
                    'signer_title' => trim((string) $input['signer_title']),
                    'signer_email' => strtolower(trim((string) $input['signer_email'])),
                    'electronic_signature_value' => trim((string) $input['electronic_signature_value']),
                    'accepted_at' => $acceptedAt->toIso8601String(),
                    'ip_hash' => hash('sha256', (string) $request->ip()),
                    'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
                    'confirmations' => array_fill_keys(['authorized_to_bind', 'accepted_scope', 'accepted_pricing', 'accepted_subscription', 'accepted_hourly_rate', 'accepted_termination', 'electronic_consent'], true),
                ];
                $evidenceHash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                $snapshot = view('agreements.snapshot', ['version' => $locked->currentVersion, 'evidence' => $evidence, 'evidenceHash' => $evidenceHash])->render();
                $snapshotHash = hash('sha256', $snapshot);
                $snapshotPath = 'agreements/snapshots/'.(int) $locked->tenant_id.'/'.Str::uuid().'.html';
                if (! Storage::disk('local')->put($snapshotPath, $snapshot)) {
                    throw new RuntimeException('The permanent agreement snapshot could not be written.');
                }

                $acceptance = AgreementAcceptance::query()->create([
                    'agreement_id' => (int) $locked->id,
                    'agreement_version_id' => (int) $locked->currentVersion->id,
                    'tenant_id' => (int) $locked->tenant_id,
                    'accepted_by_user_id' => $request->user()?->id,
                    'signer_legal_name' => $evidence['signer_legal_name'],
                    'signer_title' => $evidence['signer_title'],
                    'signer_email' => $evidence['signer_email'],
                    'electronic_signature_value' => $evidence['electronic_signature_value'],
                    'electronic_signature_type' => 'typed',
                    'authorized_to_bind' => true,
                    'accepted_scope' => true,
                    'accepted_pricing' => true,
                    'accepted_subscription' => true,
                    'accepted_hourly_rate' => true,
                    'accepted_termination' => true,
                    'electronic_consent' => true,
                    'accepted_at' => $acceptedAt,
                    'ip_address' => $request->ip(),
                    'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
                    'evidence_hash' => $evidenceHash,
                    'snapshot_path' => $snapshotPath,
                    'snapshot_hash' => $snapshotHash,
                    'created_at' => $acceptedAt,
                ]);

                $locked->forceFill(['status' => 'active', 'accepted_at' => $acceptedAt, 'effective_at' => $acceptedAt])->save();
                $subscription = (array) $locked->currentVersion->subscription_payload;
                $authorization = SubscriptionAuthorization::query()->create([
                    'tenant_id' => (int) $locked->tenant_id,
                    'agreement_id' => (int) $locked->id,
                    'agreement_version_id' => (int) $locked->currentVersion->id,
                    'agreement_acceptance_id' => (int) $acceptance->id,
                    'billing_lane' => (string) ($subscription['billing_lane'] ?? 'unapproved'),
                    'provider' => (string) ($subscription['provider'] ?? 'unverified'),
                    'purchase_key' => (string) ($subscription['purchase_key'] ?? 'unknown'),
                    'status' => 'authorized_pending_provider',
                    'pricing_model' => (string) ($subscription['pricing_model'] ?? 'agreement_specific'),
                    'currency' => (string) ($subscription['currency'] ?? 'USD'),
                    'billing_interval' => (string) ($subscription['billing_interval'] ?? 'month'),
                    'onboarding_amount_cents' => (int) ($subscription['onboarding_amount_cents'] ?? 0),
                    'promotional_amount_cents' => (int) ($subscription['promotional_amount_cents'] ?? 0),
                    'promotional_cycles' => (int) ($subscription['promotional_cycles'] ?? 0),
                    'standard_amount_cents' => (int) ($subscription['standard_amount_cents'] ?? 0),
                    'tax_treatment' => 'provider_calculated_if_applicable',
                    'tax_disclosure' => (string) data_get($locked->currentVersion->pricing_payload, 'tax_disclosure', ''),
                    'authorized_line_items' => (array) ($subscription['authorized_line_items'] ?? []),
                    'authorized_at' => $acceptedAt,
                    'metadata' => ['canonical_plan_key' => $subscription['canonical_plan_key'] ?? null, 'activation_requirements' => $subscription['activation_requirements'] ?? [], 'activation_status' => 'disabled_pending_verified_payment'],
                ]);
                $this->billingOrders->authorize($locked, $acceptance, $authorization);
                $this->events->record($locked, 'accepted', $request->user()?->id, ['content_hash' => $locked->currentVersion->content_hash, 'evidence_hash' => $evidenceHash], $locked->currentVersion);
                $this->audit->record((int) $locked->tenant_id, $request->user()?->id, 'agreement.accept', targetType: 'agreement', targetId: $locked->id, afterState: [
                    'status' => 'active', 'version_id' => $locked->currentVersion->id, 'content_hash' => $locked->currentVersion->content_hash, 'subscription_status' => 'authorized_pending_provider',
                ]);

                return $acceptance;
            });
        } catch (\Throwable $exception) {
            if ($snapshotPath !== null && ! AgreementAcceptance::query()->where('snapshot_path', $snapshotPath)->exists()) {
                Storage::disk('local')->delete($snapshotPath);
            }
            throw $exception;
        }
    }
}

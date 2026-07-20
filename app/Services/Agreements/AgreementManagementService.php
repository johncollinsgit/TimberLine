<?php

namespace App\Services\Agreements;

use App\Models\Agreement;
use App\Models\AgreementVersion;
use App\Models\Tenant;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AgreementManagementService
{
    public function __construct(
        protected FrontYardFoodsAgreementTemplate $frontYardTemplate,
        protected CollinsElectricAgreementTemplate $collinsElectricTemplate,
        protected SupplementalWorkAgreementTemplate $supplementalTemplate,
        protected AgreementDocumentRenderer $renderer,
        protected AgreementEventRecorder $events,
        protected LandlordOperatorActionAuditService $audit,
    ) {}

    public function prepareCollinsElectric(
        Tenant $tenant,
        ?int $actorUserId,
        int $onboardingAmountCents = 29900,
        int $launchPartnerAmountCents = 5900,
        int $standardAmountCents = 14900,
        ?string $additionalScope = null,
    ): Agreement {
        $payload = $this->collinsElectricTemplate->build($onboardingAmountCents, $launchPartnerAmountCents, $standardAmountCents, $additionalScope);

        return $this->prepareClientAgreement($tenant, $actorUserId, Agreement::TEMPLATE_COLLINS_ELECTRIC_CLIENT_SERVICES, $payload);
    }

    public function prepareFrontYardFoods(
        Tenant $tenant,
        ?int $actorUserId,
        ?int $implementationAmountCents = null,
        ?int $dueOnAcceptanceCents = null,
        ?int $dueBeforeLaunchCents = null,
        ?string $additionalScope = null,
    ): Agreement {
        $payload = $this->frontYardTemplate->build($implementationAmountCents, $dueOnAcceptanceCents, $dueBeforeLaunchCents, $additionalScope);

        return $this->prepareClientAgreement($tenant, $actorUserId, Agreement::TEMPLATE_FRONT_YARD_CLIENT_SERVICES, $payload);
    }

    /** @param array<string,mixed> $payload */
    protected function prepareClientAgreement(Tenant $tenant, ?int $actorUserId, string $templateKey, array $payload): Agreement
    {

        return DB::transaction(function () use ($tenant, $actorUserId, $templateKey, $payload): Agreement {
            $agreement = Agreement::query()
                ->forTenant($tenant)
                ->where('agreement_type', Agreement::TYPE_FRONT_YARD_CLIENT_SERVICES)
                ->where('template_key', $templateKey)
                ->whereNull('parent_agreement_id')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $agreement) {
                $agreement = Agreement::query()->create([
                    'tenant_id' => (int) $tenant->id,
                    'agreement_type' => (string) $payload['agreement_type'],
                    'template_key' => $templateKey,
                    'title' => (string) $payload['title'],
                    'status' => 'draft',
                    'created_by' => $actorUserId,
                    'updated_by' => $actorUserId,
                ]);
                $this->events->record($agreement, 'created', $actorUserId);
            }

            if (in_array($agreement->status, ['accepted', 'active', 'termination_pending', 'terminated'], true)) {
                return $agreement->load('currentVersion');
            }

            $rendered = $this->renderer->render($payload);
            $hash = $this->renderer->hash($rendered);
            $current = $agreement->currentVersion;

            if (! $current || ! hash_equals((string) $current->content_hash, $hash)) {
                $version = AgreementVersion::query()->create([
                    'agreement_id' => (int) $agreement->id,
                    'version_number' => ((int) $agreement->versions()->max('version_number')) + 1,
                    'title' => (string) $payload['title'],
                    'rendered_content' => $rendered,
                    'content_payload' => (array) $payload['content'],
                    'scope_payload' => (array) $payload['scope'],
                    'pricing_payload' => (array) $payload['pricing'],
                    'subscription_payload' => (array) $payload['subscription'],
                    'termination_payload' => (array) $payload['termination'],
                    'content_hash' => $hash,
                    'created_by' => $actorUserId,
                    'created_at' => now(),
                ]);
                $agreement->forceFill([
                    'current_version_id' => (int) $version->id,
                    'title' => (string) $payload['title'],
                    'updated_by' => $actorUserId,
                ])->save();
                $this->events->record($agreement, 'version_created', $actorUserId, ['version_number' => $version->version_number, 'content_hash' => $hash], $version);
            }

            $agreement->load('currentVersion');
            $this->audit->record((int) $tenant->id, $actorUserId, 'agreement.prepare', targetType: 'agreement', targetId: $agreement->id, afterState: [
                'status' => $agreement->status,
                'version_id' => $agreement->current_version_id,
                'content_hash' => $agreement->currentVersion?->content_hash,
            ]);

            return $agreement;
        });
    }

    /**
     * Create a disposable validation agreement without selecting, versioning, or
     * otherwise changing the canonical client agreement.
     */
    public function createFrontYardFoodsSandboxValidation(Tenant $tenant, ?int $actorUserId): Agreement
    {
        $payload = $this->frontYardTemplate->build();
        $payload['agreement_type'] = Agreement::TYPE_SANDBOX_VALIDATION;
        $payload['title'] = 'TEST MODE ONLY — '.$payload['title'];
        $payload['content']['agreement_type'] = Agreement::TYPE_SANDBOX_VALIDATION;
        $payload['content']['title'] = $payload['title'];
        $payload['content']['purpose'] = 'TEST MODE ONLY. This disposable agreement validates the Evergrove proposal, Stripe Checkout, invoice, receipt, subscription schedule, and webhook flow. It does not activate service, grant workspace access, or replace the real Front Yard Foods agreement.';
        $payload['subscription']['validation_only'] = true;
        $payload['subscription']['activation_status'] = 'validation_only_no_entitlement';
        $payload['subscription']['activation_requirements'] = [];

        return DB::transaction(function () use ($tenant, $actorUserId, $payload): Agreement {
            $agreement = Agreement::query()->create([
                'tenant_id' => (int) $tenant->id,
                'agreement_type' => Agreement::TYPE_SANDBOX_VALIDATION,
                'template_key' => Agreement::TEMPLATE_FRONT_YARD_SANDBOX_VALIDATION,
                'title' => (string) $payload['title'],
                'status' => 'draft',
                'internal_notes' => 'TEST MODE ONLY — disposable Stripe validation agreement. Never send this agreement to the client.',
                'created_by' => $actorUserId,
                'updated_by' => $actorUserId,
            ]);
            $this->events->record($agreement, 'created', $actorUserId, ['validation_only' => true]);

            $rendered = $this->renderer->render($payload);
            $version = AgreementVersion::query()->create([
                'agreement_id' => (int) $agreement->id,
                'version_number' => 1,
                'title' => (string) $payload['title'],
                'rendered_content' => $rendered,
                'content_payload' => (array) $payload['content'],
                'scope_payload' => (array) $payload['scope'],
                'pricing_payload' => (array) $payload['pricing'],
                'subscription_payload' => (array) $payload['subscription'],
                'termination_payload' => (array) $payload['termination'],
                'content_hash' => $this->renderer->hash($rendered),
                'created_by' => $actorUserId,
                'created_at' => now(),
            ]);
            $agreement->forceFill(['current_version_id' => (int) $version->id])->save();
            $this->events->record($agreement, 'version_created', $actorUserId, [
                'version_number' => 1,
                'content_hash' => $version->content_hash,
                'validation_only' => true,
            ], $version);
            $this->audit->record((int) $tenant->id, $actorUserId, 'agreement.sandbox_validation.prepare', targetType: 'agreement', targetId: $agreement->id, afterState: [
                'status' => 'draft',
                'version_id' => (int) $version->id,
                'content_hash' => $version->content_hash,
                'validation_only' => true,
            ]);

            return $agreement->load('currentVersion');
        });
    }

    /** @return array{agreement:Agreement,password:string,url:string,short_url:string} */
    public function send(Agreement $agreement, ?int $actorUserId, ?string $password = null, int $expiresInDays = 14): array
    {
        if (! $agreement->currentVersion) {
            throw new InvalidArgumentException('Create an agreement version before sending.');
        }
        if (in_array($agreement->status, ['accepted', 'active', 'termination_pending', 'terminated'], true)) {
            throw new InvalidArgumentException('Accepted or terminated agreements cannot be resent. Create an amendment.');
        }

        $password = trim((string) $password) ?: Str::password(10, symbols: false);
        if (mb_strlen($password) < 10) {
            throw new InvalidArgumentException('Proposal passwords must contain at least 10 characters.');
        }
        $token = Str::random(64);
        $before = ['status' => $agreement->status, 'access_expires_at' => $agreement->access_expires_at?->toIso8601String()];
        $agreement->forceFill([
            'status' => 'sent',
            'public_token_hash' => hash('sha256', $token),
            'public_token_encrypted' => $token,
            'password_hash' => Hash::make($password),
            'access_expires_at' => now()->addDays(max(1, min(90, $expiresInDays))),
            'access_revoked_at' => null,
            'sent_at' => now(),
            'updated_by' => $actorUserId,
        ])->save();

        $this->events->record($agreement, 'sent', $actorUserId, ['expires_at' => $agreement->access_expires_at?->toIso8601String()]);
        $this->audit->record((int) $agreement->tenant_id, $actorUserId, 'agreement.send', targetType: 'agreement', targetId: $agreement->id, beforeState: $before, afterState: [
            'status' => 'sent',
            'access_expires_at' => $agreement->access_expires_at?->toIso8601String(),
            'token_rotated' => true,
            'password_rotated' => true,
        ]);

        return [
            'agreement' => $agreement,
            'password' => $password,
            'url' => $this->publicUrl($token),
            'short_url' => $this->shortPublicUrl($agreement),
        ];
    }

    public function revoke(Agreement $agreement, ?int $actorUserId): Agreement
    {
        $agreement->forceFill(['access_revoked_at' => now(), 'updated_by' => $actorUserId])->save();
        $this->events->record($agreement, 'access_revoked', $actorUserId);
        $this->audit->record((int) $agreement->tenant_id, $actorUserId, 'agreement.access.revoke', targetType: 'agreement', targetId: $agreement->id, afterState: ['revoked' => true]);

        return $agreement;
    }

    public function updateInternalNotes(Agreement $agreement, ?int $actorUserId, ?string $notes): Agreement
    {
        $agreement->forceFill(['internal_notes' => $notes, 'updated_by' => $actorUserId])->save();
        $this->audit->record((int) $agreement->tenant_id, $actorUserId, 'agreement.internal_notes.update', targetType: 'agreement', targetId: $agreement->id, afterState: ['has_internal_notes' => filled($notes)]);

        return $agreement;
    }

    public function createAmendment(Agreement $parent, ?int $actorUserId, ?int $implementationAmountCents = null, ?string $additionalScope = null): Agreement
    {
        if ($parent->agreement_type === Agreement::TYPE_SANDBOX_VALIDATION) {
            throw new InvalidArgumentException('Sandbox validation agreements cannot receive amendments.');
        }
        if (! in_array($parent->status, ['active', 'termination_pending'], true)) {
            throw new InvalidArgumentException('Only an accepted agreement can receive an amendment.');
        }
        if ($parent->template_key === Agreement::TEMPLATE_COLLINS_ELECTRIC_CLIENT_SERVICES) {
            $cards = collect((array) $parent->currentVersion?->pricing_payload['cards'])->keyBy('key');
            $payload = $this->collinsElectricTemplate->build(
                (int) data_get($cards->get('everbranch_onboarding'), 'amount_cents', 29900),
                (int) data_get($cards->get('everbranch_launch_partner'), 'amount_cents', 5900),
                (int) data_get($cards->get('everbranch_standard'), 'amount_cents', 14900),
                $additionalScope,
            );
        } else {
            $payload = $this->frontYardTemplate->build($implementationAmountCents, additionalScope: $additionalScope);
        }

        return DB::transaction(function () use ($parent, $actorUserId, $payload): Agreement {
            $amendment = Agreement::query()->create([
                'tenant_id' => (int) $parent->tenant_id,
                'parent_agreement_id' => (int) $parent->id,
                'agreement_type' => 'amendment',
                'template_key' => $parent->template_key,
                'title' => 'Amendment — '.(string) $parent->title,
                'status' => 'draft',
                'created_by' => $actorUserId,
                'updated_by' => $actorUserId,
            ]);
            $payload['title'] = $amendment->title;
            $rendered = $this->renderer->render($payload);
            $version = AgreementVersion::query()->create([
                'agreement_id' => (int) $amendment->id,
                'version_number' => 1,
                'title' => $amendment->title,
                'rendered_content' => $rendered,
                'content_payload' => (array) $payload['content'],
                'scope_payload' => (array) $payload['scope'],
                'pricing_payload' => (array) $payload['pricing'],
                'subscription_payload' => (array) $payload['subscription'],
                'termination_payload' => (array) $payload['termination'],
                'content_hash' => $this->renderer->hash($rendered),
                'created_by' => $actorUserId,
                'created_at' => now(),
            ]);
            $amendment->forceFill(['current_version_id' => $version->id])->save();
            $this->events->record($amendment, 'amendment_created', $actorUserId, ['parent_agreement_id' => $parent->id], $version);
            $this->audit->record((int) $parent->tenant_id, $actorUserId, 'agreement.amendment.create', targetType: 'agreement', targetId: $amendment->id, afterState: ['parent_agreement_id' => $parent->id, 'version_id' => $version->id]);

            return $amendment->load('currentVersion');
        });
    }

    public function publicUrl(string $token): string
    {
        $host = strtolower(trim((string) config('evergrove.canonical_host', 'evergrovesoftware.com')));
        $scheme = app()->environment('local', 'testing') ? 'http' : 'https';

        return $scheme.'://'.$host.'/proposals/'.$token;
    }

    public function shortPublicUrl(Agreement $agreement): string
    {
        $host = strtolower(trim((string) config('evergrove.canonical_host', 'evergrovesoftware.com')));
        $scheme = app()->environment('local', 'testing') ? 'http' : 'https';

        return $scheme.'://'.$host.'/a/'.$agreement->id.'/'.$this->shortPublicSignature($agreement);
    }

    public function hasValidShortPublicSignature(Agreement $agreement, string $signature): bool
    {
        return hash_equals($this->shortPublicSignature($agreement), trim($signature));
    }

    protected function shortPublicSignature(Agreement $agreement): string
    {
        return substr(hash_hmac('sha256', implode('|', [
            'agreement-short-link',
            (string) $agreement->id,
            (string) $agreement->public_token_hash,
        ]), (string) config('app.key')), 0, 16);
    }

    public function createSupplementalWork(Agreement $parent, ?int $actorUserId, string $description, int $amountCents, ?float $approvedHours = null): Agreement
    {
        if ($parent->agreement_type === Agreement::TYPE_SANDBOX_VALIDATION) {
            throw new InvalidArgumentException('Sandbox validation agreements cannot authorize supplemental work.');
        }
        if (! in_array($parent->status, ['active', 'termination_pending'], true)) {
            throw new InvalidArgumentException('Supplemental work requires an accepted parent agreement.');
        }
        $description = trim($description);
        if ($description === '' || $amountCents < 1) {
            throw new InvalidArgumentException('Supplemental work requires a description and positive approved amount.');
        }
        if ($approvedHours !== null && (int) round($approvedHours * 5000) !== $amountCents) {
            throw new InvalidArgumentException('Hourly supplemental work must equal approved hours multiplied by $50.00.');
        }
        $payload = $this->supplementalTemplate->build($parent->loadMissing(['tenant', 'currentVersion']), $description, $amountCents, $approvedHours);

        return DB::transaction(function () use ($parent, $actorUserId, $payload, $approvedHours): Agreement {
            $work = Agreement::query()->create([
                'tenant_id' => (int) $parent->tenant_id, 'parent_agreement_id' => (int) $parent->id,
                'agreement_type' => 'supplemental_work', 'template_key' => 'supplemental_work',
                'title' => (string) $payload['title'], 'status' => 'draft', 'created_by' => $actorUserId, 'updated_by' => $actorUserId,
            ]);
            $rendered = $this->renderer->render($payload);
            $version = AgreementVersion::query()->create([
                'agreement_id' => (int) $work->id, 'version_number' => 1, 'title' => (string) $payload['title'],
                'rendered_content' => $rendered, 'content_payload' => (array) $payload['content'], 'scope_payload' => (array) $payload['scope'],
                'pricing_payload' => (array) $payload['pricing'], 'subscription_payload' => (array) $payload['subscription'],
                'termination_payload' => (array) $payload['termination'], 'content_hash' => $this->renderer->hash($rendered),
                'created_by' => $actorUserId, 'created_at' => now(),
            ]);
            $work->forceFill(['current_version_id' => (int) $version->id])->save();
            $this->events->record($work, 'supplemental_work_created', $actorUserId, ['parent_agreement_id' => $parent->id, 'approved_hours' => $approvedHours], $version);
            $this->audit->record((int) $parent->tenant_id, $actorUserId, 'agreement.supplemental_work.create', targetType: 'agreement', targetId: $work->id, afterState: ['parent_agreement_id' => $parent->id, 'version_id' => $version->id]);

            return $work->load('currentVersion');
        });
    }

    public function createImplementationMilestone(Agreement $parent, ?int $actorUserId): Agreement
    {
        if ($parent->agreement_type === Agreement::TYPE_SANDBOX_VALIDATION) {
            throw new InvalidArgumentException('Sandbox validation agreements cannot create implementation milestones.');
        }
        if (! in_array($parent->status, ['active', 'termination_pending'], true)) {
            throw new InvalidArgumentException('A milestone requires an accepted parent agreement.');
        }
        $parent->loadMissing(['tenant', 'currentVersion']);
        $amount = (int) data_get($parent->currentVersion?->pricing_payload, 'implementation_payment_schedule.due_before_launch_cents', 0);
        if ($amount < 1) {
            throw new InvalidArgumentException('The accepted agreement has no due-before-launch milestone.');
        }
        $existing = Agreement::query()->forTenant($parent->tenant_id)->where('parent_agreement_id', $parent->id)->where('agreement_type', 'milestone')->first();
        if ($existing) {
            return $existing->load('currentVersion');
        }
        $description = 'Implementation balance due before production launch under the accepted parent agreement.';
        $payload = $this->supplementalTemplate->build($parent, $description, $amount, agreementType: 'milestone');

        return DB::transaction(function () use ($parent, $actorUserId, $payload): Agreement {
            $milestone = Agreement::query()->create([
                'tenant_id' => (int) $parent->tenant_id, 'parent_agreement_id' => (int) $parent->id,
                'agreement_type' => 'milestone', 'template_key' => 'implementation_milestone',
                'title' => (string) $payload['title'], 'status' => 'draft', 'created_by' => $actorUserId, 'updated_by' => $actorUserId,
            ]);
            $rendered = $this->renderer->render($payload);
            $version = AgreementVersion::query()->create([
                'agreement_id' => (int) $milestone->id, 'version_number' => 1, 'title' => (string) $payload['title'],
                'rendered_content' => $rendered, 'content_payload' => (array) $payload['content'], 'scope_payload' => (array) $payload['scope'],
                'pricing_payload' => (array) $payload['pricing'], 'subscription_payload' => (array) $payload['subscription'],
                'termination_payload' => (array) $payload['termination'], 'content_hash' => $this->renderer->hash($rendered),
                'created_by' => $actorUserId, 'created_at' => now(),
            ]);
            $milestone->forceFill(['current_version_id' => (int) $version->id])->save();
            $this->events->record($milestone, 'milestone_created', $actorUserId, ['parent_agreement_id' => $parent->id], $version);
            $this->audit->record((int) $parent->tenant_id, $actorUserId, 'agreement.milestone.create', targetType: 'agreement', targetId: $milestone->id, afterState: ['parent_agreement_id' => $parent->id, 'version_id' => $version->id]);

            return $milestone->load('currentVersion');
        });
    }
}

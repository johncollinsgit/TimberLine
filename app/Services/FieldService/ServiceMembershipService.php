<?php

namespace App\Services\FieldService;

use App\Models\CustomerServiceMembership;
use App\Models\CustomerServiceMembershipVisit;
use App\Models\MarketingProfile;
use App\Models\ServiceMembershipEvent;
use App\Models\ServicePlanOffer;
use App\Models\ServicePlanTemplate;
use App\Models\ServicePlanVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceAsset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ServiceMembershipService
{
    /**
     * Publish an immutable customer-facing version. Existing offers and
     * memberships only ever reference the version snapshot, never a mutable
     * template or price-book item.
     *
     * @param  array<string,mixed>  $snapshot
     * @param  array<int,array<string,mixed>>  $addons
     * @param  array<int,array<string,mixed>>  $media
     */
    public function createVersion(Tenant $tenant, ServicePlanTemplate $template, User $actor, array $snapshot, array $addons = [], array $media = []): ServicePlanVersion
    {
        $this->assertTemplateTenant($tenant, $template);
        $snapshot = $this->normalizedSnapshot($template, $snapshot);

        return DB::transaction(function () use ($tenant, $template, $actor, $snapshot, $addons, $media): ServicePlanVersion {
            $nextVersion = ((int) ServicePlanVersion::query()
                ->where('service_plan_template_id', (int) $template->id)
                ->lockForUpdate()
                ->max('version')) + 1;
            $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $version = ServicePlanVersion::query()->create([
                'tenant_id' => (int) $tenant->id,
                'service_plan_template_id' => (int) $template->id,
                'created_by_user_id' => (int) $actor->id,
                'version' => $nextVersion,
                'snapshot' => $snapshot,
                'content_hash' => hash('sha256', $encoded),
                'published_at' => now(),
            ]);

            foreach (array_values($addons) as $position => $addon) {
                $version->addons()->create([
                    'tenant_id' => (int) $tenant->id,
                    'field_service_price_book_item_id' => $this->nullablePositiveInt($addon['field_service_price_book_item_id'] ?? null),
                    'name' => trim((string) ($addon['name'] ?? '')),
                    'description' => $this->nullableString($addon['description'] ?? null),
                    'billing_frequency' => in_array($addon['billing_frequency'] ?? null, ['one_time', 'monthly', 'annual'], true) ? $addon['billing_frequency'] : 'one_time',
                    'price' => max(0, (float) ($addon['price'] ?? 0)),
                    'max_quantity' => min(99, max(1, (int) ($addon['max_quantity'] ?? 1))),
                    'sort_order' => $position,
                ]);
            }

            foreach (array_values($media) as $position => $item) {
                $assetId = $this->nullablePositiveInt($item['workspace_asset_id'] ?? null);
                if ($assetId === null || ! WorkspaceAsset::query()->forTenantId((int) $tenant->id)->whereKey($assetId)->exists()) {
                    throw new InvalidArgumentException('Plan media must be a tenant-owned workspace asset.');
                }
                $version->media()->create([
                    'tenant_id' => (int) $tenant->id,
                    'workspace_asset_id' => $assetId,
                    'visibility' => in_array($item['visibility'] ?? null, ['staff_only', 'customer_offer'], true) ? $item['visibility'] : 'customer_offer',
                    'sort_order' => $position,
                    'caption' => $this->nullableString($item['caption'] ?? null),
                    'alt_text' => Str::limit(trim((string) ($item['alt_text'] ?? 'Service plan photo')), 500, ''),
                ]);
            }

            $template->forceFill([
                'current_version' => $nextVersion,
                'status' => 'published',
                'published_at' => now(),
            ])->save();

            $this->event($tenant, 'plan_version_published', $actor, context: ['template_id' => (int) $template->id, 'version' => $nextVersion]);

            return $version->fresh(['addons', 'media.asset']);
        });
    }

    /** @return array{offer:ServicePlanOffer,token:string} */
    public function createOffer(Tenant $tenant, MarketingProfile $customer, ServicePlanVersion $version, User $actor, ?CarbonImmutable $expiresAt = null): array
    {
        $this->assertCustomerTenant($tenant, $customer);
        if ((int) $version->tenant_id !== (int) $tenant->id || $version->published_at === null) {
            throw new InvalidArgumentException('Only published tenant plan versions may be offered.');
        }
        $token = Str::random(64);
        $version->loadMissing(['template', 'addons', 'media.asset']);
        $snapshot = [
            'plan' => $version->snapshot,
            'template' => Arr::only($version->template?->toArray() ?? [], ['id', 'name', 'badge']),
            'version_id' => (int) $version->id,
            'version' => (int) $version->version,
            'content_hash' => (string) $version->content_hash,
            'addons' => $version->addons->map(fn ($addon): array => Arr::only($addon->toArray(), ['id', 'name', 'description', 'billing_frequency', 'price', 'max_quantity', 'sort_order']))->values()->all(),
            'media' => $version->media->where('visibility', 'customer_offer')->map(fn ($item): array => ['id' => (int) $item->id, 'workspace_asset_id' => (int) $item->workspace_asset_id, 'caption' => $item->caption, 'alt_text' => $item->alt_text])->values()->all(),
        ];
        $offer = ServicePlanOffer::query()->create([
            'tenant_id' => (int) $tenant->id,
            'marketing_profile_id' => (int) $customer->id,
            'service_plan_version_id' => (int) $version->id,
            'created_by_user_id' => (int) $actor->id,
            'portal_token_hash' => hash('sha256', $token),
            'status' => 'sent',
            'snapshot' => $snapshot,
            'expires_at' => $expiresAt ?? now()->addDays(14),
            'sent_at' => now(),
        ]);
        $this->event($tenant, 'offer_created', $actor, offer: $offer, context: ['customer_id' => (int) $customer->id]);

        return ['offer' => $offer, 'token' => $token];
    }

    /** @param array<int,array{id:int,quantity:int}> $selections */
    public function acceptOffer(ServicePlanOffer $offer, array $selections, string $acceptedName, ?string $ip, ?string $userAgent): ServicePlanOffer
    {
        if ($offer->status !== 'sent' || $offer->revoked_at !== null || ($offer->expires_at !== null && $offer->expires_at->isPast())) {
            throw new InvalidArgumentException('This service plan offer is no longer available.');
        }
        $acceptedName = trim($acceptedName);
        if ($acceptedName === '' || mb_strlen($acceptedName) > 255) {
            throw new InvalidArgumentException('A valid acceptance name is required.');
        }
        $selected = $this->selectedAddons($offer, $selections);
        $offer->forceFill([
            'status' => 'accepted',
            'selected_addons' => $selected,
            'accepted_at' => now(),
            'accepted_name' => $acceptedName,
            'accepted_ip' => $ip,
            'accepted_user_agent' => Str::limit((string) $userAgent, 500, ''),
        ])->save();
        $this->event($offer->tenant, 'offer_accepted', null, offer: $offer, context: ['selected_addons' => $selected]);

        return $offer->fresh();
    }

    public function activateOffer(ServicePlanOffer $offer, User $actor, ?string $externalInvoiceReference = null, ?string $externalInvoiceUrl = null): CustomerServiceMembership
    {
        if ($offer->status !== 'accepted' || $offer->revoked_at !== null) {
            throw new InvalidArgumentException('Only an accepted offer may be activated.');
        }
        if ($externalInvoiceUrl !== null && $externalInvoiceUrl !== '' && ! filter_var($externalInvoiceUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('The external invoice link must be a valid URL.');
        }

        return DB::transaction(function () use ($offer, $actor, $externalInvoiceReference, $externalInvoiceUrl): CustomerServiceMembership {
            $offer->refresh();
            $existing = CustomerServiceMembership::query()->where('service_plan_offer_id', (int) $offer->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $snapshot = (array) $offer->snapshot;
            $plan = (array) ($snapshot['plan'] ?? []);
            $startsOn = now()->toDateString();
            $interval = max(1, (int) ($plan['visit_interval_days'] ?? 365));
            $membership = CustomerServiceMembership::query()->create([
                'tenant_id' => (int) $offer->tenant_id,
                'marketing_profile_id' => (int) $offer->marketing_profile_id,
                'service_plan_offer_id' => (int) $offer->id,
                'service_plan_version_id' => (int) $offer->service_plan_version_id,
                'activated_by_user_id' => (int) $actor->id,
                'status' => 'active',
                'snapshot' => $snapshot,
                'selected_addons' => $offer->selected_addons,
                'external_invoice_reference' => $this->nullableString($externalInvoiceReference),
                'external_invoice_url' => $this->nullableString($externalInvoiceUrl),
                'starts_on' => $startsOn,
                'renews_on' => CarbonImmutable::parse($startsOn)->addYear()->toDateString(),
                'next_visit_due_on' => CarbonImmutable::parse($startsOn)->addDays($interval)->toDateString(),
                'priority' => in_array($plan['priority'] ?? null, ['normal', 'priority'], true) ? $plan['priority'] : 'normal',
                'activated_at' => now(),
            ]);
            $this->event($membership->tenant, 'membership_activated', $actor, membership: $membership, offer: $offer, context: ['external_invoice_reference' => $this->nullableString($externalInvoiceReference)]);

            return $membership;
        });
    }

    /** @return array{visits_created:int,jobs_created:int} */
    public function generateDueVisits(?int $tenantId = null, int $daysAhead = 30): array
    {
        $summary = ['visits_created' => 0, 'jobs_created' => 0];
        CustomerServiceMembership::query()
            ->when($tenantId !== null, fn ($query) => $query->forTenantId($tenantId))
            ->where('status', 'active')
            ->whereNotNull('next_visit_due_on')
            ->whereDate('next_visit_due_on', '<=', now()->addDays($daysAhead)->toDateString())
            ->with(['customer', 'tenant'])
            ->orderBy('id')
            ->each(function (CustomerServiceMembership $membership) use (&$summary): void {
                DB::transaction(function () use ($membership, &$summary): void {
                    $membership = CustomerServiceMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
                    if ($membership->status !== 'active' || $membership->next_visit_due_on === null) {
                        return;
                    }
                    $due = $membership->next_visit_due_on->toImmutable();
                    $visit = CustomerServiceMembershipVisit::query()->firstOrCreate([
                        'customer_service_membership_id' => (int) $membership->id,
                        'period_key' => $due->format('Ymd'),
                    ], [
                        'tenant_id' => (int) $membership->tenant_id,
                        'due_on' => $due->toDateString(),
                        'status' => 'due',
                    ]);
                    if (! $visit->wasRecentlyCreated) {
                        return;
                    }
                    $summary['visits_created']++;
                    $customer = $membership->customer;
                    $plan = (array) data_get($membership->snapshot, 'plan', []);
                    $job = \App\Models\FieldServiceJob::query()->create([
                        'tenant_id' => (int) $membership->tenant_id,
                        'marketing_profile_id' => $membership->marketing_profile_id,
                        'title' => (string) ($plan['visit_title'] ?? data_get($membership->snapshot, 'template.name', 'Membership').' service visit'),
                        'operational_status' => 'scheduled',
                        'status' => 'open',
                        'priority' => $membership->priority === 'priority' ? 'high' : 'normal',
                        'customer_name' => trim(($customer?->first_name ?? '').' '.($customer?->last_name ?? '')),
                        'customer_email' => $customer?->email,
                        'customer_phone' => $customer?->phone,
                        'service_address_line_1' => $customer?->address_line_1,
                        'service_address_line_2' => $customer?->address_line_2,
                        'service_city' => $customer?->city,
                        'service_state' => $customer?->state,
                        'service_postal_code' => $customer?->postal_code,
                        'service_country' => $customer?->country,
                        'scheduled_for' => $due->setTime(9, 0),
                        'scheduled_end_at' => $due->setTime(10, 0),
                        'metadata' => ['service_membership_id' => (int) $membership->id, 'service_membership_visit_id' => (int) $visit->id, 'generated_by' => 'service_memberships'],
                    ]);
                    $visit->forceFill(['field_service_job_id' => (int) $job->id, 'status' => 'scheduled'])->save();
                    $interval = max(1, (int) ($plan['visit_interval_days'] ?? 365));
                    $membership->forceFill(['next_visit_due_on' => $due->addDays($interval)->toDateString()])->save();
                    $summary['jobs_created']++;
                    $this->event($membership->tenant, 'membership_visit_generated', null, membership: $membership, context: ['visit_id' => (int) $visit->id, 'job_id' => (int) $job->id]);
                });
            });

        return $summary;
    }

    /** @param array<int,array{id:int,quantity:int}> $selections @return array<int,array<string,mixed>> */
    protected function selectedAddons(ServicePlanOffer $offer, array $selections): array
    {
        $available = collect((array) data_get($offer->snapshot, 'addons', []))->keyBy(fn (array $addon): int => (int) ($addon['id'] ?? 0));

        return collect($selections)->map(function (array $selection) use ($available): array {
            $id = (int) ($selection['id'] ?? 0);
            $addon = $available->get($id);
            if (! is_array($addon)) {
                throw new InvalidArgumentException('The selected add-on is not part of this offer.');
            }
            $quantity = min((int) ($addon['max_quantity'] ?? 1), max(1, (int) ($selection['quantity'] ?? 1)));

            return ['id' => $id, 'name' => (string) $addon['name'], 'quantity' => $quantity, 'price' => (float) ($addon['price'] ?? 0), 'billing_frequency' => (string) ($addon['billing_frequency'] ?? 'one_time')];
        })->values()->all();
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    protected function normalizedSnapshot(ServicePlanTemplate $template, array $snapshot): array
    {
        $frequency = in_array($snapshot['billing_frequency'] ?? null, ['monthly', 'annual'], true) ? $snapshot['billing_frequency'] : 'annual';

        return [
            'name' => Str::limit(trim((string) ($snapshot['name'] ?? $template->name)), 255, ''),
            'badge' => Str::limit(trim((string) ($snapshot['badge'] ?? $template->badge)), 80, ''),
            'description' => Str::limit(trim((string) ($snapshot['description'] ?? $template->description)), 5000, ''),
            'price' => max(0, (float) ($snapshot['price'] ?? 0)),
            'billing_frequency' => $frequency,
            'visit_interval_days' => min(730, max(1, (int) ($snapshot['visit_interval_days'] ?? 365))),
            'visit_title' => Str::limit(trim((string) ($snapshot['visit_title'] ?? 'Membership service visit')), 255, ''),
            'priority' => ($snapshot['priority'] ?? null) === 'priority' ? 'priority' : 'normal',
            'benefits' => array_values(array_filter((array) ($snapshot['benefits'] ?? []), fn ($value): bool => is_string($value) && trim($value) !== '')),
            'terms' => Str::limit(trim((string) ($snapshot['terms'] ?? '')), 10000, ''),
        ];
    }

    protected function event(Tenant $tenant, string $type, ?User $actor, ?CustomerServiceMembership $membership = null, ?ServicePlanOffer $offer = null, array $context = []): void
    {
        ServiceMembershipEvent::query()->create(['tenant_id' => (int) $tenant->id, 'customer_service_membership_id' => $membership?->id, 'service_plan_offer_id' => $offer?->id, 'actor_user_id' => $actor?->id, 'event_type' => $type, 'context' => $context]);
    }

    protected function assertTemplateTenant(Tenant $tenant, ServicePlanTemplate $template): void
    {
        if ((int) $template->tenant_id !== (int) $tenant->id) {
            throw new InvalidArgumentException('The plan template does not belong to this tenant.');
        }
    }

    protected function assertCustomerTenant(Tenant $tenant, MarketingProfile $customer): void
    {
        if ((int) $customer->tenant_id !== (int) $tenant->id) {
            throw new InvalidArgumentException('The customer does not belong to this tenant.');
        }
    }

    protected function nullablePositiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}

<?php

namespace App\Services\Marketing;

use App\Models\Tenant;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketingTenantOwnershipService
{
    public function strictModeEnabled(): bool
    {
        return $this->tenantCount() > 0;
    }

    public function multiTenantModeEnabled(): bool
    {
        return $this->tenantCount() > 1;
    }

    public function resolveTenantId(Request $request, bool $required = false): ?int
    {
        foreach (['current_tenant_id', 'host_tenant_id'] as $attribute) {
            $resolved = $this->positiveInt($request->attributes->get($attribute));
            if ($resolved !== null) {
                $request->attributes->set('current_tenant_id', $resolved);

                return $resolved;
            }
        }

        $sessionTenantId = $this->positiveInt($request->session()->get('tenant_id'));
        if ($sessionTenantId !== null) {
            $request->attributes->set('current_tenant_id', $sessionTenantId);

            return $sessionTenantId;
        }

        $user = $request->user();
        if ($user !== null) {
            $tenantIds = $user->tenants()
                ->pluck('tenants.id')
                ->map(fn ($value): int => (int) $value)
                ->filter(fn (int $value): bool => $value > 0)
                ->values();

            if ($tenantIds->count() === 1) {
                $resolved = (int) $tenantIds->first();
                $request->attributes->set('current_tenant_id', $resolved);

                return $resolved;
            }
        }

        if ($required && $this->strictModeEnabled()) {
            abort(403, 'Tenant context is required for this marketing surface.');
        }

        return null;
    }

    /**
     * @return Collection<int,int>
     */
    public function tenantCampaignIds(int $tenantId): Collection
    {
        if ($this->hasCampaignTenantRail()) {
            return DB::table('marketing_campaigns')
                ->where('tenant_id', $tenantId)
                ->pluck('id')
                ->map(fn ($value): int => (int) $value)
                ->filter(fn (int $value): bool => $value > 0)
                ->values();
        }

        return $this->tenantEntityIds($this->campaignEvidenceQuery(), $tenantId);
    }

    /**
     * @return Collection<int,int>
     */
    public function tenantSegmentIds(int $tenantId): Collection
    {
        if ($this->hasSegmentTenantRail()) {
            return DB::table('marketing_segments')
                ->where('tenant_id', $tenantId)
                ->pluck('id')
                ->map(fn ($value): int => (int) $value)
                ->filter(fn (int $value): bool => $value > 0)
                ->values();
        }

        return $this->tenantEntityIds($this->segmentEvidenceQuery(), $tenantId);
    }

    /**
     * @return Collection<int,int>
     */
    public function tenantTemplateIds(int $tenantId): Collection
    {
        if ($this->hasTemplateTenantRail()) {
            return DB::table('marketing_message_templates')
                ->where('tenant_id', $tenantId)
                ->pluck('id')
                ->map(fn ($value): int => (int) $value)
                ->filter(fn (int $value): bool => $value > 0)
                ->values();
        }

        return $this->tenantEntityIds($this->templateEvidenceQuery(), $tenantId);
    }

    /**
     * @return Collection<int,int>
     */
    public function tenantGroupIds(int $tenantId): Collection
    {
        return $this->tenantEntityIds($this->groupEvidenceQuery(), $tenantId);
    }

    /**
     * @return Collection<int,int>
     */
    public function tenantMessageGroupIds(int $tenantId): Collection
    {
        return $this->tenantEntityIds($this->messageGroupEvidenceQuery(), $tenantId);
    }

    /**
     * @return Collection<int,int>
     */
    public function tenantRecommendationIds(int $tenantId): Collection
    {
        return $this->tenantEntityIds($this->recommendationEvidenceQuery(), $tenantId);
    }

    public function campaignOwnerTenantId(int $campaignId): ?int
    {
        if ($this->hasCampaignTenantRail()) {
            $tenantId = DB::table('marketing_campaigns')
                ->where('id', $campaignId)
                ->value('tenant_id');

            return $this->positiveInt($tenantId);
        }

        return $this->ownerTenantId($this->campaignEvidenceQuery(), $campaignId);
    }

    public function campaignOwnedByTenant(int $campaignId, int $tenantId): bool
    {
        if (! $this->strictModeEnabled()) {
            return true;
        }

        return $this->campaignOwnerTenantId($campaignId) === $tenantId;
    }

    public function segmentOwnedByTenant(int $segmentId, int $tenantId): bool
    {
        if (! $this->strictModeEnabled()) {
            return true;
        }

        if ($this->hasSegmentTenantRail()) {
            $ownerTenantId = DB::table('marketing_segments')
                ->where('id', $segmentId)
                ->value('tenant_id');

            return $this->positiveInt($ownerTenantId) === $tenantId;
        }

        return $this->ownerTenantId($this->segmentEvidenceQuery(), $segmentId) === $tenantId;
    }

    public function templateOwnedByTenant(int $templateId, int $tenantId): bool
    {
        if (! $this->strictModeEnabled()) {
            return true;
        }

        if ($this->hasTemplateTenantRail()) {
            $ownerTenantId = DB::table('marketing_message_templates')
                ->where('id', $templateId)
                ->value('tenant_id');

            return $this->positiveInt($ownerTenantId) === $tenantId;
        }

        return $this->ownerTenantId($this->templateEvidenceQuery(), $templateId) === $tenantId;
    }

    public function groupOwnedByTenant(int $groupId, int $tenantId): bool
    {
        if (! $this->strictModeEnabled()) {
            return true;
        }

        return $this->ownerTenantId($this->groupEvidenceQuery(), $groupId) === $tenantId;
    }

    public function messageGroupOwnedByTenant(int $groupId, int $tenantId): bool
    {
        if (! $this->strictModeEnabled()) {
            return true;
        }

        return $this->ownerTenantId($this->messageGroupEvidenceQuery(), $groupId) === $tenantId;
    }

    public function recommendationOwnedByTenant(int $recommendationId, int $tenantId): bool
    {
        if (! $this->strictModeEnabled()) {
            return true;
        }

        return $this->ownerTenantId($this->recommendationEvidenceQuery(), $recommendationId) === $tenantId;
    }

    public function recipientOwnedByTenant(int $recipientId, int $tenantId): bool
    {
        if (! $this->strictModeEnabled()) {
            return true;
        }

        if (! Schema::hasTable('marketing_campaign_recipients') || ! Schema::hasTable('marketing_profiles')) {
            return false;
        }

        $ownerTenantId = DB::table('marketing_campaign_recipients as mcr')
            ->join('marketing_profiles as mp', 'mp.id', '=', 'mcr.marketing_profile_id')
            ->where('mcr.id', $recipientId)
            ->whereNotNull('mp.tenant_id')
            ->value('mp.tenant_id');

        return $this->positiveInt($ownerTenantId) === $tenantId;
    }

    public function profileOwnedByTenant(int $profileId, int $tenantId): bool
    {
        if (! $this->strictModeEnabled()) {
            return true;
        }

        if (! Schema::hasTable('marketing_profiles')) {
            return false;
        }

        $ownerTenantId = DB::table('marketing_profiles')
            ->where('id', $profileId)
            ->value('tenant_id');

        return $this->positiveInt($ownerTenantId) === $tenantId;
    }

    protected function tenantCount(): int
    {
        if (! Schema::hasTable('tenants')) {
            return 0;
        }

        return (int) Tenant::query()->count();
    }

    /**
     * @return Collection<int,int>
     */
    protected function tenantEntityIds(QueryBuilder $evidenceQuery, int $tenantId): Collection
    {
        return DB::query()
            ->fromSub($evidenceQuery, 'ownership_evidence')
            ->selectRaw('ownership_evidence.entity_id')
            ->groupBy('ownership_evidence.entity_id')
            ->havingRaw('count(distinct ownership_evidence.tenant_id) = 1')
            ->havingRaw('min(ownership_evidence.tenant_id) = ?', [$tenantId])
            ->pluck('ownership_evidence.entity_id')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->values();
    }

    protected function ownerTenantId(QueryBuilder $evidenceQuery, int $entityId): ?int
    {
        $tenantId = DB::query()
            ->fromSub($evidenceQuery, 'ownership_evidence')
            ->where('ownership_evidence.entity_id', $entityId)
            ->selectRaw('min(ownership_evidence.tenant_id) as tenant_id')
            ->groupBy('ownership_evidence.entity_id')
            ->havingRaw('count(distinct ownership_evidence.tenant_id) = 1')
            ->value('tenant_id');

        return $this->positiveInt($tenantId);
    }

    protected function campaignResolvedTenantQuery(): QueryBuilder
    {
        if ($this->hasCampaignTenantRail()) {
            return DB::table('marketing_campaigns')
                ->whereNotNull('tenant_id')
                ->selectRaw('id as campaign_id, tenant_id')
                ->groupBy('id', 'tenant_id');
        }

        return DB::query()
            ->fromSub($this->campaignEvidenceQuery(), 'campaign_evidence')
            ->selectRaw('campaign_evidence.entity_id as campaign_id, min(campaign_evidence.tenant_id) as tenant_id')
            ->groupBy('campaign_evidence.entity_id')
            ->havingRaw('count(distinct campaign_evidence.tenant_id) = 1');
    }

    protected function campaignEvidenceQuery(): QueryBuilder
    {
        if ($this->hasCampaignTenantRail()) {
            return DB::table('marketing_campaigns')
                ->whereNotNull('tenant_id')
                ->selectRaw('id as entity_id, tenant_id');
        }

        $sources = [];

        if (Schema::hasTable('marketing_campaign_recipients') && Schema::hasTable('marketing_profiles')) {
            $sources[] = DB::table('marketing_campaign_recipients as mcr')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'mcr.marketing_profile_id')
                ->whereNotNull('mp.tenant_id')
                ->selectRaw('mcr.campaign_id as entity_id, mp.tenant_id as tenant_id');
        }

        if (
            Schema::hasTable('marketing_campaign_groups')
            && Schema::hasTable('marketing_group_members')
            && Schema::hasTable('marketing_profiles')
        ) {
            $sources[] = DB::table('marketing_campaign_groups as mcg')
                ->join('marketing_group_members as mgm', 'mgm.marketing_group_id', '=', 'mcg.marketing_group_id')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'mgm.marketing_profile_id')
                ->whereNotNull('mp.tenant_id')
                ->selectRaw('mcg.campaign_id as entity_id, mp.tenant_id as tenant_id');
        }

        if (Schema::hasTable('marketing_campaign_conversions') && Schema::hasTable('marketing_profiles')) {
            $sources[] = DB::table('marketing_campaign_conversions as mcc')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'mcc.marketing_profile_id')
                ->whereNotNull('mp.tenant_id')
                ->selectRaw('mcc.campaign_id as entity_id, mp.tenant_id as tenant_id');
        }

        return $this->unionEvidence($sources);
    }

    protected function segmentEvidenceQuery(): QueryBuilder
    {
        if ($this->hasSegmentTenantRail()) {
            return DB::table('marketing_segments')
                ->whereNotNull('tenant_id')
                ->selectRaw('id as entity_id, tenant_id');
        }

        if (! Schema::hasTable('marketing_campaigns')) {
            return $this->emptyEvidenceQuery();
        }

        return DB::table('marketing_campaigns as mc')
            ->joinSub($this->campaignResolvedTenantQuery(), 'resolved_campaigns', function ($join): void {
                $join->on('resolved_campaigns.campaign_id', '=', 'mc.id');
            })
            ->whereNotNull('mc.segment_id')
            ->selectRaw('mc.segment_id as entity_id, resolved_campaigns.tenant_id as tenant_id');
    }

    protected function templateEvidenceQuery(): QueryBuilder
    {
        if ($this->hasTemplateTenantRail()) {
            return DB::table('marketing_message_templates')
                ->whereNotNull('tenant_id')
                ->selectRaw('id as entity_id, tenant_id');
        }

        if (! Schema::hasTable('marketing_campaign_variants')) {
            return $this->emptyEvidenceQuery();
        }

        return DB::table('marketing_campaign_variants as mcv')
            ->joinSub($this->campaignResolvedTenantQuery(), 'resolved_campaigns', function ($join): void {
                $join->on('resolved_campaigns.campaign_id', '=', 'mcv.campaign_id');
            })
            ->whereNotNull('mcv.template_id')
            ->selectRaw('mcv.template_id as entity_id, resolved_campaigns.tenant_id as tenant_id');
    }

    protected function groupEvidenceQuery(): QueryBuilder
    {
        $sources = [];

        if (Schema::hasTable('marketing_group_members') && Schema::hasTable('marketing_profiles')) {
            $sources[] = DB::table('marketing_group_members as mgm')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'mgm.marketing_profile_id')
                ->whereNotNull('mp.tenant_id')
                ->selectRaw('mgm.marketing_group_id as entity_id, mp.tenant_id as tenant_id');
        }

        if (Schema::hasTable('marketing_campaign_groups')) {
            $sources[] = DB::table('marketing_campaign_groups as mcg')
                ->joinSub($this->campaignResolvedTenantQuery(), 'resolved_campaigns', function ($join): void {
                    $join->on('resolved_campaigns.campaign_id', '=', 'mcg.campaign_id');
                })
                ->selectRaw('mcg.marketing_group_id as entity_id, resolved_campaigns.tenant_id as tenant_id');
        }

        return $this->unionEvidence($sources);
    }

    protected function messageGroupEvidenceQuery(): QueryBuilder
    {
        if ($this->hasMessageGroupTenantRail()) {
            return DB::table('marketing_message_groups')
                ->whereNotNull('tenant_id')
                ->selectRaw('id as entity_id, tenant_id');
        }

        if (! Schema::hasTable('marketing_message_group_members') || ! Schema::hasTable('marketing_profiles')) {
            return $this->emptyEvidenceQuery();
        }

        return DB::table('marketing_message_group_members as mmgm')
            ->join('marketing_profiles as mp', 'mp.id', '=', 'mmgm.marketing_profile_id')
            ->whereNotNull('mp.tenant_id')
            ->selectRaw('mmgm.marketing_message_group_id as entity_id, mp.tenant_id as tenant_id');
    }

    protected function recommendationEvidenceQuery(): QueryBuilder
    {
        $sources = [];

        if (Schema::hasTable('marketing_recommendations') && Schema::hasTable('marketing_profiles')) {
            $sources[] = DB::table('marketing_recommendations as mr')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'mr.marketing_profile_id')
                ->whereNotNull('mp.tenant_id')
                ->selectRaw('mr.id as entity_id, mp.tenant_id as tenant_id');
        }

        if (Schema::hasTable('marketing_recommendations')) {
            $sources[] = DB::table('marketing_recommendations as mr')
                ->joinSub($this->campaignResolvedTenantQuery(), 'resolved_campaigns', function ($join): void {
                    $join->on('resolved_campaigns.campaign_id', '=', 'mr.campaign_id');
                })
                ->selectRaw('mr.id as entity_id, resolved_campaigns.tenant_id as tenant_id');
        }

        return $this->unionEvidence($sources);
    }

    /**
     * @param array<int,QueryBuilder> $sources
     */
    protected function unionEvidence(array $sources): QueryBuilder
    {
        if ($sources === []) {
            return $this->emptyEvidenceQuery();
        }

        $query = array_shift($sources);
        foreach ($sources as $source) {
            $query->unionAll($source);
        }

        return $query;
    }

    protected function emptyEvidenceQuery(): QueryBuilder
    {
        return DB::query()
            ->selectRaw('0 as entity_id, 0 as tenant_id')
            ->whereRaw('1 = 0');
    }

    protected function hasCampaignTenantRail(): bool
    {
        return Schema::hasTable('marketing_campaigns')
            && Schema::hasColumn('marketing_campaigns', 'tenant_id');
    }

    protected function hasSegmentTenantRail(): bool
    {
        return Schema::hasTable('marketing_segments')
            && Schema::hasColumn('marketing_segments', 'tenant_id');
    }

    protected function hasTemplateTenantRail(): bool
    {
        return Schema::hasTable('marketing_message_templates')
            && Schema::hasColumn('marketing_message_templates', 'tenant_id');
    }

    protected function hasMessageGroupTenantRail(): bool
    {
        return Schema::hasTable('marketing_message_groups')
            && Schema::hasColumn('marketing_message_groups', 'tenant_id');
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : null;
    }
}

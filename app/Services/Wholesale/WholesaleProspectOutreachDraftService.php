<?php

namespace App\Services\Wholesale;

use App\Models\TenantDiscoveryProfile;
use App\Models\TenantWholesaleSetting;
use App\Models\User;
use App\Models\WholesaleProspect;
use App\Models\WholesaleProspectActivity;
use App\Models\WholesaleProspectEvidence;
use App\Models\WholesaleProspectOutreachDraft;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WholesaleProspectOutreachDraftService
{
    public function requestInstagramDraft(WholesaleProspect $prospect, User $actor): WholesaleProspectOutreachDraft
    {
        return DB::transaction(function () use ($prospect, $actor): WholesaleProspectOutreachDraft {
            $prospect = WholesaleProspect::query()->forAllTenants()->lockForUpdate()->findOrFail($prospect->id);
            $this->assertEligible($prospect);

            $existing = WholesaleProspectOutreachDraft::query()->forAllTenants()
                ->where('tenant_id', $prospect->tenant_id)
                ->where('wholesale_prospect_id', $prospect->id)
                ->where('channel', 'instagram')
                ->first();
            if ($existing) {
                return $existing;
            }

            $evidence = $this->evidence($prospect);
            $draft = WholesaleProspectOutreachDraft::query()->create([
                'tenant_id' => $prospect->tenant_id,
                'wholesale_prospect_id' => $prospect->id,
                'public_id' => (string) Str::uuid(),
                'channel' => 'instagram',
                'status' => 'draft',
                'body' => $this->body($prospect, $evidence),
                'evidence_snapshot' => $evidence->map(fn (WholesaleProspectEvidence $row): array => [
                    'evidence_id' => $row->id,
                    'source_url' => $row->source_url,
                    'summary' => $row->summary,
                    'observed_at' => $row->observed_at?->toIso8601String(),
                ])->all(),
                'generated_by_user_id' => $actor->id,
                'generated_at' => now(),
            ]);
            $cooldownDays = max(1, (int) (TenantWholesaleSetting::query()->forAllTenants()
                ->where('tenant_id', $prospect->tenant_id)
                ->value('prospect_outreach_cooldown_days') ?? 30));
            $prospect->forceFill(['outreach_cooldown_until' => now()->addDays($cooldownDays)])->save();
            $this->activity($prospect, $actor, 'instagram_draft_generated', 'Instagram outreach draft created in Everbranch; it was not sent or pushed to Instagram.', [
                'draft_public_id' => $draft->public_id,
                'evidence_ids' => $evidence->pluck('id')->all(),
                'cooldown_until' => $prospect->outreach_cooldown_until?->toIso8601String(),
            ]);

            return $draft;
        });
    }

    protected function assertEligible(WholesaleProspect $prospect): void
    {
        if ($prospect->review_status !== 'approved') {
            throw new DomainException('Approve this prospect before requesting an outreach draft.');
        }
        if ($prospect->do_not_contact) {
            throw new DomainException('This prospect is marked do not contact.');
        }
        if ($prospect->duplicate_status === 'confirmed_duplicate' || $prospect->status === 'duplicate') {
            throw new DomainException('Resolve the duplicate record before requesting an outreach draft.');
        }
        if ($prospect->outreach_cooldown_until?->isFuture()) {
            throw new DomainException('This prospect is in its outreach cooldown. Review its contact history before suggesting another draft.');
        }
        if (blank($prospect->instagram_handle) && blank($prospect->instagram_url)) {
            throw new DomainException('Add a public Instagram handle or URL before requesting an Instagram draft.');
        }
    }

    /** @return \Illuminate\Support\Collection<int,WholesaleProspectEvidence> */
    protected function evidence(WholesaleProspect $prospect)
    {
        $evidence = WholesaleProspectEvidence::query()->forAllTenants()
            ->where('tenant_id', $prospect->tenant_id)
            ->where('wholesale_prospect_id', $prospect->id)
            ->whereNotNull('source_url')
            ->whereIn('source_type', ['public_website', 'google_places'])
            ->orderByDesc('supports_fit')
            ->orderByDesc('observed_at')
            ->limit(2)
            ->get();
        if ($evidence->isEmpty()) {
            throw new DomainException('Add at least one source-linked public research finding before requesting a draft.');
        }

        return $evidence;
    }

    /** @param \Illuminate\Support\Collection<int,WholesaleProspectEvidence> $evidence */
    protected function body(WholesaleProspect $prospect, $evidence): string
    {
        $profile = TenantDiscoveryProfile::query()
            ->where('tenant_id', $prospect->tenant_id)
            ->where('is_active', true)
            ->first();
        $brand = trim((string) ($profile?->wholesale_brand_label ?: $profile?->primary_brand_name ?: 'Modern Forestry'));
        $cue = trim((string) collect((array) $prospect->merchandising_cues)->first());
        if ($cue === '') {
            $cue = trim((string) $evidence->first()?->summary);
        }

        return "Hi {$prospect->business_name} team,\n\n"
            ."Your public business information highlights {$cue}. {$brand} makes hand-poured, forest-inspired candles that may complement a thoughtful retail assortment.\n\n"
            .'If you are open to reviewing a wholesale line sheet, I would be glad to share one.\n\n'
            .'Best,\nModern Forestry';
    }

    /** @param array<string,mixed> $metadata */
    protected function activity(WholesaleProspect $prospect, User $actor, string $type, string $summary, array $metadata = []): void
    {
        WholesaleProspectActivity::query()->create([
            'tenant_id' => $prospect->tenant_id,
            'wholesale_prospect_id' => $prospect->id,
            'actor_user_id' => $actor->id,
            'activity_type' => $type,
            'channel' => 'instagram',
            'summary' => $summary,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}

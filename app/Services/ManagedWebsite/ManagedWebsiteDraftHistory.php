<?php

namespace App\Services\ManagedWebsite;

use App\Models\LandlordOperatorAction;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantSite;
use App\Models\TenantSitePage;
use App\Models\TenantSitePageVersion;
use App\Models\TenantSitePublishEvent;
use App\Models\TenantSiteVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Private recovery copies immutable snapshots; publication is always separate. */
class ManagedWebsiteDraftHistory
{
    public const PAGE_DRAFTS = 30;

    public const SITE_DRAFTS = 10;

    public function assertActor(TenantSite $site, User $actor): void
    {
        $actor = User::query()->findOrFail($actor->id);
        $membership = $actor->tenants()->whereKey($site->tenant_id)->first();
        abort_unless($actor->is_active && $actor->hasVerifiedEmail() && $membership
            && (bool) $membership->pivot->membership_active
            && in_array($membership->pivot->role, ['owner', 'tenant_owner', 'admin', 'manager', 'marketing_manager'], true), 403);
    }

    /** The original successful audit, not registration metadata alone, admits retention. */
    public function retentionApplies(Tenant $tenant): bool
    {
        if (! Schema::hasTable('landlord_operator_actions') || ! Schema::hasTable('tenant_access_profiles')) {
            return false;
        }
        $profile = TenantAccessProfile::query()->where('tenant_id', $tenant->id)->first();
        $clock = data_get($profile?->metadata, 'self_service_trial');
        if (! is_array($clock) || data_get($profile?->metadata, 'self_service_registration') !== true
            || ($clock['tenant_id'] ?? null) !== (int) $tenant->id || ($clock['profile_id'] ?? null) !== (int) $profile?->id
            || ($clock['owner_user_id'] ?? null) !== data_get($profile?->metadata, 'self_service_owner_id')
            || ! is_string($clock['clock_sha256'] ?? null)) {
            return false;
        }
        $audit = LandlordOperatorAction::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->where('actor_user_id', $clock['owner_user_id'])->where('action_type', 'self_service.trial_started')
            ->where('target_type', 'tenant_access_profile')->where('target_id', (string) $profile->id)
            ->where('status', 'success')->whereNull('before_state')->where('after_state->trial_clock_sha256', $clock['clock_sha256'])->first();
        $original = data_get($audit?->after_state, 'trial');
        if (! is_array($original)) {
            return false;
        }
        unset($clock['converted_at'], $original['converted_at']);

        return hash_equals($this->canonical($original), $this->canonical($clock));
    }

    /** Called only inside the successful ordinary draft-write transaction. */
    public function prunePage(TenantSite $site, TenantSitePage $page): void
    {
        if (! $this->retentionApplies($site->tenant)) {
            return;
        }
        $query = TenantSitePageVersion::query()->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)->where('tenant_site_page_id', $page->id)->where('status', 'draft');
        $keep = (clone $query)->orderByDesc('version_number')->limit(self::PAGE_DRAFTS)->pluck('id')->all();
        $current = $page->fresh();
        $keep = [...$keep, $current->draft_version_id, $current->published_version_id];
        foreach ($this->publicationEvents($site)->where('tenant_site_page_id', $page->id)->get() as $event) {
            $keep[] = data_get($event->context, 'source_version_id');
            $keep[] = data_get($event->context, 'version_id');
        }
        $query->whereNotIn('id', array_filter($keep))->delete();
    }

    public function pruneSite(TenantSite $site): void
    {
        if (! $this->retentionApplies($site->tenant)) {
            return;
        }
        $query = TenantSiteVersion::query()->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)->where('status', 'draft');
        $keep = (clone $query)->orderByDesc('version_number')->limit(self::SITE_DRAFTS)->pluck('id')->all();
        $current = $site->fresh();
        $keep = [...$keep, $current->draft_site_version_id, $current->published_site_version_id];
        foreach ($this->publicationEvents($site)->get() as $event) {
            $keep[] = data_get($event->context, 'site_version_id');
            $keep[] = data_get($event->context, 'source_site_version_id');
        }
        $query->whereNotIn('id', array_filter($keep))->delete();
    }

    public function payload(TenantSite $site, TenantSitePage $page, bool $canRestore): array
    {
        abort_if(app(ConnectedWebsiteService::class)->connected($site), 409, 'Use Website history for this connected site.');
        $page = $page->fresh();
        $site = $site->fresh();
        $pages = $this->recent(TenantSitePageVersion::query()->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)->where('tenant_site_page_id', $page->id), self::PAGE_DRAFTS, [$page->draft_version_id, $page->published_version_id]);
        $settings = $this->recent(TenantSiteVersion::query()->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id), self::SITE_DRAFTS, [$site->draft_site_version_id, $site->published_site_version_id]);
        $row = fn ($version, bool $siteRow): array => [
            'id' => $version->id, 'version_number' => $version->version_number,
            'title' => $siteRow ? (data_get($version->settings, 'theme_name') ?: 'Website settings') : $version->title,
            'status' => $version->status, 'created_at' => $version->created_at->toIso8601String(),
            'is_current' => (int) $version->id === (int) ($siteRow ? $site->draft_site_version_id : $page->draft_version_id),
            'can_restore' => $canRestore && (int) $version->id !== (int) ($siteRow ? $site->draft_site_version_id : $page->draft_version_id),
            'restore_url' => $siteRow
                ? route('managed-website.history.site.restore', ['version' => $version, 'tenant' => $site->tenant->slug])
                : route('managed-website.history.page.restore', ['page' => $page, 'version' => $version, 'tenant' => $site->tenant->slug]),
        ];

        return [
            'page' => ['current_version_id' => $page->draft_version_id, 'versions' => $pages->map(fn ($version): array => $row($version, false))->values()->all()],
            'site' => ['current_version_id' => $site->draft_site_version_id, 'versions' => $settings->map(fn ($version): array => $row($version, true))->values()->all()],
            'limits' => ['page_drafts' => self::PAGE_DRAFTS, 'site_drafts' => self::SITE_DRAFTS, 'published_shown' => 30],
            'retention_applies' => $this->retentionApplies($site->tenant), 'can_restore' => $canRestore,
        ];
    }

    public function restorePage(TenantSite $site, TenantSitePage $page, int $sourceId, int $expected, User $actor): TenantSitePageVersion
    {
        $this->assertActor($site, $actor);
        abort_if(app(ConnectedWebsiteService::class)->connected($site), 409, 'Use Website history for this connected site.');
        $websites = app(ManagedWebsiteService::class);

        return DB::transaction(fn (): TenantSitePageVersion => $websites->withDraftWriteLock($site, function () use ($site, $page, $sourceId, $expected, $actor, $websites): TenantSitePageVersion {
            $site = TenantSite::query()->whereKey($site->id)->where('tenant_id', $site->tenant_id)->lockForUpdate()->firstOrFail();
            $this->assertActor($site, $actor);
            $page = TenantSitePage::query()->whereKey($page->id)->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $page->draft_version_id === $expected, 409, 'This page changed. Reload its history before restoring.');
            abort_if($sourceId === $expected, 409, 'That version is already the current draft.');
            $source = TenantSitePageVersion::query()->whereKey($sourceId)->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)->where('tenant_site_page_id', $page->id)->whereIn('status', ['draft', 'published'])->firstOrFail();
            $restored = $websites->saveDraft($site, $page, ['title' => $source->title, 'blocks' => $source->blocks, 'seo' => $source->seo], $actor);
            $websites->recordEvent($site, $page, $actor, 'page.draft_restored', ['source_version_id' => $sourceId, 'version_id' => $restored->id]);

            return $restored;
        }));
    }

    public function restoreSite(TenantSite $site, int $sourceId, int $expected, User $actor): TenantSiteVersion
    {
        $this->assertActor($site, $actor);
        abort_if(app(ConnectedWebsiteService::class)->connected($site), 409, 'Use Website history for this connected site.');
        $websites = app(ManagedWebsiteService::class);

        return DB::transaction(fn (): TenantSiteVersion => $websites->withDraftWriteLock($site, function () use ($site, $sourceId, $expected, $actor, $websites): TenantSiteVersion {
            $site = TenantSite::query()->whereKey($site->id)->where('tenant_id', $site->tenant_id)->lockForUpdate()->firstOrFail();
            $this->assertActor($site, $actor);
            abort_unless((int) $site->draft_site_version_id === $expected, 409, 'Website settings changed. Reload history before restoring.');
            abort_if($sourceId === $expected, 409, 'That version is already the current draft.');
            $source = TenantSiteVersion::query()->whereKey($sourceId)->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)->whereIn('status', ['draft', 'published'])->firstOrFail();
            $restored = $websites->saveSiteDraft($site, ['settings' => $source->settings, 'navigation' => $source->navigation, 'seo' => $source->seo, 'source_manifest' => $source->source_manifest], $actor);
            $websites->recordEvent($site, null, $actor, 'site.draft_restored', ['source_site_version_id' => $sourceId, 'site_version_id' => $restored->id]);

            return $restored;
        }));
    }

    private function publicationEvents(TenantSite $site)
    {
        return TenantSitePublishEvent::query()->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)->whereIn('event_type', ['site.published', 'page.rolled_back']);
    }

    private function recent($query, int $drafts, array $pointers)
    {
        $ids = [...(clone $query)->where('status', 'draft')->orderByDesc('version_number')->limit($drafts)->pluck('id')->all(),
            ...(clone $query)->where('status', 'published')->orderByDesc('version_number')->limit(30)->pluck('id')->all(), ...array_filter($pointers)];

        return $query->whereIn('id', $ids)->orderByDesc('version_number')->get();
    }

    private function canonical(array $value): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($sort, $value);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR);
    }
}

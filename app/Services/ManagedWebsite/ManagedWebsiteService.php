<?php

namespace App\Services\ManagedWebsite;

use App\Jobs\GenerateTenantSiteThumbnail;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSite;
use App\Models\TenantSitePage;
use App\Models\TenantSitePageVersion;
use App\Models\TenantSitePublishEvent;
use App\Models\TenantSiteVersion;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManagedWebsiteService
{
    public function __construct(private readonly WebsiteThemeCatalog $themeCatalog) {}

    /** @return array<int,array<string,mixed>> */
    public function themes(): array
    {
        return $this->themeCatalog->all();
    }

    public function applyTheme(TenantSite $site, string $themeKey, ?User $actor): TenantSite
    {
        $theme = $this->themeCatalog->find($themeKey);
        abort_unless(is_array($theme), 422, 'That website theme is not available.');

        return DB::transaction(function () use ($site, $theme, $actor): TenantSite {
            $navigation = [];
            foreach ((array) $theme['pages'] as $definition) {
                $page = TenantSitePage::query()->firstOrCreate(
                    ['tenant_site_id' => $site->id, 'slug' => (string) $definition['slug']],
                    ['tenant_id' => $site->tenant_id, 'page_type' => $definition['page_type'], 'title' => $definition['title'], 'is_navigation_visible' => true]
                );
                $page->forceFill(['title' => $definition['title'], 'page_type' => $definition['page_type'], 'is_navigation_visible' => true])->save();
                $this->saveDraft($site, $page, ['title' => $definition['title'], 'blocks' => $definition['blocks'], 'seo' => $definition['seo']], $actor);
                $navigation[] = ['label' => $definition['title'], 'url' => $definition['slug'] === '/' ? '/' : '/'.ltrim($definition['slug'], '/'), 'type' => 'page'];
            }
            $settings = (array) $theme['settings'];
            $settings['theme_thumbnail'] = $theme['thumbnail'] ?? null;
            $this->saveSiteDraft($site, ['settings' => $settings, 'navigation' => $navigation, 'source_manifest' => $theme['source_manifest'] ?? []], $actor);
            $this->event($site, null, $actor, 'site.theme_applied', ['theme_key' => $theme['key'], 'page_count' => count($navigation)]);

            return $site->fresh(['pages.draftVersion', 'pages.publishedVersion', 'draftSiteVersion', 'publishedSiteVersion']);
        });
    }

    public function editorEnabledFor(Tenant $tenant): bool
    {
        if (! ((bool) config('managed_website.editor_enabled', false)
            && in_array((int) $tenant->id, (array) config('managed_website.editor_tenant_ids', []), true))) {
            return false;
        }

        return TenantModuleEntitlement::query()
            ->forTenant($tenant)
            ->where('module_key', 'managed_website')
            ->where('enabled_status', 'enabled')
            ->whereIn('billing_status', ['add_on_paid', 'add_on_comped', 'custom_contract', 'trial'])
            ->exists();
    }

    public function publishingEnabled(): bool
    {
        return (bool) config('managed_website.publishing_enabled', false);
    }

    public function publicRenderingEnabled(): bool
    {
        return (bool) config('managed_website.public_render_enabled', false);
    }

    public function publicHostAllowed(TenantSite $site, string $host): bool
    {
        $host = strtolower(trim(explode(':', $host)[0]));
        $baseDomain = strtolower(trim((string) config('tenancy.domains.canonical.base_domain', 'theeverbranch.com')));
        $includedHost = $site->subdomain.'.'.$baseDomain;
        $pilot = $site->relationLoaded('setup') ? $site->setup : $site->setup()->first();
        if ($pilot?->domain_choice === 'everbranch_subdomain') {
            return $host !== '' && $baseDomain !== '' && hash_equals($includedHost, $host);
        }

        return $host !== '' && ($baseDomain !== '' && hash_equals($includedHost, $host)
            || $site->domains()->where('status', 'active')->where('hostname', $host)->exists());
    }

    public function createSite(Tenant $tenant, ?User $actor): TenantSite
    {
        return DB::transaction(function () use ($tenant, $actor): TenantSite {
            $site = TenantSite::query()->firstOrCreate(
                ['tenant_id' => (int) $tenant->id],
                [
                    'status' => 'draft',
                    'public_enabled' => false,
                    'subdomain' => (string) $tenant->slug,
                    'settings' => ['navigation_label' => 'Home'],
                    'created_by_user_id' => $actor?->id,
                    'updated_by_user_id' => $actor?->id,
                ]
            );

            if (! $site->pages()->exists()) {
                $page = TenantSitePage::query()->create([
                    'tenant_id' => $tenant->id,
                    'tenant_site_id' => $site->id,
                    'slug' => '/',
                    'page_type' => 'home',
                    'title' => $tenant->name,
                    'is_navigation_visible' => true,
                ]);
                $this->saveDraft($site, $page, [
                    'title' => $tenant->name,
                    'blocks' => [[
                        'type' => 'hero',
                        'heading' => $tenant->name,
                        'body' => 'A clearer online home for your business.',
                        'cta_label' => 'Get in touch',
                        'cta_url' => '#contact',
                    ]],
                    'seo' => ['title' => $tenant->name, 'description' => 'Learn more about '.$tenant->name.'.'],
                ], $actor);
            }

            if (! $site->draft_site_version_id) {
                $home = $site->pages()->where('slug', '/')->first();
                $this->saveSiteDraft($site, [
                    'settings' => ['theme_name' => $tenant->brandProfile?->display_name ?: $tenant->name, 'theme_palette' => ['ink' => '#142327', 'brand' => '#1e5a63', 'surface' => '#ffffff']],
                    'navigation' => $home ? [['label' => $home->title, 'url' => '/', 'type' => 'page']] : [],
                ], $actor);
            }

            $this->event($site, null, $actor, 'site.created');

            return $site->fresh(['pages.draftVersion', 'pages.publishedVersion', 'draftSiteVersion', 'publishedSiteVersion']);
        });
    }

    /** @param array<string,mixed> $input */
    public function saveSiteDraft(TenantSite $site, array $input, ?User $actor): TenantSiteVersion
    {
        $current = $this->siteVersion($site, true);
        $settings = $this->sanitizeSettings(array_replace((array) ($current?->settings ?? $site->settings ?? []), (array) ($input['settings'] ?? [])));
        $navigation = $this->sanitizeNavigation((array) ($input['navigation'] ?? $current?->navigation ?? []));
        $seo = $this->sanitizeSeo(array_replace((array) ($current?->seo ?? []), (array) ($input['seo'] ?? [])));
        $sourceManifest = $this->sanitizeSourceManifest((array) ($input['source_manifest'] ?? $current?->source_manifest ?? []));

        $version = TenantSiteVersion::query()->create([
            'tenant_id' => $site->tenant_id,
            'tenant_site_id' => $site->id,
            'version_number' => ((int) $site->siteVersions()->max('version_number')) + 1,
            'status' => 'draft',
            'settings' => $settings,
            'navigation' => $navigation,
            'seo' => $seo,
            'thumbnail_path' => $current?->thumbnail_path,
            'source_manifest' => $sourceManifest,
            'created_by_user_id' => $actor?->id,
        ]);
        $site->forceFill([
            'draft_site_version_id' => $version->id,
            // Compatibility only. Public rendering uses publishedSiteVersion.
            'settings' => $settings,
            'updated_by_user_id' => $actor?->id,
        ])->save();
        $this->event($site, null, $actor, 'site.draft_saved', ['site_version_id' => $version->id]);
        if ((bool) config('managed_website.screenshot_enabled', false)) {
            DB::afterCommit(fn () => GenerateTenantSiteThumbnail::dispatch((int) $version->id));
        }

        return $version;
    }

    public function siteVersion(TenantSite $site, bool $draft = true): ?TenantSiteVersion
    {
        $relation = $draft ? 'draftSiteVersion' : 'publishedSiteVersion';
        if ($site->relationLoaded($relation)) {
            return $site->getRelation($relation);
        }

        return $draft ? $site->draftSiteVersion()->first() : $site->publishedSiteVersion()->first();
    }

    /** @param array<string,mixed> $input */
    public function saveDraft(TenantSite $site, TenantSitePage $page, array $input, ?User $actor): TenantSitePageVersion
    {
        $blocks = $this->sanitizeBlocks((array) ($input['blocks'] ?? []));
        if ($blocks === []) {
            throw ValidationException::withMessages(['blocks' => 'Add at least one approved website section.']);
        }

        return DB::transaction(function () use ($site, $page, $input, $actor, $blocks): TenantSitePageVersion {
            $next = ((int) $page->versions()->max('version_number')) + 1;
            $version = TenantSitePageVersion::query()->create([
                'tenant_id' => $site->tenant_id,
                'tenant_site_id' => $site->id,
                'tenant_site_page_id' => $page->id,
                'version_number' => $next,
                'status' => 'draft',
                'title' => trim((string) ($input['title'] ?? $page->title)),
                'blocks' => $blocks,
                'seo' => $this->sanitizeSeo((array) ($input['seo'] ?? [])),
                'created_by_user_id' => $actor?->id,
            ]);
            $page->forceFill([
                'title' => $version->title,
                'draft_version_id' => $version->id,
            ])->save();
            $site->forceFill(['updated_by_user_id' => $actor?->id])->save();
            $this->event($site, $page, $actor, 'page.draft_saved', ['version_id' => $version->id]);

            return $version;
        });
    }

    public function publish(TenantSite $site, ?User $actor): void
    {
        if (! $this->publishingEnabled()) {
            abort(423, 'Website publishing is temporarily frozen. Your draft is safe.');
        }

        DB::transaction(function () use ($site, $actor): void {
            $pages = $site->pages()->with('draftVersion')->get();
            abort_if($pages->isEmpty() || $pages->firstWhere('slug', '/') === null, 422, 'A Home page is required before publishing.');
            $siteDraft = $this->siteVersion($site, true);
            abort_unless($siteDraft instanceof TenantSiteVersion, 422, 'Save your website theme before publishing.');

            foreach ($pages as $page) {
                $draft = $page->draftVersion;
                if (! $draft) {
                    continue;
                }
                $published = TenantSitePageVersion::query()->create([
                    'tenant_id' => $site->tenant_id,
                    'tenant_site_id' => $site->id,
                    'tenant_site_page_id' => $page->id,
                    'version_number' => ((int) $page->versions()->max('version_number')) + 1,
                    'status' => 'published',
                    'title' => $draft->title,
                    'blocks' => $draft->blocks,
                    'seo' => $draft->seo,
                    'created_by_user_id' => $actor?->id,
                    'published_at' => now(),
                ]);
                $page->forceFill(['published_version_id' => $published->id])->save();
            }

            $publishedSiteVersion = TenantSiteVersion::query()->create([
                'tenant_id' => $site->tenant_id,
                'tenant_site_id' => $site->id,
                'version_number' => ((int) $site->siteVersions()->max('version_number')) + 1,
                'status' => 'published',
                'settings' => $siteDraft->settings,
                'navigation' => $siteDraft->navigation,
                'seo' => $siteDraft->seo,
                'thumbnail_path' => $siteDraft->thumbnail_path,
                'source_manifest' => $siteDraft->source_manifest,
                'created_by_user_id' => $actor?->id,
                'published_at' => now(),
            ]);

            $site->forceFill([
                'status' => 'published',
                'public_enabled' => true,
                'published_site_version_id' => $publishedSiteVersion->id,
                'published_at' => now(),
                'updated_by_user_id' => $actor?->id,
            ])->save();
            $this->event($site, null, $actor, 'site.published', ['site_version_id' => $publishedSiteVersion->id]);
            $this->forgetPublicCache($site);
        });
    }

    public function rollback(TenantSite $site, TenantSitePage $page, TenantSitePageVersion $source, ?User $actor): void
    {
        abort_unless($source->tenant_site_page_id === $page->id && $source->status === 'published', 404);

        DB::transaction(function () use ($site, $page, $source, $actor): void {
            $restored = TenantSitePageVersion::query()->create([
                'tenant_id' => $site->tenant_id,
                'tenant_site_id' => $site->id,
                'tenant_site_page_id' => $page->id,
                'version_number' => ((int) $page->versions()->max('version_number')) + 1,
                'status' => 'published',
                'title' => $source->title,
                'blocks' => $source->blocks,
                'seo' => $source->seo,
                'created_by_user_id' => $actor?->id,
                'published_at' => now(),
            ]);
            $page->forceFill(['published_version_id' => $restored->id])->save();
            $this->event($site, $page, $actor, 'page.rolled_back', ['source_version_id' => $source->id, 'version_id' => $restored->id]);
            $this->forgetPublicCache($site);
        });
    }

    /** @return array<string,mixed>|null */
    public function publicPage(Tenant $tenant, string $path): ?array
    {
        if (! $this->publicRenderingEnabled()) {
            return null;
        }
        $site = TenantSite::query()->forTenant($tenant)->where('status', 'published')->where('public_enabled', true)->with('publishedSiteVersion')->first();
        if (! $site) {
            return null;
        }
        $slug = trim('/'.trim($path, '/'), '/');
        $slug = $slug === '' ? '/' : $slug;
        $cacheKey = 'managed-website:public:'.$site->id.':'.sha1($slug);

        return Cache::remember($cacheKey, (int) config('managed_website.cache_seconds', 300), function () use ($site, $slug): ?array {
            $page = TenantSitePage::query()->forTenantId($site->tenant_id)
                ->where('tenant_site_id', $site->id)
                ->where('slug', $slug)
                ->with('publishedVersion')
                ->first();
            if (! $page?->publishedVersion) {
                return null;
            }

            return ['site' => $site, 'page' => $page, 'version' => $page->publishedVersion, 'theme' => $this->siteVersion($site, false)];
        });
    }

    /** @return array<string,mixed>|null */
    public function draftPage(TenantSite $site, TenantSitePage $page): ?array
    {
        $page->loadMissing('draftVersion');
        $theme = $this->siteVersion($site, true);

        if (! $page->draftVersion || ! $theme) {
            return null;
        }

        return ['site' => $site, 'page' => $page, 'version' => $page->draftVersion, 'theme' => $theme, 'isDraftPreview' => true];
    }

    /** @param array<int,mixed> $blocks @return array<int,array<string,mixed>> */
    public function sanitizeBlocks(array $blocks): array
    {
        $allowed = (array) config('managed_website.allowed_blocks', []);
        $safe = [];
        foreach (array_slice($blocks, 0, 40) as $block) {
            if (! is_array($block) || ! in_array((string) ($block['type'] ?? ''), $allowed, true)) {
                continue;
            }
            $row = ['type' => (string) $block['type']];
            $id = preg_replace('/[^a-z0-9_-]/i', '', (string) ($block['id'] ?? ''));
            if ($id !== '') {
                $row['id'] = $id;
            }
            foreach (['heading', 'body', 'label', 'image_alt', 'cta_label', 'question', 'answer', 'hidden', 'visible', 'layout', 'image_position'] as $key) {
                if (isset($block[$key])) {
                    $row[$key] = in_array($key, ['hidden', 'visible'], true)
                        ? ((string) $block[$key] === 'true' ? 'true' : 'false')
                        : strip_tags(mb_substr(trim((string) $block[$key]), 0, 3000));
                }
            }
            foreach (['cta_url', 'image_url'] as $key) {
                if (isset($block[$key]) && $this->safeUrl((string) $block[$key])) {
                    $row[$key] = trim((string) $block[$key]);
                }
            }
            if (isset($block['items']) && is_array($block['items'])) {
                $row['items'] = collect($block['items'])->take(12)->filter(fn (mixed $item): bool => is_array($item))->map(function (array $item): array {
                    $safe = [];
                    foreach (['heading', 'body', 'label', 'image_alt'] as $key) {
                        if (isset($item[$key])) {
                            $safe[$key] = strip_tags(mb_substr(trim((string) $item[$key]), 0, 1000));
                        }
                    }
                    foreach (['url', 'image_url'] as $key) {
                        if (isset($item[$key]) && $this->safeUrl((string) $item[$key])) {
                            $safe[$key] = trim((string) $item[$key]);
                        }
                    }

                    return $safe;
                })->filter()->values()->all();
            }
            $safe[] = $row;
        }

        return $safe;
    }

    /** @param array<string,mixed> $seo @return array<string,string> */
    protected function sanitizeSeo(array $seo): array
    {
        return [
            'title' => strip_tags(mb_substr(trim((string) ($seo['title'] ?? '')), 0, 190)),
            'description' => strip_tags(mb_substr(trim((string) ($seo['description'] ?? '')), 0, 320)),
            'social_image' => $this->safeUrl((string) ($seo['social_image'] ?? '')) ? trim((string) $seo['social_image']) : '',
        ];
    }

    protected function safeUrl(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > 2000 || preg_match('/[\x00-\x1F\x7F\s"\'\\\\]/', $value) === 1) {
            return false;
        }

        return str_starts_with($value, '/') || str_starts_with($value, '#') || preg_match('/^(tel|mailto):/i', $value) === 1 || filter_var($value, FILTER_VALIDATE_URL);
    }

    /** @param array<string,mixed> $settings @return array<string,mixed> */
    protected function sanitizeSettings(array $settings): array
    {
        $palette = (array) ($settings['theme_palette'] ?? []);
        $safePalette = [];
        foreach (['ink', 'brand', 'surface', 'soft', 'accent'] as $key) {
            $value = trim((string) ($palette[$key] ?? ''));
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
                $safePalette[$key] = $value;
            }
        }
        $announcement = (array) ($settings['announcement'] ?? []);
        $footer = (array) ($settings['footer'] ?? []);

        return [
            'theme_key' => preg_replace('/[^a-z0-9_-]/i', '', (string) ($settings['theme_key'] ?? 'custom')) ?: 'custom',
            'theme_name' => strip_tags(mb_substr(trim((string) ($settings['theme_name'] ?? 'Website')), 0, 120)),
            'theme_palette' => $safePalette,
            'typography' => in_array((string) ($settings['typography'] ?? 'sans'), ['sans', 'serif', 'system'], true) ? (string) ($settings['typography'] ?? 'sans') : 'sans',
            'corners' => in_array((string) ($settings['corners'] ?? 'soft'), ['square', 'soft', 'rounded'], true) ? (string) ($settings['corners'] ?? 'soft') : 'soft',
            'content_width' => in_array((string) ($settings['content_width'] ?? 'wide'), ['standard', 'wide'], true) ? (string) ($settings['content_width'] ?? 'wide') : 'wide',
            'announcement' => ['enabled' => (bool) ($announcement['enabled'] ?? false), 'text' => strip_tags(mb_substr(trim((string) ($announcement['text'] ?? '')), 0, 300)), 'url' => $this->safeUrl((string) ($announcement['url'] ?? '')) ? trim((string) $announcement['url']) : ''],
            'footer' => ['copyright' => strip_tags(mb_substr(trim((string) ($footer['copyright'] ?? '')), 0, 300)), 'tagline' => strip_tags(mb_substr(trim((string) ($footer['tagline'] ?? '')), 0, 500))],
            'social_links' => collect((array) ($settings['social_links'] ?? []))->filter(fn (mixed $url): bool => is_string($url) && $this->safeUrl($url))->take(6)->values()->all(),
            'theme_thumbnail' => $this->safeUrl((string) ($settings['theme_thumbnail'] ?? '')) ? trim((string) $settings['theme_thumbnail']) : '',
        ];
    }

    /** @param array<int,mixed> $navigation @return array<int,array<string,string>> */
    protected function sanitizeNavigation(array $navigation): array
    {
        return collect($navigation)->take(10)->filter(fn (mixed $item): bool => is_array($item))->map(function (array $item): ?array {
            $label = strip_tags(mb_substr(trim((string) ($item['label'] ?? '')), 0, 80));
            $url = trim((string) ($item['url'] ?? ''));

            return $label !== '' && $this->safeUrl($url) ? ['label' => $label, 'url' => $url, 'type' => ($item['type'] ?? 'link') === 'page' ? 'page' : 'link'] : null;
        })->filter()->values()->all();
    }

    /** @param array<int,mixed> $sources @return array<int,array<string,string>> */
    protected function sanitizeSourceManifest(array $sources): array
    {
        return collect($sources)->take(20)->filter(fn (mixed $source): bool => is_array($source))->map(function (array $source): ?array {
            $url = trim((string) ($source['url'] ?? ''));

            return filter_var($url, FILTER_VALIDATE_URL) ? ['url' => $url, 'retrieved_on' => preg_replace('/[^0-9-]/', '', (string) ($source['retrieved_on'] ?? '')), 'use' => strip_tags(mb_substr(trim((string) ($source['use'] ?? '')), 0, 300))] : null;
        })->filter()->values()->all();
    }

    protected function forgetPublicCache(TenantSite $site): void
    {
        foreach ($site->pages()->pluck('slug') as $slug) {
            Cache::forget('managed-website:public:'.$site->id.':'.sha1((string) $slug));
        }
    }

    /** @param array<string,mixed> $context */
    protected function event(TenantSite $site, ?TenantSitePage $page, ?User $actor, string $type, array $context = []): void
    {
        TenantSitePublishEvent::query()->create([
            'tenant_id' => $site->tenant_id, 'tenant_site_id' => $site->id, 'tenant_site_page_id' => $page?->id,
            'actor_user_id' => $actor?->id, 'event_type' => $type, 'context' => $context,
        ]);
    }

    /** @param array<string,mixed> $context */
    public function recordEvent(TenantSite $site, ?TenantSitePage $page, ?User $actor, string $type, array $context = []): void
    {
        $this->event($site, $page, $actor, $type, $context);
    }
}

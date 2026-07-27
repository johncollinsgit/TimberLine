<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSite;
use App\Models\TenantSitePage;
use App\Models\TenantSitePageVersion;
use App\Models\TenantSitePublishEvent;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManagedWebsiteService
{
    /** @return array<int,array<string,mixed>> */
    public function themes(): array
    {
        return [
            [
                'key' => 'hvac-service', 'name' => 'HVAC Service', 'eyebrow' => 'Service-first',
                'description' => 'A calm, clear service website built for urgent calls, seasonal work, and trust.',
                'palette' => ['ink' => '#17343b', 'brand' => '#167f8c', 'surface' => '#f8fbfb'],
                'hero' => ['heading' => 'Comfort for every season.', 'body' => 'Clear service, dependable technicians, and an easy way to request help.', 'cta_label' => 'Request service', 'cta_url' => '#contact'],
            ],
            [
                'key' => 'collins-electric', 'name' => 'Collins Upstate Electric', 'eyebrow' => 'Clean trade',
                'description' => 'A sharp white, navy, and service-forward starter built around the approved Collins mark.',
                'palette' => ['ink' => '#13243b', 'brand' => '#164b7a', 'surface' => '#ffffff'],
                'hero' => ['heading' => 'Power your next project with confidence.', 'body' => 'A clean, direct starting point for residential, commercial, and service work.', 'cta_label' => 'Talk to an electrician', 'cta_url' => '#contact'],
            ],
            [
                'key' => 'outdoor-elements', 'name' => 'Outdoor Elements', 'eyebrow' => 'Outdoor living',
                'description' => 'A warm, premium starter for outdoor structures, furniture, cabinetry, and fireplaces.',
                'palette' => ['ink' => '#26352e', 'brand' => '#6d7d56', 'surface' => '#fbfaf6'],
                'hero' => ['heading' => 'Elevate your outdoor space.', 'body' => 'Explore considered structures, furniture, cabinetry, and fire features for life outside.', 'cta_label' => 'Explore the collection', 'cta_url' => '/shop'],
            ],
        ];
    }

    public function applyTheme(TenantSite $site, string $themeKey, ?User $actor): TenantSite
    {
        $theme = collect($this->themes())->firstWhere('key', $themeKey);
        abort_unless(is_array($theme), 422, 'That website theme is not available.');
        $home = $site->pages()->where('slug', '/')->firstOrFail();
        $this->saveDraft($site, $home, [
            'title' => $site->tenant->brandProfile?->display_name ?: $site->tenant->name,
            'seo' => ['title' => $site->tenant->brandProfile?->display_name ?: $site->tenant->name, 'description' => $theme['description']],
            'blocks' => [
                ['type' => 'announcement', 'body' => 'Thoughtful service. Clear next steps.'],
                ['type' => 'header', 'heading' => $site->tenant->brandProfile?->display_name ?: $site->tenant->name],
                ['type' => 'hero'] + $theme['hero'],
                ['type' => 'services', 'heading' => 'What we can help with', 'body' => 'Use these cards to make your most important services or products easy to understand.'],
                ['type' => 'testimonial', 'heading' => 'Built around real customers', 'body' => 'Add a customer story that makes the decision to contact you feel easy.'],
                ['type' => 'product_grid', 'heading' => 'Featured products and services', 'body' => 'Choose what to feature from your Website catalog.'],
                ['type' => 'faq', 'question' => 'What happens after I reach out?', 'answer' => 'Add the accurate next step for your business here.'],
                ['type' => 'contact_form', 'heading' => 'Start a conversation'],
                ['type' => 'footer', 'body' => '© '.now()->year.' '.($site->tenant->brandProfile?->display_name ?: $site->tenant->name)],
            ],
        ], $actor);
        $settings = (array) $site->settings;
        $site->forceFill(['settings' => $settings + ['theme_key' => $theme['key'], 'theme_name' => $theme['name'], 'theme_palette' => $theme['palette']], 'updated_by_user_id' => $actor?->id])->save();
        $this->event($site, $home, $actor, 'site.theme_applied', ['theme_key' => $theme['key']]);

        return $site->fresh(['pages.draftVersion', 'pages.publishedVersion']);
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

            $this->event($site, null, $actor, 'site.created');

            return $site->fresh(['pages.draftVersion', 'pages.publishedVersion']);
        });
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

            $site->forceFill([
                'status' => 'published',
                'public_enabled' => true,
                'published_at' => now(),
                'updated_by_user_id' => $actor?->id,
            ])->save();
            $this->event($site, null, $actor, 'site.published');
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
        $site = TenantSite::query()->forTenant($tenant)->where('status', 'published')->where('public_enabled', true)->first();
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

            return ['site' => $site, 'page' => $page, 'version' => $page->publishedVersion];
        });
    }

    /** @param array<int,mixed> $blocks @return array<int,array<string,string>> */
    public function sanitizeBlocks(array $blocks): array
    {
        $allowed = (array) config('managed_website.allowed_blocks', []);
        $safe = [];
        foreach (array_slice($blocks, 0, 40) as $block) {
            if (! is_array($block) || ! in_array((string) ($block['type'] ?? ''), $allowed, true)) {
                continue;
            }
            $row = ['type' => (string) $block['type']];
            foreach (['heading', 'body', 'label', 'image_alt', 'cta_label', 'question', 'answer'] as $key) {
                if (isset($block[$key])) {
                    $row[$key] = strip_tags(mb_substr(trim((string) $block[$key]), 0, 3000));
                }
            }
            foreach (['cta_url', 'image_url'] as $key) {
                if (isset($block[$key]) && $this->safeUrl((string) $block[$key])) {
                    $row[$key] = trim((string) $block[$key]);
                }
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

        return $value !== '' && (str_starts_with($value, '/') || str_starts_with($value, '#') || filter_var($value, FILTER_VALIDATE_URL));
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
}

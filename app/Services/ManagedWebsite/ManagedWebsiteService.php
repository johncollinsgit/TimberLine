<?php

namespace App\Services\ManagedWebsite;

use App\Jobs\GenerateTenantSiteThumbnail;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSite;
use App\Models\TenantSiteMedia;
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
        $blocks = $this->sanitizeBlocks((array) ($input['blocks'] ?? []), $site);
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
        if (! $site || app(ConnectedWebsiteService::class)->connected($site)) {
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

    /**
     * @param array<int,mixed> $blocks
     * @return array<int,array<string,mixed>>
     */
    public function sanitizeBlocks(array $blocks, ?TenantSite $site = null): array
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
            if ($row['type'] === 'interactive_product_viewer') {
                if (! $site instanceof TenantSite) {
                    throw ValidationException::withMessages(['blocks' => 'An interactive product viewer must belong to a website.']);
                }

                $safe[] = $this->sanitizeInteractiveProductViewer($block, $row, $site);

                continue;
            }
            if ($row['type'] === 'product_video') {
                if (! $site instanceof TenantSite) {
                    throw ValidationException::withMessages(['blocks' => 'A product video must belong to a website.']);
                }

                $safe[] = $this->sanitizeProductVideo($block, $row, $site);

                continue;
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

    /**
     * Return server-resolved URLs for a published or previewed product viewer.
     * Page snapshots retain media IDs only; a renderer must never persist these
     * mutable URLs into a version record.
     *
     * @param array<string,mixed> $block
     * @return array{model_url:string,poster_url:string}|null
     */
    public function interactiveProductViewerMedia(TenantSite $site, array $block): ?array
    {
        $modelId = filter_var($block['model_media_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $posterId = filter_var($block['poster_media_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $modelId || ! $posterId) {
            return null;
        }

        $media = TenantSiteMedia::query()
            ->where('tenant_id', $site->tenant_id)
            ->where('tenant_site_id', $site->id)
            ->whereIn('id', [$modelId, $posterId])
            ->get()
            ->keyBy('id');
        $model = $media->get($modelId);
        $poster = $media->get($posterId);
        if (! $model instanceof TenantSiteMedia || ! $poster instanceof TenantSiteMedia
            || $model->kind !== 'model' || $model->mime_type !== 'model/gltf-binary' || $poster->kind !== 'image') {
            return null;
        }

        return [
            'model_url' => route('managed-website.media.show', ['media' => $model]),
            'poster_url' => route('managed-website.media.show', ['media' => $poster]),
        ];
    }

    /** @param array<string,mixed> $block @return array<int,array<string,mixed>> */
    public function interactiveProductViewerVariants(TenantSite $site, array $block): array
    {
        return collect((array) ($block['variants'] ?? []))->map(function (mixed $variant) use ($site): ?array {
            if (! is_array($variant)) {
                return null;
            }
            $media = $this->interactiveProductViewerMedia($site, $variant);
            if ($media === null) {
                return null;
            }

            return [
                'id' => (string) ($variant['id'] ?? ''),
                'label' => (string) ($variant['label'] ?? ''),
                'poster_alt' => (string) ($variant['poster_alt'] ?? ''),
                'raise_clip' => (string) ($variant['raise_clip'] ?? 'raise'),
                'lower_clip' => (string) ($variant['lower_clip'] ?? 'lower'),
                'hotspots' => (array) ($variant['hotspots'] ?? []),
                ...$media,
            ];
        })->filter()->values()->all();
    }

    /**
     * Verify a binary, self-contained glTF 2.0 file and return public-safe
     * metadata used by the editor contract. This intentionally does not accept
     * JSON .gltf files, URI-based buffers/textures, or arbitrary extensions.
     *
     * @return array<string,mixed>
     */
    public function inspectGlb(string $path): array
    {
        $size = @filesize($path);
        $handle = @fopen($path, 'rb');
        if (! is_int($size) || $size < 20 || $handle === false) {
            throw ValidationException::withMessages(['model' => 'Upload a valid binary GLB model.']);
        }

        try {
            $header = fread($handle, 12);
            if (! is_string($header) || strlen($header) !== 12) {
                throw new \RuntimeException('missing header');
            }
            $values = unpack('Vmagic/Vversion/Vlength', $header);
            if (($values['magic'] ?? 0) !== 0x46546c67 || ($values['version'] ?? 0) !== 2 || ($values['length'] ?? 0) !== $size) {
                throw new \RuntimeException('invalid GLB header');
            }

            $offset = 12;
            $json = null;
            $binBytes = 0;
            $chunkCount = 0;
            while ($offset < $size) {
                $chunkHeader = fread($handle, 8);
                if (! is_string($chunkHeader) || strlen($chunkHeader) !== 8) {
                    throw new \RuntimeException('truncated GLB chunk header');
                }
                $chunk = unpack('Vlength/Vtype', $chunkHeader);
                $chunkLength = (int) ($chunk['length'] ?? -1);
                $chunkType = (int) ($chunk['type'] ?? 0);
                if ($chunkLength < 0 || $chunkLength % 4 !== 0 || $offset + 8 + $chunkLength > $size) {
                    throw new \RuntimeException('invalid GLB chunk length');
                }
                $data = fread($handle, $chunkLength);
                if (! is_string($data) || strlen($data) !== $chunkLength) {
                    throw new \RuntimeException('truncated GLB chunk');
                }
                if ($chunkCount === 0 && $chunkType === 0x4e4f534a) { // JSON
                    $json = $data;
                } elseif ($chunkType === 0x004e4942) { // BIN\0
                    $binBytes += $chunkLength;
                } else {
                    throw new \RuntimeException('unsupported GLB chunk');
                }
                $offset += 8 + $chunkLength;
                $chunkCount++;
            }
            if (! is_string($json) || $offset !== $size) {
                throw new \RuntimeException('missing JSON chunk');
            }
            $document = json_decode(rtrim($json, "\\0 \\t\\r\\n"), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($document) || ! isset($document['asset']['version']) || ! str_starts_with((string) $document['asset']['version'], '2.')) {
                throw new \RuntimeException('unsupported glTF version');
            }
            $this->assertSelfContainedGlb($document, $binBytes);

            $clips = collect((array) ($document['animations'] ?? []))
                ->map(fn (mixed $animation): string => is_array($animation) ? trim((string) ($animation['name'] ?? '')) : '')
                ->filter(fn (string $name): bool => preg_match('/^[A-Za-z0-9_-]{1,80}$/', $name) === 1)
                ->unique()->values()->all();
            $nodes = collect((array) ($document['nodes'] ?? []))
                ->map(fn (mixed $node): string => is_array($node) ? trim((string) ($node['name'] ?? '')) : '')
                ->filter(fn (string $name): bool => preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $name) === 1)
                ->unique()->values()->all();

            return [
                'format' => 'glb',
                'gltf_version' => '2.0',
                'animation_clips' => $clips,
                'nodes' => $nodes,
                'bounding_box' => $this->glbBoundingBox($document),
                'asset_manifest' => [
                    'generator' => mb_substr(trim((string) ($document['asset']['generator'] ?? '')), 0, 190),
                    'extensions_used' => array_values(array_filter((array) ($document['extensionsUsed'] ?? []), 'is_string')),
                    'byte_length' => $size,
                ],
            ];
        } catch (\Throwable) {
            throw ValidationException::withMessages(['model' => 'Upload a valid, self-contained glTF 2.0 binary (.glb) model.']);
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $block @param array<string,mixed> $row @return array<string,mixed> */
    protected function sanitizeInteractiveProductViewer(array $block, array $row, TenantSite $site): array
    {
        $model = $this->ownedViewerMedia($site, $block['model_media_id'] ?? null, 'model', 'model_media_id');
        $poster = $this->ownedViewerMedia($site, $block['poster_media_id'] ?? null, 'image', 'poster_media_id');
        if ($model->mime_type !== 'model/gltf-binary') {
            throw ValidationException::withMessages(['blocks' => 'The selected product model is not a GLB asset.']);
        }
        $metadata = (array) $model->metadata;
        $clips = array_values(array_filter((array) ($metadata['animation_clips'] ?? []), 'is_string'));
        $nodes = array_values(array_filter((array) ($metadata['nodes'] ?? []), 'is_string'));
        $raiseClip = $this->viewerName($block['raise_clip'] ?? 'raise', 'raise_clip');
        $lowerClip = $this->viewerName($block['lower_clip'] ?? 'lower', 'lower_clip');
        if (! in_array($raiseClip, $clips, true) || ! in_array($lowerClip, $clips, true)) {
            throw ValidationException::withMessages(['blocks' => 'The selected model must include the configured raise and lower animation clips.']);
        }
        $row['model_media_id'] = $model->id;
        $row['poster_media_id'] = $poster->id;
        $row['raise_clip'] = $raiseClip;
        $row['lower_clip'] = $lowerClip;
        foreach (['label', 'heading', 'body', 'cta_label'] as $key) {
            if (isset($block[$key])) {
                $row[$key] = strip_tags(mb_substr(trim((string) $block[$key]), 0, 3000));
            }
        }
        if (isset($block['poster_alt'])) {
            $row['poster_alt'] = strip_tags(mb_substr(trim((string) $block['poster_alt']), 0, 1000));
        }
        if (isset($block['cta_url']) && $this->safeUrl((string) $block['cta_url'])) {
            $row['cta_url'] = trim((string) $block['cta_url']);
        }
        $row['raise_label'] = strip_tags(mb_substr(trim((string) ($block['raise_label'] ?? 'Raise cabinet')), 0, 120));
        $row['lower_label'] = strip_tags(mb_substr(trim((string) ($block['lower_label'] ?? 'Lower cabinet')), 0, 120));
        if ($row['raise_label'] === '' || $row['lower_label'] === '') {
            throw ValidationException::withMessages(['blocks' => 'Viewer control labels cannot be empty.']);
        }
        $hotspotIds = [];
        $row['hotspots'] = collect((array) ($block['hotspots'] ?? []))->take(12)->map(function (mixed $hotspot) use (&$hotspotIds, $nodes): array {
            if (! is_array($hotspot)) {
                throw ValidationException::withMessages(['blocks' => 'Each viewer hotspot must be an object.']);
            }
            $id = trim((string) ($hotspot['id'] ?? ''));
            $node = trim((string) ($hotspot['node_name'] ?? ''));
            if (preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id) !== 1 || in_array($id, $hotspotIds, true)
                || preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $node) !== 1 || ! in_array($node, $nodes, true)) {
                throw ValidationException::withMessages(['blocks' => 'Viewer hotspots must have unique IDs and reference named model nodes.']);
            }
            $hotspotIds[] = $id;

            return [
                'id' => $id,
                'label' => strip_tags(mb_substr(trim((string) ($hotspot['label'] ?? '')), 0, 190)),
                'body' => strip_tags(mb_substr(trim((string) ($hotspot['body'] ?? '')), 0, 1000)),
                'node_name' => $node,
            ];
        })->all();
        if (array_key_exists('variants', $block)) {
            $variants = (array) $block['variants'];
            if (count($variants) < 2) {
                throw ValidationException::withMessages(['blocks' => 'A product viewer toggle needs at least two approved variants.']);
            }
            $ids = [];
            $row['variants'] = collect($variants)->take(6)->values()->map(function (mixed $variant, int $index) use ($site, &$ids): array {
                if (! is_array($variant)) {
                    throw ValidationException::withMessages(['blocks' => 'Each product viewer variant must be an object.']);
                }
                $id = trim((string) ($variant['id'] ?? ''));
                if (preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id) !== 1 || in_array($id, $ids, true)) {
                    throw ValidationException::withMessages(['blocks' => 'Viewer variants need unique IDs.']);
                }
                $ids[] = $id;
                $model = $this->ownedViewerMedia($site, $variant['model_media_id'] ?? null, 'model', "variants.$index.model_media_id");
                $poster = $this->ownedViewerMedia($site, $variant['poster_media_id'] ?? null, 'image', "variants.$index.poster_media_id");
                $variantMetadata = (array) ($model->metadata ?? []);
                $clips = array_values(array_filter((array) ($variantMetadata['animation_clips'] ?? []), 'is_string'));
                $raise = $this->viewerName($variant['raise_clip'] ?? 'raise', "variants.$index.raise_clip");
                $lower = $this->viewerName($variant['lower_clip'] ?? 'lower', "variants.$index.lower_clip");
                if ($model->mime_type !== 'model/gltf-binary' || ! in_array($raise, $clips, true) || ! in_array($lower, $clips, true)) {
                    throw ValidationException::withMessages(['blocks' => 'Each variant needs an owned GLB with its configured raise and lower clips.']);
                }

                return [
                    'id' => $id,
                    'label' => strip_tags(mb_substr(trim((string) ($variant['label'] ?? $id)), 0, 120)),
                    'model_media_id' => $model->id,
                    'poster_media_id' => $poster->id,
                    'poster_alt' => strip_tags(mb_substr(trim((string) ($variant['poster_alt'] ?? '')), 0, 1000)),
                    'raise_clip' => $raise,
                    'lower_clip' => $lower,
                    'hotspots' => [],
                ];
            })->all();
        }

        return $row;
    }

    /** @param array<string,mixed> $block @param array<string,mixed> $row @return array<string,mixed> */
    protected function sanitizeProductVideo(array $block, array $row, TenantSite $site): array
    {
        $video = $this->ownedViewerMedia($site, $block['video_media_id'] ?? null, 'video', 'video_media_id');
        if ($video->mime_type !== 'video/mp4') {
            throw ValidationException::withMessages(['video_media_id' => 'The selected product video must be an MP4 file.']);
        }
        $row['video_media_id'] = $video->id;
        if (isset($block['poster_media_id'])) {
            $poster = $this->ownedViewerMedia($site, $block['poster_media_id'], 'image', 'poster_media_id');
            $row['poster_media_id'] = $poster->id;
        }
        foreach (['label', 'heading', 'body'] as $key) {
            if (isset($block[$key])) {
                $row[$key] = strip_tags(mb_substr(trim((string) $block[$key]), 0, 3000));
            }
        }
        if (isset($block['video_alt'])) {
            $row['video_alt'] = strip_tags(mb_substr(trim((string) $block['video_alt']), 0, 1000));
        }

        return $row;
    }

    /** @param array<string,mixed> $block @return array{video_url:string,poster_url:?string}|null */
    public function productVideoMedia(TenantSite $site, array $block): ?array
    {
        $video = $this->ownedSiteMedia($site, $block['video_media_id'] ?? null, 'video');
        $poster = isset($block['poster_media_id']) ? $this->ownedSiteMedia($site, $block['poster_media_id'], 'image') : null;
        if (! $video instanceof TenantSiteMedia || $video->mime_type !== 'video/mp4') {
            return null;
        }

        return [
            'video_url' => route('managed-website.media.show', ['media' => $video]),
            'poster_url' => $poster instanceof TenantSiteMedia ? route('managed-website.media.show', ['media' => $poster]) : null,
        ];
    }

    protected function ownedSiteMedia(TenantSite $site, mixed $id, string $kind): ?TenantSiteMedia
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id ? TenantSiteMedia::query()->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)->where('kind', $kind)->find($id) : null;
    }

    protected function ownedViewerMedia(TenantSite $site, mixed $id, string $kind, string $field): TenantSiteMedia
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $media = $id ? TenantSiteMedia::query()->where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)->find($id) : null;
        if (! $media instanceof TenantSiteMedia || $media->kind !== $kind) {
            throw ValidationException::withMessages([$field => 'Select media owned by this website.']);
        }

        return $media;
    }

    protected function viewerName(mixed $name, string $field): string
    {
        $name = trim((string) $name);
        if (preg_match('/^[A-Za-z0-9_-]{1,80}$/', $name) !== 1) {
            throw ValidationException::withMessages([$field => 'Use an approved animation clip name.']);
        }

        return $name;
    }

    /** @param array<string,mixed> $document */
    protected function assertSelfContainedGlb(array $document, int $binBytes): void
    {
        $allowedExtensions = [
            'EXT_meshopt_compression',
            'KHR_materials_emissive_strength',
            'KHR_texture_basisu',
            // UV scale/offset for embedded PBR textures. This affects no external
            // resource loading and is part of the glTF 2.0 material contract.
            'KHR_texture_transform',
        ];
        foreach (array_merge((array) ($document['extensionsUsed'] ?? []), (array) ($document['extensionsRequired'] ?? [])) as $extension) {
            if (! is_string($extension) || ! in_array($extension, $allowedExtensions, true)) {
                throw new \RuntimeException('unsupported glTF extension');
            }
        }
        $walk = function (mixed $value) use (&$walk): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if ((string) $key === 'uri' || (is_string($child) && preg_match('/<\\s*script|javascript:/i', $child) === 1)) {
                    throw new \RuntimeException('external or executable resource');
                }
                $walk($child);
            }
        };
        $walk($document);
        $buffers = (array) ($document['buffers'] ?? []);
        foreach ($buffers as $buffer) {
            $meshoptFallback = is_array($buffer) && (($buffer['extensions']['EXT_meshopt_compression']['fallback'] ?? false) === true);
            if (! is_array($buffer) || ! isset($buffer['byteLength']) || ! is_int($buffer['byteLength']) || $buffer['byteLength'] < 0
                || ($buffer['byteLength'] > $binBytes && ! $meshoptFallback)) {
                throw new \RuntimeException('invalid binary buffer');
            }
        }
        $bufferViews = (array) ($document['bufferViews'] ?? []);
        foreach ($bufferViews as $bufferView) {
            $meshopt = is_array($bufferView) ? (($bufferView['extensions']['EXT_meshopt_compression'] ?? null)) : null;
            $source = is_array($meshopt) ? $meshopt : $bufferView;
            $bufferIndex = is_array($source) ? ($source['buffer'] ?? null) : null;
            $buffer = is_int($bufferIndex) ? ($buffers[$bufferIndex] ?? null) : null;
            $byteOffset = is_array($source) ? ($source['byteOffset'] ?? 0) : null;
            $byteLength = is_array($source) ? ($source['byteLength'] ?? null) : null;
            if (! is_array($buffer) || ! is_int($byteOffset) || ! is_int($byteLength) || $byteOffset < 0 || $byteLength < 0 || $byteOffset + $byteLength > (int) $buffer['byteLength']) {
                throw new \RuntimeException('invalid buffer view');
            }
        }
        foreach ((array) ($document['images'] ?? []) as $image) {
            $bufferView = is_array($image) ? ($image['bufferView'] ?? null) : null;
            if (! is_array($image) || ! is_int($bufferView) || ! array_key_exists($bufferView, $bufferViews)) {
                throw new \RuntimeException('image is not embedded in the GLB binary');
            }
        }
    }

    /** @param array<string,mixed> $document @return array{min:array<int,float>,max:array<int,float>}|null */
    protected function glbBoundingBox(array $document): ?array
    {
        $accessors = (array) ($document['accessors'] ?? []);
        $minimum = null;
        $maximum = null;
        foreach ((array) ($document['meshes'] ?? []) as $mesh) {
            foreach ((array) (is_array($mesh) ? ($mesh['primitives'] ?? []) : []) as $primitive) {
                $index = is_array($primitive) ? ($primitive['attributes']['POSITION'] ?? null) : null;
                $accessor = is_int($index) ? ($accessors[$index] ?? null) : null;
                if (! is_array($accessor) || ($accessor['type'] ?? null) !== 'VEC3' || ! isset($accessor['min'], $accessor['max']) || ! is_array($accessor['min']) || ! is_array($accessor['max'])) {
                    continue;
                }
                $min = array_map('floatval', array_slice($accessor['min'], 0, 3));
                $max = array_map('floatval', array_slice($accessor['max'], 0, 3));
                if (count($min) !== 3 || count($max) !== 3) {
                    continue;
                }
                $minimum = $minimum === null ? $min : array_map(fn (float $current, float $candidate): float => min($current, $candidate), $minimum, $min);
                $maximum = $maximum === null ? $max : array_map(fn (float $current, float $candidate): float => max($current, $candidate), $maximum, $max);
            }
        }

        return $minimum === null || $maximum === null ? null : ['min' => $minimum, 'max' => $maximum];
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

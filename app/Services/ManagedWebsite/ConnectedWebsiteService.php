<?php

namespace App\Services\ManagedWebsite;

use App\Models\TenantSite;
use App\Models\TenantSiteVersion;
use App\Models\User;
use App\Models\WebsiteProduct;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Versioned content for the separately hosted, existing Carolina Barrel renderer. */
class ConnectedWebsiteService
{
    public const SLUG = 'carolina-barrel-co';

    public const RENDERER = 'carolina_barrel_v1';

    public const ORIGIN = 'https://carolina-barrel-co.theeverbranch.com';

    public function manifest(): array
    {
        return json_decode(file_get_contents(resource_path('connected-sites/carolina-barrel.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function connected(?TenantSite $site): bool
    {
        return $site && data_get($site->settings, 'connected_renderer') === self::RENDERER
            && $site->tenant?->slug === self::SLUG;
    }

    public function assertAvailable(TenantSite $site, bool $editor = false): void
    {
        abort_unless($this->connected($site), 404);
        $websites = app(ManagedWebsiteService::class);
        abort_unless(app(TenantModuleAccessResolver::class)->canAccess($site->tenant_id, 'managed_website'), 423);
        abort_unless(in_array($site->tenant_id, config('managed_website.editor_tenant_ids', []), true), 423);
        if ($editor) {
            abort_unless($websites->editorEnabledFor($site->tenant), 423);
        } else {
            abort_unless($websites->publicRenderingEnabled() && $site->public_enabled && $site->status === 'published', 423);
        }
    }

    public function assertEditor(TenantSite $site, ?User $actor, bool $publish = false): void
    {
        $this->assertAvailable($site, true);
        $membership = $actor?->tenants()->whereKey($site->tenant_id)->first();
        abort_unless($actor?->is_active && $membership && (bool) ($membership->pivot->membership_active ?? true)
            && in_array($membership->pivot->role, ['owner', 'tenant_owner', 'admin', 'manager', 'marketing_manager'], true), 403);
        if ($publish) {
            abort_unless(app(ManagedWebsiteAccessService::class)->canPublish($site->tenant, $actor), 403);
            abort_unless(app(ManagedWebsiteService::class)->publishingEnabled(), 423);
        }
    }

    public function content(TenantSite $site, int $versionId): array
    {
        $version = $site->siteVersions()->where('tenant_id', $site->tenant_id)->findOrFail($versionId);
        abort_unless(data_get($version->settings, 'connected_renderer') === self::RENDERER, 404);

        return $this->validateContent((array) data_get($version->settings, 'connected_content'));
    }

    /** @return array<int,array<string,mixed>> */
    public function catalog(TenantSite $site, bool $includeDrafts = false): array
    {
        $query = WebsiteProduct::query()->forTenantId($site->tenant_id)
            ->where('tenant_site_id', $site->id)
            ->with('variants');
        $includeDrafts ? $query->whereIn('status', ['active', 'draft']) : $query->where('status', 'active');

        return $query->orderBy('id')->get()->map(function (WebsiteProduct $product): array {
            $catalog = (array) data_get($product->service_details, 'catalog', []);
            $images = collect((array) $product->media)->filter(fn ($url) => is_string($url) && filter_var($url, FILTER_VALIDATE_URL))->take(12)->values()->all();
            $collection = (string) ($catalog['collection'] ?? 'Carolina Barrel Co.');
            $collectionHandle = \Illuminate\Support\Str::slug($collection);
            $alt = (array) ($catalog['media_alt'] ?? []);

            return [
                'slug' => $product->handle,
                'name' => $product->title,
                'shortName' => $product->title,
                'collection' => $collection,
                'collections' => $collectionHandle !== '' ? [$collectionHandle] : [],
                'image' => $images[0] ?? '',
                'images' => $images,
                'alt' => $alt[0] ?? $product->title,
                'mediaAlt' => $alt,
                'summary' => $product->description,
                'details' => (array) ($catalog['details'] ?? []),
                'sourceEvidence' => (array) ($catalog['source_evidence'] ?? []),
                'reviewStatus' => (string) ($catalog['review_status'] ?? 'approved'),
                'quoteOnly' => true,
                'retail' => null,
                'seo' => (array) $product->seo,
                'variants' => [],
            ];
        })->values()->all();
    }

    /** @return array<int,array{title:string,handle:string,description:null,image_url:null}> */
    public function collections(TenantSite $site, bool $includeDrafts = false): array
    {
        return collect($this->catalog($site, $includeDrafts))->map(fn (array $product) => [
            'title' => $product['collection'],
            'handle' => $product['collections'][0] ?? '',
            'description' => null,
            'image_url' => $product['image'] ?: null,
        ])->filter(fn (array $collection) => $collection['handle'] !== '')->unique('handle')->values()->all();
    }

    public function activeProduct(TenantSite $site, string $handle): bool
    {
        return WebsiteProduct::query()->forTenantId($site->tenant_id)
            ->where('tenant_site_id', $site->id)
            ->where('status', 'active')
            ->where('handle', $handle)
            ->exists();
    }

    public function presentation(TenantSite $site, int $versionId, bool $preview = false): string
    {
        if ($preview) {
            return 'catalog_v2';
        }
        $version = $site->siteVersions()->where('tenant_id', $site->tenant_id)->findOrFail($versionId);

        return data_get($version->settings, 'connected_presentation') === 'catalog_v2' ? 'catalog_v2' : 'v1';
    }

    public function validateContent(array $content): array
    {
        $content = array_map(fn ($value) => $value ?? '', $content);
        $fields = $this->manifest()['fields'];
        if (array_diff(array_keys($content), array_keys($fields)) || array_diff(array_keys($fields), array_keys($content))) {
            throw ValidationException::withMessages(['content' => 'The website field list changed. Reload before saving.']);
        }
        $rules = [];
        foreach ($fields as $key => $field) {
            $rules[$key] = ['present', 'string', 'max:8000'];
        }
        Validator::make($content, $rules)->validate();
        foreach ($fields as $key => $field) {
            $value = $content[$key];
            $valid = match ($field['type']) {
                'money' => preg_match('/^\d{1,7}(\.\d{1,2})?$/D', $value),
                'image' => preg_match('~^/(?!/)[a-zA-Z0-9/_.,@%+\-]+$~D', $value)
                    || (filter_var($value, FILTER_VALIDATE_URL) && str_starts_with($value, 'https://') && ! preg_match('/[\s"\'<>\\\\]/', $value)),
                'link' => preg_match('~^/(?!/)[a-zA-Z0-9/_.,@%+?=&\#\-]*$~D', $value)
                    || preg_match('~^(https://|mailto:|tel:)[^\s"\'<>\\\\]+$~D', $value),
                default => true,
            };
            if (! $valid) {
                throw ValidationException::withMessages(["content.$key" => $field['label'].': enter a valid '.$field['type'].'.']);
            }
        }

        return $content;
    }

    private function version(TenantSite $site, array $content, User $actor, string $status, string $presentation = 'v1'): TenantSiteVersion
    {
        return $site->siteVersions()->create([
            'tenant_id' => $site->tenant_id,
            'version_number' => ((int) $site->siteVersions()->max('version_number')) + 1,
            'status' => $status,
            'settings' => ['connected_renderer' => self::RENDERER, 'connected_content' => $content, 'connected_presentation' => $presentation],
            'navigation' => [], 'seo' => [],
            'source_manifest' => ['renderer' => self::RENDERER, 'origin' => self::ORIGIN],
            'created_by_user_id' => $actor->id,
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }

    public function save(TenantSite $site, array $content, User $actor, int $expected): TenantSiteVersion
    {
        $this->assertEditor($site, $actor);
        $content = $this->validateContent($content);

        return DB::transaction(function () use ($site, $content, $actor, $expected) {
            $site = TenantSite::query()->lockForUpdate()->findOrFail($site->id);
            abort_unless($site->draft_site_version_id === $expected, 409, 'Someone saved a newer draft. Reload before saving.');
            $version = $this->version($site, $content, $actor, 'draft');
            $site->update(['draft_site_version_id' => $version->id, 'updated_by_user_id' => $actor->id]);
            app(ManagedWebsiteService::class)->recordEvent($site, null, $actor, 'connected.draft_saved', ['previous_version_id' => $expected, 'version_id' => $version->id]);

            return $version;
        });
    }

    public function publish(TenantSite $site, User $actor, int $expected): TenantSiteVersion
    {
        $this->assertEditor($site, $actor, true);

        return DB::transaction(function () use ($site, $actor, $expected) {
            $site = TenantSite::query()->lockForUpdate()->findOrFail($site->id);
            abort_unless($site->draft_site_version_id === $expected, 409, 'The draft changed. Reload and review it before publishing.');
            $content = $this->content($site, $expected);
            $presentation = $this->presentation($site, $expected);
            $version = $this->version($site, $content, $actor, 'published', $presentation);
            $before = $site->published_site_version_id;
            $site->update(['published_site_version_id' => $version->id, 'status' => 'published', 'public_enabled' => true, 'published_at' => now(), 'updated_by_user_id' => $actor->id]);
            $this->publishCatalog($site, $content, $presentation);
            app(ManagedWebsiteService::class)->recordEvent($site, null, $actor, 'connected.published', ['previous_version_id' => $before, 'version_id' => $version->id, 'draft_version_id' => $expected]);

            return $version;
        });
    }

    public function previewUrl(TenantSite $site, User $actor): string
    {
        $this->assertEditor($site, $actor);
        $token = Crypt::encryptString(json_encode(['site' => $site->id, 'tenant' => $site->tenant_id, 'version' => $site->draft_site_version_id, 'actor' => $actor->id, 'expires' => now()->addMinutes(15)->timestamp], JSON_THROW_ON_ERROR));

        return self::ORIGIN.'/?__preview='.rawurlencode($token);
    }

    /** Stages the renderer-only catalog presentation without exposing it publicly. */
    public function stageCatalogPreview(TenantSite $site, User $actor): TenantSiteVersion
    {
        $this->assertEditor($site, $actor);

        return DB::transaction(function () use ($site, $actor): TenantSiteVersion {
            $site = TenantSite::query()->lockForUpdate()->findOrFail($site->id);
            $content = $this->content($site, (int) $site->draft_site_version_id);
            $version = $this->version($site, $content, $actor, 'draft', 'catalog_v2');
            $site->update(['draft_site_version_id' => $version->id, 'updated_by_user_id' => $actor->id]);
            app(ManagedWebsiteService::class)->recordEvent($site, null, $actor, 'connected.catalog_preview_staged', ['version_id' => $version->id]);

            return $version;
        });
    }

    public function previewContent(TenantSite $site, string $token): array
    {
        try {
            $claims = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(403, 'This preview link is invalid.');
        }
        abort_unless(($claims['site'] ?? null) === $site->id && ($claims['tenant'] ?? null) === $site->tenant_id && ($claims['expires'] ?? 0) > now()->timestamp, 403, 'This preview link expired. Open a new preview from Website.');
        $this->assertEditor($site, User::query()->find($claims['actor'] ?? 0));

        return $this->content($site, (int) ($claims['version'] ?? 0));
    }

    /** Import the currently live baseline once; preserves all previous native snapshots. */
    public function connect(TenantSite $site, User $actor): void
    {
        abort_unless($site->tenant->slug === self::SLUG, 404);
        if ($this->connected($site)) {
            return;
        }
        DB::transaction(function () use ($site, $actor) {
            $site = TenantSite::query()->lockForUpdate()->findOrFail($site->id);
            if ($this->connected($site)) {
                return;
            }
            $before = ['settings' => $site->settings, 'draft' => $site->draft_site_version_id, 'published' => $site->published_site_version_id, 'public_enabled' => $site->public_enabled];
            $site->update(['settings' => array_merge($site->settings ?? [], ['connected_renderer' => self::RENDERER])]);
            $this->assertEditor($site, $actor, true);
            $version = $this->version($site, $this->validateContent($this->manifest()['defaults']), $actor, 'draft');
            $site->update(['draft_site_version_id' => $version->id]);
            $this->publish($site->fresh(), $actor, $version->id);
            app(ManagedWebsiteService::class)->recordEvent($site, null, $actor, 'connected.imported', ['before' => $before, 'version_id' => $version->id]);
        });
    }

    private function publishCatalog(TenantSite $site, array $content, string $presentation): void
    {
        if ($presentation === 'catalog_v2') {
            WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->where('status', 'draft')
                ->get()->filter(fn (WebsiteProduct $product) => data_get($product->service_details, 'catalog.review_status') === 'approved')
                ->each(function (WebsiteProduct $product): void {
                    $product->update(['status' => 'active']);
                });

            return;
        }
        if (WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->exists()) {
            return;
        }
        foreach ($this->manifest()['products'] as $definition) {
            $prefix = $definition['prefix'];
            $product = WebsiteProduct::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->where('handle', $definition['slug'])->first();
            app(WebsiteCommerceService::class)->saveProduct($site, array_filter(['id' => $product?->id], fn ($value) => $value !== null) + [
                'handle' => $definition['slug'], 'title' => $content[$prefix.'name'], 'description' => $content[$prefix.'summary'],
                'product_type' => 'quote', 'status' => 'active', 'price' => $content[$prefix.'retail'], 'track_inventory' => false, 'is_available' => true,
                'media' => [str_starts_with($content[$prefix.'image'], '/') ? self::ORIGIN.$content[$prefix.'image'] : $content[$prefix.'image']],
            ]);
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\TenantSiteMedia;
use App\Models\User;
use App\Services\ManagedWebsite\ConnectedWebsiteService;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Imports a reviewed, local-only inventory manifest. The manifest is intentionally
 * not committed: source conversations, device paths, and image bytes do not belong
 * in application source or website audit events.
 */
class StageCarolinaBarrelCatalog extends Command
{
    private const TENANT_SLUG = 'carolina-barrel-co';

    private const CONFIRMATION = 'STAGE-CAROLINA-BARREL-CATALOG';

    protected $signature = 'website:stage-carolina-barrel-catalog
        {--manifest= : Absolute path to a reviewed catalog JSON manifest}
        {--source-dir= : Absolute directory containing the local image files named in the manifest}
        {--actor= : Existing authorized Carolina Barrel workspace user ID}
        {--apply : Import media and stage records; without this, validates only}
        {--confirm= : Required with --apply; enter STAGE-CAROLINA-BARREL-CATALOG}';

    protected $description = 'Import Carolina Barrel catalog evidence as a private, quote-only catalog_v2 draft.';

    public function handle(): int
    {
        $manifestPath = (string) $this->option('manifest');
        $sourceDir = (string) $this->option('source-dir');
        if (! str_starts_with($manifestPath, '/') || ! is_file($manifestPath) || ! str_starts_with($sourceDir, '/') || ! is_dir($sourceDir)) {
            $this->error('Provide absolute existing --manifest and --source-dir paths. No records were changed.');

            return self::FAILURE;
        }
        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->error('The catalog manifest must be valid JSON. No records were changed.');

            return self::FAILURE;
        }
        if (! is_array($manifest) || ! is_array($manifest['products'] ?? null) || $manifest['products'] === []) {
            $this->error('The manifest needs a non-empty products array. No records were changed.');

            return self::FAILURE;
        }
        $sourceDir = realpath($sourceDir) ?: '';
        $files = $this->validatedFiles($manifest['products'], $sourceDir);
        if ($files === null) {
            return self::FAILURE;
        }
        $tenant = Tenant::query()->where('slug', self::TENANT_SLUG)->first();
        $site = $tenant ? TenantSite::query()->forTenant($tenant)->first() : null;
        $actor = User::query()->find((int) $this->option('actor'));
        if (! $tenant || ! $site || ! $actor) {
            $this->error('Carolina Barrel, its site, or the specified actor was not found. No records were changed.');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->table(['products', 'files', 'jev decisions', 'visibility'], [[count($manifest['products']), count($files), count((array) ($manifest['jev_decisions'] ?? [])), 'private preview only']]);
            $this->comment('Validated only. Re-run with --apply --confirm='.self::CONFIRMATION.'.');

            return self::SUCCESS;
        }
        if ((string) $this->option('confirm') !== self::CONFIRMATION) {
            $this->error('Refusing to import without the exact --confirm value.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($manifest, $files, $tenant, $site, $actor): void {
            $mediaUrls = $this->importMedia($files, $tenant, $site, $actor);
            foreach ($manifest['products'] as $product) {
                $media = collect((array) ($product['media'] ?? []))->map(fn (array $item) => $mediaUrls[$item['file']] ?? null)->filter()->values()->all();
                app(WebsiteCommerceService::class)->saveProduct($site, [
                    'handle' => $product['handle'], 'title' => $product['title'], 'description' => $product['description'] ?? '',
                    'product_type' => 'quote', 'status' => 'draft', 'price' => '0', 'is_available' => true, 'track_inventory' => false,
                    'media' => $media,
                    'service_details' => ['catalog' => [
                        'collection' => $product['collection'] ?? 'Carolina Barrel Co.',
                        'details' => $product['details'] ?? [], 'media_alt' => array_column((array) ($product['media'] ?? []), 'alt'),
                        'source_evidence' => $product['source_evidence'] ?? [], 'review_status' => $product['review_status'] ?? 'ready_for_review',
                    ]],
                    'seo_title' => $product['seo_title'] ?? $product['title'], 'seo_description' => $product['seo_description'] ?? ($product['description'] ?? ''),
                ]);
            }
            $websites = app(ManagedWebsiteService::class);
            $websites->recordEvent($site, null, $actor, 'connected.catalog_media_imported', [
                'product_count' => count($manifest['products']), 'media_count' => count($files), 'source' => 'client_imessage', 'originals_preserved' => true,
            ]);
            foreach ((array) ($manifest['jev_decisions'] ?? []) as $decision) {
                // This is a minimized decision audit: never add message bodies, filenames, contact data, or image bytes.
                $websites->recordEvent($site, null, $actor, 'connected.jev_decision', [
                    'model' => (string) ($decision['model'] ?? 'typesafe-ai/jev'),
                    'criteria_version' => (string) ($decision['criteria_version'] ?? 'catalog-family-v1'),
                    'input_tokens' => (int) ($decision['input_tokens'] ?? 0), 'output_tokens' => (int) ($decision['output_tokens'] ?? 0),
                    'gateway_cost' => (string) ($decision['gateway_cost'] ?? '0'), 'market_cost' => (string) ($decision['market_cost'] ?? '0'),
                    'probabilities' => (array) ($decision['probabilities'] ?? []), 'result' => (string) ($decision['result'] ?? ''),
                    'human_disposition' => (string) ($decision['human_disposition'] ?? 'pending_review'),
                ]);
            }
            app(ConnectedWebsiteService::class)->stageCatalogPreview($site, $actor);
        });
        $this->info('Carolina Barrel catalog_v2 was staged as a private preview. No product was published and no inquiry, customer, payment, or notification was created.');

        return self::SUCCESS;
    }

    /** @param array<int,mixed> $products @return array<string,array{path:string,mime:string,size:int,alt:string,source:string}>|null */
    private function validatedFiles(array $products, string $sourceDir): ?array
    {
        $files = [];
        foreach ($products as $product) {
            if (! is_array($product) || ! filled($product['handle'] ?? null) || ! filled($product['title'] ?? null)) {
                $this->error('Every product needs a handle and title.');

                return null;
            }
            foreach ((array) ($product['media'] ?? []) as $item) {
                if (! is_array($item) || ! is_string($item['file'] ?? null) || basename($item['file']) !== $item['file']) {
                    $this->error('Every media item must use a bare filename inside --source-dir.');

                    return null;
                }
                $path = realpath($sourceDir.'/'.$item['file']);
                if (! $path || ! str_starts_with($path, $sourceDir.'/') || ! is_file($path)) {
                    $this->error('A referenced media file is missing or outside --source-dir.');

                    return null;
                }
                $mime = mime_content_type($path) ?: '';
                if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true) || filesize($path) > (int) config('managed_website.media_max_bytes', 10485760)) {
                    $this->error('Media must be a supported image within the configured size limit.');

                    return null;
                }
                $files[$item['file']] = ['path' => $path, 'mime' => $mime, 'size' => filesize($path), 'alt' => strip_tags((string) ($item['alt'] ?? 'Carolina Barrel product image')), 'source' => (string) ($item['source'] ?? 'imessage_original')];
            }
        }

        return $files;
    }

    /** @param array<string,array{path:string,mime:string,size:int,alt:string,source:string}> $files @return array<string,string> */
    private function importMedia(array $files, Tenant $tenant, TenantSite $site, User $actor): array
    {
        $urls = [];
        foreach ($files as $name => $file) {
            $checksum = hash_file('sha256', $file['path']);
            $record = TenantSiteMedia::query()->where(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'checksum' => $checksum])->first();
            if (! $record) {
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'jpg';
                $path = 'tenant-site-media/'.$tenant->id.'/'.Str::uuid().'.'.$extension;
                Storage::disk('local')->put($path, file_get_contents($file['path']));
                $record = TenantSiteMedia::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id,
                    'uploaded_by_user_id' => $actor->id, 'storage_disk' => 'local', 'storage_path' => $path, 'file_name' => $name,
                    'mime_type' => $file['mime'], 'file_size' => $file['size'], 'checksum' => $checksum, 'kind' => 'image',
                    'source' => $file['source'], 'alt_text' => $file['alt'], 'is_starter' => false]);
            }
            $urls[$name] = route('managed-website.media.show', ['media' => $record]);
        }

        return $urls;
    }
}

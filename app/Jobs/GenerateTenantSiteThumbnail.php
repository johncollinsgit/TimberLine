<?php

namespace App\Jobs;

use App\Models\TenantSitePage;
use App\Models\TenantSiteVersion;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Throwable;

class GenerateTenantSiteThumbnail implements ShouldQueue
{
    use Queueable;

    public bool $afterCommit = true;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $siteVersionId) {}

    public function handle(ManagedWebsiteService $websites): void
    {
        if (! config('managed_website.screenshot_enabled', false)) {
            return;
        }

        $version = TenantSiteVersion::query()->find($this->siteVersionId);
        if (! $version) {
            return;
        }
        $site = $version->site;
        $page = TenantSitePage::query()->where('tenant_site_id', $version->tenant_site_id)->where('slug', '/')->first();
        if (! $site || ! $page || (int) $site->draft_site_version_id !== (int) $version->id || ! $page->draft_version_id) {
            return;
        }

        $path = 'tenant-site-thumbnails/'.$version->tenant_id.'/'.$version->id.'.png';
        $target = Storage::disk('local')->path($path);
        $source = URL::temporarySignedRoute('managed-website.thumbnail.source', now()->addMinutes(10), [
            'siteVersion' => $version->id,
            'pageVersion' => $page->draft_version_id,
        ]);

        try {
            Storage::disk('local')->makeDirectory(dirname($path));
            $result = Process::timeout((int) config('managed_website.screenshot_timeout_seconds', 60))->run([
                (string) config('managed_website.screenshot_node_binary', 'node'),
                base_path('scripts/managed-website/capture-thumbnail.mjs'),
                $source,
                $target,
            ]);
            if ($result->failed() || ! Storage::disk('local')->exists($path)) {
                throw new \RuntimeException('Thumbnail capture did not produce an image.');
            }

            $version->forceFill(['thumbnail_path' => $path])->save();
            $websites->recordEvent($site, null, null, 'site.thumbnail_captured', ['site_version_id' => $version->id]);
        } catch (Throwable $exception) {
            report($exception);
            $websites->recordEvent($site, null, null, 'site.thumbnail_failed', ['site_version_id' => $version->id, 'reason' => class_basename($exception)]);
        }
    }
}

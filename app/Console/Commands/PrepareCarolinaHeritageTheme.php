<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\User;
use App\Services\ManagedWebsite\ConnectedWebsiteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareCarolinaHeritageTheme extends Command
{
    protected $signature = 'website:prepare-carolina-heritage {--actor= : Existing authorized user ID} {--apply : Save the new theme} {--publish : Publish after saving}';

    protected $description = 'Preserve Carolina’s existing design and prepare its Wine & Oak theme; dry run by default.';

    public function handle(ConnectedWebsiteService $service): int
    {
        $tenant = Tenant::query()->where('slug', ConnectedWebsiteService::SLUG)->firstOrFail();
        $site = TenantSite::query()->forTenant($tenant)->firstOrFail();
        $actor = User::query()->findOrFail((int) $this->option('actor'));
        $service->assertEditor($site, $actor, (bool) $this->option('publish'));
        if ($service->theme($site, $site->draft_site_version_id) === 'heritage') {
            $this->info('Heritage already prepared; no changes.');

            return self::SUCCESS;
        }
        DB::beginTransaction();
        try {
            // Preserve any unpublished copy as well as the last live design.
            $published = $site->siteVersions()->findOrFail($site->published_site_version_id);
            if (! $service->savedThemes($site)->contains(fn ($saved) => $service->content($site, $saved->id) === $service->content($site, $published->id) && $service->theme($site, $saved->id) === $service->theme($site, $published->id))) {
                $backup = $published->replicate(['published_at']);
                $backup->fill(['status' => 'saved_theme', 'version_number' => ((int) $site->siteVersions()->max('version_number')) + 1, 'settings' => $published->settings + ['theme_name' => 'Carolina Original · Previous live design'], 'created_by_user_id' => $actor->id]);
                $backup->save();
                app(\App\Services\ManagedWebsite\ManagedWebsiteService::class)->recordEvent($site, null, $actor, 'connected.theme_saved', ['version_id' => $backup->id, 'source_version_id' => $published->id]);
            }
            $draft = $service->applyTheme($site, 'heritage', $actor, $site->draft_site_version_id);
            if ($this->option('publish')) {
                $service->publish($site->fresh(), $actor, $draft->id);
            }
            if ($this->option('apply')) {
                DB::commit();
                $this->info('Heritage saved. Previous designs are available in the theme selector.'.($this->option('publish') ? ' Published.' : ' Draft only.'));
            } else {
                DB::rollBack();
                $this->info('Validated preservation, theme preparation, and access. Dry run rolled back.');
            }
        } catch (\Throwable $error) {
            DB::rollBack();
            throw $error;
        }

        return self::SUCCESS;
    }
}

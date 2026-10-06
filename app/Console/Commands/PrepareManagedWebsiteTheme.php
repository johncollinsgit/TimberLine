<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use Illuminate\Console\Command;

class PrepareManagedWebsiteTheme extends Command
{
    protected $signature = 'everbranch:prepare-managed-website-theme
        {tenant : Tenant slug}
        {theme=collins-electric : Starter theme key}
        {--actor-email= : Existing tenant admin email to record in the audit trail}';

    protected $description = 'Create or replace a tenant Website draft from a starter theme. Never publishes or changes commerce data.';

    public function handle(ManagedWebsiteService $websites): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('tenant'))->first();
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        $theme = (string) $this->argument('theme');
        if (! $websites->themes() || ! collect($websites->themes())->contains('key', $theme)) {
            $this->error('Theme not found.');

            return self::FAILURE;
        }

        $actorEmail = strtolower(trim((string) $this->option('actor-email')));
        $actor = $actorEmail !== '' ? User::query()->where('email', $actorEmail)->first() : $tenant->users()->wherePivotIn('role', ['owner', 'admin'])->orderBy('users.id')->first();
        if (! $actor instanceof User) {
            $this->error('An existing tenant owner or admin is required. Pass --actor-email if necessary.');

            return self::FAILURE;
        }

        $site = $websites->createSite($tenant, $actor);
        $site = $websites->applyTheme($site, $theme, $actor);

        $this->info("Draft prepared for {$tenant->slug}.");
        $this->line('site_id='.$site->id);
        $this->line('theme='.$theme);
        $this->line('status='.$site->status);
        $this->line('published=no');

        return self::SUCCESS;
    }
}

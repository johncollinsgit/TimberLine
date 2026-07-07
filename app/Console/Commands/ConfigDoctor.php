<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Asserts that the configuration keys required for an environment are actually
 * present, and fails loudly when they are not. Catches the class of bug where
 * .env.example (or a fresh tenant/dev/prod setup) is missing a required block —
 * e.g. the primary Shopify retail store credentials.
 */
class ConfigDoctor extends Command
{
    protected $signature = 'config:doctor {--env= : Environment to validate against (defaults to the running environment)}';

    protected $description = 'Assert required configuration keys are present for the environment; fails loudly on gaps.';

    public function handle(): int
    {
        $env = strtolower((string) ($this->option('env') ?: app()->environment()));
        $isProd = $env === 'production';

        /** @var array<string, array<int, array{0:string,1:string,2:bool}>> $groups */
        $groups = [
            'Application' => [
                ['app.key', 'APP_KEY', true],
                ['app.url', 'APP_URL', $isProd],
            ],
            'Shopify retail store (primary storefront)' => [
                ['services.shopify.stores.retail.shop', 'SHOPIFY_RETAIL_SHOP', $isProd],
                ['services.shopify.stores.retail.access_token', 'SHOPIFY_RETAIL_ACCESS_TOKEN', $isProd],
                ['services.shopify.stores.retail.client_id', 'SHOPIFY_RETAIL_CLIENT_ID', $isProd],
                ['services.shopify.stores.retail.client_secret', 'SHOPIFY_RETAIL_CLIENT_SECRET', $isProd],
            ],
            'Square (legacy reconciliation)' => [
                ['services.square.access_token', 'SQUARE_ACCESS_TOKEN', false],
                ['services.square.location_id', 'SQUARE_LOCATION_ID', false],
            ],
        ];

        $failures = 0;
        $this->line("Config doctor — environment: <info>{$env}</info>");

        foreach ($groups as $group => $items) {
            $this->line('');
            $this->line("<comment>{$group}</comment>");
            foreach ($items as [$path, $envKey, $required]) {
                if (filled(config($path))) {
                    $this->line("  <info>✓</info> {$envKey}");
                } elseif ($required) {
                    $this->line("  <fg=red>✗ {$envKey} — MISSING (required in {$env})</>");
                    $failures++;
                } else {
                    $this->line("  <fg=yellow>–</> {$envKey} (not set, optional)");
                }
            }
        }

        if ($isProd && strtolower((string) config('mail.default')) === 'log') {
            $this->line('');
            $this->line("  <fg=red>✗ MAIL_MAILER is 'log' in production — no real email will send.</>");
            $failures++;
        }

        $this->line('');

        if ($failures > 0) {
            $this->error("{$failures} required configuration issue(s) found.");

            return self::FAILURE;
        }

        $this->info('All required configuration present.');

        return self::SUCCESS;
    }
}

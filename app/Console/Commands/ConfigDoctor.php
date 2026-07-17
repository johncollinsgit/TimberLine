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
                // Access token is preferred from the encrypted DB store (shopify_stores),
                // so an empty env token is expected — not required.
                ['services.shopify.stores.retail.access_token', 'SHOPIFY_RETAIL_ACCESS_TOKEN', false],
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

        $failures += $this->validateAgreementStripeCheckout($isProd);

        $this->line('');

        if ($failures > 0) {
            $this->error("{$failures} required configuration issue(s) found.");

            return self::FAILURE;
        }

        $this->info('All required configuration present.');

        return self::SUCCESS;
    }

    protected function validateAgreementStripeCheckout(bool $isProd): int
    {
        $this->line('');
        $this->line('<comment>Stripe direct agreement checkout</comment>');

        $agreementCheckoutEnabled = (bool) config('commercial.billing_readiness.agreement_checkout.enabled', false);
        $directInvoicingEnabled = (bool) config('commercial.billing_readiness.direct_invoicing.enabled', false);
        if (! $agreementCheckoutEnabled && ! $directInvoicingEnabled) {
            $this->line('  <fg=yellow>–</> agreement checkout and direct invoicing are disabled (Stripe validation skipped)');

            return 0;
        }

        $this->line('  Agreement checkout: '.($agreementCheckoutEnabled ? '<info>enabled</info>' : '<fg=yellow>disabled</>'));
        $this->line('  Direct invoicing: '.($directInvoicingEnabled ? '<info>enabled</info>' : '<fg=yellow>disabled</>'));

        $allowProductionTestMode = (bool) config('commercial.billing_readiness.allow_production_test_mode', false);
        $enabledTenantSlugs = $this->enabledStripeTenantSlugs($agreementCheckoutEnabled, $directInvoicingEnabled);
        $accountId = trim((string) config('services.stripe.account_id'));
        $publishableKey = trim((string) config('services.stripe.publishable_key'));
        $secretKey = trim((string) config('services.stripe.secret'));
        $webhookSecret = trim((string) config('services.stripe.webhook_secret'));
        $publishableMode = $this->stripeKeyMode($publishableKey, 'pk');
        $secretMode = $this->stripeKeyMode($secretKey, 'sk');

        $failures = 0;
        $checks = [
            ['STRIPE_ACCOUNT_ID', preg_match('/^acct_[A-Za-z0-9]+$/', $accountId) === 1, 'must be the acct_ identifier from Stripe'],
            ['STRIPE_KEY', $publishableMode !== null, 'must be a complete pk_test_ or pk_live_ key'],
            ['STRIPE_SECRET', $secretMode !== null, 'must be a complete sk_test_ or sk_live_ key'],
            ['STRIPE_WEBHOOK_SECRET', preg_match('/^whsec_[A-Za-z0-9]+$/', $webhookSecret) === 1, 'must be the whsec_ secret for this endpoint'],
        ];

        foreach ($checks as [$name, $valid, $message]) {
            if ($valid) {
                $this->line("  <info>✓</info> {$name}");
            } else {
                $this->line("  <fg=red>✗ {$name} — INVALID ({$message}).</>");
                $failures++;
            }
        }

        if ($publishableMode !== null && $secretMode !== null && $publishableMode !== $secretMode) {
            $this->line('  <fg=red>✗ STRIPE_KEY and STRIPE_SECRET use different test/live modes.</>');
            $failures++;
        }

        if ($isProd && ($publishableMode === 'test' || $secretMode === 'test')) {
            if (! $allowProductionTestMode) {
                $this->line('  <fg=red>✗ Test-mode Stripe keys cannot enable production-host billing unless EVERBRANCH_STRIPE_TEST_MODE_ON_PRODUCTION_ALLOWED=true.</>');
                $failures++;
            } else {
                $this->line('  <info>✓</info> EVERBRANCH_STRIPE_TEST_MODE_ON_PRODUCTION_ALLOWED');
                if ($enabledTenantSlugs === [] || in_array('*', $enabledTenantSlugs, true)) {
                    $this->line('  <fg=red>✗ Stripe production-host test mode requires a concrete tenant allowlist, not blank or *.</>');
                    $failures++;
                } else {
                    $this->line('  <info>✓</info> Stripe production-host test mode tenant allowlist');
                }
            }
        }

        if (! $isProd && ($publishableMode === 'live' || $secretMode === 'live')) {
            $this->line('  <fg=red>✗ Live Stripe keys are reserved for the production secret store.</>');
            $failures++;
        }

        if ($publishableMode === 'live' && $secretMode === 'live') {
            $readinessPath = $directInvoicingEnabled ? 'direct_invoicing' : 'agreement_checkout';
            foreach ([
                'EVERBRANCH_AGREEMENT_TAX_DECISION_CONFIRMED' => (bool) config('commercial.billing_readiness.'.$readinessPath.'.tax_decision_confirmed', false),
                'EVERBRANCH_STRIPE_RELAY_PAYOUT_VERIFIED' => (bool) config('commercial.billing_readiness.'.$readinessPath.'.relay_payout_verified', false),
            ] as $name => $valid) {
                if ($valid) {
                    $this->line("  <info>✓</info> {$name}");
                } else {
                    $this->line("  <fg=red>✗ {$name}=false — required before live agreement checkout.</>");
                    $failures++;
                }
            }
        }

        return $failures;
    }

    /** @return array<int,string> */
    protected function enabledStripeTenantSlugs(bool $agreementCheckoutEnabled, bool $directInvoicingEnabled): array
    {
        $slugs = [];
        if ($agreementCheckoutEnabled) {
            $slugs = array_merge($slugs, (array) config('commercial.billing_readiness.agreement_checkout.tenant_slugs', []));
        }
        if ($directInvoicingEnabled) {
            $slugs = array_merge($slugs, (array) config('commercial.billing_readiness.direct_invoicing.tenant_slugs', []));
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $slug): string => strtolower(trim((string) $slug)),
            $slugs
        ))));
    }

    protected function stripeKeyMode(string $value, string $prefix): ?string
    {
        if (preg_match('/^'.preg_quote($prefix, '/').'_(test|live)_[A-Za-z0-9]+$/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Agreements\AgreementManagementService;
use Illuminate\Console\Command;

class EverbranchPrepareManagedWebsiteAgreement extends Command
{
    protected $signature = 'everbranch:prepare-managed-website-agreement
        {tenant : Tenant slug}
        {--actor-email= : Existing operator email to attribute the draft to}
        {--json : Emit a machine-readable result}';

    protected $description = 'Idempotently prepare a managed website agreement draft and report production-safe billing readiness.';

    public function handle(AgreementManagementService $management): int
    {
        $slug = strtolower(trim((string) $this->argument('tenant')));
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $this->error('The tenant must be a valid slug.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('slug', $slug)->first();
        if (! $tenant instanceof Tenant) {
            $this->error("Tenant [{$slug}] was not found.");

            return self::FAILURE;
        }

        $actorEmail = strtolower(trim((string) $this->option('actor-email')));
        $actor = $actorEmail !== '' ? User::query()->whereRaw('LOWER(email) = ?', [$actorEmail])->first() : null;
        if ($actorEmail !== '' && ! $actor instanceof User) {
            $this->error("Actor [{$actorEmail}] was not found.");

            return self::FAILURE;
        }

        $agreement = $management->prepareManagedWebsite($tenant, $actor?->id);
        $agreement->loadMissing('currentVersion');
        $billing = $this->billingReadiness($slug);
        $result = [
            'tenant_id' => (int) $tenant->id,
            'tenant_slug' => $slug,
            'agreement_id' => (int) $agreement->id,
            'agreement_status' => (string) $agreement->status,
            'agreement_version' => (int) $agreement->currentVersion?->version_number,
            'title' => (string) $agreement->title,
            'pricing' => [
                'setup_cents' => 29900,
                'founder_monthly_cents' => 8900,
                'founder_cycles' => 6,
                'standard_monthly_cents' => 14900,
                'standard_starts_cycle' => 7,
            ],
            'billing_readiness' => $billing,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("Prepared agreement #{$agreement->id} for {$tenant->name}.");
            $this->line("Version: {$result['agreement_version']} ({$agreement->status})");
            $this->line('Billing readiness: '.($billing['ready'] ? 'READY' : 'BLOCKED'));
            foreach ($billing['blockers'] as $blocker) {
                $this->warn(' - '.$blocker);
            }
        }

        return self::SUCCESS;
    }

    /** @return array{ready:bool,mode:string,enabled:bool,tenant_allowlisted:bool,blockers:array<int,string>} */
    protected function billingReadiness(string $slug): array
    {
        $path = 'commercial.billing_readiness.agreement_checkout';
        $enabled = (bool) config($path.'.enabled', false);
        $allowed = array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), (array) config($path.'.tenant_slugs', []));
        $tenantAllowlisted = in_array($slug, $allowed, true) && ! in_array('*', $allowed, true);
        $secret = trim((string) config('services.stripe.secret', ''));
        $mode = str_starts_with($secret, 'sk_live_') ? 'live' : (str_starts_with($secret, 'sk_test_') ? 'test' : 'missing');
        $blockers = [];

        if (! $enabled) {
            $blockers[] = 'agreement checkout is disabled';
        }
        if (! $tenantAllowlisted) {
            $blockers[] = 'tenant is not explicitly allowlisted';
        }
        if ($mode !== 'live') {
            $blockers[] = app()->environment('production') ? 'production does not have live Stripe credentials' : 'live Stripe credentials are not active';
        }
        if (! filled(config('services.stripe.webhook_secret'))) {
            $blockers[] = 'Stripe webhook secret is missing';
        }
        if (! (bool) config($path.'.live_webhook_verified', false)) {
            $blockers[] = 'live Stripe webhook is not verified';
        }
        if (! (bool) config($path.'.tax_decision_confirmed', false)) {
            $blockers[] = 'tax decision is not confirmed';
        }
        if (! (bool) config($path.'.relay_payout_verified', false)) {
            $blockers[] = 'Relay payout destination is not verified';
        }

        return [
            'ready' => $blockers === [],
            'mode' => $mode,
            'enabled' => $enabled,
            'tenant_allowlisted' => $tenantAllowlisted,
            'blockers' => $blockers,
        ];
    }
}

<?php

namespace App\Console\Commands;

use App\Services\AiBranch\AiBranchOffer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class AiPrepareAccessPrice extends Command
{
    protected $signature = 'ai-branch:prepare-access-price {--apply} {--authorization= : Owner approval reference}';

    protected $description = 'Preview or create the exact $20/month future-tenant AI access price; never creates a customer, checkout or subscription';

    public function handle(): int
    {
        abort_if($this->option('apply') && blank($this->option('authorization')), 422, 'An owner approval reference is required.');
        $secret = (string) config('services.stripe.secret');
        $live = str_starts_with($secret, 'sk_live_');
        abort_unless(($live || str_starts_with($secret, 'sk_test_')) && (! app()->isProduction() || $live)
            && rtrim((string) config('services.stripe.api_base'), '/') === 'https://api.stripe.com', 503, 'Verified Stripe configuration is required.');
        $lookup = config('module_catalog.addons.'.AiBranchOffer::ADDON.'.stripe.recurring_price_lookup_key');
        $http = Http::asForm()->acceptJson()->withBasicAuth($secret, '')->connectTimeout(5)->timeout(20);
        $list = $http->get('https://api.stripe.com/v1/prices', ['lookup_keys[0]' => $lookup, 'limit' => 2])->throw()->json();
        abort_unless(($list['has_more'] ?? true) === false && is_array($list['data'] ?? null) && count($list['data']) <= 1, 409, 'Reconcile existing AI access prices before proceeding.');
        $price = $list['data'][0] ?? null;
        if (! $price && ! $this->option('apply')) {
            $this->info('Preview: prepare USD 20/month AI access, with separately metered usage. No writes or customer charges.');

            return self::SUCCESS;
        }
        if (! $price) {
            abort_unless(filled($this->option('authorization')), 422, 'An owner approval reference is required.');
            $price = $http->withHeaders(['Idempotency-Key' => 'ai-access-20-v1-'.substr(hash('sha256', $secret), 0, 16)])
                ->post('https://api.stripe.com/v1/prices', ['currency' => 'usd', 'unit_amount' => 2000,
                    'recurring[interval]' => 'month', 'recurring[interval_count]' => 1, 'lookup_key' => $lookup,
                    'product_data[name]' => 'Everbranch AI Voice Branch access', 'product_data[metadata][purchase_key]' => 'addon.'.AiBranchOffer::ADDON,
                    'metadata[purchase_key]' => 'addon.'.AiBranchOffer::ADDON])->throw()->json();
        }
        abort_unless(($price['active'] ?? false) === true && ($price['livemode'] ?? null) === $live && ($price['currency'] ?? '') === 'usd'
            && ($price['unit_amount'] ?? null) === 2000 && ($price['lookup_key'] ?? '') === $lookup && ($price['billing_scheme'] ?? '') === 'per_unit'
            && data_get($price, 'recurring.interval') === 'month' && data_get($price, 'recurring.interval_count') === 1
            && data_get($price, 'metadata.purchase_key') === 'addon.'.AiBranchOffer::ADDON, 409, 'Existing Stripe price does not match the approved exact $20 offer.');
        if ($this->option('apply')) {
            app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record(null, null, 'ai_branch.access_price.prepare',
                confirmation: ['explicit' => true, 'reference' => $this->option('authorization')], result: ['price_id' => $price['id'], 'monthly_cents' => 2000]);
        }
        $this->info('Verified '.$price['id'].' at USD 20/month. No customer or subscription created.');

        return self::SUCCESS;
    }
}

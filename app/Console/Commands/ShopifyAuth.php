<?php

namespace App\Console\Commands;

use App\Services\Shopify\ShopifyOAuth;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ShopifyAuth extends Command
{
    protected $signature = 'shopify:auth {store}';

    protected $description = 'Print the Shopify OAuth authorization URL for a store.';

    public function handle(ShopifyOAuth $oauth): int
    {
        $config = ShopifyStores::find($this->argument('store'), true);
        if (!$config || empty($config['client_id'])) {
            $this->error('Unknown store or missing client id.');
            return self::FAILURE;
        }

        $state = Str::random(32);
        Cache::store('file')->put("shopify_oauth_state_{$config['key']}", $state, now()->addMinutes(15));
        $redirectUri = route('shopify.callback', ['store' => $config['key']]);
        $url = $oauth->buildAuthUrl($config, $redirectUri, $state);

        $this->line($url);
        return self::SUCCESS;
    }
}

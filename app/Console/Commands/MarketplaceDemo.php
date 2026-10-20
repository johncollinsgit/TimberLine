<?php

namespace App\Console\Commands;

use App\Models\Marketplace\Identity;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\LoginLink;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\Membership;
use App\Models\Marketplace\PickupLocation;
use App\Models\Marketplace\RateAcceptance;
use App\Services\Marketplace\MarketplaceAccess;
use App\Services\Marketplace\MarketplaceRates;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MarketplaceDemo extends Command
{
    protected $signature = 'marketplace:demo';

    protected $description = 'Prepare fictional marketplace products in the dedicated local SQLite preview only';

    public function handle(): int
    {
        if (! app()->environment('local') || ! config('marketplace.demo') || config('database.default') !== 'sqlite' || basename((string) config('database.connections.sqlite.database')) !== 'markets-makers-preview.sqlite') {
            $this->error('Demo data is restricted to the dedicated local marketplace preview database.');

            return self::FAILURE;
        }
        app(MarketplaceAccess::class)->settings()->forceFill(['checkout_enabled' => false])->save();
        $rate = app(MarketplaceRates::class)->global();
        $pickup = PickupLocation::firstOrCreate(['name' => 'Saturday Market · Preview'], ['address' => 'Example pickup location, Easley, SC', 'instructions' => 'Fictional preview: Saturday, 9 am–noon. Real pickup details are configured before launch.', 'shopify_location_gid' => 'gid://shopify/Location/1', 'active' => true]);
        $makers = [];
        foreach ([['Fieldwork Clay', 'Easley', 'Useful shapes, quiet colors, and a little evidence of the hands that made them. Small-batch pottery for the everyday rituals.'], ['Sunday Loom', 'Greer', 'Natural fibers, patient hands, and textiles that feel at home. Woven in a small Upstate studio.'], ['Hollow Oak Workshop', 'Travelers Rest', 'Honest materials, thoughtful details. Wooden goods made slowly, built to become part of your everyday.'], ['Moss & Wick', 'Greenville', 'Small-batch candles inspired by slower mornings, open windows, and the place we call home.']] as $index => [$name, $city, $story]) {
            $identity = Identity::firstOrCreate(['email' => 'maker'.($index + 1).'@marketplace.example.test'], ['active' => true]);
            $maker = Maker::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'city' => $city, 'story' => $story, 'status' => 'approved']);
            Membership::firstOrCreate(['maker_id' => $maker->id, 'identity_id' => $identity->id], ['role' => 'owner', 'active' => true]);
            RateAcceptance::firstOrCreate(['maker_id' => $maker->id, 'rate_id' => $rate->id], ['identity_id' => $identity->id]);
            $makers[] = $maker;
        }
        // External stock photography is illustrative; no fictional products are seeded in production.
        foreach ([
            [0, 'Everyday mug', 'Oat glaze · 12 oz', 'Ceramics', 3200, 'photo-1514228742587-6b1558fcca3d'],
            [1, 'Everyday basket', 'Natural fibers · Woven by hand', 'Home & living', 2800, 'photo-1607629155006-2391d17b940f'],
            [2, 'The gathering board', 'Solid wood · Hand finished', 'Home & living', 6800, 'photo-1645743714191-bf033e0c181a'],
            [3, 'A slower morning', 'Cedar & citrus · 8 oz', 'Candles', 2400, 'photo-1603006905003-be475563bc59'],
            [0, 'Stoneware morning set', 'Hand-thrown stoneware', 'Ceramics', 4800, 'photo-1610701596007-11502861dcfa'],
            [0, 'Objects for everyday', 'Small-batch studio collection', 'Ceramics', 5400, 'photo-1493106641515-6b5631de4bb9'],
        ] as [$index, $title, $variant, $category, $price, $image]) {
            $listing = Listing::firstOrCreate(['slug' => Str::slug($title)], ['maker_id' => $makers[$index]->id, 'title' => $title, 'variant_label' => $variant, 'category' => $category, 'description' => 'A thoughtful addition to your everyday, made with care in a small Upstate studio. This is an illustrative preview product; actual maker details and product photos will be supplied before launch.', 'images' => ['https://images.unsplash.com/'.$image.'?auto=format&fit=crop&w=1200&q=85'], 'price_cents' => $price, 'stock_quantity' => 0, 'shipping' => true, 'pickup_location_ids' => [$pickup->id], 'lead_time' => 'Prepared in 2–3 business days', 'status' => 'approved']);
            if (config('marketplace.checkout_provider') === 'everbranch' && ! \App\Models\Marketplace\InventoryAllocation::where('listing_id', $listing->id)->exists()) {
                app(\App\Services\Marketplace\MarketplaceCatalog::class)->allocate($listing, 8, 'eb:shipping:'.$listing->maker_id, (string) Str::uuid(), null, Identity::where('email', 'maker'.($index + 1).'@marketplace.example.test')->firstOrFail());
                app(\App\Services\Marketplace\MarketplaceCatalog::class)->allocate($listing, 4, 'eb:pickup:'.$pickup->id, (string) Str::uuid(), null, Identity::where('email', 'maker'.($index + 1).'@marketplace.example.test')->firstOrFail());
            }
        }
        $identity = Identity::where('email', 'maker1@marketplace.example.test')->firstOrFail();
        $token = Str::random(64);
        LoginLink::create(['email' => $identity->email, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour()]);
        $this->info('Fictional preview prepared. Checkout remains off.');
        $this->line('Local maker sign-in: http://127.0.0.1:8067/account/verify/'.$token);

        return self::SUCCESS;
    }
}

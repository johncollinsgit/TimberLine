<?php

namespace App\Services\Retail;

use App\Models\LandlordProspect;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantOnboardingBlueprint;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteCart;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteProduct;
use App\Models\WebsiteProductVariant;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RetailShowroomService
{
    public function definitions(): array
    {
        return json_decode(file_get_contents(resource_path('data/retail-showroom.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function merchant(string $slug): array
    {
        return $slug === 'sawyer-naturals' ? $this->definitions()['sawyer'] : (collect($this->definitions()['vendors'])->firstWhere('slug', $slug) ?? abort(404));
    }

    public function owner(?User $user): bool
    {
        $email = (string) config('retail_showroom.owner_email');

        return config('retail_showroom.enabled') && $email !== '' && $user && $user->is_active && $user->hasVerifiedEmail() && hash_equals($email, strtolower($user->email));
    }

    public function site(string $slug): TenantSite
    {
        $this->merchant($slug);
        $tenant = Tenant::where('slug', 'ww-demo-'.$slug)->firstOrFail();
        $site = TenantSite::where('tenant_id', $tenant->id)->firstOrFail();
        abort_unless(data_get($site->settings, 'retail_demo.slug') === $slug && data_get($site->settings, 'retail_demo.is_demo') === true, 404);

        return $site;
    }

    public function staffRole(User $user, string $slug): string
    {
        $role = $this->staffRoleOrNull($user, $slug);
        abort_unless($role !== null, 403);

        return $role;
    }

    public function staffRoleOrNull(?User $user, string $slug): ?string
    {
        if ($this->owner($user)) {
            return 'owner';
        }
        if (! config('retail_showroom.enabled') || ! $user || ! $user->is_active || ! $user->hasVerifiedEmail()) {
            return null;
        }
        $site = $this->site($slug);
        $member = $user->tenants()->where('tenants.id', $site->tenant_id)->wherePivot('membership_active', true)->first();
        if (! $member) {
            return null;
        }

        return in_array($member->pivot->role, ['owner', 'admin', 'manager'], true) ? 'manager' : 'associate';
    }

    public function seed(User $owner): array
    {
        return DB::transaction(function () use ($owner) {
            $result = [];
            $walkingOrder = ['old-beech-mountain-farms' => 1, 'joy-fudge-company' => 2, '4-pelicans-woodworking' => 3, 'leather-link-studio' => 4, 'mountain-momma-organics' => 5, 'highland-croft-candle-company' => 6, 'mcneills-pottery' => 7];
            $researchPath = resource_path('data/retail-additional-vendor-research.json');
            $research = is_file($researchPath) ? collect(json_decode(file_get_contents($researchPath), true, 512, JSON_THROW_ON_ERROR)['vendors'] ?? [])->keyBy('slug') : collect();
            foreach ($this->definitions()['vendors'] as $index => $merchant) {
                $tenant = Tenant::firstOrCreate(['slug' => 'ww-demo-'.$merchant['slug']], ['name' => 'Demo · '.$merchant['name']]);
                $profile = TenantAccessProfile::firstOrCreate(['tenant_id' => $tenant->id], ['plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'woolly-worm-2026', 'metadata' => ['account_mode' => 'demo', 'merchant_active' => false, 'retail_demo' => true]]);
                abort_unless(data_get($profile->metadata, 'retail_demo') === true, 409, 'This workspace is not a showroom sandbox.');
                if ($profile->wasRecentlyCreated) {
                    app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record(
                        tenantId: $tenant->id, actorUserId: $owner->id,
                        actionType: 'retail_showroom_draft_prepared', targetType: 'tenant_access_profile', targetId: $profile->id,
                        result: ['account_mode' => 'demo', 'merchant_active' => false, 'billing_impact' => 'none', 'modules_activated' => []]
                    );
                }
                $site = app(ManagedWebsiteService::class)->createSite($tenant, $owner);
                $settings = $site->settings ?? [];
                $settings['retail_demo'] ??= ['is_demo' => true, 'slug' => $merchant['slug'], 'orders' => [], 'shares' => [], 'merchant_active' => false];
                $site->forceFill(['settings' => $settings, 'public_enabled' => false, 'status' => 'draft'])->save();
                foreach ($merchant['products'] as $product) {
                    if (WebsiteProduct::where('tenant_id', $tenant->id)->where('tenant_site_id', $site->id)->where('handle', $product['id'])->exists()) {
                        continue;
                    }
                    $first = $product['variants'][0];
                    $row = app(WebsiteCommerceService::class)->saveProduct($site, ['handle' => $product['id'], 'title' => $product['name'], 'description' => $product['description'], 'status' => 'active', 'product_type' => $product['quote_only'] ? 'quote' : 'physical', 'track_inventory' => true, 'inventory_quantity' => $first['stock'], 'price' => number_format($first['price_cents'] / 100, 2, '.', ''), 'variant_title' => $first['title'], 'sku' => $merchant['slug'].'-'.$product['id'].'-'.$first['id'], 'is_available' => true, 'service_details' => ['is_demo' => true, 'source' => $product['price_source']]]);
                    foreach (array_slice($product['variants'], 1) as $variant) {
                        $row->variants()->create(['tenant_id' => $tenant->id, 'title' => $variant['title'], 'sku' => $merchant['slug'].'-'.$product['id'].'-'.$variant['id'], 'price_cents' => $variant['price_cents'], 'inventory_quantity' => $variant['stock'], 'is_available' => true]);
                    }
                }
                foreach ([['Avery', 'Morgan'], ['Jordan', 'Lee']] as $index => [$first,$last]) {
                    WebsiteCustomer::firstOrCreate(['tenant_id' => $tenant->id, 'email' => "sample-{$index}@{$merchant['slug']}.invalid"], ['first_name' => $first, 'last_name' => $last, 'status' => 'active', 'notes' => ['is_demo' => true, 'private_notes' => [], 'reward_points' => 150, 'reward_entries' => []]]);
                }
                $prospect = LandlordProspect::whereRaw('lower(business_name) = ?', [strtolower($merchant['name'])])->first();
                $prospect ??= LandlordProspect::create(['business_name' => $merchant['name'], 'source' => 'woolly-worm-2026', ...['status' => 'draft_ready', 'website' => $merchant['website'] ?? $merchant['primary_public_profile'] ?? $merchant['facebook'] ?? null, 'trade' => 'Retail', 'county' => 'Research pending', 'city' => $merchant['city'] ?? null, 'notes' => 'Private unofficial concept; merchant activation not approved.', 'created_by_user_id' => $owner->id, 'source_snapshot' => ['research' => $merchant, 'showroom' => ['tenant_id' => $tenant->id, 'site_id' => $site->id, 'slug' => $merchant['slug']], 'festival' => ['stage' => 'Research', 'shown' => false]], 'last_verified_at' => now()]]);
                $snapshot = $prospect->source_snapshot ?? [];
                $snapshot['research'] ??= $merchant;
                if (isset($research[$merchant['slug']]['site_review'])) {
                    $snapshot['research']['site_review'] ??= $research[$merchant['slug']]['site_review'];
                }
                $snapshot['showroom'] ??= ['tenant_id' => $tenant->id, 'site_id' => $site->id, 'slug' => $merchant['slug']];
                $snapshot['festival'] ??= ['stage' => 'Research', 'shown' => false];
                $snapshot['festival']['walking_order'] ??= $merchant['walking_order'] ?? $walkingOrder[$merchant['slug']] ?? $index + 1;
                $prospect->source_snapshot = $snapshot;
                $prospect->save();
                $draft = TenantOnboardingBlueprint::where('tenant_id', $tenant->id)->where('status', 'draft')->first();
                if (! $draft || (data_get($draft->origin, 'source') === 'woolly-worm-2026' && ! isset($draft->payload['version']))) {
                    app(\App\Services\Onboarding\TenantOnboardingBlueprintStore::class)->stageDraft($tenant->id, [
                        'account_mode' => 'demo', 'rail' => 'direct', 'selected_modules' => [], 'data_source' => 'manual',
                        'desired_outcome_first' => 'Review a merchant-approved retail website and connected customer workflow.',
                        'setup_preferences' => ['client_brand' => ['display_name' => $merchant['name']]],
                        'demo_origin' => ['source' => 'woolly-worm-2026', 'slug' => $merchant['slug'], 'merchant_activation_approved' => false,
                            'review_configuration' => ['branding' => $merchant['colors'], 'catalog' => $merchant['products'], 'shipping' => $merchant['shipping'], 'offer' => app(EvergroveOffer::class)->presentation()],
                            'excluded' => ['customers', 'orders', 'users', 'rewards', 'private_notes'], 'all_catalog_and_shipping_require_merchant_approval' => true],
                    ], $owner->id, ['source' => 'woolly-worm-2026', 'no_demo_data_to_production' => true]);
                }
                $result[] = $merchant['slug'];
            }

            return $result;
        });
    }

    public function catalog(string $slug): array
    {
        $merchant = $this->merchant($slug);
        if ($slug === 'sawyer-naturals') {
            return $merchant;
        } $site = $this->site($slug);
        foreach ($merchant['products'] as &$product) {
            $row = WebsiteProduct::where('tenant_id', $site->tenant_id)->where('tenant_site_id', $site->id)->where('handle', $product['id'])->with('variants')->firstOrFail();
            $product['name'] = $row->title;
            $product['description'] = $row->description;
            foreach ($product['variants'] as &$variant) {
                $record = $row->variants->firstWhere('sku', $slug.'-'.$product['id'].'-'.$variant['id']);
                $variant['price_cents'] = (int) ($record?->price_cents ?? $variant['price_cents']);
                $variant['stock'] = $record && $record->is_available && $row->status === 'active' ? (int) $record->inventory_quantity : 0;
            } unset($variant);
        } unset($product);

        return array_intersect_key($merchant, array_flip(['slug', 'name', 'booth', 'website', 'image', 'headline', 'tagline', 'cta', 'theme', 'colors', 'shipping', 'products', 'category_label', 'intro', 'shopping_journey']));
    }

    public function resolveLines(string $slug, array $lines): array
    {
        $merchant = $this->catalog($slug);
        $result = [];
        $seen = [];
        abort_if(count($lines) > 40, 422, 'Too many cart lines.');
        foreach ($lines as $line) {
            $product = collect($merchant['products'])->firstWhere('id', $line['product_id'] ?? '');
            $variant = collect($product['variants'] ?? [])->firstWhere('id', $line['variant_id'] ?? '');
            $quantity = $line['quantity'] ?? 0;
            if (! $product || ! $variant || ! is_int($quantity) || $quantity < 1 || $quantity > 20 || $quantity > $variant['stock'] || $product['quote_only']) {
                throw ValidationException::withMessages(['cart' => 'An item is unavailable, needs a custom quote, or has an invalid quantity.']);
            }
            $key = $product['id'].':'.$variant['id'];
            abort_if(isset($seen[$key]), 422, 'Duplicate variant lines are not allowed.');
            $seen[$key] = true;
            $result[] = ['product' => $product, 'variant' => $variant, 'quantity' => $quantity, 'line_cents' => $quantity * $variant['price_cents']];
        }

        return $result;
    }

    public function quote(string $slug, array $lines, array $destination): array
    {
        $merchant = $this->merchant($slug);
        $resolved = $this->resolveLines($slug, $lines);
        $groups = [];
        foreach ($resolved as $line) {
            $p = $line['product'];
            $key = $p['group'];
            if (! isset($groups[$key])) {
                $groups[$key] = ['kind' => $key, 'weight_oz' => 0, 'length' => $p['dimensions_inches'][0], 'width' => $p['dimensions_inches'][1], 'height' => $p['dimensions_inches'][2], 'oversized' => false, 'sample_measurements' => true];
            }
            $groups[$key]['weight_oz'] += $p['weight_oz'] * $line['quantity'];
            $groups[$key]['oversized'] = $groups[$key]['oversized'] || $p['oversized'];
            if ($merchant['shipping']['kind'] === 'ceramic') {
                $groups[$key]['weight_oz'] += 6 * $line['quantity'];
                $groups[$key]['height'] += 2 * $line['quantity'];
            }
        }
        $subtotal = array_sum(array_column($resolved, 'line_cents'));
        $carrier = app(SandboxCarrierRateProvider::class);
        $provider = $carrier->configured($merchant) ? $carrier : app(DemoRateProvider::class);
        $quote = $provider->quote($merchant, [...$destination, 'subtotal_cents' => $subtotal], array_values($groups), $merchant['shipping']);

        return [...$quote, 'subtotal_cents' => $subtotal, 'tax_cents' => null, 'tax_note' => 'Tax is not calculated in this demonstration.'];
    }

    public function syncCart(string $slug, array $lines, ?string $token): WebsiteCart
    {
        $site = $this->site($slug);
        $this->resolveLines($slug, $lines);
        $commerce = app(WebsiteCommerceService::class);
        $cart = $commerce->cartFor($site, $token);

        return DB::transaction(function () use ($cart, $site, $slug, $lines, $commerce) {
            // Reconcile through the existing tenant-owned Website cart service.
            foreach ($cart->items as $item) {
                $commerce->setCartQuantity($cart, $item->id, 0);
            }
            foreach ($lines as $line) {
                $variant = WebsiteProductVariant::where('tenant_id', $site->tenant_id)->where('sku', $slug.'-'.$line['product_id'].'-'.$line['variant_id'])->firstOrFail();
                $cart = $commerce->addToCart($cart, $variant, $line['quantity']);
            }

            return $cart->fresh('items.variant.product');
        });
    }

    public function checkout(string $slug, array $input, User $actor, string $key): array
    {
        abort_unless(preg_match('/^[a-zA-Z0-9-]{16,80}$/', $key), 422, 'A valid checkout request key is required.');

        return DB::transaction(function () use ($slug, $input, $actor, $key) {
            $site = TenantSite::whereKey($this->site($slug)->id)->lockForUpdate()->firstOrFail();
            $settings = $site->settings;
            $demo = $settings['retail_demo'];
            foreach ($demo['orders'] as $order) {
                if ($order['request_key'] === $key) {
                    return $order;
                }
            }
            abort_if(count($demo['orders']) >= 1000, 422, 'The sample ledger is full. Ask the owner to reset it.');
            $lines = $this->resolveLines($slug, $input['lines']);
            abort_if($lines === [], 422, 'Add an item first.');
            $quote = $this->quote($slug, $input['lines'], $input['destination']);
            $rate = collect($quote['options'])->firstWhere('id', $input['service']);
            abort_unless($rate, 422, 'Choose an available shipping or pickup option.');
            $customer = null;
            $points = 0;
            $credit = 0;
            if (! empty($input['customer_id'])) {
                $customer = WebsiteCustomer::where('tenant_id', $site->tenant_id)->findOrFail($input['customer_id']);
                abort_unless(data_get($customer->notes, 'is_demo') === true, 404);
                $points = (int) data_get($customer->notes, 'reward_points', 0);
            }
            $redeem = (int) ($input['redeem_points'] ?? 0);
            abort_unless(in_array($redeem, [0, 100], true) && ($redeem === 0 || ($customer && $points >= 100)), 422, 'This customer needs 100 demo points to redeem a $5 sample reward.');
            if ($redeem) {
                $credit = min(500, $quote['subtotal_cents']);
            }
            foreach ($lines as $line) {
                $v = WebsiteProductVariant::where('tenant_id', $site->tenant_id)->where('sku', $slug.'-'.$line['product']['id'].'-'.$line['variant']['id'])->lockForUpdate()->firstOrFail();
                abort_if($v->inventory_quantity < $line['quantity'], 409, 'Stock changed. Review the cart.');
                $v->decrement('inventory_quantity', $line['quantity']);
            }
            $order = ['id' => 'DEMO-'.strtoupper(Str::random(8)), 'is_demo' => true, 'request_key' => $key, 'actor_id' => $actor->id, 'customer_id' => $customer?->id, 'lines' => array_map(fn ($l) => ['name' => $l['product']['name'], 'variant' => $l['variant']['title'], 'quantity' => $l['quantity'], 'line_cents' => $l['line_cents']], $lines), 'subtotal_cents' => $quote['subtotal_cents'], 'shipping_cents' => $rate['amount_cents'], 'reward_credit_cents' => $credit, 'total_cents' => $quote['subtotal_cents'] + $rate['amount_cents'] - $credit, 'tax_cents' => null, 'service' => $rate['label'], 'status' => 'simulated', 'created_at' => now()->toIso8601String()];
            if ($customer) {
                $notes = $customer->notes;
                $earned = (int) floor(($quote['subtotal_cents'] - $credit) / 100);
                $notes['reward_points'] = $points - $redeem + $earned;
                $notes['reward_entries'][] = ['order_id' => $order['id'], 'earned' => $earned, 'redeemed' => $redeem, 'is_demo' => true];
                $customer->notes = $notes;
                $customer->save();
            }
            $demo['orders'][] = $order;
            $settings['retail_demo'] = $demo;
            $site->settings = $settings;
            $site->save();

            return $order;
        });
    }

    public function staff(string $slug): array
    {
        $site = $this->site($slug);

        return ['orders' => array_reverse(data_get($site->settings, 'retail_demo.orders', [])), 'customers' => WebsiteCustomer::where('tenant_id', $site->tenant_id)->get()->filter(fn ($c) => data_get($c->notes, 'is_demo') === true)->map(fn ($c) => ['id' => $c->id, 'name' => trim($c->first_name.' '.$c->last_name), 'email' => $c->email, 'notes' => data_get($c->notes, 'private_notes', []), 'points' => (int) data_get($c->notes, 'reward_points', 0), 'reward_entries' => data_get($c->notes, 'reward_entries', [])])->values()];
    }
}

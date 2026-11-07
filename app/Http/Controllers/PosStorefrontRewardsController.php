<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Services\Marketing\CustomerAccountFence;
use App\Services\Marketing\MarketingStorefrontRequestVerifier;
use App\Services\Pos\PosCustomerRewards;
use App\Services\Pos\PosException;
use App\Services\Pos\PosExistingWalletReview;
use App\Services\Pos\PosRewardCustomerProof;
use App\Services\Pos\PosRewardRedemptionSettings;
use App\Services\Pos\PosStore;
use App\Services\Pos\PosVerifiedCustomerAccount;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** Browser counterpart of the customer app. Only Shopify's freshly signed subject can enter. */
final class PosStorefrontRewardsController extends Controller
{
    public function show(Request $request)
    {
        [$identity, $store, $tenant] = $this->context($request);
        $nonce = base64_encode(random_bytes(18));
        $challenge = null;
        $cap = null;
        $balance = null;
        $label = data_get(app(PosCustomerRewards::class)->policy((int) $tenant->id), 'program.label', 'Rewards');
        $profile = $identity['customer_id'] !== '' ? $this->wallet($store, $identity['customer_id']) : null;
        if ($identity['customer_id'] !== '') {
            $terms = null;
            try {
                if ($profile && config('pos_live.durable_start_claims_enabled')) {
                    $terms = app(PosRewardRedemptionSettings::class)->forProfile($profile);
                    $cap = $terms['max_minor'];
                    $balance = (int) floor(round((float) (\App\Models\CandleCashBalance::whereKey($profile->id)->value('balance') ?? 0) * 1000) / 10);
                }
            } catch (PosException) {
                // Setup is tenant-owned. Missing policy is never supplied from another program.
            }
            $challenge = Crypt::encryptString(json_encode(['purpose' => 'pos_browser_rewards_v1', 'id' => bin2hex(random_bytes(16)),
                'tenant' => (int) $tenant->id, 'store' => $store->store_key, 'subject' => $identity['customer_id'],
                'profile' => $profile?->id, 'cap' => $cap, 'policy' => $terms['version'] ?? null,
                'expires' => now()->addMinutes(2)->timestamp, 'request_id' => (string) Str::uuid()], JSON_THROW_ON_ERROR));
        }

        return response()->view('pos.customer-rewards', compact('tenant', 'label', 'cap', 'balance', 'profile', 'challenge', 'nonce', 'identity'))
            ->header('Cache-Control', 'private, no-store, max-age=0')->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; connect-src 'self'; img-src data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    }

    public function act(Request $request)
    {
        [$identity, $store, $tenant] = $this->context($request);
        $input = $request->validate(['challenge' => ['required', 'string', 'max:4000'], 'action' => ['required', 'in:code,review'], 'confirmed' => ['accepted']]);
        try {
            $challenge = json_decode(Crypt::decryptString($input['challenge']), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $this->error('customer_authorization_expired', 422);
        }
        if (($challenge['purpose'] ?? null) !== 'pos_browser_rewards_v1' || ($challenge['tenant'] ?? null) !== (int) $tenant->id
            || ($challenge['store'] ?? null) !== $store->store_key || ($challenge['subject'] ?? null) !== $identity['customer_id']
            || $identity['customer_id'] === '' || ($challenge['expires'] ?? 0) <= now()->timestamp) {
            return $this->error('customer_authorization_expired', 422);
        }
        try {
            $body = app(CustomerAccountFence::class)->forTenant((int) $tenant->id, function () use ($input, $identity, $store, $tenant, $challenge) {
                $pos = app(PosStore::class);
                if ($pos->rows('events', (int) $tenant->id)->where('sale_id', $challenge['id'])->where('code', 'browser_rewards_consent_used')->exists()) {
                    throw new PosException('customer_authorization_expired', 409);
                }
                $pos->event((int) $tenant->id, $challenge['id'], 'browser_rewards_consent_used', ['store' => $store->store_key, 'subject' => $identity['customer_id'], 'action' => $input['action']]);
                if ($input['action'] === 'review') {
                    return ['review' => app(PosExistingWalletReview::class)->request((int) $tenant->id, 'gid://shopify/Customer/'.$identity['customer_id'], $challenge['request_id'], $store->store_key)];
                }
                $profile = $this->wallet($store, $identity['customer_id']);
                if (! $profile || (int) $profile->id !== ($challenge['profile'] ?? null) || ! is_int($challenge['cap'] ?? null)) {
                    throw new PosException('customer_identity_review_required', 409);
                }
                $link = MarketingProfileLink::forTenantId((int) $tenant->id)->where('source_type', PosVerifiedCustomerAccount::SOURCE)
                    ->where('source_id', $store->store_key.':'.$identity['customer_id'])->firstOrFail();
                $proof = app(PosRewardCustomerProof::class)->issueForAuthenticatedProfile($profile, ['max_minor' => $challenge['cap'],
                    'policy_version' => $challenge['policy'], 'disclosure_version' => PosRewardCustomerProof::DISCLOSURE_VERSION], (int) $link->id);
                $writer = new Writer(new ImageRenderer(new RendererStyle(320, 4), new SvgImageBackEnd));

                return ['svg' => $writer->writeString($proof['qr_payload']), 'expires_at' => $proof['expires_at']];
            });
        } catch (PosException $error) {
            return $this->error($error->blocker, $error->status);
        }

        return response()->json($body)->header('Cache-Control', 'private, no-store');
    }

    private function context(Request $request): array
    {
        $identity = app(MarketingStorefrontRequestVerifier::class)->customerProxy($request);
        abort_unless($identity, 401);
        $store = ShopifyStore::query()->where('shop_domain', $identity['shop'])->whereNotNull('tenant_id')->firstOrFail();
        $tenant = Tenant::query()->whereKey($store->tenant_id)->firstOrFail();

        return [$identity, $store, $tenant];
    }

    private function wallet(ShopifyStore $store, string $customer): ?MarketingProfile
    {
        $link = MarketingProfileLink::forTenantId((int) $store->tenant_id)->where('source_type', PosVerifiedCustomerAccount::SOURCE)
            ->where('source_id', $store->store_key.':'.$customer)->first();
        $profile = $link ? MarketingProfile::forTenantId((int) $store->tenant_id)->whereKey($link->marketing_profile_id)->first() : null;

        return $profile && app(PosVerifiedCustomerAccount::class)->verified($profile, (int) $link->id) ? $profile : null;
    }

    private function error(string $code, int $status)
    {
        return response()->json(['error' => ['code' => $code]], $status)->header('Cache-Control', 'private, no-store');
    }
}

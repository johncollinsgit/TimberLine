<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Audit;
use App\Models\Marketplace\Identity;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\Membership;
use App\Models\Marketplace\RateAcceptance;
use App\Models\Marketplace\RateAgreement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketplaceRates
{
    public function global(): RateAgreement
    {
        return RateAgreement::query()->where('scope_key', 'global')->latest('version')->first()
            ?? RateAgreement::query()->firstOrCreate(['scope_key' => 'global', 'version' => 1], [
                'kind' => 'percentage', 'value' => 1500,
                'terms' => 'Markets and Makers retains 15% of merchandise before marketplace-funded discounts. Standard processing is included. Shipping collected is passed through. No listing or monthly seller fees. Payments are reviewed after fulfillment; refunds adjust supplier earnings.',
            ]);
    }

    public function resolve(Listing $listing): RateAgreement
    {
        foreach (['listing:'.$listing->id, 'maker:'.$listing->maker_id] as $scope) {
            if ($rate = RateAgreement::query()->where('scope_key', $scope)->latest('version')->first()) {
                return $rate;
            }
        }

        return $this->global();
    }

    public function amounts(int $price, RateAgreement $rate): array
    {
        $fee = $rate->kind === 'percentage' ? intdiv($price * $rate->value + 5000, 10000) : $rate->value;
        if ($price < 1 || $fee < 0 || $fee > $price || ($rate->kind === 'percentage' && $rate->value > 10000)) {
            throw ValidationException::withMessages(['rate' => 'The marketplace fee must be between zero and the item price.']);
        }

        return ['price_cents' => $price, 'fee_cents' => $fee, 'maker_cents' => $price - $fee, 'rate_id' => $rate->id];
    }

    public function accepted(Listing $listing, ?RateAgreement $rate = null): bool
    {
        return RateAcceptance::query()->where('maker_id', $listing->maker_id)->where('rate_id', ($rate ?? $this->resolve($listing))->id)->exists();
    }

    public function preview(Listing $listing): ?array
    {
        try {
            return $this->amounts($listing->price_cents, $this->resolve($listing));
        } catch (ValidationException) {
            return null;
        }
    }

    public function accept(Maker $maker, Identity $identity, RateAgreement $rate): void
    {
        abort_unless(Membership::query()->where('maker_id', $maker->id)->where('identity_id', $identity->id)->where('role', 'owner')->where('active', true)->exists(), 403);
        $listingOwned = str_starts_with($rate->scope_key, 'listing:') && $maker->listings()->whereKey((int) substr($rate->scope_key, 8))->exists();
        abort_unless(in_array($rate->scope_key, ['global', 'maker:'.$maker->id], true) || $listingOwned, 403);
        RateAcceptance::query()->firstOrCreate(['maker_id' => $maker->id, 'rate_id' => $rate->id], ['identity_id' => $identity->id]);
    }

    public function revise(string $scope, string $kind, int $value, string $terms, int $actor): RateAgreement
    {
        if (! preg_match('/^(global|maker:[1-9][0-9]*|listing:[1-9][0-9]*)$/', $scope) || ! in_array($kind, ['percentage', 'fixed'], true) || $value < 0 || ($kind === 'percentage' && $value > 10000)) {
            throw ValidationException::withMessages(['rate' => 'Choose a valid scope and a percentage from 0 to 100, or a fixed amount per item.']);
        }
        if (str_starts_with($scope, 'maker:')) {
            Maker::query()->findOrFail(substr($scope, 6));
        }
        if (str_starts_with($scope, 'listing:')) {
            $listing = Listing::query()->findOrFail(substr($scope, 8));
            if ($kind === 'fixed' && $value > $listing->price_cents) {
                throw ValidationException::withMessages(['rate' => 'The fee exceeds this item price.']);
            }
        }

        return DB::transaction(function () use ($scope, $kind, $value, $terms, $actor): RateAgreement {
            // One singleton row serializes revision numbers for every scope.
            app(MarketplaceAccess::class)->settings();
            DB::table('marketplace_settings')->where('id', 1)->lockForUpdate()->first();
            $version = (int) RateAgreement::query()->where('scope_key', $scope)->max('version') + 1;
            $rate = RateAgreement::query()->create(['scope_key' => $scope, 'version' => $version, 'kind' => $kind, 'value' => $value, 'terms' => $terms, 'created_by' => $actor]);
            Audit::query()->create(['actor_user_id' => $actor, 'action' => 'rate.revised', 'subject_id' => $rate->id, 'details' => ['scope' => $scope, 'version' => $version, 'kind' => $kind, 'value' => $value]]);

            return $rate;
        });
    }
}

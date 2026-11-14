<?php

namespace App\Services\Marketing;

use App\Models\MarketingImportRun;
use App\Models\MarketingProfileLink;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Reviewed tax-place evidence describes a purchase location, never a customer's home or event attendance. */
class CustomerPurchasePlaceMap
{
    public const KEY = 'customer_purchase_place_map';

    public function normalize(string $label): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($label))) ?? '');
    }

    public function status(int $tenant): array
    {
        abort_unless($tenant > 0, 404);

        return Cache::remember(self::KEY.':'.$tenant, 5, function () use ($tenant): array {
            $value = TenantMarketingSetting::forTenantId($tenant)->where('key', self::KEY)->value('value');
            $value = is_string($value) ? json_decode($value, true) : $value;

            return is_array($value) ? $value : ['version' => hash('sha256', 'unconfigured:'.$tenant), 'labels' => []];
        });
    }

    public function configure(int $tenant, User $actor, string $expectedVersion, array $labels): array
    {
        app(SquareCustomerIdentityService::class)->authorize($tenant, $actor);
        abort_unless(count($labels) <= 2000, 422);
        $normalized = [];
        foreach ($labels as $label => $postals) {
            abort_unless(is_string($label) && strlen($label) <= 500 && preg_match('/[a-z]/', $this->normalize($label)) && is_array($postals) && count($postals) >= 1 && count($postals) <= 10, 422, 'Use a named purchase place; a tax percentage alone cannot identify a location.');
            $postals = array_values(array_unique($postals));
            sort($postals);
            foreach ($postals as $postal) {
                abort_unless(is_string($postal) && app(CustomerLocationDistance::class)->point($postal), 422, 'Choose a US ZIP with a Census reference point.');
            }
            $key = $this->normalize($label);
            abort_if(isset($normalized[$key]) && $normalized[$key]['postal_codes'] !== $postals, 422, 'Two labels normalize to conflicting purchase locations.');
            $normalized[$key] = ['source_label' => $label, 'postal_codes' => $postals];
        }
        ksort($normalized);

        return app(CustomerAccountFence::class)->forTenant($tenant, function () use ($tenant, $actor, $expectedVersion, $normalized): array {
            app(SquareCustomerIdentityService::class)->authorize($tenant, $actor->fresh());
            Cache::forget(self::KEY.':'.$tenant);
            $current = $this->status($tenant);
            abort_unless(hash_equals($current['version'], $expectedVersion), 409, 'Purchase-location mapping changed. Review its current version.');
            if ($current['labels'] === $normalized) {
                return $current;
            }
            $value = ['labels' => $normalized, 'reviewed_by' => $actor->id, 'reviewed_at' => now()->toIso8601String(),
                'basis' => 'reviewed_original_tax_place_names', 'customer_home_changed' => false, 'event_attendance_inferred' => false];
            $value['version'] = hash('sha256', json_encode([$tenant, $value], JSON_THROW_ON_ERROR));
            TenantMarketingSetting::updateOrCreate(['tenant_id' => $tenant, 'key' => self::KEY], ['value' => $value]);
            MarketingImportRun::create(['tenant_id' => $tenant, 'type' => 'customer_purchase_place_mapping', 'source_label' => 'reviewed_tax_location_evidence',
                'created_by' => $actor->id, 'status' => 'completed', 'started_at' => now(), 'finished_at' => now(),
                'summary' => ['previous_version' => $current['version'], 'mapping_version' => $value['version'], 'recognized_labels' => count($normalized), 'messages_sent' => 0, 'permissions_changed' => 0]]);
            Cache::forget(self::KEY.':'.$tenant);

            return $value;
        });
    }

    public function places(int $tenant, array $taxNames): array
    {
        $map = $this->status($tenant);
        $postalCodes = [];
        if (! $taxNames) {
            return [];
        }
        foreach ($taxNames as $name) {
            if (! is_string($name) || ! isset($map['labels'][$this->normalize($name)])) {
                return [];
            }
            $postalCodes = [...$postalCodes, ...$map['labels'][$this->normalize($name)]['postal_codes']];
        }
        $postalCodes = array_values(array_unique($postalCodes));

        return ['postal_codes' => $postalCodes, 'points' => array_map(fn ($zip) => app(CustomerLocationDistance::class)->point($zip), $postalCodes),
            'tax_names' => $taxNames, 'mapping_version' => $map['version'], 'basis' => 'tax_named_purchase_place'];
    }

    /** Source keys have already passed the shared confirmed-purchase and canonical identity readers. */
    public function groupsForConfirmedSources(int $tenant, array $sourceKeys): array
    {
        $orders = [];
        $payments = [];
        foreach ($sourceKeys as $key) {
            if (str_starts_with($key, 'square_order:')) {
                $orders[] = substr($key, strlen('square_order:'));
            } elseif (str_starts_with($key, 'square_payment:')) {
                $payments[] = substr($key, strlen('square_payment:'));
            }
        }
        if ($payments) {
            $orders = [...$orders, ...SquarePayment::forTenantId($tenant)->whereIn('square_payment_id', $payments)->where('status', 'COMPLETED')->pluck('square_order_id')->filter()->all()];
        }
        if (! $orders) {
            return [];
        }
        $groups = [];
        foreach (SquareOrder::forTenantId($tenant)->whereIn('square_order_id', array_unique($orders))->get(['square_order_id', 'raw_tax_names']) as $order) {
            if ($place = $this->places($tenant, (array) $order->raw_tax_names)) {
                $groups[] = $place + ['source_type' => 'square_order', 'source_id' => $order->square_order_id];
            }
        }

        return $groups;
    }

    /** Coarse original-link prefilter; final membership still requires confirmed purchase projection. */
    public function candidateProfileIds(int $tenant, string $postal, float $radius): array
    {
        $center = app(CustomerLocationDistance::class)->point($postal);
        abort_unless($center && $radius > 0 && $radius <= 500, 422);
        $taxValues = [];
        foreach (DB::table('square_orders')->where('tenant_id', $tenant)->selectRaw('CAST(raw_tax_names AS CHAR) AS raw_tax_names')->groupByRaw('CAST(raw_tax_names AS CHAR)')->cursor() as $row) {
            $place = $this->places($tenant, json_decode($row->raw_tax_names ?? '[]', true) ?? []);
            if ($place && collect($place['points'])->every(fn ($point) => ($miles = app(CustomerLocationDistance::class)->miles($point, $center)) !== null && $miles <= $radius)) {
                $taxValues[] = $row->raw_tax_names;
            }
        }
        if (! $taxValues) {
            return [];
        }
        $orders = fn () => DB::table('square_orders')->where('tenant_id', $tenant)->whereIn(DB::raw('CAST(raw_tax_names AS CHAR)'), $taxValues)->select('square_order_id');
        $payments = fn ($column) => DB::table('square_payments')->where('tenant_id', $tenant)->whereIn('square_order_id', $orders())->where('status', 'COMPLETED')->select($column);

        return MarketingProfileLink::forTenantId($tenant)->where(fn ($q) => $q
            ->where(fn ($s) => $s->where('source_type', 'square_order')->whereIn('source_id', $orders()))
            ->orWhere(fn ($s) => $s->where('source_type', 'square_payment')->whereIn('source_id', $payments('square_payment_id')))
            ->orWhere(fn ($s) => $s->where('source_type', 'square_customer')->whereIn('source_id', $payments('square_customer_id'))))
            ->distinct()->pluck('marketing_profile_id')->map('intval')->all();
    }
}

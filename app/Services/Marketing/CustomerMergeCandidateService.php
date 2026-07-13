<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerMergeCandidateService
{
    /** @return array<int,array<string,mixed>> */
    public function search(int $tenantId, string $query, string $storeKey, int $limit = 30): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $normalized = $this->normalize($query);
        $terms = collect(explode(' ', $normalized))->filter()->values();
        $email = str_contains($query, '@') ? strtolower($query) : null;
        $phone = preg_replace('/\D+/', '', $query) ?: null;
        $shopifyQueryId = (ctype_digit($query) || str_contains(strtolower($query), 'gid://shopify/customer/')) && preg_match('/(\d+)$/', $query, $shopifyMatch)
            ? $shopifyMatch[1]
            : null;

        $profiles = MarketingProfile::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('merged_at')
            ->where(function ($matches) use ($email, $phone, $shopifyQueryId, $terms): void {
                if ($email !== null) {
                    $matches->orWhere('normalized_email', $email);
                }
                if ($phone !== null && strlen($phone) >= 7) {
                    $matches->orWhere('normalized_phone', 'like', '%'.$phone);
                }
                if ($shopifyQueryId !== null) {
                    $matches->orWhereExists(function ($external) use ($shopifyQueryId): void {
                        $external->selectRaw('1')->from('customer_external_profiles as candidate_external')
                            ->whereColumn('candidate_external.marketing_profile_id', 'marketing_profiles.id')
                            ->where('candidate_external.provider', 'shopify')
                            ->where(function ($id) use ($shopifyQueryId): void {
                                $id->where('candidate_external.external_customer_id', $shopifyQueryId)
                                    ->orWhere('candidate_external.external_customer_gid', 'like', '%/'.$shopifyQueryId);
                            });
                    })->orWhereExists(function ($links) use ($shopifyQueryId): void {
                        $links->selectRaw('1')->from('marketing_profile_links as candidate_links')
                            ->whereColumn('candidate_links.marketing_profile_id', 'marketing_profiles.id')
                            ->where('candidate_links.source_type', 'shopify_customer')
                            ->where(function ($id) use ($shopifyQueryId): void {
                                $id->where('candidate_links.source_id', $shopifyQueryId)
                                    ->orWhere('candidate_links.source_id', 'like', '%:'.$shopifyQueryId);
                            });
                    });
                }
                if ($terms->isNotEmpty()) {
                    $matches->orWhere(function ($names) use ($terms): void {
                        foreach ($terms as $term) {
                            $phonetic = metaphone((string) $term);
                            $fuzzyPrefix = substr((string) $term, 0, max(3, strlen((string) $term) - 2));
                            $names->where(function ($part) use ($term, $phonetic, $fuzzyPrefix): void {
                                $part->where('normalized_first_name', 'like', '%'.$term.'%')
                                    ->orWhere('normalized_last_name', 'like', '%'.$term.'%')
                                    ->orWhere('normalized_first_name', 'like', $fuzzyPrefix.'%')
                                    ->orWhere('normalized_last_name', 'like', $fuzzyPrefix.'%');
                                if ($phonetic !== '') {
                                    $part->orWhere('first_name_phonetic', $phonetic)
                                        ->orWhere('last_name_phonetic', $phonetic);
                                }
                            });
                        }
                    });
                }
            })
            ->get();

        $shopifyIds = $this->shopifyIds($profiles, $storeKey);

        $ranked = $profiles->map(function (MarketingProfile $profile) use ($normalized, $terms, $email, $phone, $shopifyIds, $tenantId, $storeKey, $shopifyQueryId): array {
            $name = $this->normalize(trim($profile->first_name.' '.$profile->last_name));
            $reverse = $this->normalize(trim($profile->last_name.' '.$profile->first_name));
            $distance = $normalized !== '' ? min(levenshtein($normalized, $name), levenshtein($normalized, $reverse)) : 99;
            $nameExact = $normalized !== '' && in_array($normalized, [$name, $reverse], true);
            $phonetic = $terms->count() >= 2
                && metaphone((string) $terms->first()) === metaphone((string) ($profile->normalized_first_name ?: $profile->first_name))
                && metaphone((string) $terms->last()) === metaphone((string) ($profile->normalized_last_name ?: $profile->last_name));
            $emailExact = $email !== null && strtolower((string) $profile->normalized_email) === $email;
            $profilePhone = preg_replace('/\D+/', '', (string) $profile->normalized_phone);
            $phoneExact = $phone !== null && strlen($phone) >= 7 && str_ends_with($profilePhone, $phone);
            $shopifyExact = $shopifyQueryId !== null && str_ends_with((string) ($shopifyIds[(int) $profile->id] ?? ''), '/'.$shopifyQueryId);
            $matched = $shopifyExact || $emailExact || $phoneExact || $nameExact || $phonetic || $distance <= max(2, (int) floor(strlen($normalized) * .18));

            return [
                'matched' => $matched,
                'score' => ($shopifyExact ? 1200 : 0) + ($emailExact ? 1000 : 0) + ($phoneExact ? 900 : 0) + ($nameExact ? 700 : 0) + ($phonetic ? 400 : 0) + max(0, 200 - ($distance * 20)),
                'id' => (int) $profile->id,
                'tenant_id' => $profile->tenant_id,
                'name' => trim($profile->first_name.' '.$profile->last_name) ?: 'Customer #'.$profile->id,
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'email' => $profile->email,
                'phone' => $profile->phone,
                'notes' => $profile->notes,
                'tags' => $profile->tags ?? [],
                'address' => array_filter([
                    'address_line_1' => $profile->address_line_1,
                    'address_line_2' => $profile->address_line_2,
                    'city' => $profile->city,
                    'state' => $profile->state,
                    'postal_code' => $profile->postal_code,
                    'country' => $profile->country,
                ]),
                'shopify_customer_gid' => $shopifyIds[(int) $profile->id] ?? null,
                'orders_count' => $this->orderCount($tenantId, $profile, $shopifyIds[(int) $profile->id] ?? null, $storeKey),
                'candle_cash_balance' => (float) DB::table('candle_cash_balances')->where('marketing_profile_id', $profile->id)->value('balance'),
                'candle_cash_transactions' => DB::table('candle_cash_transactions')->where('marketing_profile_id', $profile->id)->count(),
                'evidence' => array_values(array_filter([
                    $shopifyExact ? 'exact Shopify ID' : null,
                    $emailExact ? 'exact email' : null,
                    $phoneExact ? 'exact phone' : null,
                    $nameExact ? 'exact name' : null,
                    (! $nameExact && ($phonetic || $distance <= 2)) ? 'probable misspelling' : null,
                    isset($shopifyIds[(int) $profile->id]) ? 'Shopify identity' : null,
                ])),
            ];
        })->filter(fn (array $row): bool => (bool) $row['matched'])->sortByDesc('score')->take(max(2, min(50, $limit)))
            ->map(fn (array $row): array => collect($row)->except(['matched', 'score'])->all())->values();

        return $ranked->map(function (array $row) use ($ranked): array {
            $others = $ranked->where('id', '!=', $row['id']);
            $addressKey = $this->normalize(implode(' ', (array) $row['address']));
            $sameShopify = $row['shopify_customer_gid'] && $others->contains('shopify_customer_gid', $row['shopify_customer_gid']);
            $row['evidence'] = array_values(array_unique([
                ...$row['evidence'],
                ...($sameShopify ? ['same Shopify ID'] : []),
                ...($row['email'] && $others->contains(fn (array $other): bool => strtolower((string) $other['email']) === strtolower((string) $row['email'])) ? ['exact email'] : []),
                ...($row['phone'] && $others->contains(fn (array $other): bool => preg_replace('/\D+/', '', (string) $other['phone']) === preg_replace('/\D+/', '', (string) $row['phone'])) ? ['exact phone'] : []),
                ...($addressKey !== '' && $others->contains(fn (array $other): bool => $this->normalize(implode(' ', (array) $other['address'])) === $addressKey) ? ['shared address'] : []),
                ...($sameShopify && (int) $row['orders_count'] > 0 ? ['order overlap'] : []),
            ]));

            return $row;
        })->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function selected(int $tenantId, array $profileIds, string $storeKey): array
    {
        $ids = collect($profileIds)->map('intval')->filter()->unique();
        $profiles = MarketingProfile::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->whereNull('merged_at')
            ->get();
        $shopifyIds = $this->shopifyIds($profiles, $storeKey);

        return $profiles->map(fn (MarketingProfile $profile): array => [
            'id' => (int) $profile->id,
            'tenant_id' => $profile->tenant_id,
            'name' => trim($profile->first_name.' '.$profile->last_name) ?: 'Customer #'.$profile->id,
            'first_name' => $profile->first_name,
            'last_name' => $profile->last_name,
            'email' => $profile->email,
            'phone' => $profile->phone,
            'notes' => $profile->notes,
            'tags' => $profile->tags ?? [],
            'address_line_1' => $profile->address_line_1,
            'address_line_2' => $profile->address_line_2,
            'city' => $profile->city,
            'state' => $profile->state,
            'postal_code' => $profile->postal_code,
            'country' => $profile->country,
            'shopify_customer_gid' => $shopifyIds[(int) $profile->id] ?? null,
            'orders_count' => $this->orderCount($tenantId, $profile, $shopifyIds[(int) $profile->id] ?? null, $storeKey),
            'candle_cash_balance' => (float) DB::table('candle_cash_balances')->where('marketing_profile_id', $profile->id)->value('balance'),
            'candle_cash_transactions' => DB::table('candle_cash_transactions')->where('marketing_profile_id', $profile->id)->count(),
        ])->values()->all();
    }

    private function shopifyIds(Collection $profiles, string $storeKey): array
    {
        return DB::table('customer_external_profiles')->whereIn('marketing_profile_id', $profiles->pluck('id'))
            ->where('provider', 'shopify')->where('integration', 'shopify_customer')->where('store_key', $storeKey)
            ->get()->mapWithKeys(fn ($row): array => [(int) $row->marketing_profile_id => (string) ($row->external_customer_gid ?: 'gid://shopify/Customer/'.$row->external_customer_id)])->all();
    }

    private function orderCount(int $tenantId, MarketingProfile $profile, ?string $gid, string $storeKey): int
    {
        $shopifyId = preg_match('/(\d+)$/', (string) $gid, $match) ? $match[1] : null;

        return (int) DB::table('orders')->where('tenant_id', $tenantId)
            ->when($shopifyId,
                fn ($query) => $query->where('shopify_customer_id', $shopifyId),
                fn ($query) => $query->where(function ($identity) use ($profile): void {
                    if ($profile->normalized_email) {
                        $identity->orWhere('customer_email', $profile->normalized_email)->orWhere('email', $profile->normalized_email);
                    }
                    if ($profile->normalized_phone) {
                        $identity->orWhere('customer_phone', $profile->normalized_phone)->orWhere('phone', $profile->normalized_phone);
                    }
                })
            )
            ->count();
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($value))) ?? '');
    }
}

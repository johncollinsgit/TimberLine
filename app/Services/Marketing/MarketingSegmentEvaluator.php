<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\MarketingSegment;

class MarketingSegmentEvaluator
{
    public function __construct(
        protected MarketingProfileAnalyticsService $analyticsService
    ) {}

    /**
     * @return array{matched:bool,reasons:array<int,string>,metrics:array<string,mixed>}
     */
    public function evaluateProfile(MarketingSegment $segment, MarketingProfile $profile): array
    {
        $rules = is_array($segment->rules_json) ? $segment->rules_json : [];
        $metrics = $this->analyticsService->metricsForProfile($profile);
        $reasons = [];
        $matched = $this->evaluateGroup($rules, $metrics, $reasons);

        return [
            'matched' => $matched,
            'reasons' => $reasons,
            'metrics' => $metrics,
        ];
    }

    /**
     * @param  array<string,mixed>  $group
     * @param  array<string,mixed>  $metrics
     * @param  array<int,string>  $reasons
     */
    protected function evaluateGroup(array $group, array $metrics, array &$reasons): bool
    {
        $logic = strtolower(trim((string) ($group['logic'] ?? 'and')));
        $logic = in_array($logic, ['and', 'or'], true) ? $logic : 'and';

        $results = [];
        foreach ((array) ($group['conditions'] ?? []) as $condition) {
            if (! is_array($condition)) {
                continue;
            }
            $conditionResult = $this->evaluateCondition($condition, $metrics);
            $results[] = $conditionResult['matched'];
            if ($conditionResult['matched'] && $conditionResult['reason']) {
                $reasons[] = $conditionResult['reason'];
            }
        }

        foreach ((array) ($group['groups'] ?? []) as $nested) {
            if (! is_array($nested)) {
                continue;
            }
            $results[] = $this->evaluateGroup($nested, $metrics, $reasons);
        }

        if ($results === []) {
            return false;
        }

        return $logic === 'or'
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    /**
     * @param  array<string,mixed>  $condition
     * @param  array<string,mixed>  $metrics
     * @return array{matched:bool,reason:?string}
     */
    protected function evaluateCondition(array $condition, array $metrics): array
    {
        $field = trim((string) ($condition['field'] ?? ''));
        $operator = strtolower(trim((string) ($condition['operator'] ?? 'eq')));
        $value = $condition['value'] ?? null;
        if ($field === '') {
            return ['matched' => false, 'reason' => null];
        }

        if ($field === 'near_event_zip') {
            $postal = is_array($value) ? ($value['postal_code'] ?? null) : null;
            $radius = is_array($value) ? ($value['radius_miles'] ?? 50) : null;
            $distance = app(CustomerLocationDistance::class)->miles($metrics['location_point'] ?? null, app(CustomerLocationDistance::class)->point($postal));
            $matched = $operator === 'within' && is_numeric($radius) && $radius > 0 && $radius <= 500 && $distance !== null && $distance <= $radius;

            return ['matched' => $matched, 'reason' => $matched ? 'Approximately '.round($distance, 1).' miles from ZIP '.$postal.' (Census ZCTA)' : null];
        }
        $actual = $this->metricValue($field, $metrics);
        $matched = $actual === null && in_array($operator, ['gt', 'gte', 'lt', 'lte'], true) ? false : $this->match($actual, $operator, $value);

        return [
            'matched' => $matched,
            'reason' => $matched ? "{$field} {$operator} ".$this->displayValue($value) : null,
        ];
    }

    protected function match(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'gt' => $this->toNumber($actual) > $this->toNumber($expected),
            'gte' => $this->toNumber($actual) >= $this->toNumber($expected),
            'lt' => $this->toNumber($actual) < $this->toNumber($expected),
            'lte' => $this->toNumber($actual) <= $this->toNumber($expected),
            'neq' => $actual != $expected,
            'contains' => $this->contains($actual, $expected),
            'contains_exact' => is_array($actual) && in_array((string) $expected, array_map('strval', $actual), true),
            'in' => is_array($expected) ? in_array($actual, $expected, true) : false,
            'eq', '=' => $this->equals($actual, $expected),
            default => $this->equals($actual, $expected),
        };
    }

    protected function metricValue(string $field, array $metrics): mixed
    {
        return match ($field) {
            'total_spent' => $metrics['total_spent'] ?? null,
            'total_orders' => $metrics['total_orders'] ?? null,
            'days_since_last_order' => $metrics['days_since_last_order'] ?? null,
            'source_channel' => $metrics['source_channels'] ?? [],
            'has_email_consent' => $metrics['has_email_consent'] ?? false,
            'has_sms_consent' => $metrics['has_sms_consent'] ?? false,
            'purchased_at_event' => $metrics['purchased_at_event'] ?? false,
            'purchased_event_name' => $metrics['purchased_event_names'] ?? [],
            'last_event_name' => $metrics['last_event_name'] ?? '',
            'profile_source' => $metrics['profile_sources'] ?? [],
            'has_square_link' => $metrics['has_square_link'] ?? false,
            'has_shopify_link' => $metrics['has_shopify_link'] ?? false,
            'wishlist_product_handle' => $metrics['wishlist_product_handles'] ?? [],
            'wishlist_product_id' => $metrics['wishlist_product_ids'] ?? [],
            'wishlist_product_title' => $metrics['wishlist_product_titles'] ?? [],
            default => $metrics[$field] ?? null,
        };
    }

    protected function toNumber(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    protected function equals(mixed $actual, mixed $expected): bool
    {
        if (is_bool($actual) || is_bool($expected)) {
            return (bool) $actual === (bool) $expected;
        }

        if (is_array($actual)) {
            return in_array((string) $expected, array_map('strval', $actual), true);
        }

        return (string) $actual === (string) $expected;
    }

    protected function contains(mixed $actual, mixed $expected): bool
    {
        $needle = strtolower(trim((string) $expected));
        if ($needle === '') {
            return false;
        }

        if (is_array($actual)) {
            foreach ($actual as $item) {
                if (str_contains(strtolower((string) $item), $needle)) {
                    return true;
                }
            }

            return false;
        }

        return str_contains(strtolower((string) $actual), $needle);
    }

    protected function displayValue(mixed $value): string
    {
        if (is_array($value)) {
            return '['.implode(',', array_map(fn ($v) => (string) $v, $value)).']';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}

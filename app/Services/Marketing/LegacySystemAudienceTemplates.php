<?php

namespace App\Services\Marketing;

use App\Models\MarketingImportRun;
use App\Models\MarketingSegment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentsCore\Contract;
use Illuminate\Support\Facades\DB;

/** Reuse exact published starter definitions without assigning ambiguous legacy tenant ownership. */
final class LegacySystemAudienceTemplates
{
    private const DEFINITIONS = [
        'high-value-customers' => ['High Value Customers', 'any', 'or', [['total_spent', 'gt', 300], ['total_orders', 'gt', 5]]],
        'repeat-buyers' => ['Repeat Buyers', 'any', 'and', [['total_orders', 'gt', 1]]],
        'lapsed-customers' => ['Lapsed Customers', 'sms', 'and', [['days_since_last_order', 'gt', 60]]],
        'event-buyers' => ['Event Buyers', 'any', 'and', [['purchased_at_event', 'eq', true]]],
        'online-buyers' => ['Online Buyers', 'any', 'and', [['source_channel', 'contains', 'online']]],
        'sms-consented' => ['SMS Consented', 'sms', 'and', [['has_sms_consent', 'eq', true]]],
        'square-buyers' => ['Square Buyers', 'any', 'and', [['has_square_link', 'eq', true]]],
        'shopify-buyers' => ['Shopify Buyers', 'any', 'and', [['has_shopify_link', 'eq', true]]],
        'email-consented-no-sms' => ['Email Consented / No SMS Consent', 'sms', 'and', [['has_email_consent', 'eq', true], ['has_sms_consent', 'eq', false]]],
    ];

    public function available(): array
    {
        $rows = [];
        foreach (MarketingSegment::whereNull('tenant_id')->where('is_system', true)->whereIn('slug', array_keys(self::DEFINITIONS))->orderBy('id')->get() as $source) {
            if ($this->matches($source)) {
                $rows[] = ['id' => $source->id, 'name' => $source->name, 'fingerprint' => $this->fingerprint($source)];
            }
        }

        return $rows;
    }

    public function copy(int $tenant, User $actor, int $sourceId, string $expected): MarketingSegment
    {
        app(SquareCustomerIdentityService::class)->authorize($tenant, $actor);

        return DB::transaction(function () use ($tenant, $actor, $sourceId, $expected): MarketingSegment {
            Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            app(SquareCustomerIdentityService::class)->authorize($tenant, User::findOrFail($actor->id));
            $source = MarketingSegment::whereNull('tenant_id')->where('is_system', true)->whereKey($sourceId)->lockForUpdate()->firstOrFail();
            abort_unless($this->matches($source) && hash_equals($this->fingerprint($source), $expected), 409, 'The original starter definition changed. Review it before copying.');
            $slug = 'system-audience-'.$tenant.'-'.$source->id.'-'.substr($expected, 0, 12);
            $copy = MarketingSegment::forTenantId($tenant)->firstOrCreate(['slug' => $slug], ['tenant_id' => $tenant, 'name' => $source->name,
                'description' => 'Starter audience evaluated from this workspace\'s current customer records.', 'status' => 'draft', 'channel_scope' => $source->channel_scope, 'is_system' => false,
                'rules_json' => [...$source->rules_json, '_legacy_system_audience' => ['source_id' => $source->id, 'definition_hash' => $expected,
                    'copied_by' => $actor->id, 'copied_at' => now()->toIso8601String()]], 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            if ($copy->wasRecentlyCreated) {
                MarketingImportRun::create(['tenant_id' => $tenant, 'type' => 'legacy_audience_template', 'status' => 'completed', 'source_label' => 'published_system_starter',
                    'created_by' => $actor->id, 'started_at' => now(), 'finished_at' => now(), 'summary' => ['source_id' => $source->id, 'segment_id' => $copy->id, 'definition_hash' => $expected, 'campaigns_created' => 0]]);
            }

            return $copy;
        }, 3);
    }

    private function matches(MarketingSegment $source): bool
    {
        $definition = self::DEFINITIONS[$source->slug] ?? null;
        if (! $definition || $source->name !== $definition[0] || $source->channel_scope !== $definition[1]) {
            return false;
        }
        $rules = ['logic' => $definition[2], 'conditions' => array_map(fn ($condition) => array_combine(['field', 'operator', 'value'], $condition), $definition[3]), 'groups' => []];

        return $this->normalizedHash($source->rules_json ?? []) === $this->normalizedHash($rules);
    }

    private function fingerprint(MarketingSegment $source): string
    {
        return $this->normalizedHash([$source->name, $source->channel_scope, $source->rules_json]);
    }

    private function normalizedHash(array $value): string
    {
        $normalize = function (array $row) use (&$normalize): array {
            if (! array_is_list($row)) {
                ksort($row);
            }
            foreach ($row as &$item) {
                if (is_array($item)) {
                    $item = $normalize($item);
                }
            }
            unset($item);

            return $row;
        };

        return Contract::hash($normalize($value));
    }
}

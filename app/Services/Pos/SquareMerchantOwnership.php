<?php

declare(strict_types=1);

namespace App\Services\Pos;

/** One optional shared mutex; original settings and POS rows remain the owners. */
final class SquareMerchantOwnership
{
    private const MUTEX = 'square_customer_merchant_ownership_mutex';

    public function __construct(private PosStore $store) {}

    /** Call before writing an ownership binding, within its database transaction. */
    public function claim(int $tenant, string $merchant, string $environment): void
    {
        if ($this->store->db->transactionLevel() < 1) {
            throw new \LogicException('Square ownership needs a persistence transaction.');
        }
        $schema = $this->store->db->getSchemaBuilder();
        if (! $schema->hasTable('marketing_settings') || ! $schema->hasTable('tenant_marketing_settings')) {
            return; // Isolated Enterprise keeps its original POS ownership guards.
        }
        $this->store->db->table('marketing_settings')->insertOrIgnore(['key' => self::MUTEX, 'value' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->store->db->table('marketing_settings')->where('key', self::MUTEX)->lockForUpdate()->firstOrFail();
        abort_if($this->store->db->table('tenant_marketing_settings')->where('key', 'square_config')->where('tenant_id', '!=', $tenant)
            ->where('value->merchant_id', $merchant)->where(function ($query) use ($environment): void {
                $query->where('value->environment', $environment);
                if ($environment === 'production') {
                    $query->orWhereNull('value->environment');
                }
            })->lockForUpdate()->exists(), 409, 'This Square merchant is already bound to another workspace.');
        foreach (['pos_live_merchants', 'pos_live_connections'] as $table) {
            if ($schema->hasTable($table)) {
                $query = $this->store->db->table($table)->where('merchant_id', $merchant)->where('environment', $environment)->where('tenant_id', '!=', $tenant);
                $runtime = config('pos_live.runtime_id');
                if (is_string($runtime) && preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $runtime)) {
                    $query->where('runtime_id', $runtime);
                }
                abort_if($query->lockForUpdate()->exists(), 409, 'This Square merchant is already bound to another workspace.');
            }
        }
    }
}

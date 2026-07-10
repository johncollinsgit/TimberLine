<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_billing_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_customer_reference')->nullable();
            $table->string('provider_subscription_reference');
            $table->string('purchase_key', 100);
            $table->string('status', 50)->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('last_event_id')->nullable();
            $table->string('last_event_type')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['provider', 'provider_subscription_reference', 'purchase_key'],
                'tenant_billing_subscriptions_provider_purchase_unique'
            );
            $table->index(['tenant_id', 'status']);
        });

        $this->backfillExistingStripeMappings();
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_billing_subscriptions');
    }

    protected function backfillExistingStripeMappings(): void
    {
        if (! Schema::hasTable('tenant_commercial_overrides')) {
            return;
        }

        DB::table('tenant_commercial_overrides')
            ->select(['id', 'tenant_id', 'billing_mapping'])
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $mapping = is_string($row->billing_mapping ?? null)
                        ? json_decode((string) $row->billing_mapping, true)
                        : (array) ($row->billing_mapping ?? []);
                    $stripe = is_array(data_get($mapping, 'stripe')) ? (array) data_get($mapping, 'stripe') : [];
                    $subscription = trim((string) ($stripe['subscription_reference'] ?? ''));
                    if ($subscription === '') {
                        continue;
                    }

                    $purchaseKeys = [];
                    $planKey = strtolower(trim((string) ($stripe['confirmed_plan_key'] ?? '')));
                    if (array_key_exists($planKey, (array) config('module_catalog.plans', []))) {
                        $purchaseKeys[] = (string) data_get(config('module_catalog.plans.'.$planKey), 'purchase_key', 'plan.'.$planKey);
                    }
                    foreach ((array) ($stripe['confirmed_addon_keys'] ?? []) as $addonKey) {
                        $addonKey = strtolower(trim((string) $addonKey));
                        if (array_key_exists($addonKey, (array) config('module_catalog.addons', []))) {
                            $purchaseKeys[] = (string) data_get(config('module_catalog.addons.'.$addonKey), 'purchase_key', 'addon.'.$addonKey);
                        }
                    }

                    foreach (array_unique(array_filter($purchaseKeys)) as $purchaseKey) {
                        DB::table('tenant_billing_subscriptions')->insertOrIgnore([
                            'tenant_id' => (int) $row->tenant_id,
                            'provider' => 'stripe',
                            'provider_customer_reference' => $stripe['customer_reference'] ?? null,
                            'provider_subscription_reference' => $subscription,
                            'purchase_key' => $purchaseKey,
                            'status' => strtolower(trim((string) ($stripe['subscription_status'] ?? '')))
                                ?: (empty($stripe['billing_confirmed_at']) ? 'pending' : 'active'),
                            'last_event_id' => $stripe['last_webhook_event_id'] ?? null,
                            'last_event_type' => $stripe['last_webhook_event_type'] ?? null,
                            'metadata' => json_encode(['imported_from' => 'tenant_commercial_overrides'], JSON_THROW_ON_ERROR),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
    }
};

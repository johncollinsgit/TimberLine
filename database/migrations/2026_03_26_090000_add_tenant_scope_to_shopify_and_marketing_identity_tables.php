<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addTenantColumn('shopify_stores', 'id');
        $this->addTenantColumn('marketing_profiles', 'id');
        $this->addTenantColumn('marketing_profile_links', 'marketing_profile_id');
        $this->addTenantColumn('marketing_consent_requests', 'marketing_profile_id');
        $this->addTenantColumn('marketing_consent_events', 'marketing_profile_id');
        $this->addTenantColumn('customer_external_profiles', 'marketing_profile_id');
        $this->addTenantColumn('marketing_storefront_events', 'marketing_profile_id');

        $this->addTenantForeignKey('shopify_stores', 'shopify_stores_tenant_id_foreign');
        $this->addTenantForeignKey('marketing_profiles', 'marketing_profiles_tenant_id_foreign');
        $this->addTenantForeignKey('marketing_profile_links', 'marketing_profile_links_tenant_id_foreign');
        $this->addTenantForeignKey('marketing_consent_requests', 'marketing_consent_requests_tenant_id_foreign');
        $this->addTenantForeignKey('marketing_consent_events', 'marketing_consent_events_tenant_id_foreign');
        $this->addTenantForeignKey('customer_external_profiles', 'customer_external_profiles_tenant_id_foreign');
        $this->addTenantForeignKey('marketing_storefront_events', 'marketing_storefront_events_tenant_id_foreign');

        $this->updateMarketingProfileLinksUniqueIndex();
    }

    public function down(): void
    {
        $this->restoreMarketingProfileLinksUniqueIndex();

        $this->dropTenantColumn('marketing_storefront_events', 'marketing_storefront_events_tenant_id_foreign');
        $this->dropTenantColumn('customer_external_profiles', 'customer_external_profiles_tenant_id_foreign');
        $this->dropTenantColumn('marketing_consent_events', 'marketing_consent_events_tenant_id_foreign');
        $this->dropTenantColumn('marketing_consent_requests', 'marketing_consent_requests_tenant_id_foreign');
        $this->dropTenantColumn('marketing_profile_links', 'marketing_profile_links_tenant_id_foreign');
        $this->dropTenantColumn('marketing_profiles', 'marketing_profiles_tenant_id_foreign');
        $this->dropTenantColumn('shopify_stores', 'shopify_stores_tenant_id_foreign');
    }

    protected function addTenantColumn(string $table, ?string $after = null): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'tenant_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $tableBlueprint) use ($table, $after): void {
            $column = $tableBlueprint->unsignedBigInteger('tenant_id')->nullable();
            if ($after !== null) {
                $column->after($after);
            }

            $tableBlueprint->index('tenant_id', "{$table}_tenant_id_index");
        });
    }

    protected function addTenantForeignKey(string $table, string $foreignKey): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($foreignKey): void {
                $tableBlueprint->foreign('tenant_id', $foreignKey)
                    ->references('id')
                    ->on('tenants')
                    ->nullOnDelete();
            });
        } catch (\Throwable) {
            // Safe no-op for environments where the FK already exists.
        }
    }

    protected function dropTenantColumn(string $table, string $foreignKey): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($foreignKey): void {
                $tableBlueprint->dropForeign($foreignKey);
            });
        } catch (\Throwable) {
            // Safe no-op when FK was never created in a given environment.
        }

        try {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($table): void {
                $tableBlueprint->dropIndex("{$table}_tenant_id_index");
            });
        } catch (\Throwable) {
            // Safe no-op when index was never created in a given environment.
        }

        Schema::table($table, function (Blueprint $tableBlueprint): void {
            $tableBlueprint->dropColumn('tenant_id');
        });
    }

    protected function updateMarketingProfileLinksUniqueIndex(): void
    {
        if (! Schema::hasTable('marketing_profile_links')) {
            return;
        }

        try {
            Schema::table('marketing_profile_links', function (Blueprint $table): void {
                $table->dropUnique('mpl_source_unique_idx');
            });
        } catch (\Throwable) {
            // Safe no-op when the legacy index is absent.
        }

        try {
            Schema::table('marketing_profile_links', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'source_type', 'source_id'], 'mpl_tenant_source_unique_idx');
            });
        } catch (\Throwable) {
            // Safe no-op when the new index already exists.
        }
    }

    protected function restoreMarketingProfileLinksUniqueIndex(): void
    {
        if (! Schema::hasTable('marketing_profile_links')) {
            return;
        }

        try {
            Schema::table('marketing_profile_links', function (Blueprint $table): void {
                $table->dropUnique('mpl_tenant_source_unique_idx');
            });
        } catch (\Throwable) {
            // Safe no-op when the index is absent.
        }

        try {
            Schema::table('marketing_profile_links', function (Blueprint $table): void {
                $table->unique(['source_type', 'source_id'], 'mpl_source_unique_idx');
            });
        } catch (\Throwable) {
            // Safe no-op when legacy uniqueness cannot be restored automatically.
        }
    }
};


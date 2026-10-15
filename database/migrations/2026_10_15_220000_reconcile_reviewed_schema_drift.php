<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preflight every proposed constraint before the first durable DDL. A
        // concurrent conflict still fails the real constraint; no rows are
        // deleted, reassigned, deduplicated, or given fabricated ownership.
        $this->assertIntegrity();
        $this->preserveHistoricalTables();
        $this->repairDevelopmentColumns();
        $this->repairSubscriptionDefault();
        $this->retainDanglingDeliveryReferences();

        foreach ($this->indexes() as [$table, $name, $columns, $unique]) {
            $this->ensureIndex($table, $name, $columns, $unique);
        }
        foreach ($this->foreignKeys() as [$table, $name, $column, $target, $reference, $delete]) {
            $this->ensureForeignKey($table, $name, $column, $target, $reference, $delete);
        }
        // InnoDB may remove an automatically created supporting index when a
        // new unique key can serve the FK. Explicitly retain the reviewed old
        // ordinary indexes on both clean and upgraded schemas; never drop one.
        foreach ($this->preservedIndexes() as [$table, $name, $columns, $unique]) {
            $this->ensureIndex($table, $name, $columns, $unique);
        }
    }

    public function down(): void
    {
        // Forward-only reconciliation. Code rollback must preserve all rows,
        // historical tables, nullable ownership, and restored integrity checks.
    }

    private function preserveHistoricalTables(): void
    {
        // These exact tables survived a March migration later removed from
        // source. Keep them on upgrades and declare them on clean installs.
        if (! Schema::hasTable('collections')) {
            Schema::create('collections', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('seasonal_scents')) {
            Schema::create('seasonal_scents', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('scent_id');
                $table->string('season');
                $table->timestamps();
            });
        }
    }

    private function repairDevelopmentColumns(): void
    {
        foreach (['development_notes', 'development_change_logs'] as $tableName) {
            // Older global-admin rows have no provable tenant or Shopify actor.
            // NULL preserves that meaning; scoped application reads exclude it.
            if (! Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->unsignedBigInteger('tenant_id')->nullable());
            } else {
                $column = $this->column($tableName, 'tenant_id');
                $this->assertReviewedType($tableName, $column, ['bigint unsigned']);
                if (! $column['nullable']) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->unsignedBigInteger('tenant_id')->nullable()->change());
                }
            }
            foreach (['shopify_admin_user_id' => 190, 'shopify_admin_email' => 255] as $name => $length) {
                if (! Schema::hasColumn($tableName, $name)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->string($name, $length)->nullable());
                } else {
                    $column = $this->column($tableName, $name);
                    $this->assertReviewedType($tableName, $column, ['varchar('.$length.')']);
                    if (! $column['nullable']) {
                        throw new RuntimeException('Unexpected required actor column: '.$tableName.'.'.$name);
                    }
                }
            }
        }

        $title = $this->column('development_notes', 'title');
        $this->assertReviewedType('development_notes', $title, ['varchar(180)']);
        if (! $title['nullable']) {
            Schema::table('development_notes', fn (Blueprint $table) => $table->string('title', 180)->nullable()->change());
        }
        $area = $this->column('development_change_logs', 'area');
        $this->assertReviewedType('development_change_logs', $area, ['varchar(120)', 'varchar(180)']);
        if (DB::getDriverName() !== 'mysql' || $area['type'] !== 'varchar(180)' || ! $area['nullable']) {
            Schema::table('development_change_logs', fn (Blueprint $table) => $table->string('area', 180)->nullable()->change());
        }
    }

    private function repairSubscriptionDefault(): void
    {
        // A historical edit changed CREATE's default, but its later data update
        // never altered the live default. Change only future inserts, not saved
        // customer settings (including explicit zero/one values).
        $column = $this->column('subscription_candle_club_settings', 'allowed_pauses_per_commitment');
        $this->assertReviewedType('subscription_candle_club_settings', $column, ['int unsigned']);
        $default = (string) $column['default'];
        if (DB::getDriverName() === 'sqlite') {
            // SQLite reports numeric defaults as quoted SQL literals.
            $default = trim($default, "'\"");
        }
        if (! in_array($default, ['1', '2'], true)) {
            throw new RuntimeException('Unexpected subscription pause default; review before reconciliation.');
        }
        if ($default !== '2') {
            Schema::table('subscription_candle_club_settings', fn (Blueprint $table) => $table->unsignedInteger('allowed_pauses_per_commitment')->default(2)->change());
        }
    }

    private function assertIntegrity(): void
    {
        foreach (array_merge($this->indexes(), $this->preservedIndexes()) as [$table, $name, $columns, $unique]) {
            if (! Schema::hasTable($table)) {
                if (in_array($table, ['collections', 'seasonal_scents'], true)) {
                    continue;
                }
                throw new RuntimeException('Required reconciliation table is missing: '.$table);
            }
            if (! Schema::hasColumns($table, $columns)) {
                // Only the reviewed new development ownership column may not
                // exist yet; it is added as NULL for every retained row.
                if (in_array($table, ['development_notes', 'development_change_logs'], true)
                    && in_array('tenant_id', $columns, true)
                    && ! Schema::hasColumn($table, 'tenant_id')) {
                    continue;
                }
                throw new RuntimeException('Required reconciliation index columns are missing: '.$table.'.'.$name);
            }
            if ($this->indexExists($table, $name, $columns, $unique) || ! $unique) {
                continue;
            }
            $query = DB::table($table);
            foreach ($columns as $column) {
                $query->whereNotNull($column);
            }
            if ($query->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->limit(1)->exists()) {
                throw new RuntimeException('Reconciliation blocked by duplicate key groups: '.$table.'.'.$name);
            }
        }
        foreach ($this->foreignKeys() as [$table, $name, $column, $target, $reference, $delete]) {
            if (! Schema::hasTable($table)) {
                if ($table === 'seasonal_scents') {
                    continue;
                }
                throw new RuntimeException('Required reconciliation table is missing: '.$table);
            }
            if (! Schema::hasColumn($table, $column)) {
                if (in_array($table, ['development_notes', 'development_change_logs'], true) && $column === 'tenant_id') {
                    continue;
                }
                throw new RuntimeException('Required reconciliation reference column is missing: '.$table.'.'.$column);
            }
            if (! Schema::hasColumn($target, $reference)) {
                throw new RuntimeException('Required reconciliation reference target is missing: '.$target.'.'.$reference);
            }
            if ($this->foreignKeyExists($table, $name, $column, $target, $reference, $delete)) {
                continue;
            }
            if (DB::table($table.' as source')->leftJoin($target.' as target', 'source.'.$column, '=', 'target.'.$reference)
                ->whereNotNull('source.'.$column)->whereNull('target.'.$reference)->exists()) {
                if ($table === 'marketing_email_deliveries' && $column === 'marketing_profile_id') {
                    // Preserve the original dangling ID before applying this
                    // nullable relationship's intended ON DELETE SET NULL.
                    $this->assertDeliveryReferenceEvidence();

                    continue;
                }
                throw new RuntimeException('Reconciliation blocked by orphan references: '.$table.'.'.$name);
            }
        }
    }

    private function danglingDeliveries(): \Illuminate\Database\Query\Builder
    {
        return DB::table('marketing_email_deliveries')
            ->whereNotNull('marketing_profile_id')
            ->whereNotExists(fn (\Illuminate\Database\Query\Builder $query) => $query
                ->selectRaw('1')->from('marketing_profiles')
                ->whereColumn('marketing_profiles.id', 'marketing_email_deliveries.marketing_profile_id'));
    }

    private function assertDeliveryReferenceEvidence(): void
    {
        if (! Schema::hasColumn('marketing_email_deliveries', 'legacy_marketing_profile_id')) {
            return;
        }
        $column = $this->column('marketing_email_deliveries', 'legacy_marketing_profile_id');
        $this->assertReviewedType('marketing_email_deliveries', $column, ['bigint unsigned']);
        if (! $column['nullable']) {
            throw new RuntimeException('Unexpected required delivery reference evidence column.');
        }
        if ($this->danglingDeliveries()->whereNotNull('legacy_marketing_profile_id')
            ->whereColumn('legacy_marketing_profile_id', '<>', 'marketing_profile_id')->exists()) {
            throw new RuntimeException('Reconciliation blocked by conflicting retained delivery reference evidence.');
        }
    }

    private function retainDanglingDeliveryReferences(): void
    {
        if (! Schema::hasColumn('marketing_email_deliveries', 'legacy_marketing_profile_id')) {
            Schema::table('marketing_email_deliveries', fn (Blueprint $table) => $table->unsignedBigInteger('legacy_marketing_profile_id')->nullable());
        }
        $this->assertDeliveryReferenceEvidence();
        DB::transaction(function (): void {
            $this->danglingDeliveries()->select(['id', 'marketing_profile_id', 'legacy_marketing_profile_id'])
                ->orderBy('id')->lockForUpdate()->chunkById(100, function ($rows): void {
                    foreach ($rows as $row) {
                        if ($row->legacy_marketing_profile_id !== null
                            && (string) $row->legacy_marketing_profile_id !== (string) $row->marketing_profile_id) {
                            throw new RuntimeException('Reconciliation blocked by conflicting retained delivery reference evidence.');
                        }
                        // Literal values from a locked row avoid MySQL assignment
                        // ordering dependencies. Query Builder emits no model,
                        // workflow, email or provider events and does not change
                        // timestamps, status, recipient, content, or provider IDs.
                        $this->danglingDeliveries()->where('id', $row->id)
                            ->where('marketing_profile_id', $row->marketing_profile_id)
                            ->update(['legacy_marketing_profile_id' => $row->marketing_profile_id, 'marketing_profile_id' => null]);
                    }
                }, 'id');
        });
    }

    private function ensureIndex(string $table, string $name, array $columns, bool $unique): void
    {
        if ($this->indexExists($table, $name, $columns, $unique)) {
            return;
        }
        Schema::table($table, function (Blueprint $blueprint) use ($name, $columns, $unique): void {
            if ($unique) {
                $blueprint->unique($columns, $name);
            } else {
                $blueprint->index($columns, $name);
            }
        });
    }

    private function indexExists(string $table, string $name, array $columns, bool $unique): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] !== $name) {
                continue;
            }
            if ($index['columns'] !== $columns || $index['unique'] !== $unique || $index['primary']) {
                throw new RuntimeException('Unexpected existing reconciliation index definition: '.$table.'.'.$name);
            }
            if (DB::getDriverName() === 'mysql' && DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())->where('table_name', $table)->where('index_name', $name)
                ->whereNotNull('sub_part')->exists()) {
                throw new RuntimeException('Unexpected prefix in reconciliation index: '.$table.'.'.$name);
            }

            return true;
        }

        return false;
    }

    private function ensureForeignKey(string $table, string $name, string $column, string $target, string $reference, string $delete): void
    {
        if ($this->foreignKeyExists($table, $name, $column, $target, $reference, $delete)) {
            return;
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column, $name)->references($reference)->on($target)->onDelete($delete));
    }

    private function foreignKeyExists(string $table, string $name, string $column, string $target, string $reference, string $delete): bool
    {
        foreach (Schema::getForeignKeys($table) as $key) {
            $same = $key['columns'] === [$column] && $key['foreign_table'] === $target && $key['foreign_columns'] === [$reference];
            if (($key['name'] ?? null) !== $name) {
                // SQLite does not retain Laravel's FK names consistently.
                if (DB::getDriverName() !== 'mysql' && $same && strtolower($key['on_delete']) === $delete) {
                    return true;
                }

                continue;
            }
            if (! $same || strtolower($key['on_delete']) !== $delete) {
                throw new RuntimeException('Unexpected existing reconciliation foreign key: '.$table.'.'.$name);
            }

            return true;
        }

        return false;
    }

    private function column(string $table, string $name): array
    {
        foreach (Schema::getColumns($table) as $column) {
            if ($column['name'] === $name) {
                return $column;
            }
        }
        throw new RuntimeException('Required reconciliation column is missing: '.$table.'.'.$name);
    }

    private function assertReviewedType(string $table, array $column, array $allowed): void
    {
        if (DB::getDriverName() === 'mysql' && ! in_array($column['type'], $allowed, true)) {
            throw new RuntimeException('Unexpected reconciliation column definition: '.$table.'.'.$column['name']);
        }
    }

    /** @return array<int, array{string, string, array<int, string>, bool}> */
    private function indexes(): array
    {
        return [
            ['customer_loop_activities', 'customer_loop_activities_event_key_unique', ['event_key'], true],
            ['development_change_logs', 'development_change_logs_tenant_created_idx', ['tenant_id', 'created_at'], false],
            ['development_notes', 'development_notes_tenant_updated_idx', ['tenant_id', 'updated_at'], false],
            ['everbranch_mobile_push_devices', 'eb_push_user_enabled_idx', ['user_id', 'notifications_enabled'], false],
            ['everbranch_mobile_push_devices', 'everbranch_mobile_push_devices_device_token_hash_unique', ['device_token_hash'], true],
            ['field_service_job_note_mentions', 'fs_job_note_mention_unique', ['field_service_job_note_id', 'user_id'], true],
            ['field_service_job_note_mentions', 'fs_note_mention_user_fk', ['user_id'], false],
            ['google_business_profile_connections', 'gbp_connections_approval_idx', ['project_approval_status'], false],
            ['google_business_profile_connections', 'gbp_connections_connected_idx', ['connected_at'], false],
            ['google_business_profile_connections', 'gbp_connections_error_at_idx', ['last_error_at'], false],
            ['google_business_profile_connections', 'gbp_connections_error_idx', ['last_error_code'], false],
            ['google_business_profile_connections', 'gbp_connections_synced_idx', ['last_synced_at'], false],
            ['google_business_profile_locations', 'gbp_locations_account_idx', ['account_id'], false],
            ['google_business_profile_locations', 'gbp_locations_connection_location_unique', ['google_business_profile_connection_id', 'location_name'], true],
            ['google_business_profile_locations', 'gbp_locations_location_idx', ['location_id'], false],
            ['google_business_profile_locations', 'gbp_locations_seen_idx', ['last_seen_at'], false],
            ['google_business_profile_locations', 'gbp_locations_selected_at_idx', ['selected_at'], false],
            ['google_business_profile_locations', 'gbp_locations_selected_idx', ['is_selected'], false],
            ['marketing_email_deliveries', 'marketing_email_deliveries_email_index', ['email'], false],
            ['marketing_email_deliveries', 'marketing_email_deliveries_marketing_profile_id_foreign', ['marketing_profile_id'], false],
            ['marketing_email_deliveries', 'marketing_email_deliveries_sendgrid_message_id_index', ['sendgrid_message_id'], false],
            ['marketing_email_deliveries', 'marketing_email_deliveries_status_index', ['status'], false],
            ['marketing_email_deliveries', 'med_recipient_status_idx', ['marketing_campaign_recipient_id', 'status'], false],
            ['marketing_group_import_rows', 'marketing_group_import_rows_external_key_index', ['external_key'], false],
            ['marketing_group_import_rows', 'marketing_group_import_rows_marketing_profile_id_foreign', ['marketing_profile_id'], false],
            ['marketing_group_import_rows', 'marketing_group_import_rows_row_number_index', ['row_number'], false],
            ['marketing_group_import_rows', 'marketing_group_import_rows_status_index', ['status'], false],
            ['marketing_group_import_rows', 'mgir_import_run_fk', ['marketing_group_import_run_id'], false],
            ['marketing_message_engagement_events', 'marketing_message_engagement_events_channel_index', ['channel'], false],
            ['marketing_message_engagement_events', 'marketing_message_engagement_events_event_hash_unique', ['event_hash'], true],
            ['marketing_message_engagement_events', 'marketing_message_engagement_events_event_type_index', ['event_type'], false],
            ['marketing_message_engagement_events', 'marketing_message_engagement_events_occurred_at_index', ['occurred_at'], false],
            ['marketing_message_engagement_events', 'marketing_message_engagement_events_provider_event_id_index', ['provider_event_id'], false],
            ['marketing_message_engagement_events', 'marketing_message_engagement_events_provider_index', ['provider'], false],
            ['marketing_message_engagement_events', 'marketing_message_engagement_events_provider_message_id_index', ['provider_message_id'], false],
            ['marketing_message_engagement_events', 'marketing_message_engagement_events_store_key_index', ['store_key'], false],
            ['marketing_message_engagement_events', 'marketing_message_engagement_events_tenant_id_index', ['tenant_id'], false],
            ['marketing_message_engagement_events', 'marketing_message_engagement_events_url_domain_index', ['url_domain'], false],
            ['marketing_message_engagement_events', 'mm_eng_evt_email_delivery_fk', ['marketing_email_delivery_id'], false],
            ['marketing_message_engagement_events', 'mm_eng_evt_msg_delivery_fk', ['marketing_message_delivery_id'], false],
            ['marketing_message_engagement_events', 'mm_eng_evt_profile_fk', ['marketing_profile_id'], false],
            ['marketing_message_engagement_events', 'mm_engagement_tenant_email_type_idx', ['tenant_id', 'marketing_email_delivery_id', 'event_type'], false],
            ['marketing_message_engagement_events', 'mm_engagement_tenant_profile_type_time_idx', ['tenant_id', 'marketing_profile_id', 'event_type', 'occurred_at'], false],
            ['marketing_message_engagement_events', 'mm_engagement_tenant_store_type_time_idx', ['tenant_id', 'store_key', 'event_type', 'occurred_at'], false],
            ['marketing_message_group_members', 'marketing_message_group_members_email_index', ['email'], false],
            ['marketing_message_group_members', 'marketing_message_group_members_marketing_profile_id_foreign', ['marketing_profile_id'], false],
            ['marketing_message_group_members', 'marketing_message_group_members_normalized_phone_index', ['normalized_phone'], false],
            ['marketing_message_group_members', 'marketing_message_group_members_source_type_index', ['source_type'], false],
            ['marketing_message_group_members', 'mmgm_group_phone_idx', ['marketing_message_group_id', 'normalized_phone'], false],
            ['marketing_message_group_members', 'mmgm_group_profile_idx', ['marketing_message_group_id', 'marketing_profile_id'], false],
            ['marketing_message_order_attributions', 'marketing_message_order_attributions_attribution_model_index', ['attribution_model'], false],
            ['marketing_message_order_attributions', 'marketing_message_order_attributions_channel_index', ['channel'], false],
            ['marketing_message_order_attributions', 'marketing_message_order_attributions_click_occurred_at_index', ['click_occurred_at'], false],
            ['marketing_message_order_attributions', 'marketing_message_order_attributions_order_occurred_at_index', ['order_occurred_at'], false],
            ['marketing_message_order_attributions', 'marketing_message_order_attributions_store_key_index', ['store_key'], false],
            ['marketing_message_order_attributions', 'marketing_message_order_attributions_tenant_id_index', ['tenant_id'], false],
            ['marketing_message_order_attributions', 'mm_ord_attr_email_delivery_fk', ['marketing_email_delivery_id'], false],
            ['marketing_message_order_attributions', 'mm_ord_attr_engagement_event_fk', ['marketing_message_engagement_event_id'], false],
            ['marketing_message_order_attributions', 'mm_ord_attr_profile_fk', ['marketing_profile_id'], false],
            ['marketing_message_order_attributions', 'mm_order_attribution_order_unique', ['tenant_id', 'store_key', 'order_id', 'attribution_model'], true],
            ['marketing_message_order_attributions', 'mm_order_attribution_tenant_event_idx', ['tenant_id', 'marketing_message_engagement_event_id'], false],
            ['marketing_message_order_attributions', 'mm_order_attribution_tenant_store_delivery_idx', ['tenant_id', 'store_key', 'marketing_email_delivery_id'], false],
            ['marketing_review_summaries', 'marketing_review_summaries_external_customer_email_index', ['external_customer_email'], false],
            ['marketing_review_summaries', 'marketing_review_summaries_external_customer_id_index', ['external_customer_id'], false],
            ['marketing_review_summaries', 'marketing_review_summaries_integration_index', ['integration'], false],
            ['marketing_review_summaries', 'marketing_review_summaries_last_reviewed_at_index', ['last_reviewed_at'], false],
            ['marketing_review_summaries', 'marketing_review_summaries_provider_index', ['provider'], false],
            ['marketing_review_summaries', 'marketing_review_summaries_source_synced_at_index', ['source_synced_at'], false],
            ['marketing_review_summaries', 'marketing_review_summaries_store_key_index', ['store_key'], false],
            ['marketing_timing_insights', 'marketing_timing_insights_channel_index', ['channel'], false],
            ['marketing_timing_insights', 'marketing_timing_insights_event_context_index', ['event_context'], false],
            ['marketing_timing_insights', 'marketing_timing_insights_objective_index', ['objective'], false],
            ['marketing_timing_insights', 'marketing_timing_insights_segment_key_index', ['segment_key'], false],
            ['tenant_billing_refunds', 'billing_refunds_receipt_created_idx', ['tenant_billing_receipt_id', 'created_at'], false],
            ['tenant_candle_cash_reward_overrides', 'tenant_cc_reward_override_reward_fk', ['candle_cash_reward_id'], false],
            ['tenant_candle_cash_reward_overrides', 'tenant_cc_reward_overrides_tenant_active_idx', ['tenant_id', 'is_active'], false],
            ['tenant_candle_cash_reward_overrides', 'tenant_cc_reward_overrides_tenant_reward_unique', ['tenant_id', 'candle_cash_reward_id'], true],
            ['tenant_messaging_sender_profiles', 'tenant_sender_profiles_default_idx', ['tenant_id', 'channel', 'is_default'], false],
            ['tenant_messaging_sender_profiles', 'tenant_sender_profiles_tenant_store_from_unique', ['tenant_id', 'store_key', 'from_email'], true],
            ['tenant_messaging_sender_profiles', 'tm_sender_account_fk', ['tenant_messaging_account_id'], false],
            ['tenant_onboarding_blueprint_provisionings', 'onb_bp_prov_creator_fk', ['created_by_user_id'], false],
            ['tenant_onboarding_blueprint_provisionings', 'tenant_onboarding_blueprint_provisionings_blueprint_unique', ['source_blueprint_id'], true],
            ['tenant_onboarding_blueprint_provisionings', 'tenant_onboarding_blueprint_provisionings_provisioned_idx', ['provisioned_tenant_id'], false],
            ['website_inventory_reservations', 'website_inventory_reservations_expires_at_index', ['expires_at'], false],
            ['website_inventory_reservations', 'website_inventory_reservations_status_index', ['status'], false],
            ['collections', 'collections_name_unique', ['name'], true],
            ['seasonal_scents', 'seasonal_scents_scent_id_season_unique', ['scent_id', 'season'], true],
        ];
    }

    /** @return array<int, array{string, string, string, string, string, string}> */
    private function foreignKeys(): array
    {
        return [
            ['development_change_logs', 'development_change_logs_tenant_id_foreign', 'tenant_id', 'tenants', 'id', 'cascade'],
            ['development_notes', 'development_notes_tenant_id_foreign', 'tenant_id', 'tenants', 'id', 'cascade'],
            ['field_service_job_note_mentions', 'fs_note_mention_note_fk', 'field_service_job_note_id', 'field_service_job_notes', 'id', 'cascade'],
            ['field_service_job_note_mentions', 'fs_note_mention_user_fk', 'user_id', 'users', 'id', 'cascade'],
            ['google_business_profile_locations', 'gbp_locations_connection_fk', 'google_business_profile_connection_id', 'google_business_profile_connections', 'id', 'cascade'],
            ['marketing_email_deliveries', 'marketing_email_deliveries_marketing_profile_id_foreign', 'marketing_profile_id', 'marketing_profiles', 'id', 'set null'],
            ['marketing_email_deliveries', 'med_recipient_fk', 'marketing_campaign_recipient_id', 'marketing_campaign_recipients', 'id', 'set null'],
            ['marketing_group_import_rows', 'marketing_group_import_rows_marketing_profile_id_foreign', 'marketing_profile_id', 'marketing_profiles', 'id', 'set null'],
            ['marketing_group_import_rows', 'mgir_import_run_fk', 'marketing_group_import_run_id', 'marketing_group_import_runs', 'id', 'cascade'],
            ['marketing_message_engagement_events', 'mm_eng_evt_email_delivery_fk', 'marketing_email_delivery_id', 'marketing_email_deliveries', 'id', 'set null'],
            ['marketing_message_engagement_events', 'mm_eng_evt_msg_delivery_fk', 'marketing_message_delivery_id', 'marketing_message_deliveries', 'id', 'set null'],
            ['marketing_message_engagement_events', 'mm_eng_evt_profile_fk', 'marketing_profile_id', 'marketing_profiles', 'id', 'set null'],
            ['marketing_message_engagement_events', 'mm_engagement_events_tenant_fk', 'tenant_id', 'tenants', 'id', 'set null'],
            ['marketing_message_group_members', 'marketing_message_group_members_marketing_profile_id_foreign', 'marketing_profile_id', 'marketing_profiles', 'id', 'set null'],
            ['marketing_message_group_members', 'mmgm_message_group_fk', 'marketing_message_group_id', 'marketing_message_groups', 'id', 'cascade'],
            ['marketing_message_order_attributions', 'mm_ord_attr_email_delivery_fk', 'marketing_email_delivery_id', 'marketing_email_deliveries', 'id', 'set null'],
            ['marketing_message_order_attributions', 'mm_ord_attr_engagement_event_fk', 'marketing_message_engagement_event_id', 'marketing_message_engagement_events', 'id', 'set null'],
            ['marketing_message_order_attributions', 'mm_ord_attr_profile_fk', 'marketing_profile_id', 'marketing_profiles', 'id', 'set null'],
            ['marketing_message_order_attributions', 'mm_order_attribution_tenant_fk', 'tenant_id', 'tenants', 'id', 'set null'],
            ['tenant_candle_cash_reward_overrides', 'tenant_cc_reward_override_reward_fk', 'candle_cash_reward_id', 'candle_cash_rewards', 'id', 'cascade'],
            ['tenant_messaging_sender_profiles', 'tm_sender_account_fk', 'tenant_messaging_account_id', 'tenant_messaging_accounts', 'id', 'set null'],
            ['tenant_onboarding_blueprint_provisionings', 'onb_bp_prov_creator_fk', 'created_by_user_id', 'users', 'id', 'set null'],
            ['tenant_onboarding_blueprint_provisionings', 'onb_bp_prov_prov_tenant_fk', 'provisioned_tenant_id', 'tenants', 'id', 'set null'],
            ['seasonal_scents', 'seasonal_scents_scent_id_foreign', 'scent_id', 'scents', 'id', 'cascade'],
        ];
    }

    /** @return array<int, array{string, string, array<int, string>, bool}> */
    private function preservedIndexes(): array
    {
        return [
            ['development_change_logs', 'development_change_logs_created_at_index', ['created_at'], false],
            ['development_notes', 'development_notes_updated_at_index', ['updated_at'], false],
            ['everbranch_mobile_push_devices', 'everbranch_mobile_push_devices_user_id_foreign', ['user_id'], false],
            ['tenant_billing_refunds', 'tenant_billing_refunds_tenant_billing_receipt_id_foreign', ['tenant_billing_receipt_id'], false],
            ['tenant_candle_cash_reward_overrides', 'tenant_candle_cash_reward_overrides_tenant_id_foreign', ['tenant_id'], false],
            ['tenant_messaging_sender_profiles', 'tenant_messaging_sender_profiles_tenant_id_foreign', ['tenant_id'], false],
            ['tenant_onboarding_blueprint_provisionings', 'onb_bp_prov_src_bp_fk', ['source_blueprint_id'], false],
        ];
    }
};

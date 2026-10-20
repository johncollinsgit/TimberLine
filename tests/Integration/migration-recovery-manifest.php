<?php

declare(strict_types=1);

return [
    '2026_10_20_140000_add_marketplace_markets_and_reporting.php' => [
        'test' => 'tests/Integration/MarketplaceMySqlRecoveryTest.php',
        'scenarios' => ['market, participation, encrypted maker details and storefront sharing survive partial table/index repair'],
    ],
    '2026_10_20_130000_add_native_marketplace_store.php' => [
        'test' => 'tests/Integration/MarketplaceMySqlRecoveryTest.php',
        'scenarios' => ['native merchant and payment evidence survive interrupted table, nullable-column, and named-index repair'],
    ],
    '2026_10_20_120000_create_marketplace_tables.php' => [
        'test' => 'tests/Integration/MarketplaceMySqlRecoveryTest.php',
        'scenarios' => ['early tables retained before trailing marketplace tables', 'all tables retained without named indexes', 'encrypted order and webhook sentinels survive repeated repair', 'conflicting unique data stops without deleting evidence'],
    ],
    '2026_10_20_020000_create_website_discounts.php' => [
        'test' => 'tests/Integration/WebsiteDiscountMySqlRecoveryTest.php',
        'scenarios' => ['discount columns and saved rows retained before unique/site indexes and foreign keys; completed DDL replayed before migration bookkeeping'],
    ],

    '2026_10_20_010000_create_team_operations_tables.php' => [
        'test' => 'tests/Integration/TeamWorkMySqlMigrationRecoveryTest.php',
        'scenarios' => ['areas and encrypted records retained before remaining operation tables', 'retained tables missing named uniqueness/feed indexes', 'repeat completed DDL before migration bookkeeping'],
    ],
    '2026_10_19_010000_create_team_user_connections.php' => [
        'test' => 'tests/Integration/TeamWorkMySqlMigrationRecoveryTest.php',
        'scenarios' => ['connection table and encrypted rows retained before unique index', 'repeat after completed DDL before migration bookkeeping'],
    ],
    '2026_10_17_040000_create_team_file_uploads.php' => [
        'test' => 'tests/Integration/TeamWorkMySqlMigrationRecoveryTest.php',
        'scenarios' => ['upload table retained before chunks', 'upload uniqueness or chunk primary key missing'],
    ],
    '2026_10_17_010000_create_team_work_tables.php' => [
        'test' => 'tests/Integration/TeamWorkMySqlMigrationRecoveryTest.php',
        'scenarios' => ['early tables retained before later tables', 'retained table missing task index or member primary key'],
    ],
    '2026_10_17_020000_create_team_inbox_table.php' => [
        'test' => 'tests/Integration/TeamWorkMySqlMigrationRecoveryTest.php',
        'scenarios' => ['Inbox table retained before event uniqueness and recipient index'],
    ],
    '2026_10_17_030000_create_team_work_versions_table.php' => [
        'test' => 'tests/Integration/TeamWorkMySqlMigrationRecoveryTest.php',
        'scenarios' => ['native version table retained before composite primary key'],
    ],
    '2026_10_16_120000_create_subscription_recharge_evidence.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['early connection retained before trailing tables', 'bare tables and encrypted evidence retained before every named index and foreign key', 'partial constraints repaired repeatedly without changing accepted generations', 'wrong-tenant and wrong-connection credential and operation parents rejected', 'duplicate generation or request and orphan scope fail without deleting evidence', 'same-named changed index or referential action fails closed', 'concurrent operation UUID dedupe with recovered fenced lease and immutable request replay'],
    ],
    '2026_10_16_000000_create_ai_branch_accounts.php' => [
        'test' => 'tests/Integration/AiBranchMySqlMigrationRecoveryTest.php',
        'scenarios' => ['completed table and encrypted sentinel retained before migration bookkeeping'],
    ],
    '2026_10_16_000001_create_ai_branch_conversations.php' => [
        'test' => 'tests/Integration/AiBranchMySqlMigrationRecoveryTest.php',
        'scenarios' => ['completed table and encrypted sentinel retained before migration bookkeeping'],
    ],
    '2026_10_16_000002_create_ai_branch_memories.php' => [
        'test' => 'tests/Integration/AiBranchMySqlMigrationRecoveryTest.php',
        'scenarios' => ['completed table and encrypted sentinel retained before migration bookkeeping'],
    ],
    '2026_10_16_000003_create_ai_branch_actions.php' => [
        'test' => 'tests/Integration/AiBranchMySqlMigrationRecoveryTest.php',
        'scenarios' => ['completed table and encrypted sentinel retained before migration bookkeeping'],
    ],
    '2026_10_15_235000_create_subscription_provider_evidence.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['early installation retained before trailing tables', 'all-column bare tables with encrypted sentinel before every FK/index', 'partial constraints repaired twice with unchanged evidence and uint64 precision', 'conflicting duplicates or orphan scope fails without deleting evidence', 'same-named changed referential action fails closed', 'concurrent UUID and delivery dedupe with lost scalar after-commit dispatch remains recoverable'],
    ],
    '2026_10_15_220000_reconcile_reviewed_schema_drift.php' => [
        'test' => 'tests/Integration/ReviewedSchemaDriftRecoveryTest.php',
        'scenarios' => ['exact observed production DDL with historical rows and unowned development notes', 'earlier repairs retained before missing unique and foreign key repair', 'archival column retained after an atomic delivery-reference copy rolls back', 'conflicting evidence and unrelated duplicate/orphan conflicts stop before DDL', 'clean and observed schemas converge without production name or column-order rewrites'],
    ],
    '2026_10_15_230000_create_website_catalog_source_mappings.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['bare native catalog source table and encrypted sentinel retained before every named FK/index ALTER', 'earlier source mapping keys retained before trailing index repair'],
    ],
    '2026_10_15_210000_create_website_order_notifications.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['bare native notification table and encrypted sentinel retained before every named FK/index ALTER', 'earlier native notification constraints retained before final index repair'],
    ],
    '2026_10_13_220000_create_soccer_workspace.php' => [
        'test' => 'tests/Integration/SoccerMySqlMigrationRecoveryTest.php',
        'scenarios' => ['family and season tables retained before remaining soccer tables'],
    ],
    '2026_10_06_170000_create_tenant_mailboxes.php' => [
        'test' => 'tests/Integration/TenantMailMySqlMigrationRecoveryTest.php',
        'scenarios' => ['domain, mailbox, and grant tables retained before message table creation'],
    ],
    '2026_10_02_190000_repair_fundraiser_encrypted_column_storage.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['first encrypted fundraiser field converted before the remaining fields'],
    ],
    '2026_10_02_120000_repair_website_encrypted_column_storage.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['first encrypted Website column converted before the remaining JSON columns'],
    ],
    '2026_09_14_120000_create_trajectory_tables.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['finance account tables retained before notification and valuation tables'],
    ],
    '2026_03_12_090000_add_marketing_groups_and_addresses.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['earlier marketing tables retained before import-row table creation'],
    ],
    '2026_03_16_090000_create_customer_external_profiles_table.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['clean retry after MySQL rejects an over-wide composite key'],
    ],
    '2026_03_19_090000_create_marketing_message_group_tables.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['message-group table retained before member-table creation'],
    ],
    '2026_03_30_100000_create_tenant_rewards_editor_isolation_tables.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['earlier reward settings tables retained before reward overrides'],
    ],
    '2026_07_20_230000_create_tenant_billing_refunds_table.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['clean retry after MySQL rejects the table identifier'],
    ],
    '2026_07_24_120000_add_workflow_studio_v2_foundation.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['partial additive columns and indexes', 'trailing table not created'],
    ],
    '2026_07_27_180000_create_website_commerce_tables.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['reservation table retained before foreign keys and indexes'],
    ],
    '2026_07_27_181000_repair_partial_website_commerce_schema.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['repair is safe after the original migration resumes'],
    ],
    '2026_08_07_180000_create_customer_loop_tables.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['first table retained', 'second table retained without trailing index'],
    ],
    '2026_08_07_190000_add_commerce_operations_and_shipping_foundation.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['first table retained', 'shipping-rate table retained before named foreign key'],
    ],
    '2026_08_11_120000_create_wholesale_email_messenger_drafts_table.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['draft table retained before a migration retry'],
    ],
    '2026_08_13_150000_add_completion_state_to_modern_forestry_mobile_bag_snapshots.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['first completion-state column retained before the second column is added'],
    ],
    '2026_08_13_160000_create_field_workforce_and_fleet_tracking_tables.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['workforce settings table retained before shifts, correction requests, and fleet location tables'],
    ],
    '2026_08_19_140000_create_modern_forestry_fundraiser_invoice_preparation_tables.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['encrypted fundraiser order table retained before accounting-review package table creation'],
    ],
    '2026_08_20_120000_add_reporting_destination_fields_to_orders_table.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['first reporting destination column retained before trailing delivery-address columns'],
    ],
    '2026_08_24_120000_add_archival_to_marketing_profiles.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['archival column retained before Laravel records the migration batch'],
    ],
    '2026_08_25_150000_add_job_update_sms_setting_to_field_service_reminder_settings.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['job-update SMS setting column retained before Laravel records the migration batch'],
    ],
    '2026_09_03_150000_add_quickbooks_source_to_customer_equipment.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['QuickBooks equipment source columns retained before unique index creation'],
    ],
    '2026_09_03_200000_add_requester_to_field_service_materials.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['requester column retained before its index and foreign key are added'],
    ],
    '2026_09_03_201000_add_time_hours_reporting_index.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['incomplete time-session source columns skipped safely and reporting index retained before Laravel records the migration batch'],
    ],
    '2026_09_05_180000_create_field_service_crew_statuses_table.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['crew-status table retained before the shared tenant AI usage ledger is created'],
    ],
    '2026_09_05_210000_add_owner_approval_to_fleet_tracking_settings.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['first owner-approval column retained before the remaining approval fields and foreign key'],
    ],
    '2026_09_21_210000_create_website_collections.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['collection table retained before membership table creation'],
    ],
    '2026_09_30_180000_add_quickbooks_delivery_to_fundraiser_invoice_packages.php' => [
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
        'scenarios' => ['partial QuickBooks delivery columns retained before the remaining columns and unique index'],
    ],
    '2026_10_04_220000_create_highlevel_fleet_tables.php' => [
        'test' => 'tests/Integration/HighLevelFleetMySqlMigrationRecoveryTest.php',
        'scenarios' => ['authorization table retained before installation tables', 'device connection column retained before its index'],
    ],
    '2026_10_08_000000_create_fleet_operation_records.php' => [
        'test' => 'tests/Integration/FleetOperationsMySqlMigrationRecoveryTest.php',
        'scenarios' => ['operation table retained before Laravel records the migration batch'],
    ],
    '2026_10_16_100000_create_marketing_brand_knowledge_versions.php' => [
        'test' => 'tests/Integration/MarketingDeskMySqlRecoveryTest.php',
        'scenarios' => ['table retained before tenant and unique indexes; completed DDL and saved rows retained before migration bookkeeping'],
    ],
    '2026_10_16_100001_create_marketing_reply_policies.php' => [
        'test' => 'tests/Integration/MarketingDeskMySqlRecoveryTest.php',
        'scenarios' => ['table retained before tenant and unique indexes; completed DDL and saved rows retained before migration bookkeeping'],
    ],
    '2026_10_16_100002_create_marketing_social_posts.php' => [
        'test' => 'tests/Integration/MarketingDeskMySqlRecoveryTest.php',
        'scenarios' => ['table retained before tenant and unique indexes; completed DDL and saved rows retained before migration bookkeeping'],
    ],
    '2026_10_16_100003_create_marketing_social_publications.php' => [
        'test' => 'tests/Integration/MarketingDeskMySqlRecoveryTest.php',
        'scenarios' => ['table retained before tenant and unique indexes; completed DDL and saved rows retained before migration bookkeeping'],
    ],
    '2026_10_16_100004_create_marketing_assistant_turns.php' => [
        'test' => 'tests/Integration/MarketingDeskMySqlRecoveryTest.php',
        'scenarios' => ['table retained before tenant and unique indexes; completed DDL and saved rows retained before migration bookkeeping'],
    ],
    '2026_10_16_100005_create_marketing_audience_permissions.php' => [
        'test' => 'tests/Integration/MarketingDeskMySqlRecoveryTest.php',
        'scenarios' => ['table retained before tenant and unique indexes; completed DDL and saved rows retained before migration bookkeeping'],
    ],
    '2026_10_16_100006_create_marketing_automation_enrollments.php' => [
        'test' => 'tests/Integration/MarketingDeskMySqlRecoveryTest.php',
        'scenarios' => ['table retained before tenant and unique indexes; completed DDL and saved rows retained before migration bookkeeping'],
    ],
    '2026_10_16_100007_create_marketing_delivery_authorizations.php' => [
        'test' => 'tests/Integration/MarketingDeskMySqlRecoveryTest.php',
        'scenarios' => ['table retained before tenant and unique indexes; completed DDL and saved rows retained before migration bookkeeping'],
    ],
    '2026_10_16_100008_create_marketing_delivery_attempts.php' => [
        'test' => 'tests/Integration/MarketingDeskMySqlRecoveryTest.php',
        'scenarios' => ['table retained before tenant and unique indexes; completed DDL and saved rows retained before migration bookkeeping'],
    ],
    '2026_10_16_100009_create_marketing_conversation_controls.php' => [
        'test' => 'tests/Integration/MarketingDeskMySqlRecoveryTest.php',
        'scenarios' => ['table retained before tenant and unique indexes; completed DDL and saved rows retained before migration bookkeeping'],
    ],
    '2026_10_16_100010_create_marketing_social_account_bindings.php' => [
        'test' => 'tests/Integration/MarketingDeskMySqlRecoveryTest.php',
        'scenarios' => ['table retained before tenant and unique indexes; completed DDL and saved rows retained before migration bookkeeping'],
    ],
];

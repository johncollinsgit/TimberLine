<?php

declare(strict_types=1);

return [
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
    '2026_09_21_120000_create_replacement_modules_table.php' => [
        'test' => 'tests/Integration/ReplacementReadinessMySqlMigrationRecoveryTest.php',
        'scenarios' => ['replacement module table retained before migration bookkeeping'],
    ],
    '2026_09_21_120100_create_replacement_source_snapshots_table.php' => [
        'test' => 'tests/Integration/ReplacementReadinessMySqlMigrationRecoveryTest.php',
        'scenarios' => ['source snapshot table retained before migration bookkeeping'],
    ],
    '2026_09_21_120200_create_replacement_import_batches_table.php' => [
        'test' => 'tests/Integration/ReplacementReadinessMySqlMigrationRecoveryTest.php',
        'scenarios' => ['import batch table retained before migration bookkeeping'],
    ],
    '2026_09_21_120300_create_replacement_import_rows_table.php' => [
        'test' => 'tests/Integration/ReplacementReadinessMySqlMigrationRecoveryTest.php',
        'scenarios' => ['import row table retained before migration bookkeeping'],
    ],
    '2026_09_21_120400_create_replacement_evidence_table.php' => [
        'test' => 'tests/Integration/ReplacementReadinessMySqlMigrationRecoveryTest.php',
        'scenarios' => ['replacement evidence table retained before migration bookkeeping'],
    ],
    '2026_09_21_120500_create_replacement_activation_runs_table.php' => [
        'test' => 'tests/Integration/ReplacementReadinessMySqlMigrationRecoveryTest.php',
        'scenarios' => ['activation run table retained before migration bookkeeping'],
    ],
    '2026_09_21_120600_create_stockist_locator_settings_table.php' => [
        'test' => 'tests/Integration/ReplacementReadinessMySqlMigrationRecoveryTest.php',
        'scenarios' => ['locator settings table retained before migration bookkeeping'],
    ],
    '2026_09_21_120700_create_stockist_locations_table.php' => [
        'test' => 'tests/Integration/ReplacementReadinessMySqlMigrationRecoveryTest.php',
        'scenarios' => ['stockist locations table retained before migration bookkeeping'],
    ],
];

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
];

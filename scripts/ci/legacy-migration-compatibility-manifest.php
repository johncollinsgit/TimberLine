<?php

declare(strict_types=1);

return [
    'database/migrations/2026_03_12_090000_add_marketing_groups_and_addresses.php' => [
        'before_sha256' => '0a3d0a9e697fb5d3db7cf37eceb2963929660f33e9647a9e5dff4e45f7ca8a70',
        'after_sha256' => '10e068fb35aaf96d04e793ccad4750aad86026805030127968dff6cef7a34f07',
        'reason' => 'MySQL rejects the generated 65-character import-run foreign-key name on a clean install.',
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
    ],
    'database/migrations/2026_03_16_090000_create_customer_external_profiles_table.php' => [
        'before_sha256' => 'b9f3aed61c32ea0e78b39028f48729badff34b7662538ab491a49da04d6925b8',
        'after_sha256' => 'c790d4860d2eedf8ade400a7aeca261189b95edbf0b7088be954a08add275bcf',
        'reason' => 'MySQL rejects the original four-column utf8mb4 unique key because it can exceed 3072 bytes.',
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
    ],
    'database/migrations/2026_03_19_090000_create_marketing_message_group_tables.php' => [
        'before_sha256' => '6aebb3cfa0b7d11c6dd981fad6db317db2490bb4aa6cca7f26588a8ea9cf48ea',
        'after_sha256' => '5bb78cc87817a16ab9b4c6eb130b57a2d43c473730d2fa03820d983f54d603f1',
        'reason' => 'MySQL rejects the generated 66-character message-group foreign-key name and retains the preceding table.',
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
    ],
    'database/migrations/2026_03_30_100000_create_tenant_rewards_editor_isolation_tables.php' => [
        'before_sha256' => 'd3d141080453fa267d7c35368d01456010a16a63b1d8c2ece926f6133147099e',
        'after_sha256' => '2a91b07f6026340d86602510f4bd8d72f98dc1288484b18d146802cbc9186464',
        'reason' => 'MySQL rejects the generated 65-character tenant reward-override foreign-key name on a clean install.',
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
    ],
    'database/migrations/2026_07_20_230000_create_tenant_billing_refunds_table.php' => [
        'before_sha256' => '69d800523d3ee3a0d4216efdb626d81779be4e10d0bd255498490933e977a8be',
        'after_sha256' => 'e624883a734646b3ed4617dc897b33ed85a25e6120e72280d5d49f624df17b73',
        'reason' => 'MySQL rejects the generated 65-character receipt-created index name on a clean install.',
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
    ],
    'database/migrations/2026_09_03_201000_add_time_hours_reporting_index.php' => [
        'before_sha256' => '8d32427ba3d55bc1ae4be7de441a4803ca4839a07c3ba1e28869c9d0e1352934',
        'after_sha256' => '8ce8ab40cdcc5ca456c7e3bc5f3e0a11a8149420868ee83e971f3636af9f2318',
        'reason' => 'A durable partial legacy time-session table can lack the two source columns, so the original index ALTER fails before any later repair migration can run.',
        'test' => 'tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php',
    ],
];

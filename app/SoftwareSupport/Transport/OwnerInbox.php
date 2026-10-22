<?php

namespace App\SoftwareSupport\Transport;

interface OwnerInbox
{
    /**
     * Owner-storage adapter must atomically dedupe mutations by issuer/workspace/principal/request_key,
     * reject changed command bytes OR mapping targets, and scope every read/reply to this receiver-owned binding.
     * No production implementation is supplied; tests use their own independent fixture database.
     */
    public function execute(DepartmentBinding $binding, array $command, string $commandHash): array;
}

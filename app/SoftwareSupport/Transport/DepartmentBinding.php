<?php

namespace App\SoftwareSupport\Transport;

/** Receiver-owned references; none imply a native User or a role/membership grant. */
final readonly class DepartmentBinding
{
    public function __construct(
        public string $campusIssuer,
        public string $campusWorkspace,
        public string $campusDepartment,
        public string $campusPrincipal,
        public string $ownerWorkspace,
        public string $clientReference,
        public string $ownerDepartment,
        public array $operations,
    ) {}

    public function matches(array $command): bool
    {
        return $this->campusIssuer === $command['issuer'] && $this->campusWorkspace === $command['campus_workspace']
            && $this->campusDepartment === $command['department'] && $this->campusPrincipal === $command['principal']
            && in_array($command['operation'], $this->operations, true);
    }
}

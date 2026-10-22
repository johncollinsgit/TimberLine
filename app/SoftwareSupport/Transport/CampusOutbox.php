<?php

namespace App\SoftwareSupport\Transport;

interface CampusOutbox
{
    /** Atomically persist exact command before IO; identical retry returns it, changed bytes return 409. */
    public function freeze(array $command): array;

    public function uncertain(array $command): void;

    /** Store receipt/state only; no private signing material, roles or owner identity. */
    public function acknowledge(array $command): void;
}

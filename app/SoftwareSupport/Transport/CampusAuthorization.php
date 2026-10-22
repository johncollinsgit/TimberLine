<?php

namespace App\SoftwareSupport\Transport;

interface CampusAuthorization
{
    /** Campus-local session/membership/department authorization on EVERY attempt, including retries. */
    public function identity(string $department, string $operation): array;
}

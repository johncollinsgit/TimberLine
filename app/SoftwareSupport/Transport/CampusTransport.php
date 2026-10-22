<?php

namespace App\SoftwareSupport\Transport;

interface CampusTransport
{
    /** No live HTTP implementation is installed. Tests use an in-process API request. */
    public function exchange(array $packet): array;
}

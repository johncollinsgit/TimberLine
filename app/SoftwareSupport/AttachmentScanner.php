<?php

namespace App\SoftwareSupport;

interface AttachmentScanner
{
    /** Return status=clean|infected|unavailable, sha256, scanner, definitions. Never log bytes. */
    public function inspect(string $bytes): array;
}

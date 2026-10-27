<?php

namespace App\Proposals;

/** Approved owner copy is snapshotted into a new edition, never added to an old one. */
class PersonalLetter
{
    public static function snapshot(): array
    {
        return json_decode(file_get_contents(resource_path('data/proposals/lees-mcrae-personal-letter-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function from(array $payload): ?array
    {
        if (! array_key_exists('personal_letter', $payload)) {
            return null;
        }

        abort_unless(($payload['client']['reference'] ?? null) === CampusReviewManifest::CLIENT_REFERENCE
            && $payload['personal_letter'] === self::snapshot(), 503, 'This saved personal letter is unavailable.');

        return $payload['personal_letter'];
    }
}

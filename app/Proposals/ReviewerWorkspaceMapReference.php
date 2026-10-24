<?php

namespace App\Proposals;

use InvalidArgumentException;
use JsonException;

/** Closed source-document reference only. No HTTP, native records, GPS or caller-selected catalog. */
final class ReviewerWorkspaceMapReference
{
    public const CATALOG = 'lees-mcrae-official-map-v1';

    public const CATALOG_SHA256 = '71d604a832a6ebf9e7e4e5203eb62b11aea54322471d394f456ddd99de85e287';

    public const POINTS_SHA256 = '8ffc5109e1b9772a1ed7bb91b2e5dada8c246cc272cc621c620f9f253c5c0826';

    public const DOCUMENT_SHA256 = 'd2daae03a679d9c93f8b62dbe289734c09e4c57f127f09ca258f85713eae5839';

    public const SOURCE_URL = 'https://www.lmc.edu/campus-life/campus-safety/campus-operations/files/campus-parking-map.pdf';

    public const COORDINATE_BASIS = 'Printed map-number label centers on the source page, percentages; not GPS or surveyed positions.';

    public static function validate(mixed $reference): array
    {
        $keys = ['schema_version', 'catalog', 'catalog_sha256', 'map_number', 'source_url', 'source_page'];
        self::require(is_array($reference) && count($reference) === count($keys) && array_diff($keys, array_keys($reference)) === []);
        self::require($reference['schema_version'] === 1 && $reference['catalog'] === self::CATALOG
            && $reference['catalog_sha256'] === self::CATALOG_SHA256 && $reference['source_url'] === self::SOURCE_URL
            && $reference['source_page'] === 2 && is_string($reference['map_number'])
            && preg_match('/^(?:[1-9]|[1-5][0-9]|6[0-8])$/D', $reference['map_number']) === 1);

        $catalog = self::source('lees-mcrae-locations.json', self::CATALOG_SHA256);
        $points = self::source('campus-map-points.json', self::POINTS_SHA256);
        self::require(($catalog['catalog'] ?? null) === self::CATALOG && ($catalog['source_url'] ?? null) === self::SOURCE_URL
            && ($catalog['source_page'] ?? null) === 2 && is_array($catalog['entries'] ?? null)
            && ($points['source_url'] ?? null) === self::SOURCE_URL && ($points['source_page'] ?? null) === 1
            && ($points['source_sha256'] ?? null) === self::DOCUMENT_SHA256
            && ($points['coordinate_basis'] ?? null) === self::COORDINATE_BASIS
            && ($points['page']['width'] ?? null) === 612.0 && ($points['page']['height'] ?? null) === 792.0);
        $matches = array_filter($catalog['entries'], fn ($entry) => ($entry['map_number'] ?? null) === $reference['map_number']);
        $point = $points['points'][$reference['map_number']] ?? null;
        self::require(count($matches) === 1 && is_array($point) && count($point) === 2);
        foreach (['x', 'y'] as $axis) {
            self::require(isset($point[$axis]) && (is_int($point[$axis]) || is_float($point[$axis]))
                && is_finite((float) $point[$axis]) && $point[$axis] >= 0 && $point[$axis] <= 100);
        }

        return $reference;
    }

    private static function source(string $name, string $pin): array
    {
        $path = dirname(__DIR__, 2).'/resources/data/enterprise/'.$name;
        $bytes = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        self::require(is_string($bytes) && strlen($bytes) <= 100000 && hash_equals($pin, hash('sha256', $bytes)));
        try {
            $value = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Official building map reference is unavailable.');
        }
        self::require(is_array($value));

        return $value;
    }

    private static function require(bool $condition): void
    {
        if (! $condition) {
            throw new InvalidArgumentException('Official building map reference is unavailable.');
        }
    }
}

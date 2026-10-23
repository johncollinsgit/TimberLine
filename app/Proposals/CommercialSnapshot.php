<?php

namespace App\Proposals;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Keeps a separately reviewed draft snapshot through ordinary owner saves. No catalog writes. */
class CommercialSnapshot
{
    private const PROTECTED = ['client', 'items', 'client_details', 'unresolved', 'usage_plan', 'template', 'scope_notes', 'journey', 'approach_key'];

    public static function hash(array $payload): string
    {
        $protected = [];
        foreach (self::PROTECTED as $key) {
            $protected[$key] = $payload[$key] ?? null;
        }
        $provenance = $payload['commercial_snapshot'] ?? null;
        if (is_array($provenance)) {
            unset($provenance['protected_sha256']);
            $protected['commercial_snapshot'] = $provenance;
        }

        return hash('sha256', json_encode(self::canonical($protected), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function preserve(array $stored, array $proposed): array
    {
        $marker = $stored['commercial_snapshot'] ?? null;
        self::verify($stored);
        if ($marker === null && empty($stored['client_details']['commercial_sections'])) {
            return $proposed;
        }
        $refusal = 'This draft has reviewed commercial terms. Review a new commercial revision before changing its client, services, scope, usage or template.';
        $oldIds = array_column($stored['items'], 'id');
        $newIds = array_column($proposed['items'], 'id');
        sort($oldIds);
        sort($newIds);
        if (($stored['client']['id'] ?? null) !== ($proposed['client']['id'] ?? null) || $oldIds !== $newIds
            || ($stored['template']['id'] ?? null) !== ($proposed['template']['id'] ?? null)
            || ($stored['usage_plan'] ?? null) !== ($proposed['usage_plan'] ?? null)
            || ($stored['scope_notes'] ?? null) !== ($proposed['scope_notes'] ?? null)
            || ($stored['approach_key'] ?? null) !== ($proposed['approach_key'] ?? null)) {
            throw new ConflictHttpException($refusal);
        }
        // Catalog IDs/revisions in the stored items remain explicit origins; the marker records draft-only overrides.
        foreach (self::PROTECTED as $key) {
            $proposed[$key] = $stored[$key];
        }
        $proposed['commercial_snapshot'] = $marker;

        return $proposed;
    }

    public static function verify(array $stored): void
    {
        $marker = $stored['commercial_snapshot'] ?? null;
        if ($marker === null && empty($stored['client_details']['commercial_sections'])) {
            return;
        }
        if (! self::validMarker($stored, $marker)) {
            throw new ConflictHttpException('The reviewed commercial snapshot is incomplete or changed. Reload and review it before saving or publishing.');
        }
    }

    private static function validMarker(array $stored, mixed $marker): bool
    {
        if (! is_array($marker) || ($marker['schema_version'] ?? null) !== 1
            || ! is_string($marker['source_reference'] ?? null) || $marker['source_reference'] === ''
            || ! self::digest($marker['source_copy_sha256'] ?? null) || ! self::digest($marker['protected_sha256'] ?? null)
            || ! is_array($stored['items'] ?? null) || ! is_array($stored['client_details'] ?? null)
            || CommercialSections::from($stored['client_details']) === null
            || ! is_array($marker['catalog_origins'] ?? null) || ! is_array($marker['item_overrides'] ?? null)
            || count($marker['catalog_origins']) !== count($stored['items']) || count($marker['item_overrides']) !== count($stored['items'])) {
            return false;
        }
        foreach (self::PROTECTED as $key) {
            if (! array_key_exists($key, $stored)) {
                return false;
            }
        }
        foreach ($stored['items'] as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                return false;
            }
            $origin = $marker['catalog_origins'][$item['id']] ?? null;
            $override = $marker['item_overrides'][$item['id']] ?? null;
            if (! is_array($origin) || ($origin['revision'] ?? null) !== ($item['revision'] ?? null)
                || ! self::digest($origin['payload_sha256'] ?? null) || ! is_array($origin['terms_months'] ?? null)
                || ! is_array($override) || array_keys($override) !== ['terms_months']
                || ! is_array($override['terms_months']) || $override['terms_months'] !== ($item['terms_months'] ?? null)) {
                return false;
            }
        }

        return hash_equals(self::hash($stored), $marker['protected_sha256']);
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}

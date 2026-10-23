<?php

namespace App\Proposals;

/** Plain-text presentation from an exact stored proposal, never a source-copy fallback. */
class CommercialSections
{
    public static function from(array $details): ?array
    {
        $sections = $details['commercial_sections'] ?? null;
        if (! is_array($sections) || ! array_is_list($sections) || count($sections) < 1 || count($sections) > 20) {
            return null;
        }
        foreach ($sections as $section) {
            if (! is_array($section) || ! self::text($section['heading'] ?? null)) {
                return null;
            }
            foreach (['paragraphs', 'bullets'] as $key) {
                if (! self::texts($section[$key] ?? [])) {
                    return null;
                }
            }
            $tables = $section['tables'] ?? [];
            if (! is_array($tables) || ! array_is_list($tables) || count($tables) > 5) {
                return null;
            }
            foreach ($tables as $table) {
                if (! is_array($table) || ! self::text($table['caption'] ?? null)
                    || ! self::texts($table['columns'] ?? null) || count($table['columns']) < 1 || count($table['columns']) > 8
                    || ! is_array($table['rows'] ?? null) || ! array_is_list($table['rows']) || count($table['rows']) > 50) {
                    return null;
                }
                foreach ($table['rows'] as $row) {
                    if (! self::texts($row) || count($row) !== count($table['columns'])) {
                        return null;
                    }
                }
            }
        }

        return $sections;
    }

    private static function text(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= 16000;
    }

    private static function texts(mixed $values): bool
    {
        return is_array($values) && array_is_list($values) && count($values) <= 50
            && count(array_filter($values, self::text(...))) === count($values);
    }
}

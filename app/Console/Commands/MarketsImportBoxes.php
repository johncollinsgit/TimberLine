<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Market;
use App\Models\MarketBoxShipment;
use App\Support\Markets\SheetNameParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketsImportBoxes extends Command
{
    protected $signature = 'markets:import-boxes {year? : Specific year workbook to import (e.g. 2026)}';
    protected $description = 'Import market box count/scent notes spreadsheets into markets + event occurrences (idempotent).';

    public function handle(): int
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            $this->error('PhpSpreadsheet is not installed. Install phpoffice/phpspreadsheet to use this importer.');
            return self::FAILURE;
        }

        $years = $this->yearsToImport();
        $importedEvents = 0;
        $importedLines = 0;

        foreach ($years as $year) {
            $path = storage_path("app/market-boxes/{$year}.xlsx");
            if (!is_file($path)) {
                $this->warn("Workbook not found for {$year}: {$path}");
                continue;
            }

            $this->info("Importing {$path}");
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            if (method_exists($reader, 'setReadDataOnly')) {
                $reader->setReadDataOnly(true);
            }
            if (method_exists($reader, 'setReadEmptyCells')) {
                $reader->setReadEmptyCells(false);
            }
            if (method_exists($reader, 'setIgnoreRowsWithNoCells')) {
                $reader->setIgnoreRowsWithNoCells(true);
            }

            $parser = app(SheetNameParser::class);
            foreach ($reader->listWorksheetNames($path) as $rawSheetName) {
                $sheetNameExact = (string) $rawSheetName;
                $sheetName = trim($sheetNameExact);
                $parsed = $parser->parse($sheetName, $year);
                if (($parsed['ignored'] ?? false) === true) {
                    continue;
                }

                // PhpSpreadsheet sheet loading requires an exact worksheet title match.
                $reader->setLoadSheetsOnly([$sheetNameExact]);
                $spreadsheet = $reader->load($path);
                if ($spreadsheet->getSheetCount() === 0) {
                    $this->warn("Skipped sheet (could not load exact worksheet): {$sheetName}");
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);
                    continue;
                }
                $sheet = $spreadsheet->getSheet(0);
                $hints = $this->sheetContentHints($sheet);
                $parsed = $parser->parse($sheetName, $year, $hints);

                DB::transaction(function () use ($sheet, $sheetName, $parsed, &$importedEvents, &$importedLines) {
                    $market = $this->upsertMarket($parsed);
                    $event = $this->upsertEvent($market, $parsed, $sheetName);
                    $importedEvents++;

                    $rows = $this->extractRows($sheet);
                    if (!empty($rows)) {
                        MarketBoxShipment::query()->where('event_id', $event->id)->delete();
                        foreach ($rows as $row) {
                            MarketBoxShipment::query()->create([
                                'event_id' => $event->id,
                                'item_type' => $row['item_type'] ?? null,
                                'product_key' => $row['product_key'] ?? null,
                                'sku' => $row['sku'] ?? null,
                                'scent' => $row['scent'] ?? null,
                                'size' => $row['size'] ?? null,
                                'qty' => max(0, (int) ($row['qty'] ?? 0)),
                                'notes' => $row['notes'] ?? null,
                                'raw_row' => $row['raw_row'] ?? null,
                                'source_row_hash' => $row['source_row_hash'] ?? null,
                            ]);
                            $importedLines++;
                        }
                    }
                });

                $spreadsheet->disconnectWorksheets();
                unset($sheet, $spreadsheet);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }

        $this->info("Import complete. Events upserted: {$importedEvents}; box lines imported: {$importedLines}");
        return self::SUCCESS;
    }

    private function yearsToImport(): array
    {
        $yearArg = $this->argument('year');
        if ($yearArg !== null && ctype_digit((string) $yearArg)) {
            return [(int) $yearArg];
        }

        return [2023, 2024, 2025, 2026];
    }

    private function upsertMarket(array $parsed): Market
    {
        $name = trim((string) ($parsed['market_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($parsed['raw_sheet_name'] ?? 'Unknown Market'));
        }
        if ($name === '') {
            $name = 'Unknown Market';
        }
        $slug = (string) ($parsed['canonical_slug'] ?? Str::slug($name));
        if ($slug === '') {
            $slug = 'market-'.Str::random(8);
        }

        return Market::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'default_location_city' => $parsed['city'] ?? null,
                'default_location_state' => $parsed['state'] ?? null,
            ]
        );
    }

    private function upsertEvent(Market $market, array $parsed, string $sheetName): Event
    {
        $event = Event::query()->where('source', 'spreadsheet')
            ->where('source_ref', $sheetName)
            ->first();

        $payload = [
            'market_id' => $market->id,
            'year' => $parsed['year'] ?? null,
            'name' => $market->name,
            'display_name' => $parsed['raw_sheet_name'] ?? $sheetName,
            'starts_at' => $parsed['starts_at'] ?? null,
            'ends_at' => $parsed['ends_at'] ?? null,
            'city' => $parsed['city'] ?? null,
            'state' => $parsed['state'] ?? null,
            'venue' => $parsed['venue'] ?? null,
            'notes' => $this->mergeParseNotes(null, $parsed),
            'source' => 'spreadsheet',
            'source_ref' => $sheetName,
            'parse_confidence' => $parsed['confidence'] ?? null,
            'parse_notes_json' => $this->buildParseNotesJson($parsed),
            'needs_review' => (bool) ($parsed['needs_review'] ?? false),
            'status' => 'planned',
        ];

        if ($event) {
            $payload['notes'] = $this->mergeParseNotes($event->notes, $parsed);
            $payload['parse_confidence'] = $parsed['confidence'] ?? null;
            $payload['parse_notes_json'] = $this->buildParseNotesJson($parsed);
            $payload['needs_review'] = (bool) ($parsed['needs_review'] ?? false);
            $event->fill($payload)->save();
            return $event;
        }

        return Event::query()->create($payload);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildParseNotesJson(array $parsed): ?array
    {
        if (empty($parsed)) {
            return null;
        }

        return [
            'confidence' => $parsed['confidence'] ?? null,
            'date_confidence' => $parsed['date_confidence'] ?? null,
            'date_parse_notes' => $parsed['date_parse_notes'] ?? null,
            'location_confidence' => $parsed['location_confidence'] ?? null,
            'location_parse_notes' => $parsed['location_parse_notes'] ?? null,
            'market_name_confidence' => $parsed['market_name_confidence'] ?? null,
            'notes' => array_values(array_filter((array) ($parsed['notes'] ?? []))),
            'normalized_sheet_name' => $parsed['normalized_sheet_name'] ?? null,
            'raw_sheet_name' => $parsed['raw_sheet_name'] ?? null,
        ];
    }

    private function mergeParseNotes(?string $existing, array $parsed): ?string
    {
        $parseLines = [];
        $confidence = (string) ($parsed['confidence'] ?? '');
        if ($confidence !== '') {
            $parseLines[] = "Parse confidence: {$confidence}";
        }
        if (!empty($parsed['notes']) && is_array($parsed['notes'])) {
            foreach ($parsed['notes'] as $note) {
                $parseLines[] = 'Parse note: '.(string) $note;
            }
        }
        if (($parsed['needs_review'] ?? false) === true) {
            $parseLines[] = 'Needs review: yes';
        }
        if (empty($parseLines)) {
            return $existing;
        }

        $prefix = "[market-import-parser]\n".implode("\n", array_unique($parseLines));
        $existing = trim((string) $existing);
        if ($existing === '') {
            return $prefix;
        }

        $cleanedExisting = preg_replace('/\[market-import-parser\][\s\S]*$/m', '', $existing) ?? $existing;
        $cleanedExisting = trim($cleanedExisting);

        return trim($cleanedExisting."\n\n".$prefix);
    }

    private function extractRows(object $sheet): array
    {
        $rows = method_exists($sheet, 'toArray') ? $sheet->toArray(null, true, true, false) : [];
        if (count($rows) < 2) {
            return [];
        }

        $headerRow = array_shift($rows);
        $headers = array_map(fn ($v) => Str::of((string) $v)->lower()->snake()->value(), $headerRow);

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $assoc = [];
            foreach ($headers as $i => $header) {
                if ($header === '') {
                    continue;
                }
                $assoc[$header] = isset($row[$i]) ? trim((string) $row[$i]) : null;
            }

            if (count(array_filter($assoc, fn ($v) => !blank($v))) === 0) {
                continue;
            }

            $mapped = [
                'item_type' => $assoc['item_type'] ?? $assoc['type'] ?? $assoc['product_type'] ?? null,
                'product_key' => $assoc['product_key'] ?? $assoc['product'] ?? $assoc['item'] ?? null,
                'sku' => $assoc['sku'] ?? null,
                'scent' => $assoc['scent'] ?? $assoc['scent_name'] ?? null,
                'size' => $assoc['size'] ?? $assoc['jar_size'] ?? null,
                'qty' => $this->extractQty($assoc),
                'notes' => $assoc['notes'] ?? $assoc['note'] ?? null,
                'raw_row' => $assoc,
                'source_row_hash' => hash('sha256', json_encode($assoc)),
            ];

            $out[] = $mapped;
        }

        return $out;
    }

    /**
     * @return array<int,string>
     */
    private function sheetContentHints(object $sheet): array
    {
        $rows = method_exists($sheet, 'toArray') ? $sheet->toArray(null, true, true, false) : [];
        $hints = [];
        foreach (array_slice($rows, 0, 10) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $line = trim(implode(' ', array_map(fn ($v) => trim((string) $v), $row)));
            if ($line !== '') {
                $hints[] = preg_replace('/\s+/', ' ', $line) ?? $line;
            }
        }

        return $hints;
    }

    private function extractQty(array $assoc): int
    {
        foreach (['boxes_sent', 'qty', 'quantity', 'count', 'box_count', 'units', 'boxes_requested'] as $key) {
            if (!array_key_exists($key, $assoc)) {
                continue;
            }
            $value = preg_replace('/[^0-9\.\-]/', '', (string) $assoc[$key]);
            if ($value === '' || !is_numeric($value)) {
                continue;
            }
            return max(0, (int) round((float) $value));
        }

        // Fallback: look for a likely "sent" quantity column while avoiding returns.
        foreach ($assoc as $key => $rawValue) {
            $normalizedKey = Str::lower((string) $key);
            if (Str::contains($normalizedKey, 'return')) {
                continue;
            }
            if (!Str::contains($normalizedKey, ['sent', 'qty', 'quantity', 'count', 'units'])) {
                continue;
            }
            $value = preg_replace('/[^0-9\.\-]/', '', (string) $rawValue);
            if ($value === '' || !is_numeric($value)) {
                continue;
            }
            return max(0, (int) round((float) $value));
        }

        return 0;
    }
}

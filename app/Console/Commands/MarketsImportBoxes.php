<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Market;
use App\Models\MarketBoxShipment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketsImportBoxes extends Command
{
    protected $signature = 'markets:import-boxes {year? : Specific year workbook to import (e.g. 2026)}';
    protected $description = 'Import market box count/scent notes spreadsheets into markets + event occurrences (idempotent).';

    private array $ignoredSheets = [
        'market box count & scent notes',
        'tr room sprays sold',
        'sheet3', 'sheet4', 'sheet5', 'sheet6',
    ];

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
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);

            foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                $sheetName = trim((string) $sheet->getTitle());
                if ($this->shouldIgnoreSheet($sheetName)) {
                    continue;
                }

                $parsed = $this->parseSheetName($sheetName, $year);
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

    private function shouldIgnoreSheet(string $sheetName): bool
    {
        $normalized = Str::of($sheetName)->lower()->squish()->value();
        return in_array($normalized, $this->ignoredSheets, true);
    }

    private function parseSheetName(string $sheetName, int $fallbackYear): array
    {
        $clean = trim(preg_replace('/\s+/', ' ', str_replace(['_', '|'], ' ', $sheetName)) ?? $sheetName);
        preg_match('/\b(20\d{2})\b/', $clean, $yearMatch);
        $year = isset($yearMatch[1]) ? (int) $yearMatch[1] : $fallbackYear;

        $startsAt = null;
        $endsAt = null;
        if (preg_match('/(\d{1,2})[\/-](\d{1,2})(?:\s*[-–]\s*(\d{1,2})[\/-](\d{1,2})|\s*-\s*(\d{1,2}))?/', $clean, $m)) {
            try {
                $startMonth = (int) $m[1];
                $startDay = (int) $m[2];
                $endMonth = isset($m[4]) && $m[4] !== '' ? (int) $m[3] : $startMonth;
                $endDay = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : (isset($m[5]) && $m[5] !== '' ? (int) $m[5] : $startDay);
                $startsAt = now()->setDate($year, $startMonth, $startDay)->toDateString();
                $endsAt = now()->setDate($year, $endMonth, $endDay)->toDateString();
            } catch (\Throwable $e) {
                $startsAt = null;
                $endsAt = null;
            }
        }

        $state = null;
        if (preg_match('/\b([A-Z]{2})\b/', $clean, $stateMatch)) {
            $state = strtoupper($stateMatch[1]);
        }

        $nameWithoutDates = trim(preg_replace('/\b20\d{2}\b/', '', $clean) ?? $clean);
        $nameWithoutDates = trim(preg_replace('/\d{1,2}[\/-]\d{1,2}(\s*[-–]\s*(\d{1,2}[\/-]\d{1,2}|\d{1,2}))?/', '', $nameWithoutDates) ?? $nameWithoutDates);
        $canonicalName = trim(preg_replace('/\b(AL|AK|AZ|AR|CA|CO|CT|DE|FL|GA|HI|IA|ID|IL|IN|KS|KY|LA|MA|MD|ME|MI|MN|MO|MS|MT|NC|ND|NE|NH|NJ|NM|NV|NY|OH|OK|OR|PA|RI|SC|SD|TN|TX|UT|VA|VT|WA|WI|WV|WY)\b/', '', $nameWithoutDates) ?? $nameWithoutDates);
        $canonicalName = Str::of($canonicalName)->replace(['(', ')'], ' ')->squish()->value();
        if ($canonicalName === '') {
            $canonicalName = Str::of($sheetName)->squish()->value();
        }

        return [
            'canonical_name' => $canonicalName,
            'display_name' => $sheetName,
            'year' => $year,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'state' => $state,
            'city' => null,
            'venue' => null,
        ];
    }

    private function upsertMarket(array $parsed): Market
    {
        $name = (string) ($parsed['canonical_name'] ?? 'Unknown Market');
        $slug = Str::slug($name);
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
            'display_name' => $parsed['display_name'] ?? $sheetName,
            'starts_at' => $parsed['starts_at'] ?? null,
            'ends_at' => $parsed['ends_at'] ?? null,
            'city' => $parsed['city'] ?? null,
            'state' => $parsed['state'] ?? null,
            'venue' => $parsed['venue'] ?? null,
            'source' => 'spreadsheet',
            'source_ref' => $sheetName,
            'status' => 'planned',
        ];

        if ($event) {
            $event->fill($payload)->save();
            return $event;
        }

        return Event::query()->create($payload);
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

    private function extractQty(array $assoc): int
    {
        foreach (['qty', 'quantity', 'count', 'box_count', 'units'] as $key) {
            if (!array_key_exists($key, $assoc)) {
                continue;
            }
            $value = preg_replace('/[^\d\-]/', '', (string) $assoc[$key]);
            if ($value === '' || !is_numeric($value)) {
                continue;
            }
            return max(0, (int) $value);
        }

        return 0;
    }
}


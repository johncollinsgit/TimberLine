<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\FieldService\QuickBooksFieldServiceImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class FieldServiceImportQuickBooks extends Command
{
    protected $signature = 'field-service:import-quickbooks
        {file : CSV/XLSX export from QuickBooks}
        {--tenant-id= : Tenant ID to import into}
        {--tenant= : Tenant slug to import into}
        {--type=auto : auto|customers|jobs|items}
        {--dry-run : Parse and summarize without writing}';

    protected $description = 'Import QuickBooks CSV/XLSX exports into field-service customers, jobs, and materials.';

    public function handle(QuickBooksFieldServiceImportService $importService): int
    {
        $tenant = $this->tenant();
        if (! $tenant instanceof Tenant) {
            $this->error('Pass --tenant-id or --tenant with a valid Collins/Everbranch workspace.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error('Import file not found: '.$path);

            return self::FAILURE;
        }

        $type = strtolower(trim((string) $this->option('type'))) ?: 'auto';
        if (! in_array($type, ['auto', 'customers', 'jobs', 'items'], true)) {
            $this->error('Invalid --type. Use auto, customers, jobs, or items.');

            return self::FAILURE;
        }

        $rows = $this->rows($path);
        $dryRun = (bool) $this->option('dry-run');
        $summary = $importService->importRows($tenant, $rows, $type, $dryRun);

        $this->line($dryRun ? 'mode=dry-run' : 'mode=live');
        $this->line('tenant='.$tenant->slug);
        foreach ($summary as $key => $value) {
            $this->line($key.'='.$value);
        }

        return self::SUCCESS;
    }

    protected function tenant(): ?Tenant
    {
        $tenantId = $this->option('tenant-id');
        if (is_numeric($tenantId)) {
            return Tenant::query()->find((int) $tenantId);
        }

        $slug = strtolower(trim((string) $this->option('tenant')));
        if ($slug !== '') {
            return Tenant::query()->where('slug', $slug)->first();
        }

        return null;
    }

    /**
     * @return array<int,array<string,string>>
     */
    protected function rows(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'csv') {
            $handle = fopen($path, 'r');
            if (! is_resource($handle)) {
                return [];
            }

            $headers = array_map(fn ($value): string => $this->key((string) $value), fgetcsv($handle) ?: []);
            $rows = [];
            while (($line = fgetcsv($handle)) !== false) {
                $row = [];
                foreach ($headers as $index => $header) {
                    if ($header !== '') {
                        $row[$header] = trim((string) ($line[$index] ?? ''));
                    }
                }
                if (array_filter($row) !== []) {
                    $rows[] = $row;
                }
            }
            fclose($handle);

            return $rows;
        }

        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, true);
        $headers = [];
        $rows = [];
        foreach ($sheet as $index => $line) {
            if ($index === 1) {
                $headers = array_map(fn ($value): string => $this->key((string) $value), array_values($line));

                continue;
            }

            $row = [];
            foreach (array_values($line) as $column => $value) {
                $header = $headers[$column] ?? '';
                if ($header !== '') {
                    $row[$header] = trim((string) $value);
                }
            }
            if (array_filter($row) !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    protected function key(string $value): string
    {
        return Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }
}

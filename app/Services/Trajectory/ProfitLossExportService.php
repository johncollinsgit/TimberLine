<?php

namespace App\Services\Trajectory;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** Presentation only: every accounting amount is supplied by the screen report. */
class ProfitLossExportService
{
    public function statement(string $name, array $report, string $comparison = 'previous_period'): array
    {
        $prior = $report['comparison'][$comparison] ?? [];
        $metrics = $prior['metrics'] ?? [];
        $rows = [];
        $totals = ['income' => 'income_cents', 'cogs' => 'cogs_cents', 'expenses' => 'expenses_cents', 'other_income' => 'other_income_cents', 'other_expenses' => 'other_expenses_cents'];
        foreach ($report['statement']['sections'] ?? [] as $section) {
            $rows[] = ['kind' => 'section', 'label' => $section['label'], 'depth' => 0, 'amount_cents' => $section['total_cents'], 'previous_cents' => $metrics[$totals[$section['key']] ?? ''] ?? null];
            $this->lines($rows, $section['lines'] ?? []);
            if ($section['key'] === 'cogs') {
                $rows[] = $this->total('Gross profit', 'gross_profit_cents', $report, $metrics);
            }
            if ($section['key'] === 'expenses') {
                $rows[] = $this->total('Operating income', 'operating_profit_cents', $report, $metrics);
            }
        }
        $rows[] = $this->total('Net profit', 'net_cents', $report, $metrics);
        if ($report['statement']['unclassified_lines'] ?? []) {
            $rows[] = ['kind' => 'section', 'label' => 'Unclassified source accounts (excluded from section detail)', 'depth' => 0, 'amount_cents' => null, 'previous_cents' => null];
            $this->lines($rows, $report['statement']['unclassified_lines']);
        }

        return ['name' => $name, 'report' => $report, 'comparison_label' => $comparison === 'previous_year' ? 'Previous year' : 'Previous period', 'comparison_period' => $prior['period'] ?? null, 'rows' => $rows];
    }

    private function total(string $label, string $key, array $report, array $prior): array
    {
        return ['kind' => 'total', 'label' => $label, 'depth' => 0, 'amount_cents' => $report[$key] ?? null, 'previous_cents' => $prior[$key] ?? null];
    }

    private function lines(array &$rows, array $lines, int $depth = 1): void
    {
        foreach ($lines as $line) {
            $rows[] = ['kind' => 'account', 'label' => $line['label'], 'depth' => $depth, 'amount_cents' => $line['amount_cents'], 'previous_cents' => null];
            $this->lines($rows, $line['children'] ?? [], $depth + 1);
        }
    }

    public function csv(array $statement): string
    {
        $stream = fopen('php://temp', 'r+');
        $write = function (array $row) use ($stream): void {
            fputcsv($stream, array_map(fn ($value) => is_string($value) && preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value, $row), ',', '"', '', "\r\n");
        };
        $report = $statement['report'];
        $write(['Everbranch Profit & Loss', $statement['name']]);
        $write(['Period', $report['period']['start'], $report['period']['end']]);
        $write(['Accounting basis', $report['basis'] ?? 'Unknown', 'Requested basis', $report['requested_basis']]);
        $write(['Currency', $report['currency'] ?? 'Unknown', 'Source', $report['source'], 'Snapshot', $report['snapshot_id'] ?? 'Unavailable']);
        $write(['Coverage', $report['coverage']['status'], 'Reconciles', $report['coverage']['reconciles'] ? 'Yes' : 'No']);
        if ($statement['comparison_period']) {
            $write([$statement['comparison_label'], $statement['comparison_period']['start'], $statement['comparison_period']['end']]);
        }
        foreach ($report['coverage']['notes'] as $note) {
            $write(['Coverage note', $note]);
        }
        $write(['Unavailable amounts are blank; account comparison amounts require matching source account evidence.']);
        $write(['Account / subtotal', 'Row type', 'Depth', 'Current amount', $statement['comparison_label'].' amount']);
        foreach ($statement['rows'] as $row) {
            // Numeric negatives are emitted as numeric cells; only untrusted text
            // is formula-escaped. Missing accounting evidence is never zero.
            fputcsv($stream, [$this->csvLabel($row['label']), $row['kind'], $row['depth'], $this->decimal($row['amount_cents']), $this->decimal($row['previous_cents'])], ',', '"', '', "\r\n");
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    private function csvLabel(string $label): string
    {
        return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $label) ? "'".$label : $label;
    }

    public function decimal(?int $cents): string
    {
        return $cents === null ? '' : ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    public function amount(?int $cents, ?string $currency): string
    {
        return $cents === null ? 'Unavailable' : ($currency ? $currency.' ' : '').($cents < 0 ? '(' : '').number_format(abs($cents) / 100, 2).($cents < 0 ? ')' : '');
    }

    public function pdf(array $statement): string
    {
        $html = view('trajectory.profit-loss-statement', ['statement' => $statement, 'exporter' => $this, 'pdf' => true])->render();
        abort_if(strlen($html) > 1500000, 422, 'This statement is too large for PDF. Download CSV instead.');
        $temporary = storage_path('app/private/trajectory-pdf/'.Str::uuid());
        File::makeDirectory($temporary, 0700, true);
        try {
            $options = new Options;
            $options->setIsRemoteEnabled(false);
            $options->setIsPhpEnabled(false);
            $options->setIsJavascriptEnabled(false);
            $options->setAllowedProtocols([]);
            $options->setChroot($temporary);
            $options->setTempDir($temporary);
            $options->setFontCache($temporary);
            $options->setDefaultFont('DejaVu Sans');
            $document = new Dompdf($options);
            $document->loadHtml($html, 'UTF-8');
            $document->setPaper('letter');
            $document->render();

            return $document->output();
        } finally {
            File::deleteDirectory($temporary);
        }
    }
}

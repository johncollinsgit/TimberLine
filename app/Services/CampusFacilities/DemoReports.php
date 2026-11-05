<?php

namespace App\Services\CampusFacilities;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/** Factual exports of fictional records. No generated narrative or report submission. */
class DemoReports
{
    public function defaultPeriod(array $settings): array
    {
        $now = CarbonImmutable::now($settings['timezone']);
        $month = $settings['fiscalStartMonth'];
        $year = $now->month >= $month ? $now->year : $now->year - 1;
        $quarter = intdiv(($now->month - $month + 12) % 12, 3);
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $settings['timezone'])->addMonths($quarter * 3);

        return ['start' => $start->toDateString(), 'end' => $start->addMonths(3)->subDay()->toDateString(), 'label' => 'Demo FY'.($month === 1 ? $year : $year + 1).' · Q'.($quarter + 1)];
    }

    public function read(array $state, array $input): array
    {
        $input = Validator::make($input, ['role' => 'required|in:staff,head,technician,admin', 'actor' => 'required|string|max:50', 'reportId' => 'nullable|string|max:70|regex:/^[a-z][a-z0-9-]*$/D', 'start' => 'sometimes|date_format:Y-m-d', 'end' => 'sometimes|date_format:Y-m-d', 'format' => 'sometimes|in:json,csv'])->validate();
        $actor = app(DemoDirectory::class)->actor($input, $state);
        app(DemoRecords::class)->requirePermission($actor, 'reports.view');
        if (! empty($input['reportId'])) {
            $report = app(DemoRecords::class)->row($state, 'reports', (string) $input['reportId'])['snapshot'];
            foreach ($this->restrictedColumns($report['columns'], $actor) as $column) {
                abort(403, 'Switch to a demo role permitted to read every section of this retained report.');
            }

            return $report;
        }

        return $this->build($state, $input, $actor);
    }

    public function build(array $state, array $input, array $actor): array
    {
        $period = Validator::make($input, ['start' => 'required|date_format:Y-m-d', 'end' => 'required|date_format:Y-m-d|after_or_equal:start'])->validate();
        $settings = $state['operations']['settings'];
        $omitted = $this->restrictedColumns($settings['reportColumns'], $actor);
        $columns = array_values(array_diff($settings['reportColumns'], $omitted));
        $from = CarbonImmutable::parse($period['start'], $settings['timezone'])->startOfDay();
        $to = CarbonImmutable::parse($period['end'], $settings['timezone'])->startOfDay()->addDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Choose a report window of one year or less.');
        $within = fn ($value) => $value && CarbonImmutable::parse($value) >= $from && CarbonImmutable::parse($value) < $to && CarbonImmutable::parse($value) <= CarbonImmutable::now();
        $tickets = array_values(array_filter($state['tickets'], fn ($row) => $within($row['createdAt'])));
        $completed = [];
        $minutes = 0;
        $materials = [];
        foreach ($state['tickets'] as $ticket) {
            $completion = collect($ticket['events'])->last(fn ($event) => $event['action'] === 'Job completed');
            $completionAt = $completion['at'] ?? (($ticket['status'] === 'Completed') ? ($ticket['workLogs'][count($ticket['workLogs']) - 1]['at'] ?? $ticket['updatedAt']) : null);
            if ($ticket['status'] === 'Completed' && $within($completionAt)) {
                $completed[] = $ticket;
            }
            foreach ($ticket['workLogs'] as $log) {
                if ($within($log['at'])) {
                    $minutes += $log['minutes'];
                    $materials[] = ['ticketId' => $ticket['id'], 'text' => $log['materials'], 'at' => $log['at']];
                }
            }
        }
        $teams = array_count_values(array_column(array_column($tickets, 'fields'), 'category'));
        ksort($teams);
        $invoices = array_values(array_filter($state['operations']['invoices'], fn ($row) => $within($row['createdAt'] ?? null)));
        $certificateAttention = array_values(array_filter(app(DemoVendors::class)->attention($state), fn ($row) => $row['attention'] !== 'Current'));
        $metrics = [];
        $rows = [];
        foreach ($columns as $column) {
            switch ($column) {
                case 'work_orders':
                    $metrics[] = ['label' => 'Work orders first recorded in the period', 'value' => count($tickets)];
                    foreach ($tickets as $ticket) {
                        $rows[] = ['Work order', $ticket['id'], $ticket['title'].' · '.$ticket['fields']['location'], $ticket['status'], $ticket['createdAt']];
                    }
                    break;
                case 'completed':
                    $metrics[] = ['label' => 'Work orders completed in the period', 'value' => count($completed)];
                    foreach ($completed as $ticket) {
                        $rows[] = ['Completion', $ticket['id'], $ticket['title'], 'Completed', 'Recorded work evidence'];
                    }
                    break;
                case 'labor_minutes':
                    $metrics[] = ['label' => 'Recorded labor minutes in the period', 'value' => $minutes];
                    break;
                case 'materials':
                    $metrics[] = ['label' => 'Recorded materials notes in the period', 'value' => count($materials)];
                    foreach ($materials as $material) {
                        $rows[] = ['Materials note', $material['ticketId'], $material['text'], 'Recorded', $material['at']];
                    }
                    break;
                case 'teams':
                    foreach ($teams as $team => $count) {
                        $metrics[] = ['label' => $team.' · work first recorded in the period', 'value' => $count];
                    }
                    break;
                case 'finance':
                    $metrics[] = ['label' => 'Finance packets first recorded in the period', 'value' => count($invoices)];
                    foreach ($invoices as $invoice) {
                        $rows[] = ['Demo finance packet', $invoice['number'], $invoice['description'], $invoice['status'], number_format($invoice['amountCents'] / 100, 2, '.', '').' USD'];
                    }
                    break;
                case 'certificates':
                    $metrics[] = ['label' => 'Certificates needing attention at report time', 'value' => count($certificateAttention)];
                    foreach ($certificateAttention as $certificate) {
                        $rows[] = ['Certificate', $certificate['vendorName'], $certificate['type'], $certificate['attention'], $certificate['expiresOn'] ?? 'No accepted expiry'];
                    }
                    break;
            }
        }
        abort_if(count($rows) > 2000, 422, 'Choose a smaller report window to keep this demo export readable.');
        $report = [
            'period' => $period, 'timezone' => $settings['timezone'], 'columns' => $columns, 'omittedColumns' => $omitted,
            'settingsRevision' => $settings['revision'], 'metrics' => $metrics, 'rows' => $rows,
            'notice' => 'Fictional demo records. The period selects recorded requests and work events. Statuses and certificate attention show the state at report time; this is not a reconstruction of an earlier quarter-end.',
        ];
        $digest = hash('sha256', json_encode($report, JSON_THROW_ON_ERROR));

        return [...$report, 'digest' => $digest, 'asOf' => now()->toIso8601String()];
    }

    public function review(array &$state, array $actor, string $id, int $revision, array $data): array
    {
        $records = app(DemoRecords::class);
        $records->requireReviewer($actor, $state['operations']['settings']['reportReviewerRole']);
        $data = $records->data($data, ['start' => 'required|date_format:Y-m-d', 'end' => 'required|date_format:Y-m-d', 'digest' => 'required|string|size:64', 'title' => 'required|string|min:5|max:150', 'note' => 'required|string|min:5|max:1000']);
        abort_unless($revision === 0, 422, 'Reviewed reports are retained snapshots. Prepare a new report for a later review.');
        $report = $this->build($state, $data, $actor);
        abort_if($report['omittedColumns'], 403, 'Use a reviewer permitted to see every configured report section.');
        abort_unless(hash_equals($report['digest'], $data['digest']), 409, 'The report facts or template changed. Preview the current report before reviewing it.');
        $records->save($state, 'reports', $id, 0, ['title' => $data['title'], 'note' => $data['note'], 'reviewedBy' => $actor['name'], 'reviewedAt' => now()->toIso8601String(), 'snapshot' => $report]);

        return ['message' => 'Reviewed demo report retained. Downloading it does not send or submit it.', 'resourceId' => $id];
    }

    public function csv(array $report): string
    {
        $stream = fopen('php://temp', 'w+');
        $write = function (array $row) use ($stream): void {
            fputcsv($stream, array_map(function ($value) {
                $value = (string) $value;

                return preg_match('/^[\s\pZ\pC]*[=+\-@]/u', $value) || preg_match('/^[\t\r\n]/u', $value) ? "'".$value : $value;
            }, $row), ',', '"', '');
        };
        $write(['Lees-McRae Facilities — fictional demo report']);
        $write(['Period', $report['period']['start'], $report['period']['end'], $report['timezone']]);
        $write(['As of', $report['asOf']]);
        $write([$report['notice']]);
        if ($report['omittedColumns']) {
            $write(['Sections excluded for this demo role', implode(', ', $report['omittedColumns'])]);
        }
        $write(['Metric', 'Value']);
        foreach ($report['metrics'] as $metric) {
            $write([$metric['label'], $metric['value']]);
        }
        $write([]);
        $write(['Section', 'Reference', 'Description', 'Current state', 'Recorded value']);
        foreach ($report['rows'] as $row) {
            $write($row);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    private function restrictedColumns(array $columns, array $actor): array
    {
        $directory = app(DemoDirectory::class);

        return array_values(array_filter($columns, fn ($column) => ($column === 'finance' && ! $directory->may($actor, 'finance.view')) || ($column === 'certificates' && ! $directory->may($actor, 'vendors.view'))));
    }
}

<?php

namespace App\Services\Team\Operations;

use App\Models\TeamProviderConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SheetIdentityService
{
    public function preview(TeamProviderConnection $c): array
    {
        $provider = app(OperationsProviders::class);
        $sheet = $c->settings['source']['spreadsheet_id'] ?? '';
        abort_unless(preg_match('/^[A-Za-z0-9_-]{10,160}$/D', $sheet), 422);
        $base = 'https://sheets.googleapis.com/v4/spreadsheets/'.$sheet;
        $metadata = $provider->http($c, 'GET', $base, ['fields' => 'spreadsheetId,properties,sheets.properties']);
        $backup = ['metadata' => $metadata, 'tabs' => []];
        $tabs = [];
        foreach ($metadata['sheets'] ?? [] as $tab) {
            $p = $tab['properties'];
            $name = $p['title'];
            if (! in_array($name, $c->settings['source']['tabs'] ?? [], true) || $name === 'Payment Criteria') {
                continue;
            }
            abort_if(($p['gridProperties']['columnCount'] ?? 703) > 699, 422, 'This tab needs an explicit identifier-column mapping.');
            $range = rawurlencode("'".str_replace("'", "''", $name)."'!A:ZZ");
            $values = $provider->http($c, 'GET', $base.'/values/'.$range, ['valueRenderOption' => 'UNFORMATTED_VALUE'])['values'] ?? [];
            $formulas = $provider->http($c, 'GET', $base.'/values/'.$range, ['valueRenderOption' => 'FORMULA'])['values'] ?? [];
            $backup['tabs'][$name] = ['values' => $values, 'formulas' => $formulas];
            $mapped = app(SheetMapper::class)->rows($name, $values);
            $tabs[] = ['name' => $name, 'headers' => $values[0] ?? [], 'mapped_rows' => count($mapped['items']), 'allocation_review_rows' => count(array_filter($mapped['items'], fn ($i) => $i['needs_allocation_review'])), 'errors' => $mapped['errors']];
        }
        abort_unless($tabs, 422, 'Select mapped compensation tabs first.');
        $snapshot = app(OperationsSync::class)->snapshot($c, 'identity-preview', ['generation' => $c->generation, 'backup' => $backup]);

        return ['backup_id' => $snapshot->id, 'checksum' => $snapshot->checksum, 'tabs' => $tabs, 'notice' => 'Encrypted values and formulas backed up before writes. Review headers, identity ambiguity and shared-cost allocation. Initial IDs do not verify a person or create an account.'];
    }

    public function initialize(TeamProviderConnection $c, string $backupId): array
    {
        abort_unless(config('team_operations.sheet_ids_enabled'), 409, 'Spreadsheet ID writes are disabled. Review the backup and enable this gate first.');
        abort_unless(($c->settings['send_authorized'] ?? false) && in_array('https://www.googleapis.com/auth/spreadsheets', explode(' ', $c->settings['scopes'] ?? ''), true), 403, 'Separate spreadsheet write consent is required.');
        abort_if(\App\Models\TeamOperationAttempt::where(['tenant_id' => $c->tenant_id, 'connection_id' => $c->id, 'kind' => 'sheet.identities'])->whereIn('status', ['executing', 'unknown'])->exists(), 409, 'An identity-write outcome needs reconciliation. Further identifier writes are blocked.');
        $reviewed = \App\Models\TeamSourceSnapshot::where(['tenant_id' => $c->tenant_id, 'connection_id' => $c->id, 'resource_key' => hash('sha256', 'identity-preview')])->findOrFail($backupId);
        abort_unless(($reviewed->payload['generation'] ?? null) === $c->generation && $reviewed->observed_at->gt(now()->subMinutes(10)), 409, 'Review a current mapping preview first.');

        return Cache::lock('team-sheet-ids:'.$c->id, 120)->block(1, function () use ($c, $reviewed) {
            $provider = app(OperationsProviders::class);
            $sheetId = $c->settings['source']['spreadsheet_id'] ?? '';
            abort_unless(preg_match('/^[A-Za-z0-9_-]{10,160}$/D', $sheetId), 422);
            $base = 'https://sheets.googleapis.com/v4/spreadsheets/'.$sheetId;
            $metadata = $provider->http($c, 'GET', $base, ['fields' => 'spreadsheetId,properties,sheets.properties']);
            $backup = ['metadata' => $metadata, 'tabs' => []];
            $requests = [];
            $people = [];
            $events = [];
            $rowIdentities = [];
            $expectedIds = [];
            foreach ($metadata['sheets'] ?? [] as $tab) {
                $p = $tab['properties'];
                $name = $p['title'];
                if (! in_array($name, $c->settings['source']['tabs'] ?? [], true) || $name === 'Payment Criteria') {
                    continue;
                }
                $range = rawurlencode("'".str_replace("'", "''", $name)."'!A:ZZ");
                $values = $provider->http($c, 'GET', $base.'/values/'.$range, ['valueRenderOption' => 'UNFORMATTED_VALUE'])['values'] ?? [];
                $formulas = $provider->http($c, 'GET', $base.'/values/'.$range, ['valueRenderOption' => 'FORMULA'])['values'] ?? [];
                $backup['tabs'][$name] = ['values' => $values, 'formulas' => $formulas];
                $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $values[0] ?? []);
                abort_unless(in_array('event', $headers, true) && in_array('start date', $headers, true), 422, 'Map every selected tab before initializing IDs.');
                $wanted = ['_teams_row_id', '_teams_event_id', '_teams_person_id'];
                $columns = [];
                $count = (int) $p['gridProperties']['columnCount'];
                $append = 0;
                foreach ($wanted as $header) {
                    $at = array_search($header, $headers, true);
                    $columns[$header] = $at === false ? $count + $append++ : $at;
                }
                if ($append) {
                    $requests[] = ['appendDimension' => ['sheetId' => $p['sheetId'], 'dimension' => 'COLUMNS', 'length' => $append]];
                }
                foreach ($columns as $header => $column) {
                    $rows = [['values' => [['userEnteredValue' => ['stringValue' => $header]]]]];
                    $knownRows = [];
                    foreach (array_slice($values, 1) as $i => $row) {
                        $person = trim((string) ($row[0] ?? ''));
                        $event = trim((string) ($row[array_search('event', $headers)] ?? ''));
                        $old = trim((string) ($row[$column] ?? ''));
                        if ($old && $header === '_teams_row_id') {
                            abort_if(isset($rowIdentities[$old]), 409, 'Copied row IDs need review before further writes.');
                            $rowIdentities[$old] = true;
                        }
                        if ($old) {
                            $knownRows[$old] = true;
                        }
                        if ($old && $header === '_teams_person_id') {
                            $personKey = mb_strtolower($person);
                            abort_if(isset($people[$personKey]) && $people[$personKey] !== $old, 409, 'Duplicate names have different person identities. Review person mapping first.');
                            $people[$personKey] = $old;
                        }
                        if ($old && $header === '_teams_event_id') {
                            $eventKey = $event.'|'.($row[array_search('start date', $headers)] ?? '').'|'.($row[array_search('ending date', $headers)] ?? '');
                            abort_if(isset($events[$eventKey]) && $events[$eventKey] !== $old, 409, 'Event identity is ambiguous. Review occurrence mapping first.');
                            $events[$eventKey] = $old;
                        }
                        $identity = '';
                        if ($person !== '' && $event !== '') {
                            if ($header === '_teams_person_id') {
                                $identity = $people[mb_strtolower($person)] ??= (string) Str::uuid();
                            } elseif ($header === '_teams_event_id') {
                                $identity = $events[$event.'|'.($row[array_search('start date', $headers)] ?? '').'|'.($row[array_search('ending date', $headers)] ?? '')] ??= (string) Str::uuid();
                            } else {
                                $identity = (string) Str::uuid();
                            }
                        }
                        $rows[] = ['values' => [['userEnteredValue' => ['stringValue' => $old ?: $identity]]]];
                    }
                    foreach ($rows as $index => $row) {
                        $expectedIds[$name][$index][$column] = $row['values'][0]['userEnteredValue']['stringValue'];
                    }
                    $requests[] = ['updateCells' => ['range' => ['sheetId' => $p['sheetId'], 'startRowIndex' => 0, 'endRowIndex' => count($rows), 'startColumnIndex' => $column, 'endColumnIndex' => $column + 1], 'rows' => $rows, 'fields' => 'userEnteredValue']];
                    $requests[] = ['updateDimensionProperties' => ['range' => ['sheetId' => $p['sheetId'], 'dimension' => 'COLUMNS', 'startIndex' => $column, 'endIndex' => $column + 1], 'properties' => ['hiddenByUser' => true], 'fields' => 'hiddenByUser']];
                }
            }
            $snapshot = app(OperationsSync::class)->snapshot($c, 'identity-backup', $backup);
            abort_unless($backup == $reviewed->payload['backup'], 409, 'Spreadsheet changed after preview. No writes issued; review again.');
            // Re-read the source before applying; a stale preview must never write over new rows.
            foreach ($backup['tabs'] as $name => $data) {
                $current = $provider->http($c, 'GET', $base.'/values/'.rawurlencode("'".str_replace("'", "''", $name)."'!A:ZZ"), ['valueRenderOption' => 'FORMULA'])['values'] ?? [];
                abort_unless($current === $data['formulas'], 409, 'Spreadsheet changed while preparing IDs. No writes were issued. Review again.');
            }
            $attempt = \App\Models\TeamOperationAttempt::firstOrCreate(['tenant_id' => $c->tenant_id, 'user_id' => $c->user_id, 'request_id' => $reviewed->id], ['id' => (string) Str::uuid(), 'area_id' => $c->area_id, 'connection_id' => $c->id, 'kind' => 'sheet.identities', 'status' => 'executing', 'input_hash' => $reviewed->checksum, 'payload' => ['backup_id' => $reviewed->id]]);
            abort_unless($attempt->wasRecentlyCreated, 409, 'This identity-write attempt already exists. Reconcile its outcome before any further write.');
            try {
                if ($requests) {
                    $provider->http($c, 'POST', $base.':batchUpdate', ['requests' => $requests]);
                }
            } catch (\Throwable $e) {
                $attempt->update(['status' => 'unknown']);
                throw $e;
            }
            foreach ($backup['tabs'] as $name => $data) {
                $current = $provider->http($c, 'GET', $base.'/values/'.rawurlencode("'".str_replace("'", "''", $name)."'!A:ZZ"), ['valueRenderOption' => 'FORMULA'])['values'] ?? [];
                $idColumns = [];
                foreach ($data['values'][0] ?? [] as $i => $header) {
                    if (str_starts_with(strtolower(trim((string) $header)), '_teams_')) {
                        $idColumns[] = $i;
                    }
                }
                $unchanged = count($current) === count($data['formulas']);
                foreach ($expectedIds[$name] ?? [] as $row => $columns) {
                    foreach ($columns as $column => $value) {
                        if (($current[$row][$column] ?? '') !== $value) {
                            $unchanged = false;
                        }
                    }
                }
                foreach ($data['formulas'] as $row => $cells) {
                    foreach ($cells as $column => $value) {
                        if (! in_array($column, $idColumns, true) && ($current[$row][$column] ?? '') !== $value) {
                            $unchanged = false;
                        }
                    }
                }
                if (! $unchanged) {
                    $attempt->update(['status' => 'unknown']);
                    $c->update(['settings' => array_replace($c->settings, ['mapping_approved' => false])]);
                    abort(409, 'The spreadsheet changed during the identifier write. Backups are retained; review row identity before continuing.');
                }
            }
            $attempt->update(['status' => 'accepted', 'result' => ['backup_id' => $snapshot->id]]);
            $c->update(['settings' => array_replace($c->settings, ['identity_backup_id' => $snapshot->id, 'identity_initialized_at' => now()->toIso8601String()])]);

            return ['backup_id' => $snapshot->id, 'status' => 'initialized', 'notice' => 'Hidden IDs initialized. Visible source values and formulas were not rewritten. Refresh and review event allocations.'];
        });
    }
}

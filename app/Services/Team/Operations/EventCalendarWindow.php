<?php

namespace App\Services\Team\Operations;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/** Date selection over encrypted calendars. SQL bounds prioritize, never exclude, reviewed outliers. */
class EventCalendarWindow
{
    /**
     * Continuations recheck project access even when new rows do not use that source.
     * Legacy manual windows keep their short scan budget; complete collections
     * check every selected project, with normal provider timeout/revocation guards.
     */
    public function sourcesAvailable(Tenant $tenant, User $user, TeamArea $area, bool $bounded = true): bool
    {
        $providers = app(OperationsProviders::class);
        $deadline = $this->monotonicSeconds() + 8;
        $checks = [];
        foreach (TeamProviderConnection::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $user->id, 'provider' => 'asana', 'status' => 'connected'])->orderBy('id')->get() as $connection) {
            $source = $connection->settings['source'] ?? [];
            if (! is_array($source) || ! is_array($source['asana_projects'] ?? []) || ! is_scalar($source['asana_workspace'] ?? null) || ! ctype_digit((string) $source['asana_workspace'])) {
                return false;
            }
            foreach ([$source['asana_project'] ?? '', ...($source['asana_projects'] ?? [])] as $project) {
                if ($project !== '' && (! is_scalar($project) || ! ctype_digit((string) $project))) {
                    return false;
                }
            }
            try {
                $projects = $providers->selectedProjects($connection);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                return false;
            }
            foreach ($projects as $project) {
                $checks[] = [$connection, $project];
                if ($bounded && count($checks) > 8) {
                    return false;
                }
            }
        }
        foreach ($checks as [$connection, $project]) {
            if ($bounded && $this->monotonicSeconds() >= $deadline) {
                return false;
            }
            try {
                $providers->verifyAsanaProject($connection, $project);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                if (! in_array($error->getStatusCode(), [403, 404, 409, 422, 429, 502, 503])) {
                    throw $error;
                }

                return false;
            }
            if ($bounded && $this->monotonicSeconds() >= $deadline) {
                return false;
            }
        }

        return true;
    }

    protected function monotonicSeconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    public function fromFilters(array $filters): ?array
    {
        if (! isset($filters['calendar_from']) && ! isset($filters['calendar_to'])) {
            return null;
        }
        $input = Validator::make($filters, [
            'kind' => 'required|in:event',
            'calendar_from' => 'required|date_format:Y-m-d',
            'calendar_to' => 'required|date_format:Y-m-d|after_or_equal:calendar_from',
        ])->validate();
        abort_if(CarbonImmutable::parse($input['calendar_from'])->diffInDays(CarbonImmutable::parse($input['calendar_to'])) > 61, 422, 'Choose a calendar window of at most 62 days.');

        return ['from' => $input['calendar_from'], 'to' => $input['calendar_to']];
    }

    public function phase($query, array $window, int $phase): void
    {
        if ($phase === 0) {
            $query->whereNotNull('starts_on')->where('starts_on', '<=', $window['to'])
                ->whereRaw('COALESCE(ends_on, starts_on) >= ?', [$window['from']]);
        } else {
            $query->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '>', $window['to'])
                ->orWhereRaw('COALESCE(ends_on, starts_on) < ?', [$window['from']]));
        }
    }

    public function matches(TeamOperationRecord $record, array $window, bool $includeUndated = true): bool
    {
        $data = $record->data ?? [];
        $calendar = app(EventSchedule::class)->calendar($data, $record->starts_on?->toDateString(), $record->ends_on?->toDateString());
        $dates = array_values(array_filter(array_column($calendar['sessions'], 'date'), $this->validDate(...)));
        $due = $data['source_schedule']['due_on'] ?? $data['event_schedule']['source_schedule']['due_on'] ?? null;
        foreach ([...$dates, $due] as $date) {
            if ($this->validDate($date) && $date >= $window['from'] && $date <= $window['to']) {
                return true;
            }
        }
        if ($dates) {
            return false; // Sparse explicit sessions never become a continuous range.
        }
        $start = $record->starts_on?->toDateString();
        $end = $record->ends_on?->toDateString() ?? $start;
        if ($start || $end) {
            return ($start ?? $end) <= $window['to'] && $end >= $window['from'];
        }

        // Legacy windows may retain a separate unknown-date list. Compact dated
        // windows omit it because an undated record cannot be classified as upcoming.
        return $includeUndated && ! $this->validDate($due);
    }

    private function validDate(mixed $date): bool
    {
        return is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}

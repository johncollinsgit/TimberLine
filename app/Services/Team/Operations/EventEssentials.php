<?php

namespace App\Services\Team\Operations;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\Studio\EventCommerceReport;
use App\Services\Team\Studio\EventIntelligence;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Human-facing event cards. Source IDs, hashes, raw imports and payroll stay elsewhere. */
class EventEssentials
{
    public function __construct(private OperationsAccess $access, private OperationsWorkspace $workspace, private EventTravelPlan $travel) {}

    public function read(Tenant $tenant, User $user, TeamOperationRecord $event): array
    {
        abort_unless($event->kind === 'event', 404);
        $this->access->record($tenant, $user, $event);
        $area = TeamArea::where('tenant_id', $tenant->id)->findOrFail($event->area_id);
        $admin = $this->access->admin($this->access->actor($tenant, $user));
        $view = $this->workspace->view($event, $this->access->mayWrite($tenant, $user, $area->id), $admin);
        $data = $view['data'];
        $sourceCurrent = $this->travel->sourceCurrent($event, $this->access->admittedAsanaVersion($tenant, $user, $event));
        $presentation = $this->fieldPresentation($data);
        $fields = $presentation['values'];
        $address = $fields['address'] ?? null;
        $schedule = $data['event_schedule'] ?? [];
        $home = $this->travel->homeBase($area->settings ?? [], $tenant->accessProfile?->metadata ?? []);
        $email = $fields['coordinator_email'] ?? $fields['email'] ?? null;
        $phone = $fields['coordinator_phone'] ?? $fields['phone'] ?? null;
        $email = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        $phoneDigits = is_string($phone) ? preg_replace('/[^0-9+]/', '', $phone) : '';
        $phoneUrl = preg_match('/^\+?[0-9]{7,15}$/D', $phoneDigits) ? 'tel:'.$phoneDigits : null;
        $hotelName = $fields['hotel'] ?? null;
        $hotelAddress = $fields['hotel_address'] ?? null;
        $starts = $schedule['overall_starts_on'] ?? $event->starts_on?->toDateString();
        $ends = $schedule['overall_ends_on'] ?? $event->ends_on?->toDateString();
        $comparisonDate = $schedule['selling_dates'][0] ?? $event->starts_on?->toDateString() ?? $starts;
        $sales = ['can_view' => $admin, 'current' => ['amount_cents' => null, 'currency' => 'USD', 'status' => $admin ? 'needs_event_link' : 'restricted', 'label' => 'Current sales'],
            'previous' => ['amount_cents' => null, 'currency' => 'USD', 'year' => $comparisonDate ? ((int) substr($comparisonDate, 0, 4) - 1) : null, 'status' => $admin ? 'not_recorded' : 'restricted', 'label' => 'Previous year sales']];
        if ($admin) {
            $history = app(EventIntelligence::class)->detail($tenant, $user, $event);
            $previous = collect($history['history'])->filter(fn ($row) => substr($row['starts_on'] ?? '', 0, 4) === (string) $sales['previous']['year'])->values();
            if ($previous->count() === 1 && ($previous[0]['finance']['complete'] ?? false)) {
                $sales['previous']['amount_cents'] = $previous[0]['finance']['net_sales_cents'];
                $sales['previous']['status'] = 'recorded';
            } elseif ($previous->isNotEmpty()) {
                $sales['previous']['status'] = 'needs_review';
            }
            if (Schema::hasTable('commerce_stock_event_links')) {
                try {
                    $report = app(EventCommerceReport::class)->forEvent($tenant, $user, $event);
                    $sales['current']['amount_cents'] = $report['sales']['net_sales_minor'];
                    $sales['current']['status'] = ($report['sales']['complete'] ?? false) && $report['sales']['net_sales_minor'] !== null ? 'recorded' : 'needs_review';
                } catch (HttpException $error) {
                    if (! in_array($error->getStatusCode(), [409, 422, 502, 503], true)) {
                        throw $error;
                    }
                    $sales['current']['status'] = in_array($error->getStatusCode(), [502, 503], true) ? 'temporarily_unavailable' : 'needs_event_link';
                }
            }
        }

        return ['event_id' => $event->id, 'event_version' => $event->version, 'area_version' => $area->version, 'can_manage_travel' => $admin,
            'title' => $event->title, 'dates' => ['starts_on' => $starts, 'ends_on' => $ends, 'selling_dates' => $schedule['selling_dates'] ?? [], 'setup_dates' => $schedule['setup_dates'] ?? [], 'timezone' => $schedule['timezone'] ?? null, 'sessions' => $schedule['sessions'] ?? [], 'complete' => $schedule['schedule_complete'] ?? false],
            'location' => ['venue' => $fields['venue'] ?? null, 'address' => $address, 'maps_url' => $this->map($address), 'booth' => $fields['booth'] ?? $fields['booth_number'] ?? null],
            'team_members' => array_values(array_map(fn ($p) => ['name' => $p['name']], array_filter($view['calendar_people'] ?? [], fn ($p) => ! empty($p['name'])))),
            'hotel' => ['name' => $hotelName, 'address' => $hotelAddress, 'maps_url' => $this->map($hotelAddress ?: $hotelName)],
            'coordinator' => ['name' => $fields['coordinator'] ?? $fields['contact'] ?? $fields['organizer'] ?? null, 'email' => $email, 'phone' => $phone, 'email_url' => $email ? 'mailto:'.$email : null, 'phone_url' => $phoneUrl],
            'source_reconciled' => $sourceCurrent, 'notice' => $sourceCurrent ? null : 'Event details have changed. Refresh connected data before reviewing travel.',
            'field_status' => $sourceCurrent ? $presentation['status'] : array_fill_keys(array_keys($presentation['status']), 'needs_review'), 'has_note_fields' => in_array('notes', $presentation['status'], true),
            'can_prepare_audiences' => app(\App\Services\Marketing\CustomerCommerceLinkService::class)->canLink($user, $tenant->id),
            'can_lookup_customers' => $this->access->mayWrite($tenant, $user, $area->id) && app(\App\Services\Tenancy\TenantModuleAccessResolver::class)->canAccess($tenant->id, 'customers'),
            'commerce_event_id' => app(EventCommerceReport::class)->commerceId($event),
            'sales' => $sales, 'travel' => $this->travel->calculate($home, $address, $schedule, $data['_teams_enrichment']['travel'] ?? [], $data['source_version'] ?? null, $sourceCurrent)];
    }

    /** Current explicit note candidates may be displayed, never silently approved or used as sales. */
    public function fields(array $data): array
    {
        return $this->fieldPresentation($data)['values'];
    }

    public function fieldPresentation(array $data): array
    {
        $keys = ['venue', 'address', 'hotel', 'hotel_address', 'booth', 'booth_number', 'coordinator', 'coordinator_phone', 'coordinator_email', 'contact', 'organizer', 'phone', 'email'];
        $result = [];
        $status = [];
        $source = isset($data['source_id'], $data['source_version']);
        foreach ($keys as $key) {
            $candidates = collect($data['fields'] ?? [])->where('field', $key)->filter(fn ($field) => is_string($field['value'] ?? null) && trim($field['value']) !== '');
            $approved = $candidates->where('review_state', 'approved')->pluck('value')->map(fn ($value) => trim($value))->unique()->values();
            $values = $candidates->pluck('value')->map(fn ($value) => trim($value))->unique()->values();
            $value = $data['reviewed_fields'][$key] ?? $data[$key] ?? null;
            if ($source && $values->isNotEmpty()) {
                if ($approved->count() === 1) {
                    $result[$key] = $approved[0];
                    $status[$key] = 'reviewed';
                } elseif ($values->count() === 1) {
                    $result[$key] = $values[0];
                    $status[$key] = 'notes';
                } else {
                    $status[$key] = 'conflicting';
                }
            } elseif ($source && array_key_exists($key, $data['reviewed_fields'] ?? [])) {
                $status[$key] = 'needs_review';
            } elseif (is_string($value) && trim($value) !== '') {
                $result[$key] = trim($value);
                $status[$key] = 'recorded';
            } elseif ($values->count() === 1) {
                $result[$key] = $values[0];
                $status[$key] = 'notes';
            } elseif ($values->count() > 1) {
                $status[$key] = 'conflicting';
            }
        }

        return ['values' => $result, 'status' => $status];
    }

    private function map(?string $address): ?string
    {
        return $address ? 'https://www.google.com/maps/search/?'.http_build_query(['api' => 1, 'query' => $address], '', '&', PHP_QUERY_RFC3986) : null;
    }
}

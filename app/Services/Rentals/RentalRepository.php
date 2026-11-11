<?php

namespace App\Services\Rentals;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RentalRepository
{
    public function __construct(private RentalAccess $access, private RentalWorkspace $workspace) {}

    public function properties(Tenant $tenant, User $user): array
    {
        $actor = $this->access->context($tenant, $user);
        $items = [];
        foreach (DB::table('rental_properties')->where('tenant_id', $tenant->id)->orderBy('created_at')->get() as $row) {
            $state = $this->decode($row->payload);
            if (! $actor['admin'] && ! collect($state['households'])->contains(fn ($household) => in_array($actor['id'], $household['userIds'], true))) {
                continue;
            }
            $items[] = $state['property'];
        }

        return ['properties' => $items, 'canCreate' => $actor['admin'], 'workspaceName' => $tenant->name];
    }

    public function create(Tenant $tenant, User $user, array $input): array
    {
        $v = Validator::make($input, ['id' => 'required|uuid', 'name' => 'required|string|max:160', 'unit' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:160', 'address' => 'nullable|string|max:500', 'timezone' => 'required|timezone'])->validate();
        $hash = $this->hash($v);
        DB::transaction(function () use ($tenant, $user, $v, $hash): void {
            DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->first();
            $actor = $this->access->context($tenant, $user);
            abort_unless($actor['admin'], 403);
            $existing = DB::table('rental_properties')->where('id', $v['id'])->first();
            if ($existing) {
                abort_unless((int) $existing->tenant_id === (int) $tenant->id && hash_equals($this->decode($existing->payload)['creationHash'], $hash), 409);

                return;
            }
            abort_if(DB::table('rental_properties')->where('tenant_id', $tenant->id)->count() >= 100, 422);
            $state = $this->workspace->empty($v);
            $state['creationHash'] = $hash;
            DB::table('rental_properties')->insert(['id' => $v['id'], 'tenant_id' => $tenant->id, 'version' => 1,
                'payload' => $this->encode($state), 'created_at' => now(), 'updated_at' => now()]);
            $this->event($tenant, $actor, $v['id'], $v['id'], $hash, 1, ['action' => 'property.create', 'input' => $v, 'beforeHash' => null, 'afterHash' => $this->hash($state)]);
        }, 3);

        return $this->read($tenant, $user, $v['id']);
    }

    public function raw(Tenant $tenant, User $user, string $property): array
    {
        $actor = $this->access->context($tenant, $user);
        $row = DB::table('rental_properties')->where('tenant_id', $tenant->id)->where('id', $property)->first();
        abort_unless($row, 404);
        $state = $this->decode($row->payload);

        return [$state, $this->access->property($state, $actor), (int) $row->version];
    }

    public function read(Tenant $tenant, User $user, string $property): array
    {
        [$state, $actor, $version] = $this->raw($tenant, $user, $property);

        return $this->view($tenant, $state, $actor, $version);
    }

    public function execute(Tenant $tenant, User $user, string $property, array $input, ?callable $mutation = null): array
    {
        $v = Validator::make($input, ['requestKey' => 'required|uuid', 'version' => 'required|integer|min:1', 'action' => 'required|string|max:60'])->validate();
        $hash = $this->hash($input);
        $replayed = DB::transaction(function () use ($tenant, $user, $property, $input, $v, $hash, $mutation): bool {
            $row = DB::table('rental_properties')->where('tenant_id', $tenant->id)->where('id', $property)->lockForUpdate()->first();
            abort_unless($row, 404);
            $before = $this->decode($row->payload);
            $actor = $this->access->property($before, $this->access->context($tenant, $user));
            abort_unless($actor['write'], 403);
            $receipt = DB::table('rental_events')->where('tenant_id', $tenant->id)->where('property_id', $property)
                ->where('actor_id', $user->id)->where('request_key', $v['requestKey'])->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'This request key belongs to a different change.');

                return true;
            }
            abort_unless((int) $row->version === (int) $v['version'], 409, 'This property changed. Refresh before saving again.');
            $now = now()->toIso8601String();
            $after = $mutation ? $mutation($before, $actor, $now) : $this->workspace->apply($before, $actor, $input, $now);
            DB::table('rental_properties')->where('tenant_id', $tenant->id)->where('id', $property)->update([
                'payload' => $this->encode($after), 'version' => $row->version + 1, 'updated_at' => now(),
            ]);
            $this->event($tenant, $actor, $property, $v['requestKey'], $hash, $row->version + 1,
                ['action' => $v['action'], 'input' => $input, 'beforeHash' => $this->hash($before), 'afterHash' => $this->hash($after)]);

            return false;
        }, 3);

        return $this->read($tenant, $user, $property) + ['replayed' => $replayed];
    }

    private function view(Tenant $tenant, array $state, array $actor, int $version): array
    {
        unset($state['creationHash']);
        $docs = [];
        foreach ($state['documents'] as $doc) {
            if (($doc['teamId'] ?? '') !== '' && ! $actor['admin']) {
                $team = collect($state['teams'])->firstWhere('id', $doc['teamId']);
                if (! $team || ! in_array($actor['householdId'], $team['memberHouseholdIds'], true)) {
                    continue;
                }
            }
            if (isset($doc['storagePath'])) {
                $doc['url'] = route('rentals.file', ['property' => $state['property']['id'], 'file' => $doc['id'], 'tenant' => $tenant->slug]);
            }
            unset($doc['storagePath'], $doc['sha256']);
            $docs[$doc['id']] = $doc;
        }
        $project = function (array $record) use (&$project, $docs, $actor): array {
            $record['attachmentIds'] = array_values(array_filter(array_column($record['attachments'] ?? [], 'id'), fn ($id) => isset($docs[$id])));
            $record['attachments'] = array_values(array_intersect_key($docs, array_flip($record['attachmentIds'])));
            if (isset($record['comments'])) {
                $record['comments'] = array_map($project, $record['comments']);
            }
            if (isset($record['authorUserId'])) {
                $record['canDelete'] = $actor['write'] && ($actor['admin'] || $record['authorUserId'] === $actor['id']);
            }

            return $record;
        };
        foreach (['weeks', 'announcements', 'maintenance', 'journal'] as $collection) {
            $state[$collection] = array_map($project, $state[$collection]);
        }
        foreach ($state['teams'] as &$team) {
            $team['canView'] = $actor['admin'] || in_array($actor['householdId'], $team['memberHouseholdIds'], true);
            $team['notes'] = $team['canView'] ? array_map($project, $team['notes']) : [];
        }
        unset($team);
        foreach ($state['households'] as &$household) {
            $household['members'] = $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)->whereIn('users.id', $household['userIds'])
                ->get(['users.id', 'users.name', 'users.email'])->map(fn ($user) => ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email, 'phone' => ''])->all();
            if (! $actor['admin']) {
                unset($household['userIds']);
            }
        }
        unset($household);
        $state['dues'] = array_values(array_filter($state['dues'], fn ($due) => $actor['admin'] || $due['householdId'] === $actor['householdId']));
        foreach ($state['dues'] as &$due) {
            $due['paidCents'] = array_sum(array_map(fn ($payment) => isset($payment['voidedAt']) ? 0 : $payment['amountCents'], $due['payments']));
            $due['amount'] = $due['amountCents'] / 100;
            $due['paid'] = $due['paidCents'] / 100;
            $due['status'] = $due['paidCents'] === $due['amountCents'] ? 'paid' : ($due['paidCents'] > 0 ? 'partial' : 'unpaid');
            foreach ($due['payments'] as &$payment) {
                $payment['amount'] = $payment['amountCents'] / 100;
            }
            unset($payment);
        }
        unset($due);
        if (! $actor['admin']) {
            $state['messages'] = array_values(array_filter($state['messages'], fn ($message) => $message['authorUserId'] === $actor['id'] || in_array($actor['householdId'], $message['recipientIds'], true)));
            $state['permanentRequests'] = array_values(array_filter($state['permanentRequests'], fn ($r) => in_array($actor['householdId'], [$r['fromHouseholdId'], $r['toHouseholdId']], true)));
            $state['swaps'] = array_values(array_filter($state['swaps'], fn ($s) => in_array($actor['householdId'], [$s['requesterHouseholdId'], $s['targetHouseholdId']], true)));
        }
        $state['audit'] = [];
        if ($actor['admin']) {
            foreach (DB::table('rental_events')->where('tenant_id', $tenant->id)->where('property_id', $state['property']['id'])->orderByDesc('id')->limit(200)->get() as $event) {
                $details = $this->decode($event->payload);
                $state['audit'][] = ['id' => (string) $event->id, 'action' => $details['action'], 'detail' => $details['action'], 'by' => $details['actorName'], 'date' => $event->created_at];
            }
        }

        $state['documents'] = array_values($docs);

        return $state + ['currentHousehold' => $actor['householdId'], 'version' => $version,
            'today' => \Carbon\CarbonImmutable::today($state['property']['timezone'])->toDateString(),
            'actor' => ['id' => $actor['id'], 'name' => $actor['name']], 'workspaceName' => $tenant->name,
            'members' => $actor['admin'] ? $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)->get(['users.id', 'users.name', 'users.email'])->toArray() : [],
            'capabilities' => ['admin' => $actor['admin'], 'maintenance' => $actor['maintenance'], 'write' => $actor['write'], 'rentals' => false, 'points' => false, 'payments' => 'offline-records', 'messaging' => 'drafts-only']];
    }

    private function event(Tenant $tenant, array $actor, string $property, string $key, string $hash, int $version, array $payload): void
    {
        DB::table('rental_events')->insert(['tenant_id' => $tenant->id, 'property_id' => $property, 'actor_id' => (int) $actor['id'], 'request_key' => $key,
            'request_hash' => $hash, 'version' => $version, 'payload' => $this->encode($payload + ['actorName' => $actor['name']]), 'created_at' => now()]);
    }

    public function hash(array $input): string
    {
        $normalize = function (array $value) use (&$normalize): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $normalize($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($normalize($input), JSON_THROW_ON_ERROR));
    }

    private function encode(array $value): string
    {
        return Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function decode(string $value): array
    {
        return json_decode(Crypt::decryptString($value), true, 512, JSON_THROW_ON_ERROR);
    }
}

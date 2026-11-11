<?php

namespace App\Services\Rentals;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Tenant-owned shared-home operations; no payment or messaging provider calls. */
class RentalWorkspace
{
    public function empty(array $property): array
    {
        return ['property' => $property + ['scheduleStatus' => 'No schedule entered'], 'households' => [], 'weeks' => [],
            'announcements' => [], 'maintenance' => [], 'journal' => [], 'dues' => [], 'swaps' => [], 'permanentRequests' => [],
            'teams' => [], 'documents' => [], 'links' => [], 'guides' => [], 'messages' => [],
            'settings' => ['remindersEnabled' => false, 'duesReminders' => false, 'stayReminders' => false,
                'massMessagingEnabled' => false, 'rentalsEnabled' => false, 'pointsEnabled' => false, 'rotation' => 'Owner-reviewed allocations']];
    }

    public function apply(array $state, array $actor, array $input, string $now): array
    {
        abort_unless($actor['write'], 403);
        $action = $this->text($input, 'action', 60);
        $id = (string) Str::uuid();
        $admin = function () use ($actor): void {
            abort_unless($actor['admin'], 403);
        };
        $entry = fn (string $collection, string $key) => $this->index($state[$collection], $this->text($input, $key, 100));
        $household = function (string $key = 'householdId') use ($state, $input): string {
            $value = $this->text($input, $key, 100);
            $this->index($state['households'], $value);

            return $value;
        };
        $ownStay = function (int $i) use ($state, $actor): void {
            $week = $state['weeks'][$i];
            abort_unless($actor['admin'] || $week['currentOwner'] === $actor['householdId'], 403);
            abort_if($week['status'] === 'held' || $week['start'] < CarbonImmutable::today($state['property']['timezone'])->toDateString(), 409, 'Only upcoming owner stays can change.');
        };
        switch ($action) {
            case 'property':
                $admin();
                $values = Validator::make($input, ['name' => 'required|string|max:160', 'unit' => 'nullable|string|max:100',
                    'location' => 'nullable|string|max:160', 'address' => 'nullable|string|max:500', 'timezone' => 'required|timezone'])->validate();
                $state['property'] = array_replace($state['property'], $values);
                break;
            case 'household':
                $admin();
                $values = Validator::make($input, ['name' => 'required|string|max:160', 'userIds' => 'present|array|max:20',
                    'userIds.*' => 'required|string|distinct', 'address' => 'nullable|string|max:500', 'color' => ['required', 'regex:/^#[a-fA-F0-9]{6}$/D'],
                    'shares' => 'required|array|min:1|max:100', 'shares.*' => 'integer|min:1|max:100|distinct'])->validate();
                abort_if(array_diff($values['userIds'], $actor['activeUserIds']), 422, 'Choose active workspace members.');
                foreach ($state['households'] as $existing) {
                    abort_if(array_intersect($values['userIds'], $existing['userIds']), 422, 'A person already belongs to a household.');
                    abort_if(array_intersect($values['shares'], $existing['shares']), 422, 'An ownership slot already belongs to another household.');
                }
                $state['households'][] = $values + ['id' => $id, 'share' => $values['shares'][0], 'members' => []];
                break;
            case 'householdMembers':
                $admin();
                $i = $entry('households', 'householdId');
                $values = Validator::make($input, ['userIds' => 'present|array|max:20', 'userIds.*' => 'required|string|distinct'])->validate();
                abort_if(array_diff($values['userIds'], $actor['activeUserIds']), 422);
                foreach ($state['households'] as $n => $existing) {
                    abort_if($n !== $i && array_intersect($values['userIds'], $existing['userIds']), 422);
                }
                $state['households'][$i]['userIds'] = $values['userIds'];
                break;
            case 'createStay':
                $admin();
                $v = Validator::make($input, ['start' => 'required|date_format:Y-m-d', 'end' => 'required|date_format:Y-m-d|after:start',
                    'held' => 'required|boolean', 'share' => 'nullable|integer|min:1|max:100'])->validate();
                abort_unless(CarbonImmutable::parse($v['start'])->addDays(7)->toDateString() === $v['end'], 422, 'Enter a seven-night owner week.');
                foreach ($state['weeks'] as $week) {
                    abort_if($v['start'] < $week['end'] && $v['end'] > $week['start'], 409, 'This week overlaps an existing stay.');
                }
                $owner = $v['held'] ? '' : $household();
                $state['weeks'][] = ['id' => $id, 'start' => $v['start'], 'end' => $v['end'], 'share' => $v['share'] ?? 0,
                    'owner' => $owner, 'originalOwner' => $owner, 'currentOwner' => $owner, 'status' => $v['held'] ? 'held' : 'assigned', 'color' => '#728f8c', 'comments' => []];
                usort($state['weeks'], fn ($a, $b) => strcmp($a['start'], $b['start']));
                $state['property']['scheduleStatus'] = 'Owner-entered weekly allocations';
                break;
            case 'releaseWeek':
                $i = $entry('weeks', 'weekId');
                $ownStay($i);
                abort_unless(in_array($state['weeks'][$i]['status'], ['assigned', 'claimed', 'swapped'], true), 409);
                $state['weeks'][$i]['status'] = 'released';
                break;
            case 'claimWeek':
                $i = $entry('weeks', 'weekId');
                abort_unless($actor['householdId'] !== '' && $state['weeks'][$i]['status'] === 'released', 409, 'This week is no longer available.');
                abort_if($state['weeks'][$i]['start'] < CarbonImmutable::today($state['property']['timezone'])->toDateString(), 409);
                $state['weeks'][$i]['currentOwner'] = $actor['householdId'];
                $state['weeks'][$i]['status'] = 'claimed';
                break;
            case 'requestSwap':
                $i = $entry('weeks', 'fromWeekId');
                $j = $entry('weeks', 'toWeekId');
                $ownStay($i);
                $from = $state['weeks'][$i];
                $to = $state['weeks'][$j];
                abort_unless($actor['householdId'] !== '' && $from['currentOwner'] === $actor['householdId'] && $i !== $j
                    && $to['currentOwner'] !== $from['currentOwner'] && $to['currentOwner'] !== ''
                    && $from['status'] === 'assigned' && $to['status'] === 'assigned', 409);
                abort_if($to['start'] < CarbonImmutable::today($state['property']['timezone'])->toDateString(), 409);
                $state['swaps'][] = ['id' => $id, 'fromWeekId' => $from['id'], 'toWeekId' => $to['id'], 'requesterHouseholdId' => $actor['householdId'],
                    'targetHouseholdId' => $to['currentOwner'], 'status' => 'pending', 'date' => $now];
                break;
            case 'acceptSwap':
            case 'declineSwap':
                $i = $entry('swaps', 'swapId');
                $swap = $state['swaps'][$i];
                abort_unless($actor['householdId'] === $swap['targetHouseholdId'] && $swap['status'] === 'pending', 403);
                $a = $this->index($state['weeks'], $swap['fromWeekId']);
                $b = $this->index($state['weeks'], $swap['toWeekId']);
                abort_unless($state['weeks'][$a]['currentOwner'] === $swap['requesterHouseholdId'] && $state['weeks'][$b]['currentOwner'] === $swap['targetHouseholdId']
                    && $state['weeks'][$a]['status'] === 'assigned' && $state['weeks'][$b]['status'] === 'assigned', 409, 'The stays changed; request a new swap.');
                $ownStay($b);
                abort_if($state['weeks'][$a]['start'] < CarbonImmutable::today($state['property']['timezone'])->toDateString(), 409);
                if ($action === 'acceptSwap') {
                    $state['weeks'][$a]['currentOwner'] = $swap['targetHouseholdId'];
                    $state['weeks'][$b]['currentOwner'] = $swap['requesterHouseholdId'];
                    $state['weeks'][$a]['status'] = $state['weeks'][$b]['status'] = 'swapped';
                }
                $state['swaps'][$i]['status'] = $action === 'acceptSwap' ? 'accepted' : 'declined';
                break;
            case 'permanentChange':
                $i = $entry('weeks', 'weekId');
                $ownStay($i);
                $state['permanentRequests'][] = ['id' => $id, 'weekId' => $state['weeks'][$i]['id'], 'fromHouseholdId' => $state['weeks'][$i]['owner'],
                    'toHouseholdId' => $household(), 'reason' => $this->text($input, 'reason', 1000), 'status' => 'pending', 'date' => $now];
                break;
            case 'adminApprove':
                $admin();
                $i = $entry('permanentRequests', 'requestId');
                $r = $state['permanentRequests'][$i];
                $j = $this->index($state['weeks'], $r['weekId']);
                $week = $state['weeks'][$j];
                abort_unless($r['status'] === 'pending' && $week['owner'] === $r['fromHouseholdId'] && $week['currentOwner'] === $week['owner'] && $week['status'] === 'assigned', 409, 'Review the current allocation before approving.');
                $ownStay($j);
                $state['weeks'][$j]['owner'] = $state['weeks'][$j]['currentOwner'] = $r['toHouseholdId'];
                $state['permanentRequests'][$i]['status'] = 'approved';
                break;
            case 'announcement':
            case 'journal':
            case 'maintenance':
                if ($action === 'announcement') {
                    $admin();
                }
                $record = ['id' => $id, 'title' => $this->text($input, 'title', 160), 'author' => $actor['name'], 'authorUserId' => $actor['id'],
                    'date' => $now, 'comments' => [], 'attachments' => $this->attachments($state, $actor, $input)];
                if ($action === 'journal') {
                    $i = $entry('weeks', 'weekId');
                    abort_unless($actor['admin'] || $state['weeks'][$i]['currentOwner'] === $actor['householdId'], 403);
                    abort_if($state['weeks'][$i]['status'] === 'held', 422);
                    $record += ['weekId' => $state['weeks'][$i]['id'], 'body' => $this->text($input, 'body')];
                    array_unshift($state['journal'], $record);
                } elseif ($action === 'maintenance') {
                    $priority = Validator::make($input, ['priority' => 'required|in:low,normal,high'])->validate()['priority'];
                    $record += ['description' => $this->text($input, 'description'), 'status' => 'open', 'priority' => $priority, 'assignee' => 'Maintenance team',
                        'history' => [['date' => $now, 'by' => $actor['name'], 'note' => 'Request opened']]];
                    array_unshift($state['maintenance'], $record);
                } else {
                    $record += ['body' => $this->text($input, 'body'), 'pinned' => false];
                    array_unshift($state['announcements'], $record);
                }
                break;
            case 'maintenanceStatus':
                abort_unless($actor['maintenance'], 403);
                $i = $entry('maintenance', 'requestId');
                $status = Validator::make($input, ['status' => 'required|in:open,in-progress,completed'])->validate()['status'];
                $state['maintenance'][$i]['status'] = $status;
                $state['maintenance'][$i]['history'][] = ['date' => $now, 'by' => $actor['name'], 'note' => $this->text($input, 'note', 1000)];
                break;
            case 'stayComment':
            case 'journalComment':
            case 'maintenanceComment':
            case 'entryComment':
            case 'teamNote':
                [$collection, $key] = match ($action) {
                    'stayComment' => ['weeks', 'weekId'], 'journalComment' => ['journal', 'postId'],
                    'maintenanceComment' => ['maintenance', 'requestId'], 'teamNote' => ['teams', 'teamId'],
                    default => ['announcements', 'entryId'],
                };
                $i = $entry($collection, $key);
                $teamId = $action === 'teamNote' ? $state['teams'][$i]['id'] : '';
                if ($teamId !== '') {
                    app(RentalAccess::class)->team($state, $actor, $teamId);
                }
                $files = $this->attachments($state, $actor, $input, $teamId);
                $body = $this->text($input, 'body', 3000, true);
                abort_if($body === '' && $files === [], 422, 'Add a note, photo or file.');
                $comment = ['id' => $id, 'body' => $body, 'author' => $actor['name'], 'authorUserId' => $actor['id'], 'date' => $now, 'attachments' => $files];
                $field = $action === 'teamNote' ? 'notes' : 'comments';
                $state[$collection][$i][$field][] = $comment;
                break;
            case 'deleteStayComment':
                $i = $entry('weeks', 'weekId');
                $j = $this->index($state['weeks'][$i]['comments'], $this->text($input, 'commentId', 100));
                abort_unless($actor['admin'] || $state['weeks'][$i]['comments'][$j]['authorUserId'] === $actor['id'], 403);
                array_splice($state['weeks'][$i]['comments'], $j, 1);
                break;
            case 'team':
                $admin();
                $teamId = ($input['maintenance'] ?? false) === true ? 'maintenance' : $id;
                abort_if(collect($state['teams'])->contains('id', $teamId), 409);
                $state['teams'][] = ['id' => $teamId, 'name' => $this->text($input, 'name', 160), 'description' => $this->text($input, 'description', 1000), 'memberHouseholdIds' => [], 'notes' => []];
                break;
            case 'teamMembership':
                $admin();
                $i = $entry('teams', 'teamId');
                $target = $household();
                $members = $state['teams'][$i]['memberHouseholdIds'];
                $state['teams'][$i]['memberHouseholdIds'] = in_array($target, $members, true) ? array_values(array_diff($members, [$target])) : [...$members, $target];
                break;
            case 'chargeDues':
                $admin();
                $v = Validator::make($input, ['amountCents' => 'required|integer|min:1|max:100000000', 'dueDate' => 'required|date_format:Y-m-d'])->validate();
                $state['dues'][] = ['id' => $id, 'householdId' => $household(), 'year' => (int) substr($v['dueDate'], 0, 4),
                    'description' => $this->text($input, 'description', 200), 'amountCents' => (int) $v['amountCents'], 'dueDate' => $v['dueDate'], 'payments' => []];
                break;
            case 'recordPayment':
                $admin();
                $i = $entry('dues', 'duesId');
                $v = Validator::make($input, ['amountCents' => 'required|integer|min:1|max:100000000', 'method' => 'required|in:check,cash',
                    'date' => 'required|date_format:Y-m-d|before_or_equal:today'])->validate();
                $due = $state['dues'][$i];
                $reference = $this->text($input, 'reference', 100);
                abort_if(collect($due['payments'])->contains(fn ($payment) => ! isset($payment['voidedAt']) && $payment['method'] === $v['method']
                    && $payment['date'] === $v['date'] && $payment['amountCents'] === (int) $v['amountCents'] && $payment['reference'] === $reference), 409, 'This receipt is already recorded. Review the existing entry.');
                $paid = array_sum(array_map(fn ($payment) => isset($payment['voidedAt']) ? 0 : $payment['amountCents'], $due['payments']));
                abort_if($paid + $v['amountCents'] > $due['amountCents'], 409, 'This payment exceeds the remaining balance.');
                $state['dues'][$i]['payments'][] = ['id' => $id, 'amountCents' => (int) $v['amountCents'], 'method' => $v['method'], 'date' => $v['date'],
                    'reference' => $reference, 'by' => $actor['name'], 'actorId' => $actor['id'], 'simulated' => false];
                break;
            case 'voidPayment':
                $admin();
                $i = $entry('dues', 'duesId');
                $j = $this->index($state['dues'][$i]['payments'], $this->text($input, 'paymentId', 100));
                abort_if(isset($state['dues'][$i]['payments'][$j]['voidedAt']), 409);
                $state['dues'][$i]['payments'][$j] += ['voidedAt' => $now, 'voidedBy' => $actor['id'], 'voidReason' => $this->text($input, 'reason', 1000)];
                break;
            case 'document':
                $admin();
                $state['documents'][] = ['id' => $id, 'title' => $this->text($input, 'title', 160), 'category' => $this->text($input, 'category', 100),
                    'body' => $this->text($input, 'body'), 'updated' => $now, 'size' => 0, 'uploadedBy' => $actor['name']];
                break;
            case 'guide':
                $admin();
                $state['guides'][] = ['id' => $id, 'title' => $this->text($input, 'title', 160), 'body' => $this->text($input, 'body')];
                break;
            case 'link':
                $admin();
                $url = Validator::make($input, ['url' => 'required|url:https|max:1000'])->validate()['url'];
                abort_if(parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null, 422);
                $state['links'][] = ['id' => $id, 'title' => $this->text($input, 'title', 160), 'description' => $this->text($input, 'description', 1000), 'url' => $url];
                break;
            case 'message':
                $v = Validator::make($input, ['recipientIds' => 'required|array|min:1|max:100', 'recipientIds.*' => 'required|string|distinct', 'channel' => 'required|in:email,sms'])->validate();
                foreach ($v['recipientIds'] as $target) {
                    $this->index($state['households'], $target);
                }
                $state['messages'][] = ['id' => $id, 'subject' => $this->text($input, 'subject', 160), 'body' => $this->text($input, 'body', 3000),
                    'recipientIds' => $v['recipientIds'], 'channel' => $v['channel'], 'author' => $actor['name'], 'authorUserId' => $actor['id'], 'date' => $now, 'status' => 'draft'];
                break;
            default:
                throw ValidationException::withMessages(['action' => 'This operation is not available.']);
        }
        foreach (['households', 'weeks', 'announcements', 'journal', 'maintenance', 'dues', 'teams', 'messages', 'documents', 'swaps', 'permanentRequests'] as $collection) {
            abort_if(count($state[$collection]) > 2000, 422, 'This property has reached its workspace capacity.');
        }
        abort_if(strlen(json_encode($state, JSON_THROW_ON_ERROR)) > 8 * 1024 * 1024, 422, 'This property has reached its workspace capacity.');

        return $state;
    }

    public function attachments(array $state, array $actor, array $input, string $teamId = ''): array
    {
        $values = Validator::make($input, ['attachmentIds' => 'sometimes|array|max:6', 'attachmentIds.*' => 'required|string|distinct'])->validate();

        return array_map(function ($id) use ($state, $actor, $teamId) {
            $doc = $state['documents'][$this->index($state['documents'], $id)];
            abort_unless(isset($doc['storagePath']) && ($doc['teamId'] ?? '') === $teamId, 403, 'Choose files from the same shared or private space.');
            if ($teamId !== '') {
                app(RentalAccess::class)->team($state, $actor, $teamId);
            }

            return ['id' => $id];
        }, $values['attachmentIds'] ?? []);
    }

    private function text(array $input, string $key, int $max = 5000, bool $optional = false): string
    {
        $value = Validator::make($input, [$key => ($optional ? 'nullable' : 'required').'|string|max:'.$max])->validate()[$key] ?? '';

        return trim($value);
    }

    private function index(array $items, string $id): int
    {
        foreach ($items as $i => $item) {
            if ($item['id'] === $id) {
                return $i;
            }
        }
        abort(404);
    }
}
